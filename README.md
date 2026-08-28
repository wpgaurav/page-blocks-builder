# Page Blocks Builder

Build, reuse, and place custom HTML/CSS/JS sections anywhere in WordPress — through a visual Gutenberg block, a full-page frontend builder, a reusable block library, theme regions, a shortcode, and a REST API. Works with any theme; pairs especially well with a minimal "blank hybrid" theme that delegates its layout to Page Blocks regions.

Originally part of Marketers Delight's Page Blocks dropin (2018–2026, © Kolakube), now a standalone plugin.

**Requires:** WordPress 6.0+, PHP 8.0+ · **Current version:** 2.6.0

---

## What's in the box

| Area | What you get |
|---|---|
| **Gutenberg block** | `gt-page-block/page-block` — an HTML/CSS/JS section with a live, theme-styled preview right in the editor |
| **Visual builder** | Full-page frontend builder with section list, CodeMirror editors, live preview, and AI generation |
| **Library** | Reusable blocks stored in a custom table, managed in a card-grid admin panel |
| **Theme building** | Assign library blocks to theme regions/hooks; themes render them with one function call |
| **Shortcode** | `[page_block id="…"]` / `[page_block slug="…"]` |
| **REST API** | `pbb/v1` — full CRUD, duplicate, and server-render endpoints |
| **WP-CLI** | `wp gt-pb migrate-blocks` (block names) and `wp gt-pb migrate-library` (dropin library import) |

---

## The Page Block (block editor)

Insert **Page Block** (category: *Page Blocks*) into any post or page.

### Preview mode (default when the block has content)
- Renders your section in an **auto-sizing iframe** with the active theme's stylesheet, so the canvas shows what visitors will see.
- Header bar with HTML/CSS/JS/PHP badges and quick actions.
- **Responsive presets**: desktop / tablet (768px) / mobile (390px).
- **Dark-scheme toggle**: sets `data-theme="dark"` inside the preview document (works with themes that support that convention).
- **Save to library** (admins): promote the inline block to a reusable library block.
- When *Execute PHP* or *wpautop* is enabled, the preview is **server-rendered**, so PHP output previews accurately.

### Code mode
- Three CodeMirror tabs (HTML / CSS / JS) with line numbers, code folding, bracket matching, undo/redo isolation from Gutenberg, and `Tab` indentation.
- **Theme class autocompletion**: type `class="…"` in the HTML tab and press `Ctrl/Cmd+Space` — suggestions are scanned from your active theme's stylesheets.
- Automatic PHP syntax mode when the HTML contains `<?php`.
- **Collapsible live preview pane** beneath the editor, re-rendering ~400ms after you stop typing, with the same viewport/dark controls.
- Copy button for the active tab.

### Browse library
The block toolbar's **Browse library** button opens a searchable modal of published library blocks, with two ways to use one:

- **Copy code** — pulls the block's full code and settings into this Page Block. The copy is independent from then on.
- **Link** — points this Page Block at the library row (`blockId`). The code stays in one place and every linked placement updates together.

### Linked blocks
A Page Block with a non-zero `blockId` renders the library row instead of its own attributes, and the editor reflects that:

- The header bar shows the **library block's title, ID, and badges**, not the block's own (empty) code.
- The preview comes from the library's `/render` route, so PHP, shortcodes, and `wpautop` run exactly as they will on the front end.
- The HTML/CSS/JS editors are **hidden**, and the inspector's settings are read-only — edits there would be discarded at render time.
- **Edit in library** opens the source block; **Change** re-points the link; **Unlink** copies the library code into this block's own attributes and drops the reference.
- A link whose target is missing or unpublished is called out in place, rather than silently rendering nothing.

Blocks migrated from the Marketers Delight dropin arrive already linked, so this is how those sections appear.

### Block settings (inspector)
- **JavaScript Location**: footer (default) or inline.
- **WordPress formatting (wpautop)**: run content through `the_content`-style formatting.
- **Execute PHP code**: run PHP in the block's HTML on the front end (see [Security](#security-notes)).

On a linked block these mirror the library row and are disabled — unlink to control them here.

---

## Visual builder

A full-page frontend builder for editing all Page Block sections of a post in one place:

- Launch from the admin bar on any enabled post type, or directly: `/?build=page-blocks&post_id={ID}&pb_nonce={nonce}`.
- Sections map 1:1 to `gt-page-block/page-block` blocks in `post_content` — Gutenberg remains the source of truth; other blocks are preserved in place.
- CodeMirror editors with Emmet expansion, live preview with your theme's CSS, page-template switching, and frontend-preview handoff.
- Sections linked to a library block round-trip their link and render through it; their editors are locked, since edits there would never reach the front end.
- **AI generation** (optional): bring your own OpenAI / Anthropic / Gemini API key (Settings page); generate or edit a section's HTML — bundled `<style id="ai-generated">` / `<script id="ai-generated">` tags are split into the CSS/JS editors automatically.

Enabled post types are configured under **Page Blocks → Settings**.

---

## Library

**Page Blocks → (main screen)** is a REST-driven card grid:

