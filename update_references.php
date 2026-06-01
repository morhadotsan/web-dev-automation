<?php
/**
 * update_references.php — promote a finished project into the reference set and
 * re-synthesize the agent's master knowledge files.
 *
 *   php update_references.php <project-name>
 *   php update_references.php <project-name> --type html-to-php
 *   php update_references.php <project-name> --no-synth          # promote only, skip API re-synthesis
 *   php update_references.php --synth-only                       # re-synthesize from existing references only
 *   php update_references.php <project-name> --mode claude-code  # use the local `claude` CLI for synthesis
 *
 * What it does (per README "The Feedback Loop"):
 *   1. Promote output/<name>/ into references/<task-type>/<name>/:
 *        - after/   ← output/<name>/  (the generated PHP project)
 *        - before/  ← input/<name>/   (the original input)
 *        - features.yaml + page_map.json copied to the reference root
 *   2. Scaffold the per-project docs from templates/docs/ if they are missing
 *      (NOTES.md, SKILL.md, CLAUDE.md). Existing filled-in docs are never clobbered.
 *   3. Regenerate references/INDEX.md from every reference's features.yaml.
 *   4. Re-synthesize the master CLAUDE.md and skills/<task-type>/SKILL.md from all
 *      per-project docs (skipped with --no-synth). Synthesis uses the same backend
 *      selection as create_project.php (--mode console|claude-code, --model).
 *
 * Inputs:
 *   - output/<name>/                    the verified-good generated project
 *   - input/<name>/features.yaml        site: + features: metadata (copied into the reference)
 *   - input/<name>/page_map.json        before <-> after page mapping (copied into the reference)
 *   - templates/docs/*.tmpl             scaffolding templates for NOTES/SKILL
 *   - .env                              ANTHROPIC_API_KEY (console-mode synthesis only)
 *
 * Flags:
 *   --type <html-to-php|mysqli-to-pdo>  force task type (otherwise auto-detected from input/)
 *   --mode <console|claude-code>        synthesis backend (default: console)
 *   --model <model-id>                  override the synthesis model (default: claude-sonnet-4-6)
 *   --no-synth                          promote + scaffold + INDEX only; no API calls
 *   --synth-only                        skip promotion; just regenerate INDEX + synthesize
 *   -y, --yes                           overwrite an existing reference folder without confirming
 *   -h, --help                          show this help
 */

declare(strict_types=1);
require_once __DIR__ . "/root_functions.php";

const UR_DEFAULT_MODEL = "claude-sonnet-4-6";
const UR_DEFAULT_MAXTOK = 16000;
const UR_API_MODELS_URL = "https://api.anthropic.com/v1/models";

// The fixed synthesis instruction for skills/<type>/SKILL.md (verbatim from README
// "Synthesis Strategy"). Kept as a constant so the documented contract and the code
// can't drift apart.
const UR_SKILL_SYNTH_INSTRUCTION =
    "For each schema section, identify the canonical pattern. If two references handle the same thing " .
    "differently, document both and note when to use which. Prefix each pattern with `[Confirmed]` if " .
    "seen in 3+ references, `[Emerging]` if seen in 1-2. Aggregate all manual corrections from NOTES.md " .
    "files into Common Mistakes. Drop the \"Project-specific extras\" section — those don't synthesize.";

const UR_TASK_TYPES = ["html-to-php", "mysqli-to-pdo"];

// ============================================================================
// 1. Args + environment
// ============================================================================

$opts = ur_parse_args($argv);
$repoRoot = __DIR__;
load_dotenv("$repoRoot/.env");

if ($opts["help"]) { ur_print_usage(); exit(0); }
if (!$opts["synth-only"] && !$opts["projectName"]) {
    fwrite(STDERR, "error: project name required (or pass --synth-only to re-synthesize from existing references).\n");
    ur_print_usage();
    exit(2);
}

$model   = $opts["model"] ?? (getenv("ANTHROPIC_MODEL") ?: UR_DEFAULT_MODEL);
$maxToks = (int)(getenv("ANTHROPIC_MAX_TOKENS") ?: UR_DEFAULT_MAXTOK);

