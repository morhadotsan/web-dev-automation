<?php


// ============================================================================
// Argument parsing + usage
// ============================================================================

function parse_args(array $argv): array {
    $opts = [
        "projectName" => null, "type" => null, "model" => null, "mode" => "console",
        "yes" => false, "skip-tests" => false, "dry-run" => false, "help" => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if     ($a === "-h" || $a === "--help")     { $opts["help"] = true; }
        elseif ($a === "-y" || $a === "--yes")      { $opts["yes"]  = true; }
        elseif ($a === "--skip-tests")              { $opts["skip-tests"] = true; }
        elseif ($a === "--dry-run")                 { $opts["dry-run"]    = true; }
        elseif ($a === "--type"  && isset($argv[$i+1])) { $opts["type"]  = $argv[++$i]; }
        elseif ($a === "--model" && isset($argv[$i+1])) { $opts["model"] = $argv[++$i]; }
        elseif ($a === "--mode"  && isset($argv[$i+1])) { $opts["mode"]  = $argv[++$i]; }
        elseif ($a[0] !== "-")                      { $opts["projectName"] = $a; }
        else { fwrite(STDERR, "warning: unknown flag '$a'\n"); }
    }
    if ($opts["type"] && !in_array($opts["type"], ["html-to-php", "mysqli-to-pdo"], true)) {
        fwrite(STDERR, "error: --type must be 'html-to-php' or 'mysqli-to-pdo'\n"); exit(2);
    }
    if (!in_array($opts["mode"], ["console", "claude-code"], true)) {
        fwrite(STDERR, "error: --mode must be 'console' or 'claude-code'\n"); exit(2);
    }
    return $opts;
}

function print_usage(): void {
    echo <<<USAGE
usage: php create_project.php <project-name> [flags]

flags:
  --type <html-to-php|mysqli-to-pdo>   force task type (otherwise auto-detected)
  --mode <console|claude-code>         which backend to use (default: console)
                                         console     -> Anthropic API, billed to ANTHROPIC_API_KEY
                                         claude-code -> local `claude` CLI, billed to your CC subscription
  --model <model-id>                   override the model (default: claude-sonnet-4-6)
  --skip-tests                         skip run_tests.php and structural_diff.php
  --dry-run                            build the prompt and write .last-prompt.json, no API call
  -y, --yes                            skip the confirmation prompt (non-interactive)
  -h, --help                           show this help

required env (loaded from .env):
  ANTHROPIC_API_KEY                    your Claude Console key (console mode only)

required input files:
  input/<name>/features.yaml           site: + features: blocks
  input/<name>/page_map.json           before <-> after page mapping (template at tests/fixtures/page_map.example.json)
USAGE;
    echo "\n";
}

// ============================================================================
// Validation
// ============================================================================

function validate_site_block(array $features): void {
    if (!isset($features["site"]) || !is_array($features["site"])) {
        fwrite(STDERR, "error: features.yaml is missing a 'site:' block.\n"); exit(2);
    }
    $required = ["url", "name", "website_slug", "description", "main_categories"];
    $missing = [];
    foreach ($required as $key) {
        $v = $features["site"][$key] ?? null;
        $empty = ($v === null) || ($v === "") || (is_array($v) && count($v) === 0);
        if ($empty) $missing[] = $key;
    }
    if ($missing) {
        fwrite(STDERR, "error: features.yaml site block has empty fields: " . implode(", ", $missing) . "\n");
        fwrite(STDERR, "       Every site.* value must be filled in before the agent can run.\n");
        exit(2);
    }
}

function validate_page_map_coverage(string $inputDir, array $pageMap): void {
    $htmlFiles = array_map("basename", glob("$inputDir/*.html") ?: []);
    sort($htmlFiles);
    $mappedBefores = [];
    foreach ($pageMap["pairs"] as $pair) {
        if (isset($pair["before"])) $mappedBefores[] = $pair["before"];
    }
    $missing = array_diff($htmlFiles, $mappedBefores);
    if (!empty($missing)) {
        fwrite(STDERR, "error: page_map.json does not map every input HTML.\n");
        fwrite(STDERR, "       Unmapped: " . implode(", ", $missing) . "\n");
        fwrite(STDERR, "       Add a pair for each, then re-run.\n");
        exit(2);
    }
}

function detect_task_type(string $inputDir, ?string $override): string {
    if ($override) return $override;
    $htmlCount = count(glob("$inputDir/*.html") ?: []);
    $phpFiles  = glob("$inputDir/*.php") ?: [];
    $mysqliHit = false;
    foreach ($phpFiles as $f) {
        $s = @file_get_contents($f) ?: "";
        if (strpos($s, "mysqli_query") !== false || strpos($s, "mysqli_connect") !== false || strpos($s, "new mysqli(") !== false) {
            $mysqliHit = true; break;
        }
    }
    if ($htmlCount > 0 && !$mysqliHit) return "html-to-php";
    if ($mysqliHit && $htmlCount === 0) return "mysqli-to-pdo";
    if ($htmlCount > 0 && $mysqliHit) {
        fwrite(STDERR, "error: input has both HTML and mysqli PHP. Pass --type to disambiguate.\n"); exit(2);
    }
    fwrite(STDERR, "error: could not detect task type from input. Pass --type to set it explicitly.\n"); exit(2);
}

