# HTML → PHP Skill

_Last synthesized: 2026-05-20_
_References used: 1 (ny-mag-ag)_

Master methodology for converting a clean HTML template into a structured, PDO-backed PHP website. Synthesized from per-project `SKILL.md` files in `references/html-to-php/`.

Pattern confidence prefixes:
- **[Confirmed]** — observed in 3+ references.
- **[Emerging]** — observed in 1–2 references. With only one reference (ny-mag-ag) on file, **every pattern below is currently `[Emerging]`** and should be re-evaluated as more projects land.

---

## Page Boilerplate

**[Emerging] (ny-mag-ag)** — Every public PHP page in the project root starts with this PHP block, in this order:

```php
<?php
ini_set("session.cookie_httponly", 1);
session_start();
include("./includes/database.php");
include("./functions/functions.php");

// EITHER set $pgKey for dictionary-driven meta (static pages):
//   $pgKey = "home";   // → reads $pgMeta["home"] inside head.php
// OR set the per-page meta globals directly (dynamic pages):
//   $_neuPgTitle, $_neuPgKeywords, $_neuPgDesc1, $_neuPgSiteName,
//   $_nuRobotIndex1, $_nuRobotFollow1, $can_pageUrl, $_neuPgImg,
//   $_neuPgArticleSection, $_neuPgUpdatedTime, $_neuPgReadingTime
?>
```

**CRITICAL — the HTML shell (everything after the PHP block) MUST be taken verbatim from the input template.** Do not invent HTML structure:

- The `<html>` tag attributes (e.g. `lang`, `data-wf-*`) come from the input HTML. **Never add `class="no-js"` unless it is in the input.**
- The `<body>` class comes from the input HTML. **Never use `class="mobilemenu-active"` unless the input has it.**
- The outer wrapper `<div>` IDs and classes (e.g. `id="home"`, `class="w-layout-layout home-stack …"`) come from the input HTML. Do not replace them with `id="main-wrapper" class="main-wrapper"`.
- `includes/preloader.php` is only included if the input template contains a preloader or back-to-top element.
- The nesting of `header.php`, page content, and `footer.php` inside the body must mirror the input HTML's structure.

Example (structure varies by project — preserve whatever the input HTML uses):

```html
<!DOCTYPE html>
<html lang="en">
<head>
<?php include("./includes/head.php"); ?>
</head>
<body class="{{BODY_CLASS_FROM_INPUT}}">
    <!-- exact outer wrapper div from the input HTML -->
    <?php include("./includes/header.php"); ?>
    <!-- page-specific <section> blocks here -->
    <?php include("./includes/footer.php"); ?>
    <?php include("./includes/section-4.php"); ?>
</body>
</html>
```

- Subfolder pages use `../includes/…` and `../functions/…` instead of `./…`.
- Single-article detail pages may diverge and build their own `<head>` inline (article-specific OG type + `BlogPosting` JSON-LD).

## File Structure & Folder Roles

**[Emerging] (ny-mag-ag)** — root + subfolder split:

- **Root** holds one `.php` per top-level page. Filename matches the page concept, not the clean URL.
- **`includes/`** — layout fragments and per-request config (anything that renders into a page or sets globals).
- **`functions/`** — pure PHP helpers (functions only, no top-level side effects on load).
- **Per-feature subtrees** when a feature emits non-HTML or multiple files: e.g. `blogs_on/` (single-article detail), `feed/` (RSS), `sitemaps/` (XML sitemaps per content slice).
- **Pagination snippets are separate files** alongside each listing page (e.g. `blog_paging.php`, `cat_paging.php`, `search_paging.php`). Each runs its own `COUNT(*)` and calls `renderPagination()`. Never inline pagination math into the listing page.
- **Every non-public subfolder gets an `index.php`** containing `header("location: ../"); exit();` — applied recursively under `assets/`.
- **Inputs preserved in `before/`** and never edited; all work happens in `after/`.