// ============================================================================
// 2. Promote (unless --synth-only)
// ============================================================================

$promotedType = null;

if (!$opts["synth-only"]) {
    $projectName = $opts["projectName"];
    $inputDir    = "$repoRoot/input/$projectName";
    $outputDir   = "$repoRoot/output/$projectName";

    if (!is_dir($outputDir)) {
        fwrite(STDERR, "error: output/$projectName/ not found. Generate it with create_project.php first.\n");
        exit(2);
    }
    if (count(array_diff(scandir($outputDir) ?: [], [".", ".."])) === 0) {
        fwrite(STDERR, "error: output/$projectName/ is empty — nothing to promote.\n");
        exit(2);
    }

    // Task type: explicit override, else detect from the input project.
    if ($opts["type"]) {
        $taskType = $opts["type"];
    } elseif (is_dir($inputDir)) {
        $taskType = detect_task_type($inputDir, null);
    } else {
        fwrite(STDERR, "error: input/$projectName/ not found and no --type given — cannot determine task type.\n");
        exit(2);
    }
    $promotedType = $taskType;

    $destDir = "$repoRoot/references/$taskType/$projectName";
    if (is_dir($destDir)) {
        if (!$opts["yes"]) {
            echo "Reference references/$taskType/$projectName/ already exists. Overwrite? [y/N]: ";
            $ans = strtolower(trim((string)fgets(STDIN)));
            if ($ans !== "y" && $ans !== "yes") { echo "Aborted.\n"; exit(0); }
        }
        // Preserve any hand-written per-project docs across a re-promotion.
        $preserve = [];
        foreach (["NOTES.md", "SKILL.md", "CLAUDE.md"] as $doc) {
            if (is_file("$destDir/$doc")) $preserve[$doc] = file_get_contents("$destDir/$doc");
        }
        rrmdir($destDir);
        @mkdir($destDir, 0775, true);
        foreach ($preserve as $doc => $body) file_put_contents("$destDir/$doc", $body);
    } else {
        @mkdir($destDir, 0775, true);
    }

    echo "Promoting $projectName ($taskType)\n";

    // after/  ← output/<name>/ ; before/ ← input/<name>/. The two metadata files
    // live at the reference root, not inside after/ or before/.
    $skip = ["features.yaml", "page_map.json"];
    $afterCount = ur_rcopy($outputDir, "$destDir/after", $skip);
    echo "  after/  : $afterCount file(s) from output/$projectName/\n";

    if (is_dir($inputDir)) {
        $beforeCount = ur_rcopy($inputDir, "$destDir/before", $skip);
        echo "  before/ : $beforeCount file(s) from input/$projectName/\n";
    } else {
        echo "  before/ : skipped (input/$projectName/ not present)\n";
    }

    // features.yaml + page_map.json: prefer the input copy, fall back to the one
    // create_project.php dropped into output/.
    foreach (["features.yaml", "page_map.json"] as $meta) {
        $src = is_file("$inputDir/$meta") ? "$inputDir/$meta"
             : (is_file("$outputDir/$meta") ? "$outputDir/$meta" : null);
        if ($src) { copy($src, "$destDir/$meta"); echo "  $meta : copied\n"; }
        else      { echo "  $meta : not found (skipped)\n"; }
    }

    // Scaffold per-project docs from templates (non-destructive).
    ur_scaffold_docs($repoRoot, $destDir, $projectName, $taskType);
}

// ============================================================================
// 3. Regenerate references/INDEX.md (always, deterministic — no API)
// ============================================================================

ur_regenerate_index($repoRoot);
echo "Regenerated references/INDEX.md\n";

if ($opts["no-synth"]) {
    echo "\nDone (promotion + INDEX only; --no-synth set, master files not re-synthesized).\n";
    exit(0);
}

// ============================================================================
// 4. Re-synthesize master CLAUDE.md + skills/<type>/SKILL.md (API)
// ============================================================================

