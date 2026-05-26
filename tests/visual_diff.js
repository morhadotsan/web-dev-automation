#!/usr/bin/env node
/**
 * Screenshot every page listed in page_map.json, compare against the golden snapshot,
 * write per-page diff PNGs and a JSON summary.
 *
 *   node tests/visual_diff.js <project-name>
 *   VISUAL_DIFF_REGENERATE=1 node tests/visual_diff.js <project-name>
 *
 * Strategy: GOLDEN SNAPSHOTS.
 *   The very first run on a project captures each page's screenshot as the "golden"
 *   and marks the page captured_new (no pass/fail). Subsequent runs pixel-diff the
 *   freshly-rendered page against its golden and pass when mismatch <= threshold.
 *   This catches regressions over time without being fooled by the natural content
 *   differences between a static input HTML and a DB-driven PHP render.
 *
 *   To re-bless every golden (e.g. after an intentional layout change), pass
 *   VISUAL_DIFF_REGENERATE=1.
 *
 * Project resolution order (the "after" candidate):
 *   1) output/<project>/                                (freshly generated)
 *   2) references/html-to-php/<project>/after/          (reference checkpoint)
 *   3) references/mysqli-to-pdo/<project>/after/        (reference checkpoint)
 *
 * Goldens always live at: references/<task-type>/<project>/golden/<label>.png
 *
 * Page mapping: page_map.json is looked up in references/<task-type>/<project>/,
 *   then input/<project>/, then output/<project>/. This lets the script run against
 *   a freshly generated output without the project being promoted to references/ first.
 *
 * Output:
 *   tests/screenshots/<project>/<label>.png            (this run's render)
 *   tests/diff/<project>/<label>.png                   (only on mismatch)
 *   tests/visual_results.json
 *   references/<task>/<project>/golden/<label>.png     (on first run or --regenerate)
 */

const fs   = require("fs");
const path = require("path");
const { PNG } = require("pngjs");
const pixelmatch = require("pixelmatch");
const puppeteer  = require("puppeteer");

const projectName = process.argv[2];
if (!projectName) {
    console.error("usage: node tests/visual_diff.js <project-name>");
    process.exit(2);
}

const repoRoot   = path.resolve(__dirname, "..");
const HTDOCS     = process.env.XAMPP_HTDOCS || "c:/xampp/htdocs";
const REPO_URL   = (process.env.WEBDEV_REPO_URL || "").replace(/\/+$/, "");
const THRESHOLD  = parseFloat(process.env.VISUAL_DIFF_THRESHOLD || "0.05");
const REGENERATE = process.env.VISUAL_DIFF_REGENERATE === "1";
const VIEWPORT   = { width: 1366, height: 900 };

function resolveProject() {
    const candidates = [
        { afterDir: path.join(repoRoot, "output",     projectName),                    taskType: detectTaskType(projectName) },
        { afterDir: path.join(repoRoot, "references", "html-to-php",  projectName, "after"), taskType: "html-to-php" },
        { afterDir: path.join(repoRoot, "references", "mysqli-to-pdo", projectName, "after"), taskType: "mysqli-to-pdo" },
    ];
    for (const c of candidates) {
        if (fs.existsSync(c.afterDir)) return c;
    }
    return null;
}

function detectTaskType(name) {
    // For output/<name>/, look up the task type via where its page_map.json lives.
    if (fs.existsSync(path.join(repoRoot, "references", "mysqli-to-pdo", name, "page_map.json"))) return "mysqli-to-pdo";
    return "html-to-php";
}

function loadPageMap(taskType) {
    // Resolution order: references/ (promoted projects) → input/ (fresh runs) → output/ (fallback)
    const candidates = [
        path.join(repoRoot, "references", taskType, projectName, "page_map.json"),
        path.join(repoRoot, "input",      projectName, "page_map.json"),
        path.join(repoRoot, "output",     projectName, "page_map.json"),
    ];
    for (const p of candidates) {
        if (fs.existsSync(p)) {
            const j = JSON.parse(fs.readFileSync(p, "utf8"));
            return Array.isArray(j.pairs) ? j.pairs : null;
        }
    }
    return null;
}

function urlForAfter(afterDir, afterRel) {
    if (REPO_URL) {
        const relPath = path.relative(repoRoot, afterDir).replace(/\\/g, "/");
        return `${REPO_URL}/${relPath}/${afterRel}`;
    }
    const htdocsNorm = HTDOCS.replace(/\\/g, "/").toLowerCase().replace(/\/$/, "");
    const afterNorm  = afterDir.replace(/\\/g, "/").toLowerCase();
    if (!afterNorm.startsWith(htdocsNorm)) {
        throw new Error(`after dir '${afterDir}' is not under XAMPP htdocs (${HTDOCS}). Set WEBDEV_REPO_URL in .env.`);
    }
    const urlPath = afterNorm.slice(htdocsNorm.length).replace(/^\/+/, "");
    return `http://localhost/${urlPath}/${afterRel}`;
}

async function shoot(page, url, outPath) {
    await page.goto(url, { waitUntil: "networkidle2", timeout: 20000 });
    await page.screenshot({ path: outPath, fullPage: true });
}