## Database & Globals

**[Emerging] (ny-mag-ag)** — PDO connection style:

- **Single PDO handle `$con`** defined in `includes/database.php`. utf8mb4, `ATTR_ERRMODE = ERRMODE_EXCEPTION`, followed by `$con->exec("SET NAMES utf8mb4")`.
- **Env-switched credentials** on `$_SERVER["SERVER_NAME"] === "localhost"`. Production credentials inline in the file (no `.env` in the deliverable).
- **Localhost DB name is the literal `"web-dev-automation"`**, not a per-project name. This is the canonical test database that `tests/run_tests.php` drops, recreates, and seeds from `tests/fixtures/reverbtime.sql` + `seed.sql` on every run. Keep it verbatim in every generated `database.php` so the project is testable out of the box without manual edits.
- **`$reverbURL`** is defined immediately after the env-switch block and **before** the PDO try/catch. It is always `"https://www.reverbtimemag.com/"` — the shared cross-site image host for all projects. Never move it inside the if/else.
- **Social media URL construction** — derive from `site.website_slug` in features.yaml. Pattern: `$adm_facebook = "https://www.facebook.com/<website_slug>"`, `$adm_instagram = "https://www.instagram.com/<website_slug>"`, etc. Do not leave generic platform roots without the slug.
- **`$adm_email`** — set to `"admin@<bare_domain>"` where the bare domain is the domain part of `site.url` (e.g. `site.url = "https://www.example.com/"` → `"admin@example.com"`).
- **`$adm_keywords`** — build from `site.main_categories` joined with `", "`, then append `", magazine, blog, articles"`. Example: categories `[business, technology, lifestyle]` → `"business, technology, lifestyle, magazine, blog, articles"`.
- **`$myTopNiche`** — SQL fragment that excludes the site's own main categories from "trending/top" queries. Build from ALL `site.main_categories` in features.yaml: `"AND \`blog_category\` NOT IN ('<cat1>', '<cat2>', ...)"`. This prevents the home page trending bar from looping only over the site's own content.
- **`$greyNiche`** — standard grey-area exclusion list, identical for every project: `"AND \`blog_category\` NOT IN ('cbd', 'casino', 'vape', 'essay-writing', 'relationship', 'beverage')"`. Always include it; never leave it as an empty string.
- **Multi-tenant filter** — every `blog`/`blog_views` query carries `WHERE my_web_url = ?` bound to the site slug global. Never query the blog table without it.
- **Positional `?` placeholders only** — `$stmt = $con->prepare("… ?"); $stmt->execute([$param]);`. No named placeholders.
- **Fetch idioms**:
  - Lists → `while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { … }`.
  - Counts → `(int)$stmt->fetchColumn()` on `SELECT COUNT(*) …`.
  - Existence gates → `if ($stmt->rowCount() >= 1) { … }`.
- **Pre-filter inputs with character-class regex** before binding (`preg_replace("/[^a-z0-9-]/", "", …)` for slugs, etc.) — even though PDO escapes, this keeps shape clean.
- **`LIMIT` uses interpolated integers** (`LIMIT $start_from, $per_page`) after int-casting and regex cleaning. Intentional workaround for PDO LIMIT-binding quirks.
- **Raw SQL fragments as constants** (`$myTopNiche`, `$greyNiche`) defined in `database.php` and string-concatenated into queries. Treat as trusted constants.
- **Globals defined in `database.php`**:
  - DB handle: `$con`
  - Tenant slug: `$wiscoy_webSlug` (or analogous site-slug global)
  - URLs: `$wiscoy_url` (env-switched site base), plus cross-site URLs as needed (e.g. `$reverbURL` for shared image host)
  - Brand: `$shortTitle`, `$adm_email`, `$adm_facebook`, `$adm_twitter`, `$adm_instagram`, `$adm_linkedin`, `$adm_whatsapp`, `$adm_pinterest`, `$adm_desc`, `$adm_keywords`, `$wiscoy_phone1`, `$wiscoy_address`
  - SQL fragments: `$myTopNiche`, `$greyNiche`
  - Helper: `extractCleanUrl($url)` — strips query string to rebuild canonical URLs.