// Backend selection mirrors create_project.php.
$apiKey    = null;
$claudeBin = null;
if ($opts["mode"] === "console") {
    $apiKey = getenv("ANTHROPIC_API_KEY") ?: "";
    if ($apiKey === "") { fwrite(STDERR, "error: ANTHROPIC_API_KEY not set (.env). Use --mode claude-code or --no-synth.\n"); exit(2); }
    [$ok, $msg] = validate_console_api_key($apiKey);
    if (!$ok) { fwrite(STDERR, "error: API key check failed: $msg\n"); exit(1); }
    echo "API key: $msg\n";
} else {
    $claudeBin = locate_claude_cli();
    if (!$claudeBin) { fwrite(STDERR, "error: --mode claude-code but the `claude` CLI was not found on PATH.\n"); exit(1); }
    echo "Using claude CLI: $claudeBin\n";
}

// Which task types have at least one reference?
$typesWithRefs = [];
foreach (UR_TASK_TYPES as $t) {
    if (count(load_references($repoRoot, $t)) > 0) $typesWithRefs[] = $t;
}
if (empty($typesWithRefs)) {
    fwrite(STDERR, "warning: no references on file — nothing to synthesize.\n");
    exit(0);
}

// 4a. Per-task-type SKILL.md
foreach ($typesWithRefs as $taskType) {
    echo "\n--- Synthesizing skills/$taskType/SKILL.md ---\n";
    $refs   = load_references($repoRoot, $taskType);
    $prompt = ur_build_skill_synth_prompt($repoRoot, $taskType, $refs);
    $text   = ur_call_model($opts["mode"], $apiKey, $claudeBin, $model, $maxToks, $prompt);
    $skillPath = "$repoRoot/skills/$taskType/SKILL.md";
    if (ur_looks_like_markdown_doc($text, "Skill")) {
        @mkdir(dirname($skillPath), 0775, true);
        file_put_contents($skillPath, rtrim($text) . "\n");
        echo "  wrote $skillPath (" . strlen($text) . " chars)\n";
    } else {
        $fallback = "$repoRoot/.last-synthesis-skill-$taskType.md";
        file_put_contents($fallback, $text);
        fwrite(STDERR, "  warning: synthesis output didn't look like a SKILL.md; wrote $fallback instead (not overwriting).\n");
    }
}

// 4b. Master CLAUDE.md (synthesized from per-project CLAUDE.md across all types)
echo "\n--- Synthesizing master CLAUDE.md ---\n";
$prompt = ur_build_master_synth_prompt($repoRoot);
$text   = ur_call_model($opts["mode"], $apiKey, $claudeBin, $model, $maxToks, $prompt);
$masterPath = "$repoRoot/CLAUDE.md";
if (ur_looks_like_markdown_doc($text, "Master Project Structure")) {
    file_put_contents($masterPath, rtrim($text) . "\n");
    echo "  wrote $masterPath (" . strlen($text) . " chars)\n";
} else {
    $fallback = "$repoRoot/.last-synthesis-claude.md";
    file_put_contents($fallback, $text);
    fwrite(STDERR, "  warning: synthesis output didn't look like the master CLAUDE.md; wrote $fallback instead (not overwriting).\n");
}

echo "\nDone.\n";
exit(0);

// ============================================================================
// Functions
// ============================================================================

