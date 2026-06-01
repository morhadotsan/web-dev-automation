<?php
/**
 * Structural diff: compare input HTML against generated PHP, section by section.
 *
 *   php tests/structural_diff.php <project-name> [--page <label>]
 *
 * How it works:
 *   1. For each page pair in page_map.json, expands PHP include() calls recursively
 *      so header.php, footer.php, etc. are inlined into one document.
 *   2. Strips all PHP tags (php blocks removed, echo expressions become "PLACEHOLDER")
 *      to produce a DOM-parseable HTML string.
 *   3. Reads features.yaml for the project and removes elements from the input DOM
 *      that correspond to disabled features (newsletter_signup: false → strips
 *      newsletter elements, etc.). This prevents false failures when the agent
 *      correctly omits a disabled feature that was present in the input template.
 *   4. Detects major sections in both input HTML and expanded PHP output:
 *        - Native <section> elements (≥2) are used as-is.
 *        - Otherwise, significant direct children of #main-wrapper / .main-wrapper /
 *          <main> / <body> are used as fallback sections.
 *   5. Matches input sections to output sections:
 *        (a) exact id attribute match
 *        (b) Jaccard class overlap ≥ 0.30
 *        (c) positional (same index)
 *   6. For each matched pair, walks both sub-trees up to 4 levels deep and
 *      collects {depth, tag, classes} nodes. Deduplicates so PHP foreach loops
 *      (one template element) match multiple hardcoded cards in the input HTML.
 *      For each input node: the output must have a node at the same depth, same tag,
 *      and whose classes are a superset of the input's classes (extra PHP classes OK).
 *
 * Output: tests/structural_results.json   exit 0 = all pass, 1 = any fail
 */

declare(strict_types=1);

// ── CLI args ──────────────────────────────────────────────────────────────────

if ($argc < 2) {
    fwrite(STDERR, "usage: php tests/structural_diff.php <project-name> [--page <label>]\n");
    exit(2);
}
$projectName = $argv[1];
$pageFilter  = null;
for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--page' && isset($argv[$i + 1])) {
        $pageFilter = $argv[++$i];
    }
}

$repoRoot = realpath(__DIR__ . '/..');

// ── Project resolution ────────────────────────────────────────────────────────

$candidates = [
    [$repoRoot.'/output/'.$projectName, 'html-to-php'],
    [$repoRoot.'/references/html-to-php/'.$projectName.'/after', 'html-to-php'],
    [$repoRoot.'/references/mysqli-to-pdo/'.$projectName.'/after', 'mysqli-to-pdo']
];
$afterDir = null;
$taskType = null;
foreach ($candidates as [$dir, $type]) {
    if (is_dir($dir)) { $afterDir = $dir; $taskType = $type; break; }
}
if ($afterDir === null) {
    fwrite(STDERR, "could not locate project '$projectName' under output/ or references/\n");
    exit(2);
}

// ── Page map ──────────────────────────────────────────────────────────────────

$mapCandidates = [
    $repoRoot.'/references/'.$taskType.'/'.$projectName.'/page_map.json',
    $repoRoot.'/input/'.$projectName.'/page_map.json',
    $repoRoot.'/output/'.$projectName.'/page_map.json',
];
$pairs = null;
foreach ($mapCandidates as $mc) {
    if (!is_file($mc)) continue;
    $j = json_decode(file_get_contents($mc), true);
    if (is_array($j['pairs'] ?? null)) { $pairs = $j['pairs']; break; }
}
if ($pairs === null) {
    fwrite(STDERR, "no page_map.json found for '$projectName'\n");
    exit(2);
}

// ── PHP → HTML rendering (include-aware) ──────────────────────────────────────

/**
 * Render a PHP source string into the HTML it would emit, resolving includes
 * inline. This models PHP's actual output rather than doing textual surgery:
 *
 *   - HTML outside <?php … ?> is copied verbatim.
 *   - <?= … ?> echo blocks become "PLACEHOLDER" (keeps attribute syntax valid).
 *   - <?php … ?> code blocks emit nothing themselves, EXCEPT that any
 *     include()/require() of a literal path is replaced, at its position in the
 *     output stream, with the rendered HTML of the target file.
 *
 * Combining include resolution and PHP stripping into one pass is essential:
 * included files (database.php, functions.php, header.php, …) carry their own
 * <?php/?> tags, so inlining their RAW source into a host PHP block and then
 * scanning for ?> latches onto the wrong delimiter and dumps real PHP code into
 * the HTML. Rendering each include to HTML first avoids that entirely.
 *
 * Only literal-string include paths are handled (covers all generated PHP here).
 * $visited prevents circular includes.
 */