## Routing & Clean URLs

**[Emerging] (ny-mag-ag)** — a `.htaccess` **is shipped** with every deliverable at the project root. It is the sole routing mechanism; no server-level vhost rewrites are required. It must be included in the output. Contents:

- `<IfModule mod_rewrite.c>` wrapper with `RewriteEngine On`.
- Blocks non-GET/POST/HEAD methods → 405.
- Strips trailing slashes → 301.
- Security headers: `X-XSS-Protection "1; mode=block"`, `X-Frame-Options "SAMEORIGIN"`, `X-Content-Type-Options nosniff`, `Strict-Transport-Security "max-age=63072000; includeSubDomains"`, `Referrer-Policy "same-origin"`, `Feature-Policy "geolocation 'self'; vibrate 'none'"`.
- `Options All -Indexes` — disables directory browsing at Apache level (complements `index.php` stubs in every subfolder).
- `<Files .htaccess> Deny from all </Files>` — blocks direct HTTP access to `.htaccess`.
- `ErrorDocument` for 400/401/403/404/500 → `/404-page`.
- Commented-out www-redirect and HTTPS-redirect rules — present but disabled, intended to be toggled for production deployment.

Rewrite rules (canonical clean-URL → file map):

| Clean URL                        | Target                                          |
|----------------------------------|-------------------------------------------------|
| `/`                              | `index.php` (implicit)                          |
| `/home`                          | `index.php`                                     |
| `/about-us`                      | `about.php`                                     |
| `/contact-us`                    | `contact.php`                                   |
| `/privacy-policy`                | `privacy-policy.php`                            |
| `/our-blogs`                     | `our-blogs.php` (supports `?page=N`)            |
| `/search-result`                 | `search.php` (passes `?searchQuery=…` through)  |
| `/404-page`                      | `404.php`                                       |
| `/cat-<slug>`                    | `category.php?cat_url=<slug>`                   |
| `/cat-<slug>-page-<n>`           | `category.php?cat_url=<slug>&page=<n>`          |
| `/auth-<slug>`                   | `single-author.php?auth_url=<slug>`             |
| `/blogs_on/<slug>`               | `blogs_on/blog_details.php?blog_url=<slug>` (real dir — no rewrite needed) |
| `/feed/rss`                      | `feed/rss.php` (real dir — no rewrite needed)   |

**[Emerging] (ny-mag-ag)** — all not-found outcomes use `header("Location: ./404-page"); exit();` (or `../404-page` from subfolders). Never `http_response_code(404)`.

**[Emerging] (ny-mag-ag)** — subdirectory `.htaccess` files for `blogs_on/` and `sitemaps/`. These are **additional** `.htaccess` files inside those subdirectories; they complement but do not replace the root `.htaccess`.

- **`blogs_on/.htaccess`** — enables routing for the single-article subdirectory. Contents: commented-out www-redirect rule, trailing-slash strip → 301 using the full prod URL, the six security headers, `Options All -Indexes`, method block (405), `<Files .htaccess> Deny from all </Files>`, then the main rewrite rule `RewriteRule ^([a-z0-9-/]+)$ blog_details.php?blog_url=$1 [L]`, and `ErrorDocument` entries pointing at `../404-page`. Use the `blogs-on-htaccess.tmpl` snippet; replace `{{DOMAIN}}` with the bare domain from `site.url`.
- **`sitemaps/.htaccess`** — rewrites clean `.xml` URL aliases to the corresponding `.php` sitemap files so robots.txt can reference `.xml` URLs. One `RewriteRule ^<name>\.xml$ <name>.php [L]` per sitemap file in the folder (authors, blog-1, blog-2, category, plus one per `main_category`). Use the `sitemaps-htaccess.tmpl` snippet.