function validate_console_api_key(string $apiKey): array {
    $ch = curl_init(API_MODELS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["x-api-key: $apiKey", "anthropic-version: " . API_VERSION],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [false, "curl failed: $err"];
    if ($status === 200) {
        $j = json_decode($raw, true);
        $modelCount = is_array($j["data"] ?? null) ? count($j["data"]) : 0;
        return [true, "key valid, $modelCount model(s) accessible"];
    }
    if ($status === 401) return [false, "HTTP 401: invalid or revoked key"];
    if ($status === 403) return [false, "HTTP 403: key has no access to the API"];
    return [false, "HTTP $status: $raw"];
}

function locate_claude_cli(): ?string {
    if (PHP_OS_FAMILY === "Windows") {
        // `where claude` may return multiple shims (bash, .cmd, .ps1). proc_open
        // can only directly execute things Windows resolves via PATHEXT, so prefer
        // .cmd/.exe/.bat. The extensionless and .ps1 variants are unusable here.
        $out = trim((string)@shell_exec("where claude 2>nul"));
        if ($out !== "") {
            $lines = array_values(array_filter(array_map("trim", preg_split('/\r?\n/', $out))));
            foreach ([".cmd", ".exe", ".bat"] as $ext) {
                foreach ($lines as $l) {
                    if (str_ends_with(strtolower($l), $ext) && is_file($l)) return $l;
                }
            }
        }
        foreach ([
            getenv("APPDATA") . "\\npm\\claude.cmd",
            getenv("USERPROFILE") . "\\AppData\\Roaming\\npm\\claude.cmd",
            getenv("LOCALAPPDATA") . "\\npm\\claude.cmd",
        ] as $c) {
            if ($c && is_file($c)) return $c;
        }
        return null;
    }
    // POSIX
    $out = trim((string)@shell_exec("command -v claude 2>/dev/null"));
    if ($out !== "" && is_file($out)) return $out;
    foreach ([
        "/usr/local/bin/claude",
        getenv("HOME") . "/.local/bin/claude",
        getenv("HOME") . "/.npm-global/bin/claude",
    ] as $c) {
        if ($c && is_file($c)) return $c;
    }
    return null;
}

// ============================================================================
// Loading
// ============================================================================

function load_references(string $repoRoot, string $taskType): array {
    $base = "$repoRoot/references/$taskType";
    if (!is_dir($base)) return [];
    $out = [];
    foreach (scandir($base) ?: [] as $name) {
        if ($name === "." || $name === ".." || !is_dir("$base/$name")) continue;
        $ref = ["name" => $name, "path" => "$base/$name"];
        foreach (["CLAUDE.md","SKILL.md","NOTES.md","features.yaml","page_map.json"] as $doc) {
            $p = "$base/$name/$doc";
            if (is_file($p)) $ref[$doc] = file_get_contents($p);
        }
        if (isset($ref["features.yaml"])) $ref["features"] = parse_yaml_minimal($ref["features.yaml"]);
        $out[] = $ref;
    }
    return $out;
}

function rank_references(array $refs, array $inputFeatures, int $topK): array {
    $inputFlags = array_keys(array_filter(($inputFeatures["features"] ?? []), fn($v) => $v === true));
    $inputCats  = array_map("strval", ($inputFeatures["site"]["main_categories"] ?? []));
    $scored = [];
    foreach ($refs as $r) {
        $refFlags = array_keys(array_filter(($r["features"]["features"] ?? []), fn($v) => $v === true));
        $refCats  = array_map("strval", ($r["features"]["site"]["main_categories"] ?? []));
        $overlap  = count(array_intersect($inputFlags, $refFlags)) + count(array_intersect($inputCats, $refCats));
        $scored[] = ["ref" => $r, "overlap" => $overlap, "name" => $r["name"]];
    }
    usort($scored, fn($a, $b) => $b["overlap"] <=> $a["overlap"]);
    return array_slice($scored, 0, $topK);
}

function load_snippets(string $repoRoot): array {
    $out = [];
    foreach (glob("$repoRoot/templates/snippets/*.tmpl") ?: [] as $f) {
        $out[basename($f)] = file_get_contents($f);
    }
    return $out;
}

// Compact an HTML file for inlining into the prompt. Preserves structure the
// agent reasons about (tags, attributes, classes, inline text) and discards
// what it does not (indentation, comments, inline script/style bodies).
function minify_html_for_prompt(string $html): string {
    // 1. Pull <pre>/<textarea> aside — their whitespace is significant.
    $preserved = [];
    $stash = function(string $original) use (&$preserved): string {
        $key = "\x01PRESERVED" . count($preserved) . "\x01";
        $preserved[$key] = $original;
        return $key;
    };
    $html = preg_replace_callback(
        '/<(pre|textarea)\b[^>]*>[\s\S]*?<\/\1>/i',
        fn($m) => $stash($m[0]),
        $html
    );

    // 2. Drop inline <script> bodies; keep <script src="..."> external refs.
    $html = preg_replace_callback(
        '/<script\b([^>]*)>([\s\S]*?)<\/script>/i',
        function ($m) {
            $attrs = $m[1];
            if (preg_match('/\bsrc\s*=/i', $attrs)) return "<script$attrs></script>";
            return trim($m[2]) === "" ? "<script$attrs></script>" : "<script$attrs>/* inline body omitted */</script>";
        },
        $html
    );

    // 3. Drop inline <style> bodies.
    $html = preg_replace_callback(
        '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
        fn($m) => trim($m[2]) === "" ? "<style{$m[1]}></style>" : "<style{$m[1]}>/* inline body omitted */</style>",
        $html
    );

    // 4. Strip HTML comments, preserving IE conditional comments
    //    (<!--[if ...]>... and the closing <![endif]--> half).
    $html = preg_replace_callback(
        '/<!--([\s\S]*?)-->/',
        function ($m) {
            $c = $m[1];
            if (preg_match('/^\s*\[if\b/', $c)) return $m[0];
            if (preg_match('/\[endif\]\s*$/', $c)) return $m[0];
            return "";
        },
        $html
    );

    // 5. Collapse whitespace.
    $html = preg_replace('/[ \t]+/', " ", $html);
    $html = preg_replace('/\s*\n\s*/', "\n", $html);
    $html = preg_replace('/\n{2,}/', "\n", $html);
    $html = trim($html);

    // 6. Restore preserved blocks.
    foreach ($preserved as $key => $original) $html = str_replace($key, $original, $html);
    return $html;
}

function collect_input_tree(string $inputDir): array {
    $text = []; $binary = [];
    // CSS/JS are copied verbatim via asset_copies — the agent never rewrites
    // them, so inlining their bytes just inflates input tokens (vendor bundles
    // like jquery.min.js / plugins.js can be hundreds of KB on their own).
    $copyAsAsset = ["css", "js"];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $info) {
        if (!$info->isFile()) continue;
        $rel = ltrim(str_replace("\\", "/", substr($info->getPathname(), strlen($inputDir))), "/");
        if ($rel === "features.yaml" || $rel === "page_map.json") continue;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, TEXT_EXTS, true) && !in_array($ext, $copyAsAsset, true) && $info->getSize() < 512 * 1024) {
            $content = file_get_contents($info->getPathname());
            if ($ext === "html" || $ext === "htm") $content = minify_html_for_prompt($content);
            $text[$rel] = $content;
        } else {
            $binary[$rel] = $info->getSize();
        }
    }
    ksort($text); ksort($binary);
    return ["text" => $text, "binary" => $binary];
}

// ============================================================================
// Prompt building
// ============================================================================

