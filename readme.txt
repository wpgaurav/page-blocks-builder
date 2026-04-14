=== GT Page Blocks Builder ===
Contributors: gauravtiwari
Tags: page builder, html blocks, css sections, gutenberg, visual builder
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 8.1
Stable tag: 2.0.1
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
