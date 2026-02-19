=== GT Page Blocks Builder ===
Contributors: gauravtiwari
Tags: page builder, html blocks, css sections, gutenberg, visual builder
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build pages with custom HTML, CSS, and JavaScript sections using a visual builder and a native Gutenberg block.

== Description ==

GT Page Blocks Builder lets you create section-based pages with full control over HTML, CSS, and JavaScript. Each section is a Gutenberg block (`marketers-delight/page-block`) with separate code editors for HTML, CSS, and JS.

**Key features:**

* **Gutenberg block** with tabbed code editors and live preview
* **Frontend visual builder** launched from the admin bar on any singular post/page
* **Server-rendered preview** with `wpautop`, shortcode, and PHP execution support
* **External file output** for cacheable CSS/JS served from the uploads directory
* **CSS-in-head optimization** that combines all block CSS into a single `<style>` tag in `<head>`
* **Theme class suggestions** in the HTML editor, extracted from your active theme stylesheets
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