function build_system_prompt(string $taskType, string $skill, array $rankedRefs, array $snippets, string $projectName = ""): array {
    $localUrl = $projectName !== "" ? "http://localhost/web-dev-auto-v2/output/$projectName/" : "http://localhost/web-dev-auto-v2/output/<project-name>/";
    $blocks = [];
    $blocks[] = ["type" => "text", "text" =>
        "You are the web-dev-automation agent. Convert the given input project into a complete, working PHP output project by following the methodology below precisely.\n\n" .
        "Task type for this run: $taskType\n\n" .
        "Output contract — respond with ONE valid JSON object and nothing else. No prose, no markdown fences. Schema:\n" .
        "{\n" .
        "  \"features\":     { \"<flag>\": true|false, ... },        // your detected feature flags (mirror keys from input features.yaml)\n" .
        "  \"files\":        { \"<output relative path>\": \"<file contents>\", ... },\n" .
        "  \"asset_copies\": [ { \"from\": \"<input relative path>\", \"to\": \"<output relative path>\" }, ... ]\n" .
        "}\n\n" .
        "Rules:\n" .
        "- 'files' contains ONLY text files you generate (PHP, CSS, JS, MD, YAML, SQL). NO binaries.\n" .
        "- 'asset_copies' tells the orchestrator to copy binary input assets (images, fonts, .webp, etc.) into the output. Use for every binary the project needs.\n" .
        "- Paths use forward slashes, relative to the project root. Never absolute, never with '..'.\n" .
        "- NEVER emit empty alt attributes on <img> tags. Always use a meaningful value: the blog title, author name, or a descriptive string from the DB row.\n" .
        "- Blog and article images are cross-hosted. ALWAYS load them with the lazy pattern:\n" .
        "    class=\"lzImg2\" src=\"<?= \$reverbURL.\"reverb_images/blog_images/lazy-img.png\"; ?>\" data-src=\"<?= \$reverbURL.\"reverb_images/blog_images/\".\$img; ?>\"\n" .
        "  NEVER use \$wiscoy_url or assets/ paths for blog content images — they are not on this server.\n" .
        "- The localhost branch of includes/database.php MUST use \$db_name = \"web-dev-automation\" verbatim (canonical test DB).\n" .
        "- The localhost branch of includes/database.php MUST set \$wiscoy_url = \"$localUrl\" — use this project's folder name, NEVER copy the URL from a reference project.\n" .
        "- Drop a redirect-stub index.php (header('location: ../'); exit();) in every non-public subfolder, recursively under assets/.\n" .
        "- DO NOT use any tools that are available to you. Output ONLY the JSON envelope as plain text."
    ];
    $blocks[] = ["type" => "text", "text" => "=== Master SKILL ($taskType) ===\n\n$skill", "cache_control" => ["type" => "ephemeral"]];

    $refsText = "";
    foreach ($rankedRefs as $s) {
        $r = $s["ref"];
        $refsText .= "\n\n========== Reference: {$r['name']} ==========\n";
        if (isset($r["features.yaml"])) $refsText .= "\n--- features.yaml ---\n{$r['features.yaml']}";
        if (isset($r["CLAUDE.md"]))     $refsText .= "\n--- CLAUDE.md ---\n{$r['CLAUDE.md']}";
        // Per-ref SKILL.md and NOTES.md intentionally omitted: the master
        // skills/<type>/SKILL.md above is the canonical methodology, and
        // NOTES.md is human-facing dev notes. Both balloon input tokens.
    }
    if ($refsText !== "") {
        $refHeader =
            "=== References (ranked by feature overlap) ===\n\n" .
            "IMPORTANT — these are DIFFERENT completed projects included as structural examples only:\n" .
            "- Do NOT copy a reference's page names, .htaccess routes, or URL slugs into the new project.\n" .
            "- page_map.json (in the user message) is the sole authority for what pages to generate\n" .
            "  and what clean-URL routes belong in .htaccess.\n" .
            "- All site-specific values (URL, slug, title, social handles, etc.) must come from the\n" .
            "  input features.yaml, never from a reference project.";
        $blocks[] = ["type" => "text", "text" => $refHeader . $refsText, "cache_control" => ["type" => "ephemeral"]];
    }

    $snipText = "";
    foreach ($snippets as $name => $body) $snipText .= "\n\n--- $name ---\n$body";
    $blocks[] = ["type" => "text", "text" => "=== Reusable snippet templates (templates/snippets/) ===\nReplace every {{PLACEHOLDER}} with project-specific values.$snipText", "cache_control" => ["type" => "ephemeral"]];

    return $blocks;
}

