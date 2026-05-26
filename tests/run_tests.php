<?php
/**
 * Quality gate for a generated PHP project.
 *
 *   php tests/run_tests.php <project-name>
 *
 * Resolution order for the project under test:
 *   1) output/<project-name>/                              (freshly generated)
 *   2) references/html-to-php/<project-name>/after/        (reference checkpoint)
 *   3) references/mysqli-to-pdo/<project-name>/after/      (reference checkpoint)
 *
 * What it does:
 *   - Drops and recreates the test database (name from --db or derived from website_slug)
 *   - Loads tests/fixtures/reverbtime.sql, then seed.sql with {{WEB_SLUG}} substituted
 *   - HTTP-GETs every discovered page under the project on XAMPP, asserts no PHP error tokens
 *   - Exercises the comment form (CSRF capture -> POST -> redirect -> DB row check) when applicable
 *   - Emits tests/results.json with pass/fail per check
 *
 * XAMPP must be running. The project's includes/database.php localhost branch is expected
 * to point at the same DB name this runner creates. Override with WEBDEV_TEST_* env vars.
 */

declare(strict_types=1);
require_once __DIR__ . "/functions.php";

// --- args -------------------------------------------------------------------

if ($argc < 2) {
    fwrite(STDERR, "usage: php tests/run_tests.php <project-name>\n");
    exit(2);
}
$projectName = $argv[1];

$repoRoot   = realpath(__DIR__ . "/..");
$testsDir   = __DIR__;
$fixturesDir = $testsDir . "/fixtures";

// Load /.env so WEBDEV_TEST_DB_*, XAMPP_HTDOCS, etc. become readable via getenv().
// Existing OS-level env vars take precedence over .env (so CLI overrides still work).
load_dotenv("$repoRoot/.env");

// --- project + features resolution ------------------------------------------

$candidates = [
    "$repoRoot/output/$projectName",
    "$repoRoot/references/html-to-php/$projectName/after",
    "$repoRoot/references/mysqli-to-pdo/$projectName/after",
];
$projectPath = null;
$taskType    = null;
foreach ($candidates as $c) {
    if (is_dir($c)) {
        $projectPath = $c;
        if (strpos($c, "/html-to-php/")  !== false || strpos($c, "\\html-to-php\\")  !== false) $taskType = "html-to-php";
        if (strpos($c, "/mysqli-to-pdo/") !== false || strpos($c, "\\mysqli-to-pdo\\") !== false) $taskType = "mysqli-to-pdo";
        if ($taskType === null) $taskType = "html-to-php"; // default for fresh output/
        break;
    }
}
if ($projectPath === null) {
    fwrite(STDERR, "could not locate project '$projectName' under output/ or references/\n");
    exit(2);
}

// features.yaml lives one level up from after/ for references, or at the project root for output/
$featuresCandidates = [
    dirname($projectPath) . "/features.yaml",
    "$projectPath/features.yaml",
    "$repoRoot/references/$taskType/$projectName/features.yaml",
];
$features = ["site" => ["website_slug" => $projectName]];
foreach ($featuresCandidates as $fc) {
    if (is_file($fc)) { $features = parse_yaml_minimal(file_get_contents($fc)); break; }
}
$webSlug = $features["site"]["website_slug"] ?? $projectName;

// --- DB config (env-overridable, XAMPP defaults) ----------------------------

$dbHost = getenv("WEBDEV_TEST_DB_HOST") ?: "127.0.0.1";
$dbPort = (int)(getenv("WEBDEV_TEST_DB_PORT") ?: 3306);
$dbUser = getenv("WEBDEV_TEST_DB_USER") ?: "root";
$dbPass = getenv("WEBDEV_TEST_DB_PASS") ?: "NewStrongPassword123!";
$dbName = getenv("WEBDEV_TEST_DB_NAME") ?: "web-dev-automation";

// --- URL base ---------------------------------------------------------------
// Derive the HTTP base URL for the project so run_tests can hit it via cURL.
//
// Three ways to configure (resolution order):
//   1. WEBDEV_BASE_URL — full project URL, e.g.
//        WEBDEV_BASE_URL=http://localhost/web-dev-automation/output/test-dev-one
//   2. WEBDEV_REPO_URL — URL that maps to the repo root; the project path is
//      made repo-relative and appended. Works across symlinks and non-XAMPP
//      servers. Recommended for Linux / non-standard htdocs setups:
//        WEBDEV_REPO_URL=http://localhost/web-dev-automation
//   3. XAMPP_HTDOCS — filesystem path to htdocs; URL derived by checking that
//      the project path starts with htdocs. Defaults to the platform standard
//      (/opt/lampp/htdocs on Linux, c:/xampp/htdocs on Windows).

$forcedBaseUrl = rtrim(getenv("WEBDEV_BASE_URL") ?: "", "/");
$repoUrl       = rtrim(getenv("WEBDEV_REPO_URL")  ?: "", "/");

