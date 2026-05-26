# Using `create_project.php`

The CLI that drives the agent end-to-end: input → Claude → generated PHP → tests.

## Prerequisites

- **XAMPP running** (Apache + MySQL). The repo lives under `c:\xampp\htdocs\` so Apache serves both the generated project and the test runner at `http://localhost/...`.
- **MySQL accessible** as configured in [.env](.env) (`WEBDEV_TEST_DB_*`). Defaults: root / no-password on `127.0.0.1:3306`.
- **Node.js** installed, and `npm install` already run inside [tests/](tests/) (for the visual diff).
- **`.env` populated** with `ANTHROPIC_API_KEY` — required only for `--mode console`. For `--mode claude-code` you instead need the `claude` CLI on PATH (`npm install -g @anthropic-ai/claude-code`).

## One-time setup

```powershell
cd c:\xampp\htdocs\web-dev-automation\tests
npm install
```

## Per-project workflow

### 1. Drop your input project into `input/<name>/`

For an **HTML → PHP** conversion, place the static HTML directly under `input/<name>/`:

```
input/my-blog/
├── index.html
├── about.html
├── contact.html
├── ...
├── features.yaml        # required (see step 2)
├── page_map.json        # required (see step 3)
└── assets/
    ├── css/
    ├── js/
    └── ...
```

For a **mysqli → PDO** refactor, drop the `.php` source the same way (and the same `features.yaml` + `page_map.json` rules apply).

### 2. Create `input/<name>/features.yaml`  *(required)*

Copy [templates/docs/features.yaml.tmpl](templates/docs/features.yaml.tmpl) and fill it in. Every key under `site:` must be populated — the CLI refuses to run if any of `url`, `name`, `website_slug`, `description`, or `main_categories` is empty.

```yaml
site:
  url: "https://www.mysite.com/"
  name: "My Site"
  website_slug: "mysite"             # tenant key (my_web_url) used in queries
  description: "One-line site description."
  main_categories: [business, technology, lifestyle]

features:
  multi_page:        true
  article_listing:   true
  single_article:    true
  sidebar:           true
  categories:        true
  search:            true
  comments:          true
  newsletter_signup: false
  contact_form:      false
  image_gallery:     false
```

References are ranked by feature-flag overlap against this block, so be accurate.

### 3. Create `input/<name>/page_map.json`  *(required)*

Lists every `*.html` in `input/<name>/` and the PHP target it should produce. Template at [tests/fixtures/page_map.example.json](tests/fixtures/page_map.example.json); the canonical real example is [references/html-to-php/ny-mag-ag/page_map.json](references/html-to-php/ny-mag-ag/page_map.json).

```json
{
  "pairs": [
    { "before": "index.html",       "after": "index.php",                                              "label": "home" },
    { "before": "about.html",       "after": "about.php",                                              "label": "about" },
    { "before": "blog-details.html","after": "blogs_on/blog_details.php?blog_url=sample-article",     "label": "single-article" }
  ]
}
```

**Every `*.html` file in the input must appear as a `before` somewhere in `pairs`.** If any is unmapped, the CLI refuses to run and lists which files are missing.

### 4. Run the CLI

```powershell
php create_project.php my-blog
```

What it does, in order:

1. Verifies `input/my-blog/features.yaml` and `input/my-blog/page_map.json` exist.
2. Validates every `site.*` field is populated.
3. Detects the task type (`html-to-php` vs `mysqli-to-pdo`) — `--type` overrides.
4. Verifies every input `*.html` is covered by the page_map.
5. Loads `skills/<task-type>/SKILL.md`, the top 3 ranked references' docs, and every `templates/snippets/*.tmpl`.
6. Builds the prompt. With `--dry-run` the CLI stops here and writes `.last-prompt.json`.
7. **Backend validation:**
   - `--mode console` (default): pings `GET /v1/models` to verify `ANTHROPIC_API_KEY` works. Fails fast on bad/expired keys.
   - `--mode claude-code`: locates the `claude` CLI on PATH (checks common npm install paths too). Fails fast if not installed.
8. **Confirmation:** prints a summary (project, task, site, features, page_map, references, snippets, backend, model, prompt size) and waits for `y`/`yes`. `--yes` skips this.
9. Calls the chosen backend, parses the JSON envelope, writes files into `output/my-blog/`, runs the `asset_copies` from the agent's response, then **always copies every file from [resources/](resources/) into the standard project paths** — overwriting any conflicting agent output.
10. Runs `php tests/run_tests.php my-blog` and `node tests/visual_diff.js my-blog`. First visual-diff run captures golden snapshots into `references/<task>/my-blog/golden/`; subsequent runs diff against them.

## Choosing the backend (`--mode`)

| Mode | Billing | Requires |
|---|---|---|
| `console` *(default)* | Pay-per-call against `ANTHROPIC_API_KEY` (your Claude Console balance). | `.env` with a valid `ANTHROPIC_API_KEY`. |
| `claude-code` | Counts against your local Claude Code subscription (Pro/Max). | `claude` CLI on PATH. Install with `npm install -g @anthropic-ai/claude-code`. |