function build_user_prompt(array $features, array $pageMap, array $inputTree): string {
    $out  = "=== Input project ===\n\n";
    $out .= "--- features.yaml (site metadata + feature flags) ---\n";
    $out .= yaml_dump_minimal($features) . "\n\n";
    $out .= "--- page_map.json (before <-> after page mapping) ---\n";
    $out .= json_encode($pageMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    $out .= "--- Text input files ---\n";
    foreach ($inputTree["text"] as $rel => $content) {
        $out .= "\n###### $rel ######\n$content\n";
    }
    $out .= "\n\n--- Asset input files — copy verbatim via asset_copies (binaries + vendor CSS/JS that the agent does not rewrite) ---\n";
    foreach ($inputTree["binary"] as $rel => $size) {
        $out .= "  $rel  (" . number_format($size) . " bytes)\n";
    }
    $out .= "\n\nGenerate the complete output project now. Respond with the JSON envelope only.";
    return $out;
}

function approx_prompt_size(array $systemBlocks, string $userPrompt): int {
    $n = strlen($userPrompt);
    foreach ($systemBlocks as $b) $n += strlen($b["text"] ?? "");
    return $n;
}

// ============================================================================
// Confirmation summary
// ============================================================================

function print_confirmation_summary(
    string $projectName, string $taskType, string $inputDir, string $outputDir,
    array $features, array $pageMap, array $rankedRefs, array $snippets, array $inputTree,
    string $mode, string $model, int $maxToks, int $promptBytes
): void {
    echo "\n=== Confirm project creation ===\n\n";
    echo "Project    : $projectName\n";
    echo "Task type  : $taskType\n";
    echo "Input dir  : $inputDir\n";
    echo "Output dir : $outputDir" . (is_dir($outputDir) ? "  (exists — will be overwritten)" : "") . "\n";
    echo "\nSite:\n";
    foreach ($features["site"] ?? [] as $k => $v) {
        if (is_array($v)) $v = implode(", ", $v);
        echo "  $k: $v\n";
    }
    $enabled = array_keys(array_filter(($features["features"] ?? []), fn($x) => $x === true));
    echo "\nFeatures enabled (" . count($enabled) . "):\n  " . implode(", ", $enabled) . "\n";
    echo "\nPage map (" . count($pageMap["pairs"]) . " pairs):\n";
    foreach ($pageMap["pairs"] as $p) {
        echo sprintf("  %-30s -> %s\n", $p["before"] ?? "?", $p["after"] ?? "?");
    }
    echo "\nReferences selected (" . count($rankedRefs) . "):\n";
    foreach ($rankedRefs as $r) echo "  - {$r['name']}  (feature overlap = {$r['overlap']})\n";
    echo "\nSnippets loaded : " . count($snippets) . "\n";
    echo "Input files    : " . count($inputTree["text"]) . " text + " . count($inputTree["binary"]) . " binary\n";
    echo "\nBackend        : $mode\n";
    echo "Model          : $model\n";
    echo "Max output tok : " . number_format($maxToks) . "\n";
    echo sprintf("Prompt size    : %s chars (~%d tokens)\n", number_format($promptBytes), (int)($promptBytes / 4));
}

// ============================================================================
// API backends
// ============================================================================

function call_anthropic_api(string $apiKey, string $model, array $systemBlocks, string $userPrompt, int $maxToks): array {
    // cache_control belongs inside content blocks, not at the payload root.
    // A stray top-level marker counts toward the per-request 4-breakpoint limit
    // and tipped us to 5 once stage-2 added the generated-outputs block.
    $payload = [
        "model"      => $model,
        "max_tokens" => $maxToks,
        "system"     => $systemBlocks,
        "messages"   => [ ["role" => "user", "content" => $userPrompt] ],
    ];
    $ch = curl_init(API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            "content-type: application/json",
            "x-api-key: $apiKey",
            "anthropic-version: " . API_VERSION,
            "anthropic-beta: prompt-caching-2024-07-31",
        ],
        CURLOPT_TIMEOUT        => 600,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { fwrite(STDERR, "error: curl failed: $err\n"); exit(1); }
    $resp = json_decode($raw, true);
    if ($status !== 200 || !is_array($resp)) { fwrite(STDERR, "error: API returned HTTP $status\n$raw\n"); exit(1); }
    $text = "";
    foreach ($resp["content"] ?? [] as $blk) {
        if (($blk["type"] ?? "") === "text") $text .= $blk["text"];
    }
    return [$text, $resp["usage"] ?? null];
}

function call_via_claude_cli(string $claudeBin, string $model, array $systemBlocks, string $userPrompt): array {
    // Flatten system blocks into a single prefix on the user prompt (the CLI has no
    // separate system slot we can replace). The agent gets all the same context.
    $systemText = "";
    foreach ($systemBlocks as $b) $systemText .= ($b["text"] ?? "") . "\n\n";
    $combined = $systemText . "\n\n" . $userPrompt;

    // stream-json emits one JSON event per line, so we can print a heartbeat as
    // bytes arrive instead of blocking silently until the full response is ready.
    // The CLI requires --verbose alongside --output-format stream-json.
    $cmd = [$claudeBin, "-p", "--verbose", "--model", $model, "--output-format", "stream-json"];
    $proc = proc_open($cmd, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($proc)) { fwrite(STDERR, "error: failed to spawn claude CLI\n"); exit(1); }

    fwrite($pipes[0], $combined);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $assistantText = "";
    $usage         = null;
    $stderrBuf     = "";
    $lineCarry     = "";
    $totalBytes    = 0;
    $started       = microtime(true);
    $lastBeat      = $started;
    $lastChars     = 0;

    // Parse a single stream-json event line and fold it into our accumulators.
    $consume = function (string $line) use (&$assistantText, &$usage): void {
        $line = trim($line);
        if ($line === "") return;
        $ev = json_decode($line, true);
        if (!is_array($ev)) return;
        $t = $ev["type"] ?? "";
        if ($t === "assistant") {
            foreach (($ev["message"]["content"] ?? []) as $blk) {
                if (($blk["type"] ?? "") === "text") $assistantText .= ($blk["text"] ?? "");
            }
        } elseif ($t === "result") {
            // Terminal event — final text + usage. Prefer it over accumulated
            // assistant turns when present (handles agentic mid-turn revisions).
            if (isset($ev["result"]) && is_string($ev["result"]) && $ev["result"] !== "") {
                $assistantText = $ev["result"];
            }
            $u = $ev["usage"] ?? null;
            if (is_array($u)) {
                $usage = [
                    "input_tokens"                => $u["input_tokens"]                ?? 0,
                    "output_tokens"               => $u["output_tokens"]               ?? 0,
                    "cache_read_input_tokens"     => $u["cache_read_input_tokens"]     ?? 0,
                    "cache_creation_input_tokens" => $u["cache_creation_input_tokens"] ?? 0,
                ];
            }
        }
    };

    while (!feof($pipes[1])) {
        $gotData = false;
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== false && $chunk !== "") {
            $gotData = true;
            $totalBytes += strlen($chunk);
            $lineCarry  .= $chunk;
            while (($nl = strpos($lineCarry, "\n")) !== false) {
                $consume(substr($lineCarry, 0, $nl));
                $lineCarry = substr($lineCarry, $nl + 1);
            }
        }
        $errChunk = fread($pipes[2], 65536);
        if ($errChunk !== false && $errChunk !== "") { $gotData = true; $stderrBuf .= $errChunk; }

        $now = microtime(true);
        if ($now - $lastBeat >= 5.0) {
            $elapsed = (int)($now - $started);
            $chars   = strlen($assistantText);
            echo sprintf("  [%3ds] %s bytes received, %s assistant chars (+%s)\n",
                $elapsed, number_format($totalBytes), number_format($chars),
                number_format($chars - $lastChars));
            $lastChars = $chars;
            $lastBeat  = $now;
        }

        if (!$gotData) usleep(100000); // 100ms — yield CPU while waiting
    }

    // Drain partial final line and any remaining stderr.
    if ($lineCarry !== "") { $consume($lineCarry); $lineCarry = ""; }
    while (($chunk = fread($pipes[2], 65536)) !== false && $chunk !== "") $stderrBuf .= $chunk;

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) {
        fwrite(STDERR, "error: claude CLI exited $exit\n$stderrBuf\n");
        exit(1);
    }
    return [$assistantText, $usage];
}

