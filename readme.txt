=== GT Page Blocks Builder ===
Contributors: gauravtiwari
Tags: page builder, html blocks, css sections, gutenberg, visual builder
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 8.1
Stable tag: 2.8.1
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

== Upgrade Notice ==

= 2.8.1 =
Security release. Fixes privilege escalation in the block preview (any Author could execute PHP on sites with PHP blocks enabled) and restores certificate verification on the update channel. If you have PHP blocks turned on, update now. Requires PHP 8.1.

== Changelog ==

= 2.8.1 =
Security release. Update before anything else.

* PHP in a Page Block no longer runs for users who can merely edit the post. The builder's preview endpoint is reachable by anyone with `edit_post` — an Author, or a Contributor on their own draft — and it executed the section's PHP after deriving the content checksum from the very content it was about to run, so the check was satisfied by definition. On any site that had turned PHP blocks on, that was the site-wide constant standing alone as the only gate. Running PHP in a preview now requires administrator access, and everyone else previews with the tags stripped and a note saying so rather than silently different output.
* The update channel verifies certificates again. It was requesting with `sslverify` off, and the server's reply supplies the `package` URL WordPress downloads and installs a plugin from, so anything able to answer as the licence server could have installed arbitrary code. Certificates are now verified, the request goes through `wp_safe_remote_post()`, and any `package`, `url` or `homepage` pointing somewhere other than the licence server's own host is discarded rather than followed. A host with a genuinely broken CA bundle can opt out per-site with `GT_PB_LICENSE_INSECURE`.
* The changelog the update screen renders is escaped before display.
* The plugin declares what it needs: `Requires PHP: 8.1`, `Requires at least: 6.0`, a licence and a text-domain path. It previously declared none, so WordPress offered the update to sites that would fatal on it, and the update payload separately claimed PHP 7.4 while the code has needed 8.1 since 2.7. A site below 8.1 now gets an admin notice naming the versions instead of a white screen.
* The preview endpoint checks the post type, matching the builder, instead of answering for post types the builder itself will not open.

= 2.8.0 =
* The library shows what each block looks like. Every thumbnail was an empty frame: the preview document was escaped into an HTML attribute by a helper that handles &, < and > but not quotes, so each one was cut off at the quote in its own charset tag. Blocks with no markup to render — CSS-only token blocks, PHP-only blocks — say so instead of showing an empty rectangle, and thumbnails render at a desktop width and scale down, so a section built for 1200px shows the layout it actually produces.
* Each block reports how many posts and pages place it, counted across both the editor block and the shortcode. That is the number that tells you whether a block is safe to delete. Counted in a single pass and cached, so a library of two hundred blocks does not mean two hundred table scans to draw one screen.
* Bulk selection with duplicate, trash, restore and delete forever; sorting by recently updated, title, most used or least used; and a grid/list switch that remembers which you prefer. The shortcode is now a chip you can see and click to copy rather than an action that copied it silently.
* Import in Page Settings is two buttons, add or replace, instead of one dialog whose Cancel meant "replace every section". Only replace confirms, and it says how many blocks the builder cannot rebuild are about to go.
* A Build button in the block editor saves the page and opens it in the visual builder. The builder reads from the database, so saving first is what keeps unsaved editor changes from being silently absent.
* The library and settings screens now follow the admin around them: WordPress' own status filters, search box, view switcher and row actions, real section headings instead of headings faked with table cells, and no inline styles left in the settings template.
* Fixed: block toolbar buttons were two different heights, 26px and 29.6px, because one of them was never given a height — it inherited whatever the editor's line-height produced, and WordPress 7 changed that. Both are pinned now, and the icons are 18px rather than 14px.
* Fixed: the settings screen still documented the old md_ filter name for preview injection. It shows gt_page_blocks_builder_preview_injection.

= 2.7.5 =
* Fixed: the section panel vanished off the right edge once the AI assistant had filled the code editors. Nothing was hiding it — the builder had grown wider than the window, and the panel is the last column of that row. The code area was reporting its own content width as a minimum, which with the assistant open kept the whole builder above 1552px; a 14-inch laptop is 1512. It now fits from 1920px down to 1000px with the panel fully visible, and the tag-snippet toolbar scrolls inside its own bar instead.
* Import in Page Settings is two buttons, Import & add and Import & replace. It was one button behind a dialog whose Cancel meant "replace every section" — the destructive choice sitting on the dismissive button. Only replace asks for confirmation, and it says how many blocks the builder cannot rebuild are about to be removed.
* Page Settings opens with a count of what is on the page, reports the result of an import or export inline instead of through a browser alert, and stays open while you work. Exported files are named after the page rather than its post ID.
* The AI model list keeps only the GPT-5.6 family: Sol, Terra and Luna. A site still set to an older model falls back to the default rather than sending one the API would reject.

= 2.7.4 =
* The two builder filters and the four helper functions now carry the `gt_` prefix, matching everything else the plugin exposes: `gt_page_blocks_builder_preview_injection`, `gt_page_blocks_builder_post_types`, `gt_page_blocks_builder_url()`, `gt_page_blocks_builder_post_types()`, `gt_page_blocks_builder_nonce_action()` and `gt_page_blocks_preview_nonce_action()`.
* Every old `md_`-prefixed name still works. The filters run immediately before their replacements, so the current one has the last word, and the functions delegate to theirs. Each points at its replacement under `WP_DEBUG` while in use and is silent otherwise, so existing snippets keep working and nobody gets a notice for a hook they never touched.
* `gt_page_blocks_builder_nonce_action()` still returns its original string. It identifies nonces already issued into open builder tabs and saved URLs, and renaming the value would invalidate them for nothing.

