=== GT Page Blocks Builder ===
Contributors: gauravtiwari
Tags: page builder, html blocks, css sections, gutenberg, visual builder
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 8.1
Stable tag: 2.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build pages with custom HTML, CSS, and JavaScript sections using a visual builder and a native Gutenberg block.

== Description ==

GT Page Blocks Builder lets you create section-based pages with full control over HTML, CSS, and JavaScript. Each section is a Gutenberg block (`marketers-delight/page-block`) with separate code editors for HTML, CSS, and JS.

**Key features:**

* **Gutenberg block** with tabbed code editors and live preview
* **Frontend visual builder** launched from the admin bar on any singular post/page
* **AI chat sidebar** with multi-turn conversation (OpenAI, Anthropic, Gemini)
* **Inline text editing in preview** — click any heading, paragraph, link, or list item to edit it directly
* **Live preview patching** — no flicker on edits, only structural changes trigger full reload
* **HTML snippet buttons** — quick-insert sec, div, h1-h3, p, a, img, ul, ol, span, b, i with selection wrapping
* **Section management** — drag-and-drop reorder, duplicate, delete, hide, rename (double-click)
* **Export/Import** — JSON download/upload with append or replace modes
* **Keyboard shortcuts** — Cmd+S save, Cmd+K AI, Cmd+N add section, and more
* **Page template switcher** — change post template from the builder sidebar
* **Preview customization** — add custom CSS, head HTML, footer JS via settings (no PHP filter needed)
* **Server-rendered preview** with `wpautop`, shortcode, and PHP execution support
* **External file output** for cacheable CSS/JS served from the uploads directory
* **CSS-in-head optimization** that combines all block CSS into a single `<style>` tag in `<head>`
* **Theme class suggestions** in HTML/CSS editors, extracted from your active theme stylesheets
* **Theme CSS context for AI** — CSS variables and utility classes sent to AI as system context
* **Post type allowlist** under Settings > Page Blocks Builder
* **Page templates** for full-width builder layouts
* **Rank Math SEO integration** for content analysis of Page Block content

Works with any WordPress theme. No dependencies on any theme framework.

== Installation ==

1. Upload the `page-blocks-builder` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **Settings > Page Blocks Builder** and choose which post types can use the builder.
4. Add a Page Block in the Gutenberg editor, or launch the visual builder from the admin bar on any enabled post/page.

== Frequently Asked Questions ==

= Does this require a specific theme? =

No. GT Page Blocks Builder works with any WordPress theme.

= How do I launch the visual builder? =

Visit any singular post or page on the frontend while logged in. Click "Page Blocks Builder" in the admin bar.

= Can I use PHP in my sections? =

Yes. Enable PHP execution per block. PHP runs on the frontend and in server-rendered previews for administrators.

= What is the "file" output mode? =

When set to "file", CSS and JS for that block are written to external files in `wp-content/uploads/gt-page-blocks/` and served as cacheable resources instead of inline output.

== Changelog ==

= 2.7.0 =
* Dropin migration: `wp gt-pb migrate-library [--dry-run] [--overwrite]` (or Settings -> Tools -> Import from dropin) copies the Marketers Delight `md_page_blocks` table into this plugin **preserving block IDs**, so existing `[page_block id="N"]` shortcodes and `blockId` block references keep resolving.
* Blocks can reference the library again: `blockId` is a registered attribute and renders the library row server-side. Editing one library block updates every placement, instead of each block carrying its own copy.
* The block editor understands the reference: a linked block shows the library block's title, ID, and badges, previews through the library's own render route (so PHP, shortcodes, and wpautop match the front end), and hides the inline HTML/CSS/JS editors that render_block() would ignore. Missing and unpublished targets are called out instead of silently rendering nothing.
* Library modal offers **Link** alongside **Copy code**, so a placement can follow the library or take a one-off copy, deliberately.
* **Unlink** detaches a copy: the library code is written into the block's own attributes and the reference is dropped.
* Fixed two paths that silently unlinked migrated blocks. The editor's client-side block registration did not declare `blockId`, so re-saving a page dropped it; the visual builder normalized it away on load and reserialized every section without it. Both now round-trip the link, and the builder locks a linked section's editors instead of accepting edits that never render.
* `marketers-delight/inline-page-block` is now covered by the block-name migration alongside `marketers-delight/page-block`.
* Security: PHP execution now requires two independent gates — a site constant (`GT_PB_ALLOW_PHP`, or `MD_ALLOW_PHP_SNIPPETS` for sites coming from the dropin) **and** a save-time content checksum. Content mutated directly in the database no longer executes; it falls back to stripping PHP tags. Inline blocks need a further opt-in (`GT_PB_ALLOW_INLINE_PHP` / `MD_ALLOW_INLINE_PHP`) because post_content carries no separate checksum.
* Display conditions work: they are saved from the block edit screen and evaluated when rendering positioned blocks (post types, page types, specific post IDs). Previously the UI existed but nothing was stored or checked.
* Dropin `md_hook_*` positions are remapped to plugin positions and theme regions on import; anything with no equivalent is cleared to shortcode/block only and reported, rather than silently never rendering.
* Schema: adds `php_checksum` (table version 1.1), back-filled for existing PHP-enabled blocks on upgrade.
* Render parity with the dropin, from diffing real output on live sites: library CSS is emitted once per request and hoisted into `<head>` rather than inlined at every placement; library HTML is minified like the inline path already was.
* Fixed `minify_css()` corrupting quoted strings. `[style*="font-weight: 300"]` and `[style*="font-weight:300"]` are different selectors, and collapsing the space merged them so one stopped matching. Quoted strings and `url()` payloads are now preserved verbatim.