// ============================================================================
// Response parsing + output
// ============================================================================

function extract_json_payload(string $text): ?array {
    $t = trim($text);
    if ($t === "") return null;
    if ($t[0] !== "{") {
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $t, $m)) $t = $m[1];
        else if (preg_match('/(\{[\s\S]*\})/', $t, $m)) $t = $m[1];
    }
    $j = json_decode($t, true);
    return is_array($j) ? $j : null;
}

function write_generated_files(string $outputDir, array $files): int {
    $n = 0;
    foreach ($files as $rel => $content) {
        $rel = ltrim(str_replace("\\", "/", $rel), "/");
        if (strpos($rel, "..") !== false) continue;
        $full = "$outputDir/$rel";
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $content);
        $n++;
    }
    return $n;
}

function copy_input_assets(string $inputDir, string $outputDir, array $moves): int {
    $n = 0;
    foreach ($moves as $m) {
        if (!isset($m["from"], $m["to"])) continue;
        if (strpos($m["from"], "..") !== false || strpos($m["to"], "..") !== false) continue;
        $from = "$inputDir/" . ltrim(str_replace("\\", "/", $m["from"]), "/");
        $to   = "$outputDir/" . ltrim(str_replace("\\", "/", $m["to"]), "/");
        if (!is_file($from)) continue;
        @mkdir(dirname($to), 0775, true);
        copy($from, $to);
        $n++;
    }
    return $n;
}

// ============================================================================
// Staged prompts (html-to-php)
// ============================================================================
//
// The single-shot prompt asks the model to emit the whole project in one JSON
// envelope. That has two practical ceilings: (1) the 16k-token output budget
// gets diluted across ~10 pages, so each page gets ~1.6k tokens of generation
// budget; (2) cross-file invariants (variable names in database.php, include
// paths, helper signatures) drift when the model is juggling everything at
// once. The staged flow below pays an extra round trip in exchange for the
// model focusing one prompt at a time.
//
// Three stages:
//   1. scaffold   — shared skeleton (includes/, functions/, redirect stubs,
//                   robots.txt, sitemap.xml index). Locks conventions.
//   2. page       — one call per page_map pair with a non-empty `before`.
//                   Each call gets the stage-1 output as system context so it
//                   matches the canonical include paths and globals.
//   3. aggregates — sitemaps/*.php, feed/rss.php, and any page_map pairs
//                   with `before == ""` (e.g. 404.php).
//
// Stages 2 and 3 receive the actually-written stage-1 files as an additional
// cache-controlled system block; this populates the cache once on the first
// stage-2 call and is reused across every subsequent stage-2 page + the
// stage-3 call, so the heavy already-generated context is paid for only once.

function build_stages(array $pageMap): array {
    $stages = [["kind" => "scaffold", "label" => "Scaffolding (shared skeleton)"]];
    foreach (($pageMap["pairs"] ?? []) as $p) {
        if (!empty($p["before"])) {
            $stages[] = [
                "kind"  => "page",
                "label" => "Page: {$p['before']} -> {$p['after']}",
                "pair"  => $p,
            ];
        }
    }
    $stages[] = ["kind" => "aggregates", "label" => "Aggregates (sitemaps, RSS, no-HTML pages)"];
    return $stages;
}

