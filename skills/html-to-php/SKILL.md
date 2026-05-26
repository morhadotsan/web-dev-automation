# HTML → PHP Skill

_Last synthesized: 2026-05-20_
_References used: 1 (ny-mag-ag)_

Master methodology for converting a clean HTML template into a structured, PDO-backed PHP website. Synthesized from per-project `SKILL.md` files in `references/html-to-php/`.

Pattern confidence prefixes:
- **[Confirmed]** — observed in 3+ references.
- **[Emerging]** — observed in 1–2 references. With only one reference (ny-mag-ag) on file, **every pattern below is currently `[Emerging]`** and should be re-evaluated as more projects land.

---

## Page Boilerplate

**[Emerging] (ny-mag-ag)** — Every public PHP page in the project root starts with this exact block, in this order:

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
<!DOCTYPE html>
<html class="no-js" lang="en">
<head>
<?php include("./includes/head.php"); ?>
</head>
<body class="mobilemenu-active">
    <?php include("./includes/preloader.php"); ?>
    <div id="main-wrapper" class="main-wrapper">
        <?php include("./includes/header.php"); ?>
        <!-- page-specific <section> blocks here -->
        <?php include("./includes/footer.php"); ?>
    </div>
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

**[Emerging] (ny-mag-ag)** — fixed include order inside `<body>`:

1. `includes/preloader.php` — first thing inside `<body>` (it's the back-to-top anchor, despite the name).
2. `includes/header.php` — top trending bar (queries N random blogs), logo, nav, search trigger. Depends on `$con`, site-slug global, site URL, brand title.
3. (page-specific `<section>` markup)
4. `includes/footer.php` — "Follow Us" tile grid + copyright. Depends on social handles and brand globals.

After the main wrapper closes (sibling of header/footer, not child):

5. `includes/section-4.php` — search modal markup + **every** `<script>` tag in load order. Pages never load scripts inline.

Inside `<head>`:

- `includes/head.php` — composes `<meta>`, `<title>`, OG, Twitter, favicons, CSS. Always included, never replaced. Internally includes `includes/meta.php` (`$pgMeta` dictionary) and `includes/google_tags.php`.

Page-specific fragments:

- `includes/section-1.php` / `section-2.php` / `section-3.php` — home-only sections; included between header and footer in `index.php`.
- `includes/side-bar.php` — included in a `col-lg-4` next to a `col-lg-8` content column on archive and detail pages.

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

- **Reorganize `assets/media/` (input)** into `assets/images/icons/` (favicons) and `assets/img/{logo,other}/` (logos + chrome).
- **CSS and JS keep original names and paths**: `assets/css/{app.css, fonts/icomoon.css, vendor/…}`, `assets/js/{app.js, vendor/…, sweetalert.min.js}`.
- **Cross-site image hosting** for content images — blog and user images live on a sibling site (e.g. `$reverbURL.reverb_images/blog_images/<file>`), not inside this project's `assets/`. The local `assets/img/` is only for logos and template chrome.
- **Standardized logo + favicon paths** — the orchestrator copies `resources/` to these exact destinations after each stage. Generated PHP MUST reference only these paths (never the input-template's original logo filenames):

  | Resource         | Destination in output                      | Usage                                      |
  |------------------|--------------------------------------------|--------------------------------------------|
  | `favicon.ico`    | `assets/images/icons/favicon.ico`          | `<link rel="shortcut icon">`               |
  | `favicon.png`    | `assets/images/icons/favicon.png`          | `<link rel="shortcut icon">`               |
  | `favicon.jpg`    | `assets/images/icons/favicon.jpg`          | OG/Twitter image fallback                  |
  | `favicon.svg`    | `assets/images/icons/favicon.svg`          | `<link rel="apple-touch-icon">`            |
  | `rect-logo.svg`  | `assets/img/logo/rect-logo.svg`            | Desktop header logo                        |
  | `rect-logo.png`  | `assets/img/logo/rect-logo.png`            | Desktop header logo (PNG fallback)         |
  | `square-logo.svg`| `assets/img/logo/square-logo.svg`          | Mobile header logo                         |
  | `square-logo.png`| `assets/img/logo/square-logo.png`          | Mobile header logo (PNG fallback)          |
  | `rect-logo.svg`  | `website-logo.svg` (project root)          | Sidebar widget, RSS, sitemaps              |
  | `rect-logo.png`  | `website-logo.png` (project root)          | Sidebar widget, RSS, sitemaps (PNG)        |
  | `error-404.jpg`  | `error-404.jpg` (project root)             | 404 page image                             |

  The agent must NOT include any of these in `files` or `asset_copies` — the orchestrator overwrites them regardless.

## Common Mistakes

_Empty — no manual corrections have been aggregated yet. Once derivative projects produce corrections in their `NOTES.md` under "Manual corrections applied", `update_references.php` will aggregate them here._