The two modes produce the same output. `console` is the most reliable; `claude-code` reuses your subscription and avoids API charges but requires the CLI binary.

## Flags

| Flag | What it does |
|---|---|
| `--type <html-to-php\|mysqli-to-pdo>` | Force task type instead of auto-detecting. |
| `--mode <console\|claude-code>` | Backend to use (default: `console`). |
| `--model <model-id>` | Override the model (default: `claude-sonnet-4-6`). |
| `--skip-tests` | Stop after generation; don't run `run_tests.php` or `visual_diff.js`. |
| `--dry-run` | Build the prompt and write `.last-prompt.json`; no API call, no output written. |
| `-y`, `--yes` | Skip the confirmation prompt (non-interactive). |
| `-h`, `--help` | Show usage. |

## Environment variables (read from `.env` or shell)

| Variable | Default | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | — | Claude Console key. Required for `--mode console`. |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-6` | Override the model without `--model`. |
| `ANTHROPIC_MAX_TOKENS` | `16000` | Output token cap per API call. Raise for big projects. |
| `WEBDEV_TEST_DB_*` | see [.env](.env) | Forwarded to `tests/run_tests.php`. |
| `WEBDEV_BASE_URL` | _(derived)_ | Full HTTP URL to the project root, e.g. `http://localhost/web-dev-automation/output/my-blog`. Set this when the derived URL is wrong (Linux, custom DocumentRoot, etc.). Overrides `XAMPP_HTDOCS`. |
| `XAMPP_HTDOCS` | `/opt/lampp/htdocs` (Linux) / `c:/xampp/htdocs` (Windows) | Filesystem path to Apache's htdocs directory. Used to derive the base URL when `WEBDEV_BASE_URL` is not set. |

## Resources (always from `resources/`)

Every project gets the **same** set of brand/template assets, copied from [resources/](resources/) into the project's canonical paths *after* the agent's files are written:

```
resources/favicon.{ico,png,jpg,svg}   -> assets/images/icons/favicon.{ico,png,jpg,svg}
resources/square-logo.{png,jpg,svg}   -> assets/img/logo/square-logo.{png,jpg,svg}
resources/rect-logo.{png,jpg,svg}     -> assets/img/logo/rect-logo.{png,jpg,svg}
resources/error-404.jpg               -> error-404.jpg
```

The agent is instructed **not** to emit these — the orchestrator handles them. If you want custom favicons/logos across all projects, swap them in `resources/`.

## Output layout

```
output/my-blog/                      # the generated PHP project
references/html-to-php/my-blog/      # auto-created on first successful visual_diff
└── golden/                          #   (the rest of the reference folder is built by
    └── *.png                        #    update_references.php later)
tests/results.json                   # last run_tests.php report
tests/visual_results.json            # last visual_diff.js report
tests/screenshots/my-blog/*.png      # this run's screenshots
tests/diff/my-blog/*.png             # diff PNGs (only on mismatch)
.last-prompt.json                    # written only on --dry-run
.last-response.txt                   # written only when the API response can't be parsed
```

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `error: input/<name>/features.yaml not found` | Step 2 above wasn't done. Copy the template and fill it in. |
| `error: input/<name>/page_map.json not found` | Step 3 above wasn't done. Copy the example and fill it in. |
| `error: features.yaml site block has empty fields: ...` | Fill in every key under `site:` — none can be blank. |
| `error: page_map.json does not map every input HTML. Unmapped: ...` | Add a `pairs[]` entry for each unmapped HTML file. |
| `error: could not detect task type from input` | The input has neither `.html` files nor `mysqli_*` calls. Pass `--type` to force one. |
| `error: API key validation failed — HTTP 401: invalid or revoked key` | Rotate the key in Claude Console and update `.env`. |
| `error: --mode claude-code requires the \`claude\` CLI on PATH` | `npm install -g @anthropic-ai/claude-code`, or switch to `--mode console`. |
| `error: response had no usable 'files' field` | Claude didn't return JSON in the expected shape. Raw response saved to `.last-response.txt`. Likely causes: output was truncated (raise `ANTHROPIC_MAX_TOKENS`) or the model added prose. Inspect and re-run. |
| Test failures on first run | Often a real bug in the generated project. Open `tests/results.json` and look at `output/<name>/includes/database.php` — `$db_name` must be `web-dev-automation` (the canonical test DB). |
| `visual_diff` reports everything as `captured_new` | Expected — the very first visual diff run on a project captures the golden. Re-run to get real pass/fail. |

## Inspecting the prompt before spending tokens

```powershell
php create_project.php my-blog --dry-run
# inspect .last-prompt.json
```

This is the cheapest way to confirm references are being ranked sensibly and snippets are loading. No API call, no confirmation needed.

## Re-blessing visual goldens

After an intentional layout change, the golden snapshots need updating:

```powershell
$env:VISUAL_DIFF_REGENERATE = "1"; node tests/visual_diff.js my-blog
Remove-Item Env:VISUAL_DIFF_REGENERATE
```

Inspect the resulting goldens before committing.