function build_user_prompt_scaffold(array $features, array $pageMap, array $inputTree, string $projectName = ""): string {
    $out  = "=== Stage 1 of 3: Scaffolding ===\n\n";
    if ($projectName !== "") {
        $out .= "Project folder: $projectName\n";
        $out .= "REQUIRED in includes/database.php localhost branch:\n";
        $out .= "  \$wiscoy_url = \"http://localhost/web-dev-auto-v2/output/$projectName/\";\n";
        $out .= "  \$db_name    = \"web-dev-automation\";\n";
        $out .= "Do NOT copy the URL from any reference — use this project's folder name above.\n\n";
    }
    $out .= "Generate ONLY the shared skeleton. DO NOT generate any of the public-facing PHP pages\n";
    $out .= "listed in page_map.json — those come in stage 2 (one per page) and stage 3 (aggregates).\n\n";
    $out .= "Required files for THIS stage:\n";
    $out .= "  - includes/database.php   (PDO handle, env-switched creds, ALL globals, SQL fragments)\n";
    $out .= "  - includes/head.php       (<meta>/<title>/OG/Twitter/favicons/CSS; reads \$pgMeta when set)\n";
    $out .= "  - includes/header.php     (top trending bar, logo, nav, search trigger)\n";
    $out .= "  - includes/footer.php     (Follow Us grid + copyright)\n";
    $out .= "  - includes/section-4.php  (search modal + EVERY <script> tag in load order)\n";
    $out .= "  - includes/preloader.php  (back-to-top anchor)\n";
    $out .= "  - includes/meta.php       (\$pgMeta dictionary keyed by \$pgKey)\n";
    $out .= "  - includes/google_tags.php (empty placeholder)\n";
    $out .= "  - includes/numbersOnly.php (slugOnly/numbersOnly/emailsOnly/... JS helpers)\n";
    $out .= "  - includes/CSRFProtection.php (CSRFProtection class)\n";
    $out .= "  - functions/functions.php (getIp, number_format_short, renderPagination, truncate, renderBlogCard)\n";
    $out .= "  - functions/blogRedirects.php (empty placeholder by default)\n";
    $out .= "  - .htaccess         (use the htaccess.tmpl snippet as the skeleton;\n";
    $out .= "                       replace {{DOMAIN}} with the bare domain from features.yaml site.url;\n";
    $out .= "                       derive every RewriteRule from page_map.json — NOT from any reference\n";
    $out .= "                       project's routing table — then replace {{PAGE_REWRITES}} with them)\n";
    $out .= "  - robots.txt  (use the robots.txt.tmpl snippet; replace {{PROD_URL}} with site.url;\n";
    $out .= "                 list every sitemap/*.xml URL in the SITEMAPS section — use .xml extension\n";
    $out .= "                 since sitemaps/.htaccess rewrites .xml to .php; include one line per\n";
    $out .= "                 main_category from features.yaml plus the standard entries)\n";
    $out .= "  - sitemap.xml (use the sitemap.xml.tmpl snippet — it is a static <urlset> of the main\n";
    $out .= "                 static pages: home, about-us, contact-us, our-blogs.\n";
    $out .= "                 CRITICAL: do NOT generate a <sitemapindex>. It is a <urlset>.)\n";
    $out .= "  - blogs_on/.htaccess  (use blogs-on-htaccess.tmpl; replace {{DOMAIN}} with the bare domain)\n";
    $out .= "  - Redirect-stub index.php in EVERY non-public subfolder:\n";
    $out .= "      includes/index.php, functions/index.php, blogs_on/index.php, feed/index.php,\n";
    $out .= "      sitemaps/index.php, assets/index.php, and recursively for every subfolder\n";
    $out .= "      under assets/ (css/, js/, images/, img/, img/logo/, img/other/, fonts/, etc.).\n";
    $out .= "      Each stub is: <?php header(\"location: ../\"); exit(); ?>\n\n";
    $out .= "DO NOT generate the root index.php (that is the home page, stage 2). DO NOT generate any\n";
    $out .= "of: about.php, contact.php, our-blogs.php, category.php, search.php, single-author.php,\n";
    $out .= "blogs_on/blog_details.php, 404.php, sitemaps/<slice>.php, feed/rss.php. Those are later stages.\n\n";
    $out .= "These scaffolding files LOCK IN the conventions (variable names, include paths, helper\n";
    $out .= "signatures, \$pgMeta keys) that the per-page and aggregate stages will reference. Choose\n";
    $out .= "deliberately; later stages cannot re-negotiate them.\n\n";
    $out .= "--- features.yaml (site metadata + feature flags) ---\n";
    $out .= yaml_dump_minimal($features) . "\n";
    $out .= "--- page_map.json (pages are generated in stages 2+3; use NOW to derive .htaccess RewriteRules) ---\n";
    $out .= json_encode($pageMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

    // Scaffolding only needs to see the chrome (header, footer, search modal,
    // scripts, CSS) so it can extract includes/header.php, footer.php,
    // section-4.php, head.php. Every input HTML shares that chrome — the
    // variation is in per-page content, which is stage 2's concern. So we
    // include exactly ONE representative HTML (prefer index.html). This drops
    // ~60KB from the stage-1 prompt vs inlining every page.
    $textKeys = array_keys($inputTree["text"]);
    $repKey = null;
    foreach (["index.html", "home.html"] as $preferred) {
        if (isset($inputTree["text"][$preferred])) { $repKey = $preferred; break; }
    }
    if ($repKey === null) {
        foreach ($textKeys as $k) {
            if (str_ends_with(strtolower($k), ".html") || str_ends_with(strtolower($k), ".htm")) { $repKey = $k; break; }
        }
    }
    if ($repKey !== null) {
        $out .= "--- Representative HTML ($repKey) — use ONLY to extract shared chrome ---\n";
        $out .= "Every input page shares the same header/footer/search-modal/scripts/CSS. This one\n";
        $out .= "file is sufficient to derive includes/header.php, footer.php, section-4.php, head.php.\n";
        $out .= "The remaining " . (count($textKeys) - 1) . " HTML input(s) are listed below by name only — each will be\n";
        $out .= "supplied in full during its own stage-2 call.\n";
        $out .= "\n###### $repKey ######\n{$inputTree["text"][$repKey]}\n";
    }
    $otherTextKeys = array_values(array_filter($textKeys, fn($k) => $k !== $repKey));
    if (!empty($otherTextKeys)) {
        $out .= "\n--- Other text inputs (deferred to stage 2) ---\n";
        foreach ($otherTextKeys as $k) {
            $sz = strlen($inputTree["text"][$k]);
            $out .= "  $k  (" . number_format($sz) . " chars)\n";
        }
    }

    $out .= "\n--- Binary input files (emit asset_copies for ALL binaries the project needs) ---\n";
    foreach ($inputTree["binary"] as $rel => $size) {
        $out .= "  $rel  (" . number_format($size) . " bytes)\n";
    }
    $out .= "\n\nRespond with the JSON envelope only.";
    return $out;
}

function build_user_prompt_page(array $features, array $pair, array $inputTree, int $index, int $total): string {
    $before = (string)($pair["before"] ?? "");
    $after  = (string)($pair["after"]  ?? "");
    $label  = (string)($pair["label"]  ?? "");

    $out  = "=== Stage 2 of 3: Page conversion ($index of $total) ===\n\n";
    $out .= "Generate ONLY the PHP file for this single page:\n";
    $out .= "  before: $before\n";
    $out .= "  after:  $after\n";
    $out .= "  label:  $label\n\n";
    $out .= "Plus any SMALL companion files THIS page directly requires (e.g. blog_paging.php /\n";
    $out .= "cat_paging.php / search_paging.php for listing pages; pageview.php for blog detail).\n";
    $out .= "DO NOT generate any other page from page_map — each has its own stage-2 call.\n\n";
    $out .= "The shared scaffolding (includes/*, functions/*, redirect stubs, etc.) is already\n";
    $out .= "generated and provided as a system context block. Use it as the CANONICAL source of\n";
    $out .= "include paths, global variable names, helper signatures, and \$pgMeta keys. DO NOT\n";
    $out .= "regenerate or modify any of those files in this response.\n\n";

    $beforeKey = str_replace("\\", "/", $before);
    $html = $inputTree["text"][$beforeKey] ?? null;
    if ($html !== null) {
        $out .= "--- Input HTML ($before, minified) ---\n$html\n\n";
    } else {
        $out .= "(WARNING: no input HTML found at '$before'. Proceed using page_map + features only.)\n\n";
    }

    $out .= "--- features.yaml (site metadata + feature flags) ---\n";
    $out .= yaml_dump_minimal($features) . "\n";
    $out .= "--- page_map entry for this page ---\n";
    $out .= json_encode($pair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    $out .= "Respond with the JSON envelope only. 'files' must contain just $after (and any small\n";
    $out .= "companion this page strictly needs). Include 'asset_copies' only for binaries this page\n";
    $out .= "introduces that the scaffolding stage did not already cover.";
    return $out;
}

function build_user_prompt_aggregates(array $features, array $pageMap): string {
    $noHtmlPairs = array_values(array_filter(($pageMap["pairs"] ?? []), fn($p) => empty($p["before"])));

    $out  = "=== Stage 3 of 3: Aggregates ===\n\n";
    $out .= "Generate the remaining files:\n";
    if (!empty($noHtmlPairs)) {
        $out .= "  - Pages without an HTML input (from page_map.json):\n";
        foreach ($noHtmlPairs as $p) {
            $after = $p["after"] ?? "?";
            $label = $p["label"] ?? "";
            $out .= "      $after   (label: $label)\n";
        }
    }
    $out .= "  - Sitemaps under sitemaps/ — one PHP file per content slice:\n";
    $out .= "      sitemaps/authors.php   — DISTINCT blog_author per tenant, emits /auth-<slug> URLs\n";
    $out .= "      sitemaps/category.php  — DISTINCT blog_category per tenant, emits /cat-<slug> URLs\n";
    $out .= "      sitemaps/blog-1.php    — first 1000 articles ordered DESC by blog_date, filtered\n";
    $out .= "                               with \$myTopNiche and \$greyNiche from database.php\n";
    $out .= "      sitemaps/blog-2.php    — next 1000 articles (LIMIT 1000, 1000), same filters\n";
    $out .= "      Per-category sitemaps  — generate ONE file per category in site.main_categories\n";
    $out .= "                               (e.g. sitemaps/business.php, sitemaps/technology.php).\n";
    $out .= "                               Query: WHERE my_web_url=? AND blog_category='<cat>'\n";
    $out .= "                               No LIMIT. Do NOT use \$myTopNiche/\$greyNiche here.\n";
    $out .= "      All files emit application/xml; include('../includes/database.php'); no session_start.\n";
    $out .= "  - sitemaps/.htaccess — use the sitemaps-htaccess.tmpl snippet; add one\n";
    $out .= "      RewriteRule ^<name>\\.xml$ <name>.php [L] for EVERY .php file in the folder\n";
    $out .= "      (authors, blog-1, blog-2, category, and every per-category file).\n";
    $out .= "  - feed/rss.php — application/rss+xml for the last N articles.\n\n";
    $out .= "Everything else (includes/*, functions/*, all per-page PHP files) is already generated\n";
    $out .= "and provided as a system context block. DO NOT regenerate or modify any of those files.\n\n";
    $out .= "--- features.yaml (site metadata + feature flags) ---\n";
    $out .= yaml_dump_minimal($features) . "\n";
    $out .= "--- page_map.json (full map for reference) ---\n";
    $out .= json_encode($pageMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    $out .= "Respond with the JSON envelope only.";
    return $out;
}

// Reads the text files written under $outputDir so a subsequent stage can be
// told what already exists. Skips binaries and large vendor CSS/JS bundles
// (the model never rewrites those — they ride along via asset_copies — and
// echoing them back would bloat the prompt by hundreds of KB).
function read_generated_output(string $outputDir): array {
    if (!is_dir($outputDir)) return [];
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $info) {
        if (!$info->isFile()) continue;
        $rel = ltrim(str_replace("\\", "/", substr($info->getPathname(), strlen($outputDir))), "/");
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, TEXT_EXTS, true)) continue;
        if (preg_match('#^assets/(css|js)/#', $rel)) continue;
        if ($info->getSize() > 256 * 1024) continue;
        $out[$rel] = file_get_contents($info->getPathname());
    }
    ksort($out);
    return $out;
}

