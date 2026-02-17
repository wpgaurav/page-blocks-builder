# Page Blocks Builder (Standalone Plugin)

This plugin ports the Page Blocks visual builder into a standalone WordPress plugin that works with any theme.

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

1. Copy `standalone-plugins/page-blocks-builder` to `/wp-content/plugins/page-blocks-builder`.
2. Activate **Page Blocks Builder**.
3. Open **Settings -> Page Blocks Builder** and choose enabled post types.

## Notes

- Block editor overlay launcher is intentionally disabled in this standalone version.
- Use the frontend builder from the admin bar while viewing a singular post/page.