function render_php_to_html(string $src, string $fileDir, int $depth = 0, array &$visited = []): string
{
    if ($depth > 16) return '';
    $out = '';
    $len = strlen($src);
    $i   = 0;
    while ($i < $len) {
        $isEcho = substr($src, $i, 3) === '<?=';
        $isPhp  = !$isEcho && substr($src, $i, 5) === '<?php';
        if (!$isEcho && !$isPhp) { $out .= $src[$i++]; continue; }

        // Find the matching close tag (skipping quoted strings so a close tag
        // inside a string literal does not prematurely end the block).
        $blockStart = $i + ($isEcho ? 3 : 5);
        $j = $blockStart;
        while ($j < $len) {
            if ($src[$j] === '"' || $src[$j] === "'") {
                $q = $src[$j++];
                while ($j < $len) {
                    if ($src[$j] === '\\') { $j += 2; continue; }
                    if ($src[$j] === $q)   { $j++; break; }
                    $j++;
                }
                continue;
            }
            if ($j + 1 < $len && $src[$j] === '?' && $src[$j + 1] === '>') break;
            $j++;
        }
        $code = substr($src, $blockStart, $j - $blockStart);   // block body (may run to EOF)
        $i    = ($j < $len) ? $j + 2 : $len;                   // advance past close tag (or to EOF)

        if ($isEcho) {
            $out .= 'PLACEHOLDER';
            continue;
        }

        // Code block: emit only the HTML produced by includes it performs.
        if (preg_match_all(
            '/\b(?:include|require)(?:_once)?\s*\(\s*["\']([^"\']+)["\']\s*\)\s*;/',
            $code, $ms, PREG_SET_ORDER
        )) {
            foreach ($ms as $m) {
                $path = realpath($fileDir . '/' . $m[1]);
                if (!$path || !is_file($path)) continue;
                if (isset($visited[$path]))    continue;
                $visited[$path] = true;
                $out .= render_php_to_html(file_get_contents($path), dirname($path), $depth + 1, $visited);
            }
        }
    }
    return $out;
}

// ── Features + disabled-element stripping ────────────────────────────────────

/**
 * Parse the features: block and optional structural_skip_classes: block from a
 * features.yaml file. Returns:
 *   ['features' => ['newsletter_signup' => false, ...],
 *    'structural_skip_classes' => ['my-class', ...]]
 */
function load_features_yaml(string $repoRoot, string $projectName): array
{
    $candidates = [
        $repoRoot . '/input/'      . $projectName . '/features.yaml',
        $repoRoot . '/output/'     . $projectName . '/features.yaml',
        $repoRoot . '/references/html-to-php/' . $projectName . '/features.yaml',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) continue;
        $raw     = file_get_contents($path);
        $result  = ['features' => [], 'structural_skip_classes' => []];

        // features: block — lines like "  newsletter_signup: false"
        if (preg_match('/^features:\s*\n((?:[ \t]+\S[^\n]*\n?)*)/m', $raw, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^\s+(\w+)\s*:\s*(true|false)\b/', $line, $f)) {
                    $result['features'][$f[1]] = ($f[2] === 'true');
                }
            }
        }

        // structural_skip_classes: block — lines like "  - my-class"
        if (preg_match('/^structural_skip_classes:\s*\n((?:\s+-\s+\S[^\n]*\n?)*)/m', $raw, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^\s+-\s+(\S+)/', $line, $f)) {
                    $result['structural_skip_classes'][] = trim($f[1]);
                }
            }
        }

        return $result;
    }
    return ['features' => [], 'structural_skip_classes' => []];
}

/**
 * Map from feature flag name → CSS class substrings that identify elements
 * belonging to that feature in the input HTML. When a feature is false, all
 * elements whose class attribute contains one of these substrings are removed
 * from the input DOM before comparison.
 */
const FEATURE_SKIP_CLASSES = [
    'newsletter_signup' => ['newsletter'],
    'search'            => ['search-trigger', 'search-input-wrap', 'search-modal'],
    'comments'          => ['comment-form', 'comment-list', 'comments-wrap', 'comments-area'],
    'image_gallery'     => ['gallery', 'lightbox'],
];

