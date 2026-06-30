---
name: docmd-docs
description: >
  Maintain the public documentation site for laravel-iam-directory, built with docmd and living in
  docs-site/. Use this skill whenever you work inside docs-site/ — adding or editing pages under
  docs-site/docs/**, touching navigation or plugins in docmd.config.json, changing the brand/theme, or
  keeping the docs in sync with a feature or README change. Covers the layout, the build/check commands, the
  docmd container syntax (NO MDX/JSX), Lucide icons, the semantic search setup, the footer/branding, the
  Cloudflare Pages deploy, the academic page structure, and the known gotchas.
---

# docmd-docs — documentation site for laravel-iam-directory

The docs site is a [docmd](https://docs.docmd.io) static site in `docs-site/`. It is deployed to Cloudflare
Pages from `main` (root `docs-site`, build `npm run build`, output `_site`, Node 20 via `.node-version`).
Public URL: <https://doc.laravel-iam-directory.padosoft.com>.

## Layout

```
docs-site/
  docmd.config.json          # title, url, navigation (sidebar SSOT), theme, plugins
  package.json               # scripts: dev / build / check
  package-lock.json          # lockfileVersion 3 — cross-platform; DO NOT regenerate on Windows, use `npm ci`
  .node-version              # 20
  .gitignore                 # ignores _site/, node_modules/, search cache (keeps .docmd-search/config.json)
  .docmd-search/config.json  # pinned embedding model (committed — skips the interactive wizard in CI)
  assets/favicon.svg, custom.css   # brand teal #0d9488
  scripts/check-no-raw-html.mjs    # CI guard: rejects raw HTML / MDX-like tags and ::: button
  docs/                      # all pages; route mirrors the tree (docs/index.md → /, docs/a/b.md → /a/b)
  _site/                     # build output (git-ignored)
```

## Commands

```bash
cd docs-site
npm ci          # install from the committed lockfile (NOT npm install — keeps the cross-platform lock)
npm run check   # guard: no raw HTML/MDX, no ::: button
npm run build   # build _site/ (also builds the semantic search index)
npm run dev     # local preview
```

`npm run check && npm run build` must be green before you commit doc changes.

## Navigation is the single source of truth

The sidebar is **only** `navigation[]` in `docmd.config.json`. A new page that isn't registered there does
not appear. Add every new page. Icons are [Lucide](https://lucide.dev) names in kebab-case (e.g. `rocket`,
`shield-check`, `workflow`, `book-marked`).

## Content syntax — Markdown + docmd containers, NEVER MDX/JSX

The guard (`scripts/check-no-raw-html.mjs`) fails the build on raw HTML/MDX tags. Use containers only:

| Need | Syntax |
|---|---|
| Callout | `::: callout info "Title" icon:lucide-name` … `:::` (types: info, tip, warning, danger, success) |
| Tabs | `::: tabs` then `== tab "Label"` blocks, close `:::` |
| Steps | `::: steps` then a numbered list; nested body re-indented **3 spaces**; close `:::` |
| Collapsible | `::: collapsible "Title"` (prefix `open` to expand by default) … `:::` |
| Cards | `::: grids` › `::: grid` › `::: card "Title" icon:name` › body › link `[Open →](/path)` › close each `:::` |
| Diagram | a ` ```mermaid ` fenced block |
| Math | KaTeX inline `$…$`, block `$$…$$` (only outside code fences) |

## Plugins (all enabled)

search (semantic), git (repo/editLink/lastUpdated), seo, sitemap, mermaid, math, llms (`llms.txt` +
`llms-full.txt`), analytics (off). `seo`/`sitemap`/`llms` need the root `url`. `git` needs full history in CI
(`fetch-depth: 0`).

## Semantic search

`plugins.search.semantic: true` uses `docmd-search`: embeddings are computed at **build time** with ONNX
Runtime; the browser gets quantized Int8 vectors and does cosine match — 100% client-side. The model is
pinned in `.docmd-search/config.json` (`Xenova/all-MiniLM-L6-v2`) which is **committed** to skip the
interactive model wizard that would otherwise hang CI. `.gitignore` keeps that config but ignores the index
cache. Functional test:
`(sleep 34; echo "a paraphrased query") | node node_modules/docmd-search/dist/bin/docmd-search.js docs`.

## Brand & footer

Teal `#0d9488` (`assets/custom.css`, ecosystem-wide). Footer credits the author and links GitHub + Packagist:
`© Lorenzo Padovani — [Padosoft](https://github.com/padosoft) · [GitHub](…) · MIT`.

## Page structure (academic/senior standard)

Deep pages follow: **Motivation → Theory (KaTeX where apt) → Design + a Mermaid diagram → Data model/contract
→ ADR (Problem → Decision → Consequences, in a `::: collapsible`) → worked example → Gotchas (`::: callout
warning`)**. Write for juniors *and* senior readers. Cite real symbols (`DirectoryProvisioner`,
`GroupMapper`, `protected_roles`, `email_taken_non_directory`) — never invent API.

## Gotchas

1. `docs/index.md` is required (route `/`).
2. `::: button` is **not** a paired block — inside cards use a Markdown link `[Open →](/path)`.
3. Steps bodies re-indent **3 spaces** so nested fences/callouts stay in the item.
4. KaTeX only renders outside code fences.
5. Use the committed `package-lock.json` (v3, with Linux natives for Cloudflare); verify with `npm ci`, don't
   regenerate it on Windows.
6. Every new page MUST be added to `navigation[]`.

## Accuracy rule

This package's docs describe real behaviour: the LDAP-free core, the `DirectoryConnector` seam, authoritative
sync that **revokes** stale directory roles, anti-takeover on email collision (`source = directory`),
`protected_roles`, and fail-closed. If you change a feature, update the matching page in the same change (see
the auto-sync rule in `.claude/rules/rule-docmd-docs-sync.md`).
