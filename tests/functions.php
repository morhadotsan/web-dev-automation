<?php

// helper functions for run_tests.php

function record(array &$results, string $name, bool $pass, string $detail = ""): void {
    $results["checks"][] = ["name" => $name, "pass" => $pass, "detail" => $detail];
    fwrite(STDOUT, ($pass ? "  PASS  " : "  FAIL  ") . $name . ($detail !== "" ? "  -- $detail" : "") . "\n");
}



function emit_and_exit(array $results, int $defaultExit): void {
    $results["finished_at"] = date("c");
    $results["pass_count"]  = count(array_filter($results["checks"], fn($c) => $c["pass"]));
    $results["fail_count"]  = count($results["checks"]) - $results["pass_count"];
    file_put_contents(__DIR__ . "/results.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "\n{$results['pass_count']} passed, {$results['fail_count']} failed. results -> tests/results.json\n";
    exit($results["fail_count"] > 0 ? 1 : $defaultExit);
}

function load_dotenv(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
        $key = $m[1];
        $val = trim($m[2]);
        // Strip a trailing inline comment unless the value is quoted.
        if ($val !== "" && $val[0] !== '"' && $val[0] !== "'") {
            $val = preg_replace('/\s+#.*$/', '', $val);
        }
        // Strip a matching pair of surrounding quotes.
        if (strlen($val) >= 2 && (
            ($val[0] === '"' && substr($val, -1) === '"') ||
            ($val[0] === "'" && substr($val, -1) === "'")
        )) {
            $val = substr($val, 1, -1);
        }
        // OS-level env vars win over .env, so CLI overrides keep working.
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function exec_sql_file(PDO $pdo, string $path): void { exec_sql_string($pdo, file_get_contents($path)); }

function exec_sql_string(PDO $pdo, string $sql): void {
    // Naive splitter that handles -- comments and ; terminators. Sufficient for hand-written fixtures.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== "") $pdo->exec($stmt);
    }
}

function discover_pages(string $projectPath, array $features, string $baseUrl, string $webSlug): array {
    $pages = [];
    // Pages excluded from the raw glob because they are either companion-only files
    // that must not be hit directly, or they are always added below with the query
    // parameters they require to run without PHP Notices.
    $globSkipExact = [
        "pageview.php",
        "single-author.php",  // added explicitly below with ?auth_url=
    ];
    $rootPhp = glob("$projectPath/*.php") ?: [];
    foreach ($rootPhp as $f) {
        $name = basename($f);
        // Skip all *_paging.php companion files (blog_paging, cat_paging, auth_paging, etc.)
        if (str_ends_with($name, "_paging.php")) continue;
        if (in_array($name, $globSkipExact, true)) continue;
        $label = preg_replace('/\.php$/', '', $name);
        $pages[$label] = "$baseUrl/$name";
    }
    if (($features["features"]["categories"]      ?? false) === true) $pages["category"]      = "$baseUrl/category.php?cat_url=business";
    if (($features["features"]["search"]          ?? false) === true) $pages["search"]        = "$baseUrl/search.php?searchQuery=sample";
    if (($features["features"]["single_article"]  ?? false) === true) $pages["single_article"]= "$baseUrl/blogs_on/blog_details.php?blog_url=sample-business-article";
    $pages["author"] = "$baseUrl/single-author.php?auth_url=jane-doe";
    return $pages;
}

function http_get(string $url, string $cookieJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlob = substr($raw, 0, $hsize);
    $body       = substr($raw, $hsize);
    $headers    = array_filter(array_map("trim", explode("\r\n", $headerBlob)));
    return [$status, $body, $headers];
}

function http_post(string $url, array $fields, string $cookieJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlob = substr($raw, 0, $hsize);
    $body       = substr($raw, $hsize);
    $headers    = array_filter(array_map("trim", explode("\r\n", $headerBlob)));
    return [$status, $body, $headers];
}

function extract_csrf_token(string $html): ?string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    if (preg_match('/value=["\']([^"\']+)["\']\s+name=["\']csrf_token["\']/i', $html, $m)) return $m[1];
    return null;
}

// Minimal YAML reader — handles a flat `key: value` and one level of nesting (`key:\n  sub: value`).
// Sufficient for features.yaml in this project. Anything more should pull in a real parser.
function parse_yaml_minimal(string $content): array {
    $out = [];
    $stack = [&$out];
    $depths = [0];
    foreach (preg_split('/\r?\n/', $content) as $line) {
        if (preg_match('/^\s*#/', $line) || trim($line) === "") continue;
        if (!preg_match('/^(\s*)([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $m)) continue;
        $indent = strlen($m[1]);
        $key    = $m[2];
        $val    = trim($m[3]);
        while (end($depths) > $indent) { array_pop($stack); array_pop($depths); }
        $cur = &$stack[count($stack) - 1];
        if ($val === "") {
            $cur[$key] = [];
            $stack[] = &$cur[$key];
            $depths[] = $indent + 2;
        } else {
            $val = preg_replace('/\s+#.*$/', '', $val);
            $val = trim($val, "\"' ");
            if ($val === "true")  $val = true;
            elseif ($val === "false") $val = false;
            $cur[$key] = $val;
        }
        unset($cur);
    }
    return $out;
}