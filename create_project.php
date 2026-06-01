<?php
/**
 * create_project.php — the main CLI for the web-dev-automation agent.
 *
 *   php create_project.php <project-name>
 *   php create_project.php <project-name> --type html-to-php --mode claude-code
 *   php create_project.php <project-name> --yes                    # non-interactive
 *   php create_project.php <project-name> --dry-run                # build prompt only
 *
 * Pipeline:
 *   1. Validate input/<name>/, input/<name>/features.yaml, input/<name>/page_map.json.
 *   2. Validate every site.* field in features.yaml is non-empty.
 *   3. Detect task type (html-to-php vs mysqli-to-pdo) or honour --type.
 *   4. Validate every input *.html is covered by a page_map.json pair (html-to-php only).
 *   5. Load skills/<type>/SKILL.md + ranked references + all templates/snippets/.
 *   6. Build prompt; --dry-run stops here.
 *   7. If --mode console: validate ANTHROPIC_API_KEY via /v1/models.
 *      If --mode claude-code: locate the `claude` CLI on PATH (C:\nvm4w\nodejs\claude.cmd).
 *   8. Show a confirmation summary and require y/yes before proceeding.
 *   9. Generation:
 *        - html-to-php: STAGED — Stage 1 (scaffold), then one call per page_map
 *          pair with a non-empty `before` (Stage 2), then aggregates (Stage 3).
 *          Pauses for manual inspection after EVERY call unless --yes is set.
 *        - mysqli-to-pdo: legacy one-shot prompt.
 *   10. Write files to output/<name>/, run asset_copies as each stage returns.
 *   11. Invoke tests/run_tests.php and tests/structural_diff.php.
 *
 * Inputs:
 *   - .env                              -> ANTHROPIC_API_KEY (required for console mode)
 *   - input/<name>/                     -> raw input project
 *   - input/<name>/features.yaml        -> site: + features: blocks (required)
 *   - input/<name>/page_map.json        -> before <-> after page mapping (required)
 *
 * Outputs:
 *   - output/<name>/                    -> the generated PHP project
 *   - .last-prompt.json                 -> only with --dry-run
 *   - .last-response.txt                -> only when the API response can't be parsed
 * 
 * --yes (or -y) skips the interactive "Proceed with generation? [y/N]" confirmation prompt — the CLI assumes yes and goes straight to the API call. Use it for non-interactive runs (CI, batch scripts).
 */

declare(strict_types=1);
require_once __DIR__."/root_functions.php";

const DEFAULT_MODEL     = "claude-sonnet-4-6";
const DEFAULT_MAX_TOK   = 16000;
const API_URL           = "https://api.anthropic.com/v1/messages";
const API_MODELS_URL    = "https://api.anthropic.com/v1/models";
const API_VERSION       = "2023-06-01";
const TEXT_EXTS         = ["html","htm","css","js","json","txt","md","php","yaml","yml","xml","svg","sql","ini","htaccess"];

// ============================================================================
// 1. Args + environment
// ============================================================================

$opts = parse_args($argv);
$repoRoot = __DIR__;
load_dotenv("$repoRoot/.env");

if ($opts["help"]) { print_usage(); exit(0); }
if (!$opts["projectName"]) { fwrite(STDERR, "error: project name required.\n"); print_usage(); exit(2); }

$projectName = $opts["projectName"];
$inputDir    = "$repoRoot/input/$projectName";
$outputDir   = "$repoRoot/output/$projectName";

if (!is_dir($inputDir)) { fwrite(STDERR, "error: input/$projectName/ not found. Drop your project there first.\n"); exit(2); }