## Static Files: robots.txt and sitemap.xml

**[Emerging] (ny-mag-ag)** — both files are generated during Stage 1 (scaffolding) and are always included in the deliverable.

### robots.txt

Use the `robots.txt.tmpl` snippet as the skeleton. Key rules:
- Global `User-agent: *` block at the top — `Allow: /`, `Disallow: /cgi-bin/`, `Disallow: /functions/`, `Disallow: /includes/`.
- Named sections for: search engines, AI crawlers, SEO tools (all `Allow: /`), blocked bots (all `Disallow: /`).
- Final `# === SITEMAPS ===` section listing every sitemap URL with `.xml` extension (because `sitemaps/.htaccess` rewrites `.xml` → `.php`). Always include:
  - `Sitemap: <prod_url>feed/rss`
  - `Sitemap: <prod_url>sitemap.xml`
  - `Sitemap: <prod_url>sitemaps/authors.xml`
  - `Sitemap: <prod_url>sitemaps/blog-1.xml`
  - `Sitemap: <prod_url>sitemaps/blog-2.xml`
  - One `Sitemap: <prod_url>sitemaps/<category>.xml` per `main_category` from features.yaml
  - `Sitemap: <prod_url>sitemaps/category.xml`

### sitemap.xml

**CRITICAL**: `sitemap.xml` is a **static `<urlset>`** containing the site's main static pages. It is **NOT** a `<sitemapindex>` pointing to sub-sitemaps. Use the `sitemap.xml.tmpl` snippet. Replace `{{PROD_URL}}` with the production URL from `site.url`. Replace `{{LASTMOD_DATE}}` with a recent ISO-8601 datetime (e.g. `2022-06-22T13:07:41+01:00`). Include exactly these four pages: home (`/`), about-us, contact-us, our-blogs. The dynamic blog content is covered by the per-slice sitemaps in `sitemaps/`.

## Sitemaps (sitemaps/)

**[Emerging] (ny-mag-ag)** — one PHP file per content slice, all emitting `application/xml` output. Generated during Stage 3 (aggregates).

### Standard files (every project)

- **`sitemaps/authors.php`** — `SELECT DISTINCT blog_author, MAX(blog_date) as last_date FROM blog WHERE my_web_url = ? GROUP BY blog_author ORDER BY last_date DESC`. Emits `/auth-<slug>` URLs.
- **`sitemaps/category.php`** — `SELECT DISTINCT blog_category, MAX(blog_date) as last_date FROM blog WHERE my_web_url = ? GROUP BY blog_category ORDER BY last_date DESC`. Emits `/cat-<category>` URLs.
- **`sitemaps/blog-1.php`** — first 1,000 articles (LIMIT 0, 1000) ordered `DESC` by `blog_date`, filtered by `$myTopNiche` and `$greyNiche`. Emits `/blogs_on/<slug>` URLs. If the site has >1,000 articles use `blog-2.php` with LIMIT 1000, 1000, etc.
- **`sitemaps/blog-2.php`** — second 1,000 articles (LIMIT 1000, 1000), same filters as blog-1.php.

### Per-category files (one per main_category)

For each category in `site.main_categories` from features.yaml, generate `sitemaps/<category>.php`. Query:
```php
SELECT `url_slug`, `blog_date` FROM `blog` WHERE `my_web_url` = ? AND `blog_category` = '<category>' ORDER BY `blog_date` DESC
```
No LIMIT (all articles for that category). Emits `/blogs_on/<slug>` URLs with `changefreq=monthly, priority=0.7`.

### Query conventions for sitemaps

- Always include `header('Content-Type: application/xml; charset=utf-8');` at the top.
- Always include `include('../includes/database.php');` (no session_start needed for XML output).
- **Use `$myTopNiche` and `$greyNiche`** in `blog-1.php` and `blog-2.php` queries to maintain consistency with page queries. Per-category files do NOT use these fragments (they already filter by category).
- Use `htmlspecialchars()` on all URL values.
- `<lastmod>` uses `date('Y-m-d', strtotime($row['blog_date']))`.