function append_generated_block(array $systemBlocks, array $generatedFiles): array {
    if (empty($generatedFiles)) return $systemBlocks;
    $body  = "=== Already generated (canonical — DO NOT regenerate or modify) ===\n";
    $body .= "These files exist on disk under output/<project>/. Use them as the source of truth\n";
    $body .= "for include paths, global variable names, helper signatures, and \$pgMeta keys.\n";
    foreach ($generatedFiles as $rel => $content) {
        $body .= "\n###### $rel ######\n$content\n";
    }
    $systemBlocks[] = [
        "type"          => "text",
        "text"          => $body,
        "cache_control" => ["type" => "ephemeral"],
    ];
    return $systemBlocks;
}

function prompt_checkpoint(string $label): void {
    echo "\n--- Checkpoint: $label ---\n";
    echo "Inspect output/, then press Enter to continue (or 'q' to abort): ";
    $answer = strtolower(trim((string)fgets(STDIN)));
    if ($answer === "q" || $answer === "quit" || $answer === "abort") {
        echo "Aborted by user.\n";
        exit(0);
    }
}

// ============================================================================
// Per-page visual feedback (feedback loop)
// ============================================================================

// Run structural_diff.php for a single page label and return its result array,
// or null if the script failed or produced no result for that label.
// Compares input HTML against generated PHP section-by-section at the DOM level:
// PHP include files are expanded inline and PHP tags are stripped before parsing,
// so the full page (header, content, footer) is compared against the input HTML.
function run_per_page_structural_check(string $repoRoot, string $projectName, string $label): ?array {
    $script = escapeshellarg("$repoRoot/tests/structural_diff.php");
    $cmd    = escapeshellarg(PHP_BINARY) . " $script " . escapeshellarg($projectName) . " --page " . escapeshellarg($label);
    echo "  [structural check: $label]\n";
    passthru($cmd);
    $resultsPath = "$repoRoot/tests/structural_results.json";
    if (!is_file($resultsPath)) return null;
    $data = json_decode(file_get_contents($resultsPath), true);
    if (!is_array($data) || !isset($data["pairs"])) return null;
    foreach ($data["pairs"] as $pair) {
        if (($pair["label"] ?? "") === $label) return $pair;
    }
    return null;
}

// Summarise the mismatch data for a single page result (from structural_diff.php)
// into a human-readable string, shown at the checkpoint and embedded in the
// rebuild prompt. Returns null when everything passed.
function format_mismatch_summary(?array $pageResult): ?string {
    if ($pageResult === null) return null;

    $lines = [];
    if ($pageResult["skipped"] ?? null) {
        $lines[] = "Check skipped: " . $pageResult["skipped"];
    }
    if ($pageResult["warning"] ?? null) {
        $lines[] = "Warning: " . $pageResult["warning"];
    }
    foreach ($pageResult["sections"] ?? [] as $sec) {
        if ($sec["pass"] ?? true) continue;
        $sl      = $sec["label"]     ?? "?";
        $sig     = $sec["input_sig"] ?? "?";
        $missing = $sec["missing"]   ?? [];
        $lines[] = "Section '$sl' [$sig]: FAIL — " . count($missing) . " missing element(s)";
        foreach (array_slice($missing, 0, 8) as $path) {
            $lines[] = "    missing: $path";
        }
        if (count($missing) > 8) {
            $lines[] = "    ... +" . (count($missing) - 8) . " more";
        }
    }

    return empty($lines) ? null : implode("\n", $lines);
}