// output/ must be empty at the start. Promote or delete previous outputs first.
// --dry-run skips this gate since it never writes to output/.
if (!$opts["dry-run"]) {
    $outputBase = "$repoRoot/output";
    @mkdir($outputBase, 0775, true);
    $entries = array_values(array_diff(scandir($outputBase) ?: [], [".", ".."]));
    if (!empty($entries)) {
        fwrite(STDERR, "error: output/ is not empty (contains: " . implode(", ", $entries) . ").\n");
        fwrite(STDERR, "       Promote previous outputs to references/ or delete them before starting a new project.\n");
        exit(2);
    }
}

$model   = $opts["model"] ?? (getenv("ANTHROPIC_MODEL") ?: DEFAULT_MODEL);
$maxToks = (int)(getenv("ANTHROPIC_MAX_TOKENS") ?: DEFAULT_MAX_TOK);

// ============================================================================
// 2. Required input files
// ============================================================================

$featPath = "$inputDir/features.yaml";
$pmPath   = "$inputDir/page_map.json";

if (!is_file($featPath)) {
    fwrite(STDERR, "error: $featPath not found.\n");
    fwrite(STDERR, "       Copy templates/docs/features.yaml.tmpl into input/$projectName/ and fill it in.\n");
    exit(2);
}
if (!is_file($pmPath)) {
    fwrite(STDERR, "error: $pmPath not found.\n");
    fwrite(STDERR, "       Create it from tests/fixtures/page_map.example.json — every *.html in input/$projectName/ must be mapped.\n");
    exit(2);
}

$inputFeatures = parse_yaml_minimal(file_get_contents($featPath));
$pageMap       = json_decode(file_get_contents($pmPath), true);
if (!is_array($pageMap) || !isset($pageMap["pairs"]) || !is_array($pageMap["pairs"])) {
    fwrite(STDERR, "error: $pmPath is not a valid page_map (expected { \"pairs\": [ ... ] }).\n");
    exit(2);
}

// ============================================================================
// 3. Validate site block (every field non-empty)
// ============================================================================

validate_site_block($inputFeatures);

// ============================================================================
// 4. Detect task type
// ============================================================================

$taskType = detect_task_type($inputDir, $opts["type"]);

// ============================================================================
// 5. Validate page_map covers every input HTML (html-to-php only)
// ============================================================================

if ($taskType === "html-to-php") {
    validate_page_map_coverage($inputDir, $pageMap);
}

// ============================================================================
// 6. Load skill, references, snippets, input tree
// ============================================================================

$skillPath = "$repoRoot/skills/$taskType/SKILL.md";
if (!is_file($skillPath)) { fwrite(STDERR, "error: $skillPath not found (no skill for task type '$taskType' yet).\n"); exit(2); }
$skillBody = file_get_contents($skillPath);

$refs       = load_references($repoRoot, $taskType);
$rankedRefs = rank_references($refs, $inputFeatures, 3);
$snippets   = load_snippets($repoRoot);
$inputTree  = collect_input_tree($inputDir);

// ============================================================================
// 7. Build prompt
// ============================================================================

$systemBlocks = build_system_prompt($taskType, $skillBody, $rankedRefs, $snippets, $projectName);

// For html-to-php we run a 3-stage flow (scaffold / page-by-page / aggregates),
// so the user prompt that the model sees changes per call. The "representative"
// prompt used for sizing + dry-run is stage 1 (scaffold), because (a) it carries
// the same heavy input-tree payload as the one-shot prompt, and (b) stages 2/3
// depend on stage-1 outputs that don't exist until the run actually starts.
$stages = [];
if ($taskType === "html-to-php") {
    $stages           = build_stages($pageMap);
    $userPromptForRun = build_user_prompt_scaffold($inputFeatures, $pageMap, $inputTree, $projectName);
} else {
    $userPromptForRun = build_user_prompt($inputFeatures, $pageMap, $inputTree);
}

$promptBytes = approx_prompt_size($systemBlocks, $userPromptForRun);