### sitemaps/.htaccess

Must be generated alongside the sitemap PHP files (Stage 3). One `RewriteRule ^<name>\.xml$ <name>.php [L]` per sitemap file. Use the `sitemaps-htaccess.tmpl` snippet and add one line per `main_category`.

## Page Meta & SEO

**[Emerging] (ny-mag-ag)** — two coexisting strategies; `includes/head.php` picks whichever is in scope:

1. **Dictionary lookup** — set `$pgKey = "home"` before `<head>`; `head.php` reads `$pgMeta[$pgKey]` from `includes/meta.php`. Use for stable static pages (`home`, `about`, `contact`).
2. **Direct global assignment** — set the underscore-prefixed globals explicitly. Use for dynamic pages where title depends on a URL param or DB row (`category`, `search`, `our-blogs`, `single-author`, `404`):
   `$_neuPgTitle`, `$_neuPgKeywords`, `$_neuPgDesc1`, `$_neuPgSiteName`, `$_nuRobotIndex1`, `$_nuRobotFollow1`, `$can_pageUrl`, `$_neuPgImg`, `$_neuPgArticleSection`, `$_neuPgUpdatedTime`, `$_neuPgReadingTime`, `$_neuPgType`.

**[Emerging] (ny-mag-ag)** — Pagination robots rule: pages 2+ flip to `$_nuRobotIndex1 = "NOINDEX"`; page 1 stays `INDEX`.

**[Emerging] (ny-mag-ag)** — JSON-LD blocks per page type:
- Home page → three blocks: `LocalBusiness`, `WebSite` (with `SearchAction`), `BreadcrumbList`.
- Single article → two blocks: `BlogPosting`, `WebSite`.
- Other pages → none.

These are not optional; synthesized meta + schema is part of the deliverable.

## Shared Includes

**[Emerging] (ny-mag-ag)** — standard includes and their roles:

1. `includes/preloader.php` — only included if the input template has a preloader or back-to-top anchor element. **Do not include it by default.**
2. `includes/header.php` — the site's nav region (top bar + navbar, or left sidebar menu, etc.). The HTML structure inside this file comes from the input template's nav region. **header.php may include its own outer wrapper div** (e.g. `<div class="w-layout-cell left-side-menu">`) — if the input template's nav is wrapped in a container div, that wrapper belongs inside `header.php`, not added again in the page file. Never add elements (newsletter forms, search bars, trending strips) that are not in the input HTML.
3. (page-specific markup)
4. `includes/footer.php` — the site's footer region. Content comes from the input template's footer. **Do not add a "Follow Us" grid or elaborate social icon section unless the input HTML has one.** Copyright line format and back-to-top href must match the input.

After the page content (sibling of the outer wrapper, or just before `</body>` — match the input HTML):

5. `includes/section-4.php` — **every** `<script>` tag in load order. Pages never load scripts inline. Include only the JS files that exist in the input template. If the input ships `webfont.js`, include a `WebFont.load()` call with the font families the input template loads. **Do not add a search modal or other UI components not present in the input HTML.**

Inside `<head>`:

- `includes/head.php` — composes `<meta>`, `<title>`, OG, Twitter, favicons, CSS. Always included, never replaced. Internally includes `includes/meta.php` (`$pgMeta` dictionary) and `includes/google_tags.php`.

Page-specific fragments:

- `includes/section-1.php` / `section-2.php` / `section-3.php` — home-only sections; included between header and footer in `index.php` when the input has corresponding home-only regions.
- `includes/side-bar.php` — included in a sidebar column on archive and detail pages when the input template has a sidebar.

Path style: root pages use `./includes/…`; subfolder pages use `../includes/…`.

## Reusable Helpers

**[Emerging] (ny-mag-ag)** — defined once in `functions/functions.php`:

- **`renderBlogCard($row, $titleMax = 60)`** — emits the standard Bootstrap article card from a `blog` row. Used everywhere a blog teaser is needed (archives, search results, author page).
- **`renderPagination($totalPosts, $perPage, $baseUrl)`** — emits the pagination `<ul>` (Prev / windowed page numbers / Next). Reads `$_GET['page']`. Each list page has a small `*_paging.php` companion that runs the count and calls this.
- **`truncate($s, $max)`** — word-boundary-aware truncation with `..` suffix. Used inline on titles and excerpts.
- **`number_format_short($n, $precision = 1)`** — view-count formatter (1.2K / 3.4M / 1.5B).
- **`getIp()`** — IP extraction respecting `HTTP_CLIENT_IP` and `HTTP_X_FORWARDED_FOR`. Also cached as `$clientIP` at file load.

Plus a project-side helper in `database.php`:

- **`extractCleanUrl($url)`** — strips query string and rebuilds `https://host/path` for canonical URLs.

## Form Handling

**[Emerging] (ny-mag-ag)** — full recipe (used in the comment form on single-article pages):

1. **CSRF** — `require_once "…/includes/CSRFProtection.php"; $csrf = new CSRFProtection();`, then `<?php $csrf->insertToken(); ?>` inside `<form>`. Server-side: `if (!$csrf->validateToken($_POST["csrf_token"])) { error_log(…); header("Location: …?msg=csrf_token_failed"); exit(); }`.
2. **Submit dispatch by button name** — each submit `<button>` has a unique `name`; the handler gates on `if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["<buttonName>"]))`.
3. **Honeypot** — a `<input type="hidden" class="myComment" />` (no `name`) sits among the real fields. The handler refuses the submission if its expected paired field is non-empty (`if (empty($send_myComment))` → process; else → `header("Location: ../404-page"); exit();`).
4. **Input filtering before DB** (server-side, in the handler), per-field-type:
   - Names → `preg_replace("/[^a-zA-Z0-9-_ ]/", "", …)`.
   - Comments → `preg_replace("/[^a-zA-Z0-9-_ .,'!?]/", "", …)`.
   - Slugs/URLs → `preg_replace("/[^a-z0-9-]/", "", …)`.
   - Emails stay raw (validated client-side via `type="email"` + `emailsOnly` JS).
5. **Session-key dedupe** — before insert, `SELECT … WHERE user_key = ? AND blog_url = ?` using `session_id()` (captured as `$customa_user_agent_id` in `functions.php`). Refuse duplicates with `?msg=comment+already+sent`.
6. **POST → Redirect → GET** — every successful insert ends with `header("Location: …?msg=<status>"); exit();`. Never re-render after POST.
7. **Client-side validators** loaded from `includes/numbersOnly.php` (a `<script>` block: `slugOnly`, `numbersOnly`, `lettersOnly`, `addressOnly`, `licNumbersOnly`, `emailsOnly`, `timeOnly`, `currencyOnly`). Wire via `onkeyup="commentOnly(this)"` etc.

**[Emerging] (ny-mag-ag)** — Contact page convention: when the input has no contact form, the output renders only `tel:` and `wa.me` call/chat cards. Don't synthesize a contact form unless the input project actually has one.

## Security Conventions

**[Emerging] (ny-mag-ag)** — defensive habits across the codebase:

- **`ini_set("session.cookie_httponly", 1)` before every `session_start()`** at the top of every public page.
- **Session is started in three places** (page top, `functions.php`, `CSRFProtection` constructor). Each call is guarded (`session_id()` check or `session_status() === PHP_SESSION_NONE`). Don't deduplicate without verifying include order.
- **Redirect-stub `index.php`** in every non-public subfolder (recursively under `assets/`) — `<?php header("location: ../"); exit(); ?>` — blocks directory listing.
- **Character-class regex filtering** on all `$_GET`/`$_POST` values before use in queries or output, even though PDO escapes. The class is chosen per field type (slug vs name vs comment).
- **CSRF token on every POST form** via the `CSRFProtection` class — no exceptions.
- **Lookup whitelists for table-routing** — e.g. `single-author.php` keeps an in-code `$validAuthURLs` list that routes specific slugs to the `admin` table; everything else goes to `author`. Prevents arbitrary table routing.
- **DB connection errors are caught and echoed to the page** in addition to `error_log`. Diagnostic-by-design; flag before changing.