= 2.6.0 =
* Theme building: library blocks can be assigned to theme regions (header, hero, before/after content, sidebar, footer, 404) and rendered by any theme via gt_pb_region( 'header' ) / gt_pb_has_region() — a blank hybrid theme can be little more than region calls.
* Hook positions now actually render: wp_head, wp_body_open, wp_footer, loop_start/end, get_header/footer/sidebar, and before/after the content (priority-ordered).
* Block renamed to gt-page-block/page-block (category: Page Blocks). The legacy marketers-delight/page-block stays registered (hidden from the inserter) so existing content keeps rendering, with a one-click transform to the new block.
* Migration tool: Settings -> Tools -> Migrate blocks (with dry run), or WP-CLI `wp gt-pb migrate-blocks [--dry-run]` — rewrites stored content to the new block name without touching post modified dates.
* REST + library: block position and priority are exposed over the API and shown as a chip on library cards.

= 2.5.0 =
* REST API (pbb/v1): full CRUD for library blocks plus /duplicate and /render endpoints — list filtering, search, pagination, and status counts via headers.
* New library admin panel: card grid with live preview thumbnails (lazy-loaded), search, status filter tabs, duplicate / trash / restore / delete, and copy-shortcode — the classic list table remains at ?view=list.
* Block editor: the Page Block defaults to a rendered live preview (auto-sizing iframe with theme styles) when it has content; toggle Preview/Code from the block toolbar.
* Block editor: Browse library — search the library in a modal and insert a copy of any saved block into the current Page Block.
* Responsive preview: desktop / tablet (768px) / mobile (390px) viewport presets, plus a dark-scheme toggle (data-theme="dark").
* Collapsible live preview pane under the code editor — re-renders as you type (server-rendered when PHP/wpautop is on).
* Copy button for the active code tab.
* Save to library: promote an inline block to a reusable library Page Block (admins) straight from the editor.

= 2.4.0 =
* **NEW**: Emmet-style expansion in the HTML editor. Type an abbreviation like `section.hero>h1{Title}+p{Body}+a.btn[href="#"]{Click}` and press Tab to expand into nested HTML. Supports tags, `.class`, `#id`, `[attr="val"]`, `{text}`, `>` (child), `+` (sibling), `*N` (multiply), `$` (numbering).
* Removed HTML class autocomplete (was noisy and unreliable).
* Cursor auto-jumps to the first empty slot after expansion.

= 2.3.1 =
* **FIX**: CSS class autocomplete was broken because PHP sent `cssClasses` as a JSON object (due to `array_unique` key gaps) instead of a JSON array. Added `array_values()` wrapper so `Array.isArray()` passes on the JS side.

= 2.3.0 =
* **NEW**: Typography.min.css toggle — system-font-based typography defaults with responsive heading sizes via `clamp()`, proper measure (65ch), styled lists, blockquotes, code, kbd, tables. ~3KB inlined when enabled.
* Three independent toggles under Settings → Page Blocks → Frontend CSS: Semantic Reset, Typography, Utility Classes.

= 2.2.0 =
* **NEW**: Semantic Reset CSS (~1KB) — modern reset with box-sizing, list defaults, accessible images, prefers-reduced-motion support. Inlined in `<head>` when enabled.
* **NEW**: Utility Classes — ~330 Tailwind-inspired utilities (grid, flex, spacing, typography, colors, borders, shadows, etc. + `sm:/md:/lg:` responsive variants). Frontend inlines ONLY the rules actually used on the page (parses post_content for class names). Builder loads the full set for autocomplete.