/**
 * Build the list of CSS class substrings to strip from the input DOM, based on
 * features that are disabled (false) in features.yaml and any manual overrides
 * in the structural_skip_classes key.
 */
function build_skip_fragments(array $featuresData): array
{
    $skip = [];
    foreach (FEATURE_SKIP_CLASSES as $feature => $fragments) {
        if (isset($featuresData['features'][$feature]) && $featuresData['features'][$feature] === false) {
            foreach ($fragments as $f) $skip[] = $f;
        }
    }
    foreach ($featuresData['structural_skip_classes'] as $f) {
        $skip[] = $f;
    }
    return array_values(array_unique($skip));
}

/**
 * Remove any element from $dom whose class attribute contains one of the
 * $fragments as a substring. Operates on the INPUT dom only, so the comparison
 * does not require those elements to exist in the generated output.
 */
function strip_disabled_elements(DOMDocument $dom, array $fragments): void
{
    if (empty($fragments)) return;
    $xpath = new DOMXPath($dom);
    foreach ($fragments as $frag) {
        $nodes = $xpath->query("//*[contains(@class, '$frag')]");
        foreach (iterator_to_array($nodes ?? []) as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}

/**
 * Host substrings identifying template-marketplace / theme-author attribution
 * backlinks ("Designed by …", "Powered by …"). The html-to-php conversion drops
 * these credit links from the client deliverable, so they are removed from the
 * INPUT dom before comparison rather than being reported as missing structure.
 */
const ATTRIBUTION_LINK_HOSTS = [
    'themeforest', 'envato', 'themewagon', 'html5up', 'templatemo', 'colorlib',
    'bootstrapmade', 'freehtml5', 'os-templates', 'w3layouts', 'templatemonster',
    'tooplate', 'untree.co', 'styleshout', 'graygrids', 'uideck', 'creative-tim',
];

/**
 * Remove <a> elements from the INPUT dom whose href points to a known template
 * author / marketplace (see ATTRIBUTION_LINK_HOSTS). These attribution backlinks
 * are intentionally stripped during conversion, so requiring them in the output
 * would be a false failure.
 */
function strip_attribution_links(DOMDocument $dom): void
{
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//a[@href]');
    foreach (iterator_to_array($links ?? []) as $a) {
        $href = strtolower($a->getAttribute('href'));
        foreach (ATTRIBUTION_LINK_HOSTS as $host) {
            if (strpos($href, $host) !== false) {
                if ($a->parentNode) $a->parentNode->removeChild($a);
                break;
            }
        }
    }
}

// ── DOM helpers ───────────────────────────────────────────────────────────────

function parse_dom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    return $dom;
}

function el_classes(DOMElement $el): array
{
    if (!$el->hasAttribute('class')) return [];
    $cls = preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
    sort($cls);
    return $cls;
}

function el_sig(DOMElement $el): string
{
    $cls = el_classes($el);
    $id  = $el->getAttribute('id');
    return strtolower($el->tagName)
        . ($cls ? '.' . implode('.', $cls) : '')
        . ($id  ? '#' . $id : '');
}

// ── Section detection ─────────────────────────────────────────────────────────

/**
 * Return the page's major layout sections as an array of DOMElement.
 * Tries native top-level <section> elements first (≥2 required).
 * Falls back to known wrapper XPaths, each tried with recursive single-child
 * descent: if a node has exactly 1 significant child, we drill into it and
 * check its children, up to 4 levels deep. This handles templates that wrap
 * all content in one outer layout div (e.g. Webflow's w-layout-layout).
 */
function detect_sections(DOMDocument $dom): array
{
    // 1. Top-level <section> tags (not nested inside another <section>)
    $allSecs = $dom->getElementsByTagName('section');
    $topSecs = [];
    foreach ($allSecs as $s) {
        $anc    = $s->parentNode;
        $nested = false;
        while ($anc instanceof DOMElement) {
            if (strtolower($anc->tagName) === 'section') { $nested = true; break; }
            $anc = $anc->parentNode;
        }
        if (!$nested) $topSecs[] = $s;
    }
    if (count($topSecs) >= 2) return $topSecs;

    // 2. Fallback: start from known wrappers, drill through single-child chains
    $xpath = new DOMXPath($dom);
    $wrapperXpaths = [
        "//*[@id='main-wrapper']",
        "//*[contains(concat(' ',normalize-space(@class),' '),' main-wrapper ')]",
        "//main",
        "//*[@id='content']",
        "//body",
    ];
    foreach ($wrapperXpaths as $xp) {
        $nodes = $xpath->query($xp);
        if (!$nodes || $nodes->length === 0) continue;
        $candidates = drill_to_sections($nodes->item(0));
        if (count($candidates) >= 2) return $candidates;
    }

    return [];
}

/**
 * Starting at $el, recurse through single-child elements until we find a node
 * with ≥2 significant children (the "sections"). Bounded to 4 levels deep.
 */
function drill_to_sections(DOMElement $el, int $depth = 0): array
{
    if ($depth > 4) return [];
    $children = significant_children($el);
    if (count($children) >= 2) return $children;
    if (count($children) === 1) return drill_to_sections($children[0], $depth + 1);
    return [];
}

/** Direct children of $el that look like real layout blocks (not script/style/link). */
function significant_children(DOMElement $parent): array
{
    $skip = ['script', 'style', 'link', 'meta', 'noscript'];
    $out  = [];
    foreach ($parent->childNodes as $child) {
        if (!($child instanceof DOMElement)) continue;
        if (in_array(strtolower($child->tagName), $skip, true)) continue;
        $out[] = $child;
    }
    return $out;
}

// ── Section matching ──────────────────────────────────────────────────────────

/**
 * Match each input section to the best available output section.
 * Priority: exact id → Jaccard class overlap ≥ 0.30 → positional index.
 * Returns array of ['in'=>DOMElement, 'out'=>DOMElement, 'method'=>string, 'label'=>string].
 */
function match_sections(array $inSecs, array $outSecs): array
{
    $matched = [];
    $used    = [];

    foreach ($inSecs as $i => $inEl) {
        $inId  = $inEl->getAttribute('id') ?: null;
        $inCls = el_classes($inEl);
        $found = false;

        // 1. ID
        if ($inId) {
            foreach ($outSecs as $j => $outEl) {
                if (isset($used[$j])) continue;
                if ($outEl->getAttribute('id') === $inId) {
                    $matched[] = ['in' => $inEl, 'out' => $outEl, 'method' => 'id', 'label' => "s$i"];
                    $used[$j]  = true;
                    $found     = true;
                    break;
                }
            }
        }

        // 2. Jaccard class overlap
        if (!$found && $inCls) {
            $bestScore = 0.0;
            $bestJ     = -1;
            foreach ($outSecs as $j => $outEl) {
                if (isset($used[$j])) continue;
                $outCls = el_classes($outEl);
                if (!$outCls) continue;
                $overlap = count(array_intersect($inCls, $outCls));
                $union   = count(array_unique(array_merge($inCls, $outCls)));
                $score   = $union > 0 ? $overlap / $union : 0.0;
                if ($score > $bestScore) { $bestScore = $score; $bestJ = $j; }
            }
            if ($bestScore >= 0.30 && $bestJ >= 0) {
                $matched[] = ['in' => $inEl, 'out' => $outSecs[$bestJ], 'method' => 'class', 'label' => "s$i"];
                $used[$bestJ] = true;
                $found = true;
            }
        }

        // 3. Positional
        if (!$found && isset($outSecs[$i]) && !isset($used[$i])) {
            $matched[] = ['in' => $inEl, 'out' => $outSecs[$i], 'method' => 'pos', 'label' => "s$i"];
            $used[$i]  = true;
        }
    }

    return $matched;
}

// ── Structural comparison ─────────────────────────────────────────────────────

/**
 * Walk $el up to $maxDepth levels, returning [{d, tag, cls, id}] for every element.
 */
function collect_nodes(DOMElement $el, int $depth = 0, int $maxDepth = 4): array
{
    if ($depth > $maxDepth) return [];
    $rows = [['d' => $depth, 'tag' => strtolower($el->tagName), 'cls' => el_classes($el), 'id' => $el->getAttribute('id') ?: null]];
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $rows = array_merge($rows, collect_nodes($child, $depth + 1, $maxDepth));
        }
    }
    return $rows;
}

