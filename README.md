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
| **WP-CLI** | `wp gt-pb migrate-blocks` for the 2.6.0 block-name migration |

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
The block toolbar's **Browse library** button opens a searchable modal of published library blocks — *Insert copy* pulls a block's full code and settings into the current Page Block.

### Block settings (inspector)
- **JavaScript Location**: footer (default) or inline.
- **WordPress formatting (wpautop)**: run content through `the_content`-style formatting.
- **Execute PHP code**: run PHP in the block's HTML on the front end (see [Security](#security-notes)).

---

## Visual builder

A full-page frontend builder for editing all Page Block sections of a post in one place:

- Launch from the admin bar on any enabled post type, or directly: `/?build=page-blocks&post_id={ID}&pb_nonce={nonce}`.
- Sections map 1:1 to `gt-page-block/page-block` blocks in `post_content` — Gutenberg remains the source of truth; other blocks are preserved in place.
- CodeMirror editors with Emmet expansion, live preview with your theme's CSS, page-template switching, and frontend-preview handoff.
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
| `GET` | `/wp-json/pbb/v1/blocks` | List blocks — `search`, `status`, `page`, `per_page` (≤100), `orderby`, `order` |
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

The migration rewrites all serialized delimiter forms with exact-name boundaries, updates the database directly (post modified dates and `save_post` side effects are untouched), and cleans post caches.

---

## Settings

**Page Blocks → Settings**

- **Post types**: where the visual builder is available.
- **AI**: OpenAI / Anthropic / Gemini API keys, default model, optional terminal tool.
- **Preview assets**: optional reset/typography/utilities stylesheets, custom preview CSS, `<head>` HTML, and footer JS for the builder preview.
- **Tools**: block-name migration (see above).

---

## Hooks reference

| Hook | Type | Purpose |
|---|---|---|
| `gt_pb_positions` | filter | Add/remove position + region options |
| `gt_pb_can_execute_php` | filter | Gate PHP execution (`bool $can, string $content`) |
| `md_page_blocks_builder_post_types` | filter | Builder-enabled post types |
| `md_page_blocks_builder_preview_injection` | filter | Inject `headHtml` / `css` / `jsFooter` into the builder preview (`array $injection, int $post_id`) |
| `gt_pb_class_scan_content` | filter | Filter stylesheet content before class-suggestion scanning |
| `gt_pb_ai_request_timeout` | filter | AI request timeout (`int $seconds, string $provider`) |
| `gt_pb_ai_debug_enabled` / `gt_pb_ai_debug_log_raw_payload` / `gt_pb_ai_debug_max_length` | filter | AI debug logging controls |

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