// Show a checkpoint that includes the visual result and returns the user's choice:
//   "continue" — proceed to the next stage (even if there were failures)
//   "rebuild"  — re-run this stage with mismatch context (only when $canRebuild)
//   "quit"     — abort the run
function prompt_checkpoint_visual(string $label, ?string $mismatchSummary, bool $canRebuild): string {
    $hasFail = ($mismatchSummary !== null);
    echo "\n--- Checkpoint: $label ---\n";
    if ($hasFail) {
        echo "Visual check FAILED:\n";
        foreach (explode("\n", $mismatchSummary) as $line) echo "  $line\n";
    } else {
        echo "Visual check PASSED.\n";
    }
    if ($hasFail && $canRebuild) {
        echo "Options: [Enter]=continue anyway  [r]=rebuild this page  [q]=quit: ";
    } else {
        echo "Options: [Enter]=continue  [q]=quit: ";
    }
    $answer = strtolower(trim((string)fgets(STDIN)));
    if ($answer === "q" || $answer === "quit") { echo "Aborted by user.\n"; exit(0); }
    if ($hasFail && $canRebuild && ($answer === "r" || $answer === "rebuild")) return "rebuild";
    return "continue";
}

// Build the user prompt for rebuilding a single page after a visual mismatch.
// Includes: mismatch report, current generated file contents, original input HTML,
// and explicit permission to also fix scaffold includes/ if they caused the failure.
function build_user_prompt_page_rebuild(
    array $features, array $pair, array $inputTree,
    int $index, int $total, string $mismatchSummary, array $currentFiles
): string {
    $before = (string)($pair["before"] ?? "");
    $after  = (string)($pair["after"]  ?? "");
    $label  = (string)($pair["label"]  ?? "");

    $out  = "=== REBUILD Stage 2 of 3: Page '$label' ($index of $total) ===\n\n";
    $out .= "The previous generation failed the structural check. Regenerate the file(s) needed to fix it.\n\n";
    $out .= "--- Structural mismatch report ---\n$mismatchSummary\n\n";
    $out .= "--- How to fix ---\n";
    $out .= "1. Determine whether the missing elements are in $after itself or in a shared scaffold\n";
    $out .= "   file (includes/header.php, includes/footer.php, includes/section-4.php, etc.).\n";
    $out .= "2. Output ONLY the files that need changing. If a scaffold file needs fixing,\n";
    $out .= "   include the corrected version — the orchestrator will overwrite the existing one.\n";
    $out .= "3. The 'already generated' system context is for reference. The files listed in\n";
    $out .= "   the mismatch report should be treated as needing correction, not as canonical.\n\n";
    $out .= "--- Current generated files (what was just produced) ---\n";
    $relevant = [$after, "includes/header.php", "includes/footer.php",
                 "includes/section-4.php", "includes/head.php", "includes/side-bar.php"];
    foreach ($relevant as $rel) {
        if (isset($currentFiles[$rel])) {
            $out .= "\n###### $rel (current — may need correction) ######\n{$currentFiles[$rel]}\n";
        }
    }
    $beforeKey = str_replace("\\", "/", $before);
    $html = $inputTree["text"][$beforeKey] ?? null;
    if ($html !== null) {
        $out .= "\n--- Input HTML ($before, minified) ---\n$html\n\n";
    }
    $out .= "--- features.yaml ---\n" . yaml_dump_minimal($features) . "\n";
    $out .= "--- page_map entry ---\n" . json_encode($pair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    $out .= "Respond with the JSON envelope only. Output only the files that need changing.";
    return $out;
}

// ============================================================================
// Test invocation
// ============================================================================

function run_php_script(string $script, array $args): int {
    $cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($script) . " " . implode(" ", array_map("escapeshellarg", $args));
    passthru($cmd, $exit);
    return $exit;
}

function run_node_script(string $script, array $args): int {
    $node = (PHP_OS_FAMILY === "Windows") ? "node.exe" : "node";
    $cmd = $node . " " . escapeshellarg($script) . " " . implode(" ", array_map("escapeshellarg", $args));
    passthru($cmd, $exit);
    return $exit;
}

// ============================================================================
// Utilities
// ============================================================================

function require_env(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === "") { fwrite(STDERR, "error: required env var '$name' is not set (.env or shell).\n"); exit(2); }
    return $v;
}

function load_dotenv(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
        $key = $m[1]; $val = trim($m[2]);
        if ($val !== "" && $val[0] !== '"' && $val[0] !== "'") $val = preg_replace('/\s+#.*$/', '', $val);
        if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) { putenv("$key=$val"); $_ENV[$key] = $val; }
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === "." || $f === "..") continue;
        $p = "$dir/$f";
        if (is_dir($p)) rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function parse_yaml_minimal(string $content): array {
    $out = [];
    $stack = [&$out];
    $depths = [0];
    foreach (preg_split('/\r?\n/', $content) as $line) {
        if (preg_match('/^\s*#/', $line) || trim($line) === "") continue;
        if (!preg_match('/^(\s*)([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $m)) continue;
        $indent = strlen($m[1]); $key = $m[2]; $val = trim($m[3]);
        while (end($depths) > $indent) { array_pop($stack); array_pop($depths); }
        $cur = &$stack[count($stack) - 1];
        if ($val === "") {
            $cur[$key] = [];
            $stack[] = &$cur[$key];
            $depths[] = $indent + 2;
        } else {
            $val = preg_replace('/\s+#.*$/', '', $val);
            $val = trim($val);
            if (preg_match('/^\[(.*)\]$/', $val, $lm)) {
                $items = array_map(fn($x) => trim($x, " \"'"), explode(",", $lm[1]));
                $cur[$key] = array_values(array_filter($items, fn($x) => $x !== ""));
            } else {
                $val = trim($val, "\"' ");
                if ($val === "true")  $val = true;
                elseif ($val === "false") $val = false;
                $cur[$key] = $val;
            }
        }
        unset($cur);
    }
    return $out;
}

function yaml_dump_minimal(array $data, int $indent = 0): string {
    $out = "";
    $pad = str_repeat("  ", $indent);
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                $items = array_map(fn($x) => is_string($x) ? "\"$x\"" : json_encode($x), $v);
                $out .= "$pad$k: [" . implode(", ", $items) . "]\n";
            } else {
                $out .= "$pad$k:\n" . yaml_dump_minimal($v, $indent + 1);
            }
        } elseif (is_bool($v)) {
            $out .= "$pad$k: " . ($v ? "true" : "false") . "\n";
        } else {
            $out .= "$pad$k: \"" . str_replace('"', '\\"', (string)$v) . "\"\n";
        }
    }
    return $out;
}