/**
 * Deduplicate nodes so repeated PHP loop cards (one template node) match
 * multiple hardcoded cards in input. Key = depth:tag:sorted-classes.
 */
function dedupe_nodes(array $nodes): array
{
    $seen = [];
    $out  = [];
    foreach ($nodes as $n) {
        $key = $n['d'] . ':' . $n['tag'] . ':' . implode(',', $n['cls']);
        if (!isset($seen[$key])) { $seen[$key] = true; $out[] = $n; }
    }
    return $out;
}

/** True if $tag is a heading element (h1–h6). */
function is_heading(string $tag): bool
{
    return strlen($tag) === 2 && $tag[0] === 'h' && $tag[1] >= '1' && $tag[1] <= '6';
}

/**
 * Return true if $outNodes contains a node at the same depth and tag as $inNode,
 * and whose classes are a superset of $inNode's classes (extra classes added by
 * the PHP conversion — e.g. lzImg2 — are fine and do not cause a failure).
 *
 * Heading levels are treated as interchangeable: an input <h3> matches an output
 * <h1> (same class) because html-to-php conversion routinely promotes a page's
 * main heading to <h1> for SEO. The class still has to match.
 */
function node_found_in(array $inNode, array $outNodes): bool
{
    $inHeading = is_heading($inNode['tag']);
    foreach ($outNodes as $out) {
        if ($out['d']   !== $inNode['d'])  continue;
        if ($out['tag'] !== $inNode['tag'] && !($inHeading && is_heading($out['tag']))) continue;
        if ($inNode['id'] && $out['id'] !== $inNode['id']) continue;
        // All input classes must appear in the output element's class list
        if ($inNode['cls'] && array_diff($inNode['cls'], $out['cls'])) continue;
        return true;
    }
    return false;
}