= 2.1.0 =
* **NEW**: Top-level "Page Blocks" admin menu (moved out of Settings) with sub-pages: All Page Blocks (list table), Add New, Settings, License.
* **NEW**: Database-backed reusable blocks with `[page_block id="123"]` and `[page_block slug="hero-section"]` shortcodes.
* **NEW**: Admin list table with bulk actions (trash/restore/delete), search, status filtering.
* **NEW**: Admin edit form with Monaco editors for HTML/CSS/JS, live preview, position picker.
* **NEW**: `gt_pb_get_positions()` filter — theme-independent WP core hooks (wp_head, wp_footer, the_content, loop_start, etc.).

= 2.0.2 =
* **CRITICAL FIX**: "Loading Page Blocks Builder..." stuck on screen — template HTML used old element ID (`md-page-block-builder-app`) but the v2.0.0 JS expects new ID (`md-pb-builder-app`). Updated template to match.

= 2.0.1 =
* Fix `block_categories_all` filter signature mismatch
* Fix `esc_attr()` type errors when emitting asset tags (cast post_id and filemtime to string)
* Properly escape stylesheet `id` attribute in external file output
* Remove redundant defensive type checks (PHPStan level 5 cleanup)
* No functional changes — all fixes are internal hardening

= 2.0.0 =
* **MAJOR**: AI chat sidebar replaces inline AI bar — multi-turn conversation with persistent context
* **MAJOR**: Inline text editing in preview — click headings/paragraphs/links to edit them directly
* **MAJOR**: Live preview patching — no more flicker on every edit, only structural changes reload
* **MAJOR**: HTML snippet buttons on HTML editor title bar
* **MAJOR**: Section renaming via double-click on section name
* **MAJOR**: Better SVG icons for hide/duplicate/delete (replaces unicode glyphs)
* **MAJOR**: Keyboard shortcuts overlay (? button)
* **MAJOR**: Export/Import sections as JSON
* **NEW**: Preview customization settings (CSS, head HTML, footer JS) — no PHP filter required
* **NEW**: Theme CSS context sent to AI as system prompt (variables + utility classes)
* **NEW**: HTTPS enforcement on all preview style URLs
* **FIX**: Script injection in preview iframe (post-load via createElement, fixes srcdoc parsing errors)
* **FIX**: Click delegation for SVG icon buttons
* **FIX**: AI generation supports conversation history across all providers (OpenAI, Anthropic, Gemini)
* **CHANGE**: Default AI model changed from gpt-5.2 to claude-sonnet-4-6
* **REMOVED**: Terminal feature (was beta) — replaced with safer features

= 1.3.4 =
* Fix OpenAI GPT-5 model compatibility by omitting custom `temperature` for `gpt-5*` models
* Add provider debug diagnostics (finish reason, refusal summary, usage) for AI responses
* Add optional raw payload logging via filters for AI troubleshooting

= 1.3.3 =
* Improve OpenAI response parsing for newer output shapes used by GPT-5 models
* Return a clear error when AI output is empty instead of silently doing nothing

= 1.3.2 =
* Lock AI Generate to HTML mode in visual builder and ignore non-HTML text selections
* Prevent HTML from being emptied when AI returns only bundled `<style id="ai-generated">` / `<script id="ai-generated">` tags

= 1.3.1 =
* Default AI generation target to the HTML tab in the visual builder
* Add HTML AI bundle support with `<style id="ai-generated">` and `<script id="ai-generated">`
* Move bundled AI CSS/JS out of HTML and into the CSS/JS editors automatically

= 1.2.1 =
* Add "Preview on Frontend" button in the visual builder topbar to open the live page in a new tab
* Make "Add Section" button more prominent with a full-width dashed button below the section list
* Fix "Invalid page template" error when saving on block themes (TT4, etc.)

= 1.2.0 =
* Add CSS-in-head collection: all Page Block CSS is combined into a single `<style>` tag in `<head>`, preventing FOUC
* Add external file output (`output: file`): CSS/JS written to cacheable files in uploads directory
* Add `output` block attribute (`inline` or `file`)
* Add `save_post`/`delete_post` hooks to regenerate and clean up external asset files
* Add JS file collection at `template_redirect` for external JS serving
* Add transient caching for theme class suggestions
* Combine footer scripts into a single `<script>` tag
* Fix CSS minification breaking `>=` in media query range syntax
* Fix `sanitize_css` stripping `<=` operators via CSS-safe sanitization

= 1.1.3 =
* Initial standalone release with Gutenberg block, visual builder, settings page, Rank Math integration, page templates