- **Live preview thumbnails** — lazy-loaded, scaled iframes rendering each block's HTML+CSS with your theme stylesheet.
- Search (debounced), status tabs (All / Published / Drafts / Trash) with counts.
- Per-card actions: **Edit**, **Duplicate**, **Copy shortcode**, **Trash** — plus **Restore** / **Delete forever** in Trash.
- Chips for HTML/CSS/JS/PHP, draft status, and 📍 assigned position.
- The classic `WP_List_Table` view remains available at `?view=list` (bulk operations).

Library blocks live in a dedicated table (`{prefix}gt_page_blocks`) with title, slug, status, code fields, output options, position, and priority.

---

## Theme building

Library blocks can be **placed into the theme** in two ways, both driven by the block's *Position* setting:

### 1. Theme regions (`region:*`)

Assign a block to a region — Header, Hero, Before content, After content, Sidebar, Footer, 404 — and render it from your theme:

```php
<?php gt_pb_region( 'header' ); ?>

<main>
	<?php /* the loop */ ?>
</main>

<?php gt_pb_region( 'footer', array( 'wrap' => false ) ); ?>
```

- `gt_pb_region( string $name, array $args = [] )` — renders all published blocks assigned to `region:$name`, ordered by priority. By default output is wrapped in `<div class="gt-pb-region gt-pb-region--{name}">`; pass `'wrap' => false` to disable.
- `gt_pb_has_region( string $name ): bool` — for conditional markup.
- A minimal hybrid theme can be little more than region calls — header, footer, hero, and 404 content all become editable Page Blocks.

### 2. Hook positions

Blocks can hook directly into core actions with no theme changes at all: `wp_head`, `wp_body_open`, `wp_footer`, `loop_start`, `loop_end`, `get_header`, `get_footer`, `get_sidebar`, plus **Before/After The Content** (applied via `the_content` on singular main-query content).

Extend the position list with the `gt_pb_positions` filter:

```php
add_filter( 'gt_pb_positions', function ( $positions ) {
	$positions['region:announcement'] = 'Theme region: Announcement bar';
	return $positions;
} );
```

---

## Shortcode

```
[page_block id="42"]
[page_block slug="cta-footer"]
```

Renders a published library block (HTML + scoped CSS/JS, honoring its output settings). Unpublished blocks render nothing.

---

## REST API (`pbb/v1`)

| Method | Route | Description |
|---|---|---|
| `GET` | `/wp-json/pbb/v1/blocks` | List blocks — `search`, `status`, `page`, `per_page` (≤100), `orderby`, `order`, `context` (`full` default; `summary` returns id/title/slug/status/position/php_exec/`has_content`/`has_css`/`has_js`/updated_at without code payloads) |
| `POST` | `/wp-json/pbb/v1/blocks` | Create a block (`title` required) |
| `GET` | `/wp-json/pbb/v1/blocks/<id>` | Get one block |
| `PUT/PATCH` | `/wp-json/pbb/v1/blocks/<id>` | Update fields (incl. `status` — restoring from trash) |
| `DELETE` | `/wp-json/pbb/v1/blocks/<id>` | Trash; `?force=true` deletes permanently |
| `POST` | `/wp-json/pbb/v1/blocks/<id>/duplicate` | Duplicate (slug + "(Copy)" title) |
| `GET` | `/wp-json/pbb/v1/blocks/<id>/render` | Server-rendered output (`html`, `css`, `js`) |

- List responses include `X-WP-Total`, `X-WP-TotalPages`, and status-count headers (`X-PBB-Published`, `X-PBB-Drafts`, `X-PBB-Trash`).
- Item fields: `id`, `title`, `slug`, `status`, `content`, `css`, `js`, `js_location`, `output`, `php_exec`, `format`, `position`, `priority`, `created_at`, `updated_at`.
- Permissions: read = `edit_posts`, write = `manage_options`. Authenticate with a standard `X-WP-Nonce` or application password.

---

## Migrating from `marketers-delight/page-block` (≤ 2.4.0)

Version 2.6.0 renamed the block to **`gt-page-block/page-block`**. Nothing breaks on upgrade:

- The legacy name stays registered server-side, so existing content keeps rendering.
- In the editor, legacy blocks appear as *Page Block (legacy)* (hidden from the inserter) with a one-click **transform** to the new block.

To rewrite stored content permanently:

- **Admin:** Page Blocks → Settings → **Tools** → *Dry run* / *Migrate blocks* (shows the pending count).
- **WP-CLI:** `wp gt-pb migrate-blocks [--dry-run]`

The migration rewrites all serialized delimiter forms with exact-name boundaries, updates the database directly (post modified dates and `save_post` side effects are untouched), and cleans post caches. Both dropin blocks are covered: `marketers-delight/page-block` and `marketers-delight/inline-page-block`.

---

## Migrating off the Marketers Delight Page Blocks dropin

Moving a site from the theme dropin to this plugin takes two migrations, in this order.

**1. Import the library** — copies `{prefix}md_page_blocks` into `{prefix}gt_page_blocks`.

- **Admin:** Page Blocks → Settings → **Tools** → *Import from dropin*
- **WP-CLI:** `wp gt-pb migrate-library [--dry-run] [--overwrite]`

