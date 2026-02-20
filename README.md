# Page Blocks Builder (Standalone Plugin)

This plugin ports the Page Blocks visual builder into a standalone WordPress plugin that works with any theme. Originally part of Marketers Delight's Page Blocks dropin (2018-2026, (c) Kolakube).

## Features

- Gutenberg dynamic block: `marketers-delight/page-block`
- Frontend visual builder route: `/?build=page-blocks&post_id={ID}&pb_nonce={nonce}`
- Frontend-only builder launch from admin bar
- Source of truth remains Gutenberg Page Block blocks
- Server-rendered preview support for `wpautop` and PHP execution
- Child + parent theme CSS loaded into builder preview via `wp_head` and theme asset capture
- Post type allowlist settings page
- Preview injection filter support:
  - `md_page_blocks_builder_preview_injection`

## Installation

1. Copy `page-blocks-builder` to `/wp-content/plugins/page-blocks-builder`.
2. Activate **Page Blocks Builder**.
3. Open **Settings -> Page Blocks Builder** and choose enabled post types.

## Changelog

### 1.3.4

- Fix OpenAI GPT-5 model compatibility by omitting custom `temperature` for `gpt-5*` models
- Add provider debug diagnostics (finish reason, refusal summary, usage) for AI responses
- Add optional raw payload logging via filters for AI troubleshooting

### 1.3.3

- Improve OpenAI response parsing for newer output shapes used by GPT-5 models
- Return a clear error when AI output is empty instead of silently doing nothing

### 1.3.2

- Lock AI Generate to HTML mode in visual builder and ignore non-HTML text selections
- Prevent HTML from being emptied when AI returns only bundled `<style id="ai-generated">` / `<script id="ai-generated">` tags

### 1.3.1

- Default AI generation target to the HTML tab in the visual builder
- Add HTML AI bundle support with `<style id="ai-generated">` and `<script id="ai-generated">`
- Move bundled AI CSS/JS out of HTML and into the CSS/JS editors automatically

### 1.2.1

- Add "Preview on Frontend" button in the visual builder topbar to open the live page in a new tab
- Make "Add Section" button more prominent with a full-width dashed button below the section list
- Fix "Invalid page template" error when saving on block themes (TT4, etc.)

### 1.2.0

- Add CSS-in-head collection: all Page Block CSS is now combined into a single `<style>` tag in `<head>` at `template_redirect`, preventing FOUC
- Add external file output (`output: file`): CSS/JS can be written to cacheable files in `wp-content/uploads/gt-page-blocks/`
- Add `output` block attribute (`inline` or `file`)
- Add `save_post`/`delete_post` hooks to regenerate and clean up external asset files
- Add JS file collection at `template_redirect` for external JS serving
- Add transient caching for theme class suggestions (keyed by file modification times)
- Combine footer scripts into a single `<script>` tag
- Fix CSS minification breaking `>=` in media query range syntax
- Fix `sanitize_css` stripping `<=` operators by replacing `wp_strip_all_tags` with CSS-safe sanitization

### 1.1.3

- Initial standalone release with Gutenberg block, visual builder, settings page, Rank Math integration, page templates

## Notes

- Block editor overlay launcher is intentionally disabled in this standalone version.
- Use the frontend builder from the admin bar while viewing a singular post/page.