## Assets

**[Emerging] (ny-mag-ag)** — reorganization from the input template:

- **CSS and JS keep original names and paths**: `assets/css/{app.css, fonts/icomoon.css, vendor/…}`, `assets/js/{app.js, vendor/…, sweetalert.min.js}`.
- **Blog and article images are cross-hosted** — they live on a sibling server and MUST be loaded via `$reverbURL` using the lazy-load pattern. Never reference blog images through `$wiscoy_url` or a local `assets/` path; those files are not on this server and will never load.

  ```php
  <img alt="<?= htmlspecialchars($b_title); ?>"
       class="lzImg2"
       src="<?= $reverbURL."reverb_images/blog_images/lazy-img.png"; ?>"
       data-src="<?= $reverbURL."reverb_images/blog_images/".$b_img; ?>" />
  ```

  `$wiscoy_url."assets/…"` is only for the site's own chrome assets (logos, icons, template images).

- **Alt text must never be empty** — every `<img>` tag must carry a meaningful `alt` attribute. Use `htmlspecialchars($b_title)` for blog cards, author name for author photos, or a descriptive fallback. `alt=""` is forbidden.
- **Logos and favicons come from the input template** — copy them as-is from the input via `asset_copies`. Preserve whatever paths the input template uses; do not rename or relocate them.

## Common Mistakes

**[Emerging] (test-dev-one)** — manual corrections applied after first generation:

- **Adding `class="no-js"` to `<html>`** when the input template doesn't have it. Always take `<html>` attributes from the input HTML verbatim.

- **Adding `class="mobilemenu-active"` (or any other class) to `<body>`** when the input template uses a different class (e.g. `class="body"`). The `<body>` class is not fixed — read it from the input.

- **Including `preloader.php` unconditionally.** Only include it if the input template has a preloader or back-to-top anchor. Many templates do not.

- **Wrapping `<?php include("./includes/header.php"); ?>` in an extra container div** (e.g. `<div class="w-layout-cell left-side-menu">`) in the page file, when that wrapper is already part of the input template's nav region and therefore belongs *inside* `header.php` itself. The page file should include header.php directly; if the input HTML wraps the nav in a container, that container is output by header.php.

- **Adding a newsletter form to `header.php`** when the input HTML's nav region has no newsletter form. Only emit elements that are present in the input.

- **Adding a search modal to `section-4.php`** when the input HTML has no search modal. The search modal in `section-4.php.tmpl` was ny-mag-ag-specific. Only add it if the input template has one.

- **Using the Bootstrap blog card pattern in `renderBlogCard`** (column divs, `assets/img/blog/` image paths, no lazy load). The correct pattern for all projects is the `lzImg2` lazy-load with `$reverbURL`:
  ```php
  <img alt="..." class="lzImg2 {{IMAGE_CLASS}}"
       src="<?= $reverbURL."reverb_images/blog_images/lazy-img.png"; ?>"
       data-src="<?= $reverbURL."reverb_images/blog_images/".$img; ?>" />
  ```

- **Using `id="main-wrapper"` and `class="main-wrapper"` on the outer body div** when the input HTML uses different IDs/classes. Always take wrapper IDs and classes from the input template.

- **Omitting the `WebFont.load()` call** when the input template ships `webfont.js`. If `webfont.js` is in the input's JS folder, include the `WebFont.load({ google: { families: [...] } })` script in `section-4.php` with the font families the input template references.