if ($forcedBaseUrl !== "") {
    $baseUrl = $forcedBaseUrl;
} elseif ($repoUrl !== "") {
    $relPath = ltrim(str_replace("\\", "/", substr($projectPath, strlen($repoRoot))), "/");
    $baseUrl = "$repoUrl/$relPath";
} else {
    $htdocsDefault = PHP_OS_FAMILY === "Windows" ? "c:/xampp/htdocs" : "/opt/lampp/htdocs";
    $htdocsRoot    = getenv("XAMPP_HTDOCS") ?: $htdocsDefault;
    $normalizedHtdocs  = strtr(strtolower($htdocsRoot), "\\", "/");
    $normalizedProject = strtr(strtolower($projectPath), "\\", "/");
    if (strpos($normalizedProject, $normalizedHtdocs) !== 0) {
        fwrite(STDERR, "project '$projectPath' is not under htdocs ($htdocsRoot).\n");
        fwrite(STDERR, "Set WEBDEV_REPO_URL=http://localhost/<repo-url-path> in .env (recommended), or\n");
        fwrite(STDERR, "set WEBDEV_BASE_URL=http://localhost/<path-to-project>, or\n");
        fwrite(STDERR, "set XAMPP_HTDOCS to the correct htdocs directory.\n");
        exit(2);
    }
    $urlPath = trim(substr($normalizedProject, strlen($normalizedHtdocs)), "/");
    $baseUrl = "http://localhost/" . $urlPath;
}

// --- results accumulator ----------------------------------------------------

$results = [
    "project"   => $projectName,
    "task_type" => $taskType,
    "path"      => $projectPath,
    "base_url"  => $baseUrl,
    "db"        => ["name" => $dbName, "host" => $dbHost, "port" => $dbPort, "user" => $dbUser],
    "started_at" => date("c"),
    "checks"    => [],
];

echo "Testing $projectName ($taskType) at $baseUrl\n";
echo "Database: $dbName on $dbHost:$dbPort as $dbUser\n";

// --- DB: drop + recreate + load fixtures ------------------------------------

try {
    $rootPdo = new PDO("mysql:host=$dbHost;port=$dbPort", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rootPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    $rootPdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    record($results, "db.recreate", true);
} catch (Throwable $e) {
    record($results, "db.recreate", false, $e->getMessage());
    emit_and_exit($results, 1);
}

$pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

try {
    // Load the real reverbtime dump (structure only — no rows) as the test schema.
    exec_sql_file($pdo, "$fixturesDir/reverbtime.sql");
    record($results, "db.schema_loaded", true);
} catch (Throwable $e) {
    record($results, "db.schema_loaded", false, $e->getMessage());
    emit_and_exit($results, 1);
}

try {
    $seed = file_get_contents("$fixturesDir/seed.sql");
    $seed = str_replace("{{WEB_SLUG}}", $webSlug, $seed);
    exec_sql_string($pdo, $seed);
    record($results, "db.seed_loaded", true);
} catch (Throwable $e) {
    record($results, "db.seed_loaded", false, $e->getMessage());
    emit_and_exit($results, 1);
}

// --- Page health checks: HTTP 200 + no PHP error tokens ---------------------

$pagesToHit = discover_pages($projectPath, $features, $baseUrl, $webSlug);

$cookieJar = tempnam(sys_get_temp_dir(), "webdev_cookies_");
foreach ($pagesToHit as $label => $url) {
    [$status, $body, $headers] = http_get($url, $cookieJar);
    $okStatus = ($status >= 200 && $status < 400);
    $errTokens = ["Fatal error", "Parse error", "PDOException", "Uncaught", "Warning:", "Notice:"];
    $foundToken = null;
    foreach ($errTokens as $t) {
        if (stripos($body, $t) !== false) { $foundToken = $t; break; }
    }
    record($results, "page.$label.http", $okStatus, "HTTP $status — $url");
    record($results, "page.$label.no_php_errors", $foundToken === null, $foundToken ? "found '$foundToken'" : "");
}

// --- Comment form exercise (single-article page) ----------------------------

if (($features["features"]["comments"] ?? false) === true) {
    $articleSlug = "sample-business-article";
    $articleUrl  = "$baseUrl/blogs_on/blog_details.php?blog_url=$articleSlug";
    [$status, $body, ] = http_get($articleUrl, $cookieJar);

    $csrf = extract_csrf_token($body);
    if ($csrf === null) {
        record($results, "comment.csrf_token_present", false, "no <input name=\"csrf_token\"> in $articleUrl");
    } else {
        record($results, "comment.csrf_token_present", true);

        $fixture = json_decode(file_get_contents("$fixturesDir/comment_post.json"), true);
        $postFields = $fixture["fields"];
        $postFields["csrf_token"] = $csrf;
        $postFields[$fixture["_button_name"]] = "1";

        [$pStatus, $pBody, $pHeaders] = http_post($articleUrl, $postFields, $cookieJar);

        $expectMsg = $fixture["expected_redirect_msg"] ?? null;
        $locationOk = false;
        foreach ($pHeaders as $h) {
            if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) {
                if ($expectMsg === null || stripos($m[1], "msg=$expectMsg") !== false) $locationOk = true;
            }
        }
        record($results, "comment.post_redirect", $locationOk, $expectMsg ? "expected ?msg=$expectMsg" : "");

        $where = $fixture["expected_db_row"]["where"];
        $sql = "SELECT COUNT(*) FROM " . $fixture["expected_db_row"]["table"] . " WHERE " .
               implode(" AND ", array_map(fn($k) => "$k = ?", array_keys($where)));
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($where));
        $count = (int)$stmt->fetchColumn();
        record($results, "comment.db_row_inserted", $count >= 1, "found $count matching row(s)");
    }
} else {
    record($results, "comment.skipped", true, "feature disabled in features.yaml");
}

// --- multi-tenant filter regression check -----------------------------------

if ($taskType === "html-to-php") {
    [$status, $body, ] = http_get("$baseUrl/our-blogs.php", $cookieJar);
    $leaked = stripos($body, "Other-Tenant Article") !== false;
    record($results, "tenant.filter_holds", !$leaked, $leaked ? "foreign-tenant row appeared on /our-blogs.php" : "");
}

// --- finalize ---------------------------------------------------------------

@unlink($cookieJar);
emit_and_exit($results, 0);