function ur_parse_args(array $argv): array {
    $opts = [
        "projectName" => null, "type" => null, "model" => null, "mode" => "console",
        "yes" => false, "no-synth" => false, "synth-only" => false, "help" => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if     ($a === "-h" || $a === "--help")        { $opts["help"]       = true; }
        elseif ($a === "-y" || $a === "--yes")         { $opts["yes"]        = true; }
        elseif ($a === "--no-synth")                   { $opts["no-synth"]   = true; }
        elseif ($a === "--synth-only")                 { $opts["synth-only"] = true; }
        elseif ($a === "--type"  && isset($argv[$i+1])) { $opts["type"]  = $argv[++$i]; }
        elseif ($a === "--model" && isset($argv[$i+1])) { $opts["model"] = $argv[++$i]; }
        elseif ($a === "--mode"  && isset($argv[$i+1])) { $opts["mode"]  = $argv[++$i]; }
        elseif ($a[0] !== "-")                          { $opts["projectName"] = $a; }
        else { fwrite(STDERR, "warning: unknown flag '$a'\n"); }
    }
    if ($opts["type"] && !in_array($opts["type"], UR_TASK_TYPES, true)) {
        fwrite(STDERR, "error: --type must be one of: " . implode(", ", UR_TASK_TYPES) . "\n"); exit(2);
    }
    if (!in_array($opts["mode"], ["console", "claude-code"], true)) {
        fwrite(STDERR, "error: --mode must be 'console' or 'claude-code'\n"); exit(2);
    }
    return $opts;
}

function ur_print_usage(): void {
    echo <<<USAGE
usage: php update_references.php <project-name> [flags]
       php update_references.php --synth-only [flags]

Promotes output/<project-name>/ into references/<task-type>/ and re-synthesizes
the master CLAUDE.md and skills/<task-type>/SKILL.md from all references.

flags:
  --type <html-to-php|mysqli-to-pdo>   force task type (otherwise auto-detected)
  --mode <console|claude-code>         synthesis backend (default: console)
  --model <model-id>                   override synthesis model (default: claude-sonnet-4-6)
  --no-synth                           promote + scaffold + INDEX only (no API calls)
  --synth-only                         skip promotion; only regenerate INDEX + synthesize
  -y, --yes                            overwrite an existing reference folder without asking
  -h, --help                           show this help

required env (console-mode synthesis only):
  ANTHROPIC_API_KEY                    your Claude Console key

USAGE;
}

// Recursively copy $src into $dest, skipping any entry whose basename is in
// $skipNames. Returns the number of files copied.
function ur_rcopy(string $src, string $dest, array $skipNames = []): int {
    $count = 0;
    @mkdir($dest, 0775, true);
    foreach (scandir($src) ?: [] as $entry) {
        if ($entry === "." || $entry === "..") continue;
        if (in_array($entry, $skipNames, true)) continue;
        $from = "$src/$entry";
        $to   = "$dest/$entry";
        if (is_dir($from)) {
            $count += ur_rcopy($from, $to, $skipNames);
        } else {
            @mkdir(dirname($to), 0775, true);
            if (copy($from, $to)) $count++;
        }
    }
    return $count;
}

// Scaffold NOTES.md, SKILL.md and CLAUDE.md for a freshly promoted project from
// templates/docs/. Never overwrites a doc that already exists (so hand-written
// content survives re-promotion).
function ur_scaffold_docs(string $repoRoot, string $destDir, string $projectName, string $taskType): void {
    $today = date("Y-m-d");

    // NOTES.md — straight from the template skeleton.
    $notesPath = "$destDir/NOTES.md";
    if (!is_file($notesPath)) {
        $tmpl = @file_get_contents("$repoRoot/templates/docs/notes.md.tmpl");
        file_put_contents($notesPath, $tmpl !== false ? $tmpl : "## What this reference adds\n\n## Edge cases handled\n\n## Manual corrections applied\n");
        echo "  scaffolded NOTES.md (fill this in)\n";
    }

    // SKILL.md — template schema with the header placeholders filled for a single project.
    $skillPath = "$destDir/SKILL.md";
    if (!is_file($skillPath)) {
        $tmpl = (string)@file_get_contents("$repoRoot/templates/docs/skill.md.tmpl");
        $tmpl = str_replace("[Task Type]", $taskType, $tmpl);
        $tmpl = str_replace("{{date}}", $today, $tmpl);
        $tmpl = str_replace("{{count}}", "1 ($projectName)", $tmpl);
        file_put_contents($skillPath, $tmpl);
        echo "  scaffolded SKILL.md (fill in each section for this project)\n";
    }

    // CLAUDE.md — no template exists; emit a header + an auto-built file tree of
    // after/ plus section placeholders for the prose the human/agent fills in.
    $claudePath = "$destDir/CLAUDE.md";
    if (!is_file($claudePath)) {
        $tree = ur_dir_tree("$destDir/after", "after");
        $body  = "# $projectName — Project Structure\n\n";
        $body .= "_Promoted: $today · task type: {$taskType}_\n\n";
        $body .= "<!-- One-paragraph description of what this project is and how it was converted. -->\n\n";
        $body .= "## Top-level layout\n\n```\n$tree```\n\n";
        $body .= "## Globals & conventions\n";
        $body .= "<!-- Connection handle, tenant key, URL globals, helper signatures, meta keys, routing — the project-specific facts the master CLAUDE.md synthesis should pick up. -->\n";
        file_put_contents($claudePath, $body);
        echo "  scaffolded CLAUDE.md (auto-built file tree; fill in the prose)\n";
    }
}