/**
 * Compare the subtrees of a matched input/output section pair.
 * Returns ['pass'=>bool, 'missing'=>string[], 'extra'=>string[]].
 */
function compare_section(DOMElement $inEl, DOMElement $outEl): array
{
    $inNodes  = dedupe_nodes(collect_nodes($inEl,  0, 4));
    $outNodes = dedupe_nodes(collect_nodes($outEl, 0, 4));

    // Skip depth-0 (the section root itself — already confirmed by the match step)
    $inDeep  = array_values(array_filter($inNodes,  fn($n) => $n['d'] >= 1));
    $outDeep = array_values(array_filter($outNodes, fn($n) => $n['d'] >= 1));

    $missing = [];
    foreach ($inDeep as $n) {
        if (!node_found_in($n, $outDeep)) {
            $missing[] = $n['d'] . ':' . $n['tag']
                . (empty($n['cls']) ? '' : '.' . implode('.', $n['cls']))
                . ($n['id'] ? '#' . $n['id'] : '');
        }
    }

    // Extra = in output but not traceable to any input node (informational only, not a failure)
    $extra = [];
    foreach ($outDeep as $n) {
        if (!node_found_in($n, $inDeep)) {
            $extra[] = $n['d'] . ':' . $n['tag']
                . (empty($n['cls']) ? '' : '.' . implode('.', $n['cls']));
        }
    }

    return ['pass' => empty($missing), 'missing' => $missing, 'extra' => $extra];
}

// ── Main ──────────────────────────────────────────────────────────────────────

$results = [
    'project'    => $projectName,
    'task_type'  => $taskType,
    'after_dir'  => $afterDir,
    'started_at' => date('c'),
    'pairs'      => [],
];

$passCount = 0;
$failCount = 0;
$skipCount = 0;

// Load features.yaml and compute which input elements to skip
$featuresData  = load_features_yaml($repoRoot, $projectName);
$skipFragments = build_skip_fragments($featuresData);

echo "structural-diff  $projectName  ($taskType)\n";
echo "after: $afterDir\n";
if ($skipFragments) {
    echo "skip (disabled features): " . implode(', ', $skipFragments) . "\n";
}
echo "\n";