Row IDs are preserved. That is the whole point: `[page_block id="187"]` shortcodes and the `blockId` attribute on every placed block reference those numbers, so a re-keyed import would silently blank live sections. The import is idempotent — rows whose ID already exists are skipped unless you pass `--overwrite`.

Two things are rewritten on the way in:

- **PHP checksums** are recomputed from the imported content, so PHP-enabled blocks stay executable under the checksum gate below.
- **Positions** move from the dropin's `md_hook_*` theme hooks to plugin positions and theme regions (for example `md_hook_footer_top` → `region:footer`). An `md_hook_*` key with no equivalent is cleared to shortcode/block only and reported, because that hook never fires once the theme is gone — a block left pointing at it would be invisible with no explanation.

**2. Rewrite the block names** — `wp gt-pb migrate-blocks`, as above.

Then deactivate the dropin. Both plugins register `[page_block]`, so run them side by side only long enough to migrate.

### PHP execution

PHP in page blocks is off unless the site opts in, and then only for content whose save-time checksum still matches:

```php
// wp-config.php
define( 'GT_PB_ALLOW_PHP', true );        // MD_ALLOW_PHP_SNIPPETS is honoured too
define( 'GT_PB_ALLOW_INLINE_PHP', true ); // inline blocks only; off by default
```

Inline blocks live in `post_content` with no separately stored checksum, so a database-only edit to a post body cannot be detected — hence the second, separate constant. Library blocks need only the first. Both gates are overridable with the `gt_pb_can_execute_php` filter, which receives `( $default, $content, $checksum )`.

---

## Settings

**Page Blocks → Settings**

- **Post types**: where the visual builder is available.
- **AI**: OpenAI / Anthropic / Gemini API keys, default model, optional terminal tool.
- **Preview assets**: optional reset/typography/utilities stylesheets, custom preview CSS, `<head>` HTML, and footer JS for the builder preview.
- **Tools**: block-name migration and dropin library import (see above).

---

## Hooks reference

| Hook | Type | Purpose |
|---|---|---|
| `gt_pb_positions` | filter | Add/remove position + region options |
| `gt_pb_can_execute_php` | filter | Gate PHP execution (`bool $can, string $content`) |
| `gt_page_blocks_builder_post_types` | filter | Builder-enabled post types |
| `gt_page_blocks_builder_preview_injection` | filter | Inject `headHtml` / `css` / `jsFooter` into the builder preview (`array $injection, int $post_id`) |
| `gt_pb_class_scan_content` | filter | Filter stylesheet content before class-suggestion scanning |
| `gt_pb_ai_request_timeout` | filter | AI request timeout (`int $seconds, string $provider`) |
| `gt_pb_ai_debug_enabled` / `gt_pb_ai_debug_log_raw_payload` / `gt_pb_ai_debug_max_length` | filter | AI debug logging controls |

### Helper functions

| Function | Purpose |
|---|---|
| `gt_page_blocks_builder_post_types()` | Post types the builder is offered on |
| `gt_page_blocks_builder_url( $post_id, $nonce )` | Front-end builder URL for a post |
| `gt_page_blocks_builder_nonce_action( $post_id )` | Nonce action for builder requests |
| `gt_page_blocks_preview_nonce_action( $post_id )` | Nonce action for preview requests |

### Renamed in 2.7.4

Both filters and all four helper functions were prefixed `md_` before 2.7.4,
after the theme this plugin grew out of. Every old name still works — the
filters run immediately before their replacements, and the functions delegate to
them — so existing snippets keep working. Each raises a pointer to its
replacement under `WP_DEBUG` while in use, and is otherwise silent. Update them
when convenient.

`gt_page_blocks_builder_nonce_action()` still returns its original
`md_page_blocks_builder_<id>` string: it identifies nonces already issued into
open tabs and saved URLs, so renaming the value would invalidate them for no
gain.

---

## Security notes

- **PHP execution** is opt-in per block (*Execute PHP code*). At render time it is gated by the `gt_pb_can_execute_php` filter; when execution is not allowed, PHP tags are stripped rather than printed. Only grant block-editing access to users you'd trust with code execution, and consider tightening the filter for your environment.
- The block editor's live preview runs your block's JS inside an isolated iframe; PHP previews are rendered server-side through the same gate as the front end.
- REST writes require `manage_options`; AJAX endpoints are nonce-protected.

---

## Installation

1. Download `page-blocks-builder-vX.Y.Z.zip` from [Releases](https://github.com/wpgaurav/page-blocks-builder/releases) (or clone into `wp-content/plugins/page-blocks-builder`).
2. Activate **Page Blocks Builder**.
3. Visit **Page Blocks → Settings**: choose builder post types and (optionally) add an AI key.
4. Add a **Page Block** in the editor, or create reusable blocks under **Page Blocks**.

## Changelog

See [readme.txt](readme.txt) for the full changelog, and [Releases](https://github.com/wpgaurav/page-blocks-builder/releases) for tagged builds.

## License

GPL-2.0-or-later. Portions © Kolakube (Marketers Delight Page Blocks dropin, 2018–2026).