// Render a simple ASCII tree of $dir (one level of nesting shown, like the
// per-project CLAUDE.md layouts). $label is the displayed root name.
function ur_dir_tree(string $dir, string $label, string $prefix = ""): string {
    if (!is_dir($dir)) return "$label/\n";
    $out = $prefix === "" ? "$label/\n" : "";
    $entries = array_values(array_diff(scandir($dir) ?: [], [".", ".."]));
    sort($entries);
    // Directories first, then files — easier to scan.
    usort($entries, function ($a, $b) use ($dir) {
        $ad = is_dir("$dir/$a") ? 0 : 1;
        $bd = is_dir("$dir/$b") ? 0 : 1;
        return $ad === $bd ? strcmp($a, $b) : $ad - $bd;
    });
    $n = count($entries);
    foreach ($entries as $i => $entry) {
        $isLast = ($i === $n - 1);
        $branch = $isLast ? "└── " : "├── ";
        $isDir  = is_dir("$dir/$entry");
        $out .= $prefix . $branch . $entry . ($isDir ? "/" : "") . "\n";
        if ($isDir) {
            $childPrefix = $prefix . ($isLast ? "    " : "│   ");
            $out .= ur_dir_tree("$dir/$entry", $entry, $childPrefix);
        }
    }
    return $out;
}

// Regenerate references/INDEX.md from every reference's features.yaml.
function ur_regenerate_index(string $repoRoot): void {
    $out  = "# References Index\n\n";
    $out .= "_Last updated: " . date("Y-m-d") . "_\n\n";
    $out .= "_This file is auto-managed by `update_references.php`. Do not edit by hand — changes will be overwritten next time a reference is added._\n";

    foreach (UR_TASK_TYPES as $taskType) {
        $out .= "\n## $taskType\n\n";
        $refs = load_references($repoRoot, $taskType);
        usort($refs, fn($a, $b) => strcmp($a["name"], $b["name"]));
        if (empty($refs)) {
            $out .= "_No references yet. Add one by dropping a completed " .
                    ($taskType === "html-to-php" ? "HTML → PHP" : "mysqli → PDO") .
                    " project in `output/` and running `update_references.php`._\n";
            continue;
        }
        foreach ($refs as $r) {
            $site     = $r["features"]["site"]     ?? [];
            $features = $r["features"]["features"] ?? [];
            $name     = $r["name"];
            $out .= "- **$name** — `references/$taskType/$name/`\n";

            $siteName = trim((string)($site["name"] ?? ""));
            $siteUrl  = trim((string)($site["url"]  ?? ""));
            if ($siteName !== "" || $siteUrl !== "") {
                $out .= "  - Site: " . trim("$siteName" . ($siteUrl !== "" ? " · $siteUrl" : "")) . "\n";
            }

            $notable = ur_notable_line($r);
            if ($notable !== "") $out .= "  - Notable: $notable\n";

            $on = array_keys(array_filter($features, fn($v) => $v === true));
            if (!empty($on)) {
                $out .= "  - Features: " . implode(", ", array_map(fn($f) => "`$f`", $on)) . "\n";
            }
        }
    }
    file_put_contents("$repoRoot/references/INDEX.md", $out);
}