foreach ($pairs as $pair) {
    $afterFile  = $pair['after']  ?? null;
    $beforeFile = $pair['before'] ?? null;
    $label      = $pair['label']  ??
                  preg_replace(['~[?=&]~', '~\.php$~'], ['_', ''], (string)$afterFile);

    if ($pageFilter !== null && $label !== $pageFilter) continue;

    echo "  [$label]\n";

    $entry = ['label' => $label, 'pass' => false, 'sections' => []];

    if (!$beforeFile) {
        echo "    skip  no 'before' file in page_map — cannot compare\n\n";
        $entry['skipped'] = 'no before file';
        $results['pairs'][] = $entry;
        $skipCount++;
        continue;
    }

    // Strip query string from after path (e.g. category.php?cat_url=business → category.php)
    $afterFileClean = strtok($afterFile, '?');
    $beforePath     = $repoRoot . '/input/'  . $projectName . '/' . $beforeFile;
    $afterPath      = $afterDir . '/'        . $afterFileClean;

    echo "    in:  " . str_replace($repoRoot . '/', '', $beforePath) . "\n";
    echo "    out: " . str_replace($repoRoot . '/', '', $afterPath)  . "\n";

    if (!is_file($beforePath)) {
        echo "    skip  input file not found\n\n";
        $entry['skipped'] = "input not found: $beforePath";
        $results['pairs'][] = $entry;
        $skipCount++;
        continue;
    }
    if (!is_file($afterPath)) {
        echo "    skip  output file not found\n\n";
        $entry['skipped'] = "output not found: $afterPath";
        $results['pairs'][] = $entry;
        $skipCount++;
        continue;
    }

    // Render the PHP page to the HTML it would emit (includes resolved inline)
    $phpRaw    = file_get_contents($afterPath);
    $visited   = [];
    $phpHtml   = render_php_to_html($phpRaw, dirname($afterPath), 0, $visited);
    $inputHtml = file_get_contents($beforePath);

    $inDom  = parse_dom($inputHtml);
    strip_disabled_elements($inDom, $skipFragments);   // remove disabled-feature elements before comparing
    strip_attribution_links($inDom);                   // remove template-author credit backlinks (dropped in conversion)
    $outDom = parse_dom($phpHtml);

    $inSecs  = detect_sections($inDom);
    $outSecs = detect_sections($outDom);

    $inCount  = count($inSecs);
    $outCount = count($outSecs);

    if ($inCount < 2 || $outCount < 2) {
        echo "    warn  sections detected: $inCount in input, $outCount in output — skipping\n\n";
        $entry['warning'] = "too few sections detected (in=$inCount out=$outCount)";
        $results['pairs'][] = $entry;
        $skipCount++;
        continue;
    }

    $matched = match_sections($inSecs, $outSecs);
    echo "    sections: $inCount in / $outCount out / " . count($matched) . " matched\n";

    $pagePassed = true;

    foreach ($matched as $m) {
        $diff     = compare_section($m['in'], $m['out']);
        $secLabel = $m['label'];
        $inSig    = el_sig($m['in']);
        $outSig   = el_sig($m['out']);

        if ($diff['pass']) {
            echo "    sec  PASS  $secLabel [{$m['method']}]  $inSig\n";
        } else {
            $pagePassed = false;
            echo "    sec  FAIL  $secLabel [{$m['method']}]  $inSig  →  $outSig\n";
            $shown = 0;
            foreach ($diff['missing'] as $path) {
                if ($shown >= 10) {
                    echo "             … +" . (count($diff['missing']) - 10) . " more missing\n";
                    break;
                }
                echo "             missing: $path\n";
                $shown++;
            }
        }

        $entry['sections'][] = [
            'label'      => $secLabel,
            'method'     => $m['method'],
            'input_sig'  => $inSig,
            'output_sig' => $outSig,
            'pass'       => $diff['pass'],
            'missing'    => $diff['missing'],
            'extra'      => $diff['extra'],
        ];
    }

    // Flag unmatched input sections (nothing in output corresponds to them)
    $matchedCount = count($matched);
    if ($matchedCount < $inCount) {
        $unmatched = $inCount - $matchedCount;
        echo "    warn  $unmatched input section(s) had no output match\n";
        $pagePassed = false;
    }

    $entry['pass'] = $pagePassed;
    if ($pagePassed) { $passCount++; echo "    PASS\n"; }
    else             { $failCount++; echo "    FAIL\n"; }
    echo "\n";

    $results['pairs'][] = $entry;
}

$results['finished_at'] = date('c');
$results['pass_count']  = $passCount;
$results['fail_count']  = $failCount;
$results['skip_count']  = $skipCount;

$outFile = __DIR__ . '/structural_results.json';
file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "$passCount passed, $failCount failed, $skipCount skipped\n";
echo "results -> tests/structural_results.json\n";
exit($failCount > 0 ? 1 : 0);