if ($opts["dry-run"]) {
    file_put_contents("$repoRoot/.last-prompt.json", json_encode([
        "task_type" => $taskType,
        "stage"     => $taskType === "html-to-php" ? "1-scaffold (representative; stages 2+3 also run at execution time)" : "one-shot",
        "system"    => $systemBlocks,
        "user"      => $userPromptForRun,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Dry run — prompt saved to .last-prompt.json (no API call made).\n";
    echo sprintf("Prompt size: %s chars (~%d tokens)\n", number_format($promptBytes), (int)($promptBytes / 4));
    if ($taskType === "html-to-php") {
        echo "Staged plan (" . count($stages) . " API calls):\n";
        foreach ($stages as $i => $s) echo sprintf("  %2d. %s\n", $i + 1, $s["label"]);
    }
    exit(0);
}

// ============================================================================
// 8. Validate API backend (key for console, CLI presence for claude-code)
// ============================================================================

$apiKey = null;
$claudeBin = null;
if($opts["mode"] === "console"){
    $apiKey = require_env("ANTHROPIC_API_KEY");
    echo "Validating ANTHROPIC_API_KEY...\n";
    [$ok, $detail] = validate_console_api_key($apiKey);
    if (!$ok) { fwrite(STDERR, "error: API key validation failed — $detail\n"); exit(2); }
    echo "  -> OK ($detail)\n";
}else{ // claude-code
    $claudeBin = locate_claude_cli();
    if($claudeBin === null){
        fwrite(STDERR, "error: --mode claude-code requires the `claude` CLI on PATH.\n");
        fwrite(STDERR, "       Install with:   npm install -g @anthropic-ai/claude-code\n");
        fwrite(STDERR, "       Then re-run.    (Or switch to --mode console to use ANTHROPIC_API_KEY.)\n");
        exit(2);
    }
    echo "Using claude CLI at: $claudeBin\n";
}

// ============================================================================
// 9. Confirmation summary
// ============================================================================

print_confirmation_summary($projectName, $taskType, $inputDir, $outputDir,
    $inputFeatures, $pageMap, $rankedRefs, $snippets, $inputTree,
    $opts["mode"], $model, $maxToks, $promptBytes);

if ($taskType === "html-to-php") {
    echo "\nStaged plan (" . count($stages) . " API calls; you will be prompted to inspect output/ after EACH unless -y):\n";
    foreach ($stages as $i => $s) echo sprintf("  %2d. %s\n", $i + 1, $s["label"]);
}

if (!$opts["yes"]) {
    echo "\nProceed with generation? [y/N] ";
    $answer = trim((string)fgets(STDIN));
    if (!in_array(strtolower($answer), ["y", "yes"], true)) {
        echo "Aborted.\n";
        exit(0);
    }
}

// ============================================================================
// 10. Call API + write
// ============================================================================

if (is_dir($outputDir)) rrmdir($outputDir);
mkdir($outputDir, 0775, true);

$totalWritten = 0;
$totalAssets  = 0;

if ($taskType === "html-to-php") {
    // Staged: run each stage as its own backend call. Stages 2 and 3 receive
    // the actually-written stage-1 files as a cached system block so the model
    // matches the canonical include paths and globals instead of drifting.
    //
    // Feedback loop (Stage 2 pages only):
    //   After each page is written, structural_diff.php runs for that page.
    //   The user sees pass/fail and can choose: continue | rebuild | quit.
    //   Rebuild re-calls Claude with the mismatch report + current file contents,
    //   allowing the agent to also fix shared scaffold files if they caused the gap.
    $stageCount      = count($stages);
    $pageCount       = 0;
    $maxRebuildTries = 3;
    foreach ($stages as $s) if ($s["kind"] === "page") $pageCount++;

    // After Stage 1 (scaffold), seed the DB so visual checks can render PHP pages.
    $dbSeeded = false;

    $pageIndex = 0;
    foreach ($stages as $i => $stage) {
        $stageNum     = $i + 1;
        $rebuildCount = 0;
        $prevMismatch = null;
        // For page stages, fix the page index before the rebuild loop so it
        // does not re-increment on rebuild iterations.
        if ($stage["kind"] === "page") $pageIndex++;
        $stagePageIndex = $pageIndex;

        while (true) { // rebuild loop — break on continue/quit, repeat on rebuild
            $isRebuild = ($rebuildCount > 0);
            echo "\n=== Stage $stageNum/$stageCount: {$stage['label']}" . ($isRebuild ? " [REBUILD #$rebuildCount]" : "") . " ===\n";

            if ($stage["kind"] === "scaffold") {
                $stageUserPrompt   = build_user_prompt_scaffold($inputFeatures, $pageMap, $inputTree, $projectName);
                $stageSystemBlocks = $systemBlocks;
            } elseif ($stage["kind"] === "page") {
                if ($isRebuild && $prevMismatch !== null) {
                    $stageUserPrompt = build_user_prompt_page_rebuild(
                        $inputFeatures, $stage["pair"], $inputTree,
                        $stagePageIndex, $pageCount, $prevMismatch,
                        read_generated_output($outputDir)
                    );
                } else {
                    $stageUserPrompt = build_user_prompt_page($inputFeatures, $stage["pair"], $inputTree, $stagePageIndex, $pageCount);
                }
                $stageSystemBlocks = append_generated_block($systemBlocks, read_generated_output($outputDir));
            } else { // aggregates
                $stageUserPrompt   = build_user_prompt_aggregates($inputFeatures, $pageMap);
                $stageSystemBlocks = append_generated_block($systemBlocks, read_generated_output($outputDir));
            }

            echo "Calling $model via {$opts['mode']} ...\n";
            $started = microtime(true);
            [$rawResponse, $usage] = $opts["mode"] === "console"
                ? call_anthropic_api($apiKey, $model, $stageSystemBlocks, $stageUserPrompt, $maxToks)
                : call_via_claude_cli($claudeBin, $model, $stageSystemBlocks, $stageUserPrompt);
            $elapsed = microtime(true) - $started;
            echo sprintf("  -> %d output chars in %.1fs\n", strlen($rawResponse), $elapsed);
            if ($usage) echo "  -> usage: in={$usage['input_tokens']}, out={$usage['output_tokens']}, cache_read={$usage['cache_read_input_tokens']}, cache_create={$usage['cache_creation_input_tokens']}\n";

            $payload = extract_json_payload($rawResponse);
            if (!$payload || !isset($payload["files"]) || !is_array($payload["files"])) {
                fwrite(STDERR, "error: stage $stageNum response had no usable 'files' field.\n");
                file_put_contents("$repoRoot/.last-response.txt", $rawResponse);
                fwrite(STDERR, "       Raw response written to .last-response.txt for inspection.\n");
                exit(1);
            }
            $written = write_generated_files($outputDir, $payload["files"]);
            $assets  = copy_input_assets($inputDir, $outputDir, $payload["asset_copies"] ?? []);
            $totalWritten += $written;
            $totalAssets  += $assets;
            echo "  -> Wrote $written file(s), $assets input asset(s).\n";

            // After scaffold: seed the DB so subsequent pages can render for include expansion.
            if ($stage["kind"] === "scaffold" && !$dbSeeded) {
                echo "\nSeeding test DB for per-page visual checks...\n";
                run_php_script("$repoRoot/tests/run_tests.php", [$projectName, "--setup-db-only"]);
                $dbSeeded = true;
            }

            // Per-page structural check (Stage 2 pages). Auto-advance when the
            // check passes; only pause for input (continue / rebuild / quit) when
            // it FAILS. This keeps a clean run hands-off and reserves the prompt
            // for the cases that actually need a decision.
            if ($stage["kind"] === "page") {
                $pageLabel    = $stage["pair"]["label"] ?? "page-$stagePageIndex";
                $pageResult   = run_per_page_structural_check($repoRoot, $projectName, $pageLabel);
                $prevMismatch = format_mismatch_summary($pageResult);

                if ($prevMismatch !== null) {
                    if (!$opts["yes"]) {
                        $canRebuild = ($rebuildCount < $maxRebuildTries);
                        $action = prompt_checkpoint_visual(
                            "Stage $stageNum: {$stage['label']}",
                            $prevMismatch,
                            $canRebuild
                        );
                        if ($action === "rebuild") { $rebuildCount++; continue; }
                        // "continue" or "quit" (quit already called exit inside the function)
                    } else {
                        echo "  structural check reported mismatches (continuing: --yes).\n";
                    }
                } else {
                    echo "  structural check passed — advancing to next stage.\n";
                }
                break;
            }

            // Scaffold (Stage 1): manual inspection gate before the page-by-page
            // work begins. Aggregates (Stage 3): nothing to structurally check, so
            // advance automatically — the end-of-run test pass is the gate there.
            if ($stage["kind"] === "scaffold" && !$opts["yes"]) {
                prompt_checkpoint("Stage $stageNum complete — {$stage['label']}");
            }
            break;
        } // end rebuild loop
    }

    echo "\nOutput    : $totalWritten file(s) written across $stageCount stages, $totalAssets input asset(s)\n";

} else {
    // Legacy one-shot (mysqli-to-pdo). No staged skill exists for this type yet.
    echo "\nCalling $model via {$opts['mode']} ...\n";
    $started = microtime(true);
    [$rawResponse, $usage] = $opts["mode"] === "console"
        ? call_anthropic_api($apiKey, $model, $systemBlocks, $userPromptForRun, $maxToks)
        : call_via_claude_cli($claudeBin, $model, $systemBlocks, $userPromptForRun);
    $elapsed = microtime(true) - $started;
    echo sprintf("  -> %d output chars in %.1fs\n", strlen($rawResponse), $elapsed);
    if ($usage) echo "  -> usage: in={$usage['input_tokens']}, out={$usage['output_tokens']}, cache_read={$usage['cache_read_input_tokens']}, cache_create={$usage['cache_creation_input_tokens']}\n";

    $payload = extract_json_payload($rawResponse);
    if (!$payload || !isset($payload["files"]) || !is_array($payload["files"])) {
        fwrite(STDERR, "error: response had no usable 'files' field.\n");
        file_put_contents("$repoRoot/.last-response.txt", $rawResponse);
        fwrite(STDERR, "       Raw response written to .last-response.txt for inspection.\n");
        exit(1);
    }
    $totalWritten = write_generated_files($outputDir, $payload["files"]);
    $totalAssets  = copy_input_assets($inputDir, $outputDir, $payload["asset_copies"] ?? []);
    echo "Output    : $totalWritten file(s) written, $totalAssets input asset(s)\n";
}

// ============================================================================
// 11. Run tests
// ============================================================================

// Copy features.yaml and page_map.json into the output so the test scripts can
// find them without needing the project to be promoted to references/ first.
if (is_file($featPath)) {
    copy($featPath, "$outputDir/features.yaml");
}
if (is_file($pmPath)) {
    copy($pmPath, "$outputDir/page_map.json");
}

if ($opts["skip-tests"]) { echo "\nSkipping tests (--skip-tests).\n"; exit(0); }

echo "\n--- Running tests/run_tests.php ---\n";
$rtCode = run_php_script("$repoRoot/tests/run_tests.php", [$projectName]);

echo "\n--- Running tests/structural_diff.php ---\n";
$sdCode = run_php_script("$repoRoot/tests/structural_diff.php", [$projectName]);

if ($rtCode !== 0 || $sdCode !== 0) {
    echo "\nTests reported failures (run_tests=$rtCode, structural_diff=$sdCode). Inspect tests/results.json and tests/structural_results.json.\n";
    exit(1);
}

echo "\nDone.\n";
exit(0);