function diffPng(goldenPath, currentPath, diffPath) {
    const a = PNG.sync.read(fs.readFileSync(goldenPath));
    const b = PNG.sync.read(fs.readFileSync(currentPath));
    const w = Math.min(a.width,  b.width);
    const h = Math.min(a.height, b.height);
    const aCrop = cropToSize(a, w, h);
    const bCrop = cropToSize(b, w, h);
    const out   = new PNG({ width: w, height: h });
    const mismatch = pixelmatch(aCrop.data, bCrop.data, out.data, w, h, { threshold: 0.1 });
    fs.writeFileSync(diffPath, PNG.sync.write(out));
    const dimsMatch = (a.width === b.width) && (a.height === b.height);
    return {
        mismatchPixels: mismatch,
        totalPixels:    w * h,
        mismatchPct:    mismatch / (w * h),
        dimsMatch,
        goldenDims:  { width: a.width, height: a.height },
        currentDims: { width: b.width, height: b.height },
    };
}

function cropToSize(png, w, h) {
    if (png.width === w && png.height === h) return png;
    const out = new PNG({ width: w, height: h });
    PNG.bitblt(png, out, 0, 0, w, h, 0, 0);
    return out;
}

(async () => {
    const resolved = resolveProject();
    if (!resolved) {
        console.error(`could not locate an after/ for '${projectName}'`);
        process.exit(2);
    }
    const { afterDir, taskType } = resolved;

    const pairs = loadPageMap(taskType);
    if (!pairs) {
        console.error(`no page_map.json found for '${projectName}' (checked references/${taskType}/${projectName}/, input/${projectName}/, output/${projectName}/)`);
        process.exit(2);
    }

    const shotsDir  = path.join(__dirname, "screenshots", projectName);
    const diffDir   = path.join(__dirname, "diff",        projectName);
    const goldenDir = path.join(repoRoot, "references", taskType, projectName, "golden");
    fs.mkdirSync(shotsDir,  { recursive: true });
    fs.mkdirSync(diffDir,   { recursive: true });
    fs.mkdirSync(goldenDir, { recursive: true });

    const launchOpts = { headless: "new", args: ["--no-sandbox"] };
    if (process.env.PUPPETEER_EXECUTABLE_PATH) launchOpts.executablePath = process.env.PUPPETEER_EXECUTABLE_PATH;
    const browser = await puppeteer.launch(launchOpts);
    const page = await browser.newPage();
    await page.setViewport(VIEWPORT);
    // Auto-dismiss any native dialogs (alert/confirm/prompt) so Puppeteer never hangs.
    page.on("dialog", async (d) => { try { await d.dismiss(); } catch (_) {} });

    const results = {
        project:    projectName,
        taskType,
        afterDir,
        goldenDir,
        threshold:  THRESHOLD,
        regenerate: REGENERATE,
        startedAt:  new Date().toISOString(),
        pairs: [],
    };

    for (const pair of pairs) {
        const label    = pair.label || pair.after.replace(/[/?=&]/g, "_").replace(/\.php$/, "");
        const afterUrl = urlForAfter(afterDir, pair.after);
        const currentPng = path.join(shotsDir, `${label}.png`);
        const goldenPng  = path.join(goldenDir, `${label}.png`);
        const diffPngOut = path.join(diffDir,   `${label}.png`);

        const entry = { label, url: afterUrl };
        try {
            await shoot(page, afterUrl, currentPng);

            if (!fs.existsSync(goldenPng) || REGENERATE) {
                fs.copyFileSync(currentPng, goldenPng);
                entry.status = REGENERATE ? "golden_regenerated" : "captured_new";
                entry.pass   = true;
                console.log(`  ${entry.status === "golden_regenerated" ? "REGEN" : "NEW  "}  ${label}  -> ${path.relative(repoRoot, goldenPng)}`);
            } else {
                const d = diffPng(goldenPng, currentPng, diffPngOut);
                entry.mismatchPct    = d.mismatchPct;
                entry.mismatchPixels = d.mismatchPixels;
                entry.totalPixels    = d.totalPixels;
                entry.dimsMatch      = d.dimsMatch;
                entry.pass = d.dimsMatch && d.mismatchPct <= THRESHOLD;
                entry.status = entry.pass ? "match" : "mismatch";
                const dimNote = d.dimsMatch ? "" : `  dims-changed ${d.goldenDims.width}x${d.goldenDims.height} -> ${d.currentDims.width}x${d.currentDims.height}`;
                console.log(`  ${entry.pass ? "PASS " : "FAIL "}  ${label}  mismatch=${(d.mismatchPct * 100).toFixed(2)}%${dimNote}`);
            }
        } catch (e) {
            entry.error  = e.message;
            entry.pass   = false;
            entry.status = "error";
            console.log(`  FAIL   ${label}  error=${e.message}`);
        }
        results.pairs.push(entry);
    }

    await browser.close();
    results.finishedAt = new Date().toISOString();
    results.passCount  = results.pairs.filter(p => p.pass).length;
    results.failCount  = results.pairs.length - results.passCount;
    results.newCount   = results.pairs.filter(p => p.status === "captured_new").length;
    fs.writeFileSync(path.join(__dirname, "visual_results.json"), JSON.stringify(results, null, 2) + "\n");

    const newMsg = results.newCount ? `, ${results.newCount} captured as new goldens` : "";
    console.log(`\n${results.passCount} passed, ${results.failCount} failed${newMsg}. results -> tests/visual_results.json`);
    process.exit(results.failCount > 0 ? 1 : 0);
})();