= 2.7.3 =
* Page settings live in one dialog. Title, slug, template, import and export are page-level rather than section-level, so they sit together behind one button instead of being spread across the top bar and the section panel. The slug shows the permalink it will produce, and WordPress' own sanitised result is adopted after saving rather than what was typed.
* Sections get an id. Where the outermost element has none, the builder writes one in — an id you wrote yourself is never touched. The edit is made on the opening tag as text rather than by reparsing the markup, so quote style, self-closing tags and indentation survive, a `>` inside an attribute value does not end the tag early, and `data-id=` is not mistaken for `id=`. Sections named after a generated id are listed by their first ten characters of text instead.
* Every section drags, from anywhere on its row. Reordering was HTML5 drag-and-drop on a row covered by buttons, and a mousedown on a form control does not start its draggable ancestor's drag — in practice only a 14px grip worked. Locked and linked sections reorder too, a drop can land past the last row, and the drop point is shown while dragging.
* Clicking anything in the preview selects the section it belongs to, without disturbing the inline edit the same click opens.
* The AI assistant applies its own answers. A reply lands in the section and the preview updates, rather than arriving as code to copy into the editor beside it; bundled CSS and JS are unpacked into their own panes, so one prompt can fill all three. Undo restores the section exactly. A "Whole page" toggle sends every section's code with the prompt, so generated markup matches the class names, spacing and variables already on the page.
* Latest AI models, and one list behind them. The model list was duplicated in four places and had already drifted — the registered default and the request-path fallback named different models, so a saved choice could be silently replaced. GPT-5.6 Luna is the new default, alongside the Claude 5 family.
* Locked blocks can be deleted from the section panel. Locked means the builder will not rewrite a block's markup, not that the block has to stay on the page. It asks first, and the save guard that refuses to drop blocks the builder cannot rebuild stays exactly as strict. Their empty code panes are hidden.
* The builder keeps its own palette on any theme. It renders on a front-end route, so the active theme's stylesheet loads beside it — and themes style bare `select`, `input`, `textarea`, `pre` and `details` under a `[data-theme]` ancestor, which outranked the builder's own rules. Controls were wearing the theme's dark palette while the chrome around them stayed light, a tiled dropdown arrow filled the model picker with black triangles, and stray label and textarea margins pushed controls off their rows. The preview iframe had the mirror-image problem: a transparent body took the operating system's dark canvas while the theme supplied its light-mode text.
* Detach a copy is a real button again, with a broken-link icon. It carried two class names this plugin has never defined and fell through to the browser's default.
* Plainer names in the code toolbar: wpauto is Auto-format, PHP is Run PHP, and the bare "JS:" prefix is a Script label. The AI composer sends on Enter, so its button is an arrow.

= 2.7.2 =
* Editor previews mount only while near the viewport. Each preview is a full document carrying the theme's CSS — measured at ~25 stylesheets and ~2,200 rules to style ~30 elements — so a page of eight blocks kept roughly 200 stylesheets and 17,600 rules live at once, and scrolling paid for all of it. On a 12-block page at most 3 frames are now mounted instead of 12.

= 2.7.1 =
* Code editors work again inside the block canvas. wp.codeEditor loads into the admin document, but the canvas has been an iframe since WP 6.3, so a CodeMirror mounted there resolved focus and key events against the wrong document: it rendered correctly and could not be clicked into or typed in. The plain textarea is now used there, as core's Custom HTML block does.
* Previews are theme-accurate on any theme: they load theme.json global styles (presets and base element styles) alongside every theme stylesheet, so `var()` resolves the way it does on the front end instead of silently falling back.
* The CSS editor suggests the custom properties the active theme actually defines, harvested from its stylesheets and theme.json rather than a fixed list.
* Block editor UI now actually renders. Since WP 6.3 the block canvas is an iframe, and styles enqueued for the editor only reach the outer admin document — so the block's own chrome shipped unstyled (badges ran together, buttons were browser defaults, the device-preview controls were blank squares with no dashicons). The stylesheet is now registered as the block's `editor_style`, which is what gets it into the canvas.
* Redesigned that chrome to match the WordPress admin: its palette, 2px radii, system font, and standard primary/secondary buttons, with the gradients, glows and pill shapes removed.
* Block editor previews load the full theme stylesheet set, as the visual builder already did. Loading only style.css left blocks previewing unstyled on themes that split their CSS across modular files.
* The block header wraps instead of clipping, so the trailing action stays reachable in a narrow canvas.

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
* Fixed `minify_css()` collapsing the whitespace around `+` inside `calc()`, `clamp()`, `min()` and `max()`. The space is required there, so `clamp(6.75rem, 6rem + 2.2vw, 9rem)` became invalid and the whole declaration was dropped — section padding computed to 0 and clamped font sizes fell back to inherited. `+` is still collapsed in sibling selectors, where that is correct.
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