// Pick a one-line "Notable" blurb for INDEX.md: the site description if present,
// else the first non-empty bullet under "What this reference adds" in NOTES.md.
function ur_notable_line(array $ref): string {
    $desc = trim((string)($ref["features"]["site"]["description"] ?? ""));
    if ($desc !== "") return $desc;

    $notes = (string)($ref["NOTES.md"] ?? "");
    if ($notes !== "" && preg_match('/##\s*What this reference adds\s*\n(.*?)(?:\n##|\z)/s', $notes, $m)) {
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            $line = trim($line);
            if ($line === "" || str_starts_with($line, "<!--")) continue;
            return preg_replace('/^[-*]\s*/', '', $line);
        }
    }
    return "";
}

// Build the synthesis prompt for skills/<type>/SKILL.md.
function ur_build_skill_synth_prompt(string $repoRoot, string $taskType, array $refs): string {
    $names = array_map(fn($r) => $r["name"], $refs);
    $count = count($refs);
    $today = date("Y-m-d");
    $schema = (string)@file_get_contents("$repoRoot/templates/docs/skill.md.tmpl");

    $out  = "You are maintaining the synthesized master SKILL.md for the **$taskType** task type of a PHP code-generation agent.\n\n";
    $out .= "Re-synthesize it from the $count per-project SKILL.md file(s) and their NOTES.md corrections below.\n\n";
    $out .= "INSTRUCTION:\n" . UR_SKILL_SYNTH_INSTRUCTION . "\n\n";
    $out .= "OUTPUT FORMAT:\n";
    $out .= "- Return the COMPLETE SKILL.md markdown and nothing else (no commentary, no code fences around the whole file).\n";
    $out .= "- First line: `# " . ($taskType === "html-to-php" ? "HTML → PHP" : "mysqli → PDO") . " Skill`\n";
    $out .= "- Then: `_Last synthesized: $today_` and `_References used: $count (" . implode(", ", $names) . ")_`\n";
    $out .= "- Follow the fixed schema below (same section order). Prefix every pattern with `[Confirmed]` (3+ refs) or `[Emerging]` (1-2 refs) and cite the reference name(s).\n";
    $out .= "- Omit the per-project `## Project-specific extras` section entirely.\n\n";
    $out .= "=== SCHEMA (templates/docs/skill.md.tmpl) ===\n$schema\n\n";

    foreach ($refs as $r) {
        $out .= "=== reference: {$r['name']} — SKILL.md ===\n" . (string)($r["SKILL.md"] ?? "(missing)") . "\n\n";
        $corr = ur_extract_section((string)($r["NOTES.md"] ?? ""), "Manual corrections applied");
        if (trim($corr) !== "") {
            $out .= "=== reference: {$r['name']} — NOTES.md › Manual corrections applied ===\n$corr\n\n";
        }
    }
    return $out;
}

