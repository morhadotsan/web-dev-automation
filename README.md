# Web Dev Automation Agent

## Overview

This project is an attempt to leverage the power of `CLAUDE.md` and `SKILL.md` files in building an agent that automates web development programming tasks. The core job of the agent would be to build custom PHP websites / applications guided by reference projects and a defined conversion methodology. The agent handles two input types: **(a)** clean HTML projects that need to be converted into structured PHP websites, and **(b)** existing PHP projects written with `mysqli` that need to be refactored to use PDO, following the methodology established by completed PDO projects in `references/`.

The approach is iterative: I feed the agent finished projects one at a time and for each one, the agent produces a `CLAUDE.md` (capturing the project's structure) and a `SKILL.md` (capturing the coding methodology used). Over time, these accumulated references become the knowledge base that powers the agent's ability to handle new, unseen projects on its own.

## How It Works

For each reference project, two files are generated:

- **`CLAUDE.md`** — holds the complete current project structure (file layout, naming conventions, how pieces connect).
- **`SKILL.md`** — holds the complete coding methodology (patterns, decisions, gotchas, the "why" behind the structure).

The top-level `CLAUDE.md` and the per-task `SKILL.md` files in `skills/` are synthesized from all the per-project files in their matching `references/` subfolder. As more reference projects are added, the top-level files get richer and the agent gets sharper.

## Project Structure

```
web-dev-automation/
├── skills/
│   ├── html-to-php/
│   │   └── SKILL.md                      # methodology for HTML → PHP conversions
│   └── mysqli-to-pdo/
│       └── SKILL.md                      # methodology for mysqli → PDO refactors
├── references/
│   ├── INDEX.md                          # auto-managed list of all references
│   ├── html-to-php/
│   │   ├── ny-mag-ag/
│   │   │   ├── before/                   # original clean HTML input
│   │   │   ├── after/                    # final converted PHP output
│   │   │   ├── CLAUDE.md                 # this project's structure
│   │   │   ├── SKILL.md                  # this project's coding methodology
│   │   │   ├── NOTES.md                  # synthesis hints and manual corrections
│   │   │   └── features.yaml             # blog feature checklist for reference matching
│   │   ├── project-beta/
│   │   └── project-gamma/
│   └── mysqli-to-pdo/
│       ├── legacy-blog/
│       │   ├── before/                   # original mysqli-based PHP
│       │   ├── after/                    # final PDO-refactored PHP
│       │   ├── CLAUDE.md
│       │   ├── SKILL.md
│       │   ├── NOTES.md
│       │   └── features.yaml
│       └── ...
├── templates/
│   ├── snippets/                       # canonical PHP fragments (seeded from references/html-to-php/ny-mag-ag/)
│   │   ├── database.php.tmpl           # PDO connect + env-switched creds + brand globals
│   │   ├── meta.php.tmpl               # $pgMeta dictionary for static-page SEO
│   │   ├── head.php.tmpl               # <head> meta/OG/Twitter/favicons/CSS render
│   │   ├── header.php.tmpl             # site header: trending bar + logo + nav + search trigger
│   │   ├── footer.php.tmpl             # footer follow grid + copyright
│   │   ├── section-4.php.tmpl          # body-bottom: search modal + all <script> tags
│   │   ├── preloader.php.tmpl          # back-to-top anchor (legacy name)
│   │   ├── google_tags.php.tmpl        # empty GA/GTM placeholder
│   │   ├── CSRFProtection.php.tmpl     # drop-in CSRF class
│   │   ├── numbersOnly.php.tmpl        # JS input validators (slug/email/comment/...)
│   │   ├── index-redirect.php.tmpl     # anti-listing stub for non-public subfolders
│   │   ├── functions.php.tmpl          # renderBlogCard, renderPagination, truncate, getIp, ...
│   │   ├── blogRedirects.php.tmpl      # per-slug 301 redirect stub
│   │   ├── page.php.tmpl               # boilerplate for any new public PHP page
│   │   ├── 404.php.tmpl                # standard 404 page
│   │   ├── pageview.php.tmpl           # view-tracking insert (single-article)
│   │   └── paging.php.tmpl             # pagination companion file pattern
│   └── docs/                           # reference documentation templates
│       ├── notes.md.tmpl               # NOTES.md template for new references
│       ├── features.yaml.tmpl          # site metadata + feature checklist template
│       └── skill.md.tmpl               # SKILL.md schema template
├── resources/                          # dummy resources for new projects
│   ├── favicon.{png,jpg,ico,svg}
│   ├── square-logo.{jpg,png,svg}
│   ├── error-404.{jpg}
│   └── rect-logo.{jpg,png,svg}
├── tests/
│   ├── run_tests.php           # main test runner, emits JSON
│   ├── visual_diff.js          # Puppeteer screenshot comparison
│   └── fixtures/               # canned form submissions, sample DB rows
├── input/                      # drop new project folders here (HTML or mysqli PHP)
├── output/                     # generated PHP projects land here
├── create_project.php          # main CLI — converts input to output
├── update_references.php       # adds completed projects as references
├── CLAUDE.md                   # master project structure
├── README.md                   # project overview and how to use this agent via CLI
├── .env                        # API keys (Claude Console, etc.)
└── .gitignore
```

### Folder-by-Folder Breakdown

**`skills/`** — one subfolder per task type the agent handles. `html-to-php/SKILL.md` is the methodology for converting clean HTML into PHP. `mysqli-to-pdo/SKILL.md` is the methodology for refactoring legacy `mysqli` PHP into PDO. Each is continuously rebuilt from the matching `SKILL.md` files in `references/<task-type>/`.

**`references/`** — the agent's memory, split by task type. `references/html-to-php/` holds completed HTML → PHP projects; `references/mysqli-to-pdo/` holds completed mysqli → PDO refactors. Each project subfolder has its own `before/`, `after/`, `CLAUDE.md`, `SKILL.md`, `NOTES.md` (synthesis hints and any manual corrections applied), and `features.yaml` (the blog feature checklist used for reference matching). The `INDEX.md` at the top is auto-managed by `update_references.php` so the agent knows what references are available — and of which type — without having to scan the folders.

**`input/`** — drop zone for a new project. Either a raw HTML folder (triggers the html-to-php pipeline) or an existing `mysqli`-based PHP project (triggers the mysqli-to-pdo pipeline). One project at a time. The agent detects which type it is from the contents, or you can pass an explicit flag to `create_project.php`.

**`resources/`** — template assets (favicons, placeholder logos in multiple formats) that get copied into new projects as sensible defaults. Mostly used by the html-to-php pipeline.

**`templates/snippets/`** — canonical PHP fragments that the agent uses as reusable building blocks instead of writing common boilerplate from scratch. The set is seeded from `references/html-to-php/ny-mag-ag/` (the first reference) and covers connection + globals (`database.php`), per-page SEO (`meta.php`, `head.php`), layout fragments (`header.php`, `footer.php`, `section-4.php`, `preloader.php`, `google_tags.php`), security (`CSRFProtection.php`, `index-redirect.php`, `numbersOnly.php`), helpers (`functions.php`, `blogRedirects.php`), and page-level skeletons (`page.php`, `404.php`, `pageview.php`, `paging.php`). Every snippet uses `{{PLACEHOLDER}}` markers for site-specific values. Primarily consumed by the html-to-php pipeline; the mysqli-to-pdo pipeline leans more on patterns extracted from references than on fresh snippets.

**`templates/docs/`** — documentation templates scaffolded by `update_references.php` whenever a new reference is added. `notes.md.tmpl` provides the three-section structure for `NOTES.md`; `features.yaml.tmpl` provides the `site:` metadata block and the boolean `features:` checklist; `skill.md.tmpl` provides the fixed schema that all synthesized `SKILL.md` files must follow.

**`output/`** — where finished PHP projects land. One folder per converted/refactored project.

**`tests/`** — quality gates. `run_tests.php` is the main runner and emits JSON for easy programmatic checking. `visual_diff.js` uses Puppeteer to screenshot the generated output and compare it against the original (for html-to-php jobs), catching visual regressions. `fixtures/` holds canned form submissions and sample database rows so the generated PHP can be exercised end-to-end.

**`CLAUDE.md`** (root) — master project structure, synthesized from all the per-project `CLAUDE.md` files across both `references/` subfolders.

**`create_project.php`** — the main CLI entry point. Takes arguments to kick off a job (project name, `--type` flag, options, etc.) and orchestrates the agent through the pipeline: detect input type (or read `--type` flag) → load the matching `SKILL.md` from `skills/` → consult relevant references of that type → generate output → place in `output/` → run tests.

**`update_references.php`** — promotes a completed project from `output/` into the correct `references/<task-type>/` subfolder, scaffolds its `CLAUDE.md`/`SKILL.md`/`NOTES.md`/`features.yaml` from the templates in `templates/docs/`, regenerates `INDEX.md`, and triggers a re-synthesis of the master `CLAUDE.md` and the matching `skills/<task-type>/SKILL.md` so the new lessons feed back into the agent.

**`.env`** — API keys (Claude Console API key, etc.). Git-ignored.

## The Feedback Loop

The whole thing is designed around a self-reinforcing cycle:

1. A new project lands in `input/` (HTML folder or `mysqli` PHP project).
2. `create_project.php` runs the agent, which detects the project type, loads the matching `SKILL.md` from `skills/`, and consults relevant references of that type.
3. Generated PHP goes to `output/`, gets tested by `run_tests.php` and (where applicable) `visual_diff.js`.
4. Once a project is verified good, `update_references.php` promotes it into the matching `references/<task-type>/` subfolder with fresh `CLAUDE.md`/`SKILL.md`/`NOTES.md` files.
5. The master `CLAUDE.md` and the matching `skills/<task-type>/SKILL.md` are re-synthesized, incorporating whatever new patterns showed up.
6. The next project of that type benefits from everything learned so far.

The agent gets better the more it is used without anyone having to manually rewrite the methodology each time.

## Synthesis Strategy

The master `SKILL.md` for each task type follows the fixed schema defined in `templates/docs/skill.md.tmpl`, so synthesis is comparing like-with-like across sections rather than merging free-form prose. The schema (the same one used by per-project SKILL.md files) is:

```markdown
## Page Boilerplate          ← exact top-of-file PHP + outer HTML skeleton
## File Structure & Folder Roles
## Database & Globals        ← PDO style, multi-tenant filter, fetch idioms, globals
## Routing & Clean URLs      ← clean-URL → file map, 404 convention
## Page Meta & SEO           ← $pgMeta vs $_neuPg* globals, JSON-LD, robots rules
## Shared Includes           ← include order, fragment roles, path style
## Reusable Helpers          ← renderBlogCard, renderPagination, truncate, ...
## Form Handling             ← CSRF, honeypot, input filtering, POST → Redirect → GET
## Security Conventions      ← session.cookie_httponly, redirect stubs, regex filtering
## Assets                    ← folder split, cross-host images, vendor layout
## Common Mistakes           ← aggregated from per-reference NOTES.md
```

When `update_references.php` triggers re-synthesis, it passes all per-project `SKILL.md` files **and** the "Manual corrections applied" sections from every reference's `NOTES.md` to Claude with this instruction:

> *For each schema section, identify the canonical pattern. If two references handle the same thing differently, document both and note when to use which. Prefix each pattern with `[Confirmed]` if seen in 3+ references, `[Emerging]` if seen in 1–2. Aggregate all manual corrections from NOTES.md files into Common Mistakes. Drop the "Project-specific extras" section — those don't synthesize.*

This keeps the synthesized file self-annotating about certainty and surfaces the agent's recurring blind spots directly from real outputs rather than guesswork.

## `references/INDEX.md` Format

`INDEX.md` is regenerated by `update_references.php` every time a new reference is added. It gives the agent a fast, scannable map of what's available so it doesn't have to walk the folder tree. Suggested format:

```markdown
# References Index

_Last updated: 2026-05-12_

## html-to-php

- **ny-mag-ag** — `references/html-to-php/ny-mag-ag/`
  - Site: NYT Magazine Blog · https://www.newyorktimesmag.com/
  - Notable: multi-page magazine, PDO + multi-tenant filter, clean-URL routing, comment form with CSRF + honeypot, JSON-LD on home and article.
  - Features: `multi_page`, `article_listing`, `single_article`, `sidebar`, `categories`, `search`, `comments`

- **project-beta** — `references/html-to-php/project-beta/`
  - Notable: single-page blog with sidebar and newsletter signup.
  - Features: `newsletter_signup`, `single_article`, `sidebar`

## mysqli-to-pdo

- **legacy-blog** — `references/mysqli-to-pdo/legacy-blog/`
  - Notable: ~40 `mysqli_query` calls converted to prepared PDO statements.
  - Features: `article_listing`, `single_article`, `categories`

- **old-blog** — `references/mysqli-to-pdo/old-blog/`
  - Notable: heavy use of `mysqli_fetch_assoc` loops refactored to `PDO::FETCH_ASSOC` with bound parameters.
  - Features: `article_listing`, `sidebar`, `search`
```

Three things to note about the format:

- **Grouped by task type** so the agent can grab only the section it needs when working on a new job. For an html-to-php input, it ignores the mysqli-to-pdo section entirely (and vice versa).
- **Site metadata** (URL, name, categories) comes from each reference's `features.yaml` under the `site:` key. It is shown in `INDEX.md` for at-a-glance recognition.
- **Features come from `features.yaml`** in each reference folder and are listed in `INDEX.md` for human readability. When `create_project.php` picks references for a new input project, it reads each reference's `features.yaml` directly and ranks by overlap count (and optionally category overlap from `site.main_categories`) — not by string matching against INDEX.md. The full feature vocabulary is defined in `templates/docs/features.yaml.tmpl`.