// Build the synthesis prompt for the master CLAUDE.md.
function ur_build_master_synth_prompt(string $repoRoot): string {
    $today  = date("Y-m-d");
    $counts = [];
    $perType = [];
    foreach (UR_TASK_TYPES as $t) {
        $refs = load_references($repoRoot, $t);
        $names = array_map(fn($r) => $r["name"], $refs);
        $counts[$t] = count($refs);
        $perType[$t] = $refs;
    }
    // "1 html-to-php (ny-mag-ag) · 0 mysqli-to-pdo"
    $segs = [];
    foreach (UR_TASK_TYPES as $t) {
        $names = array_map(fn($r) => $r["name"], $perType[$t]);
        $segs[] = $counts[$t] . " $t" . ($names ? " (" . implode(", ", $names) . ")" : "");
    }
    $refsUsedLine = implode(" · ", $segs);

    $current = (string)@file_get_contents("$repoRoot/CLAUDE.md");

    $out  = "You are maintaining the master CLAUDE.md ('Master Project Structure') for a PHP code-generation agent.\n";
    $out .= "It is the synthesized blueprint of the typical project layout that emerges across all completed references, grouped by task type.\n\n";
    $out .= "Re-synthesize it from the per-project CLAUDE.md files below.\n\n";
    $out .= "INSTRUCTION:\n";
    $out .= "- For each task type, describe WHAT FILES EXIST WHERE (folder tree + the role of each file/folder) and the project-wide conventions (globals, routing, tables) that recur.\n";
    $out .= "- Prefix each pattern with `[Confirmed]` if observed in 3+ references, `[Emerging]` if in 1-2. Cite the reference name(s).\n";
    $out .= "- A task type with no references gets a short placeholder paragraph inviting promotion via update_references.php.\n";
    $out .= "- Keep methodology (the 'why'/recipes) out — that lives in skills/<type>/SKILL.md. This file is about file layout.\n\n";
    $out .= "OUTPUT FORMAT:\n";
    $out .= "- Return the COMPLETE CLAUDE.md markdown and nothing else.\n";
    $out .= "- First line: `# Master Project Structure`\n";
    $out .= "- Then exactly: `_Last synthesized: $today_` and `_References used: $refsUsedLine_`\n";
    $out .= "- One top-level `## <task-type> — Project Layout` section per task type, in this order: " . implode(", ", UR_TASK_TYPES) . ".\n\n";
    $out .= "=== CURRENT master CLAUDE.md (format reference — refresh its content, keep the shape) ===\n$current\n\n";

    foreach (UR_TASK_TYPES as $t) {
        foreach ($perType[$t] as $r) {
            $out .= "=== reference: $t/{$r['name']} — CLAUDE.md ===\n" . (string)($r["CLAUDE.md"] ?? "(missing)") . "\n\n";
        }
    }
    return $out;
}

// Extract the body of a "## <heading>" section from a markdown doc (text up to
// the next "## " heading or end-of-file). Strips HTML comment lines.
function ur_extract_section(string $md, string $heading): string {
    $pat = '/##\s*' . preg_quote($heading, '/') . '\s*\n(.*?)(?:\n##\s|\z)/s';
    if (!preg_match($pat, $md, $m)) return "";
    $body = preg_replace('/<!--.*?-->/s', '', $m[1]);
    return trim((string)$body);
}

// Call the configured backend with a single user prompt; return the text reply.
function ur_call_model(string $mode, ?string $apiKey, ?string $claudeBin, string $model, int $maxToks, string $prompt): string {
    $systemBlocks = [[
        "type"          => "text",
        "text"          => "You synthesize and maintain documentation files for a PHP code-generation agent. You return only the requested file's full contents, with no surrounding prose or fences.",
        "cache_control" => ["type" => "ephemeral"],
    ]];
    $started = microtime(true);
    [$text, $usage] = ($mode === "console")
        ? call_anthropic_api($apiKey, $model, $systemBlocks, $prompt, $maxToks)
        : call_via_claude_cli($claudeBin, $model, $systemBlocks, $prompt);
    $elapsed = microtime(true) - $started;
    echo sprintf("  -> %d chars in %.1fs\n", strlen($text), $elapsed);
    if ($usage) echo "  -> usage: in={$usage['input_tokens']}, out={$usage['output_tokens']}\n";
    return ur_strip_code_fence($text);
}

// Some models wrap a whole-file answer in ```markdown … ``` despite instructions.
// Strip a single outer fence if the entire reply is fenced.
function ur_strip_code_fence(string $text): string {
    $t = trim($text);
    if (preg_match('/^```[a-zA-Z]*\s*\n(.*)\n```$/s', $t, $m)) return trim($m[1]);
    return $t;
}

// Sanity-check that a synthesis reply is the expected markdown doc (starts with a
// top-level heading and mentions the marker) before we overwrite a real file.
function ur_looks_like_markdown_doc(string $text, string $marker): bool {
    $t = ltrim($text);
    if ($t === "" || $t[0] !== "#") return false;
    return stripos($t, $marker) !== false;
}
