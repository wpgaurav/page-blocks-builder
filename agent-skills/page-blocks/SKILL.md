---
name: gt-page-blocks
description: "Build and edit pages and reusable sections with GT Page Blocks Builder on a WordPress site through the Site Agent plugin. Use when asked to create a landing page or section in custom HTML, CSS and JavaScript, edit a page-block section on an existing page, or create, update, render or place a reusable library block (shortcode or theme position)."
compatibility: "GT Page Blocks Builder 4.2+ and Site Agent 0.4+ with PHP execution on. Library commands also work through the pbb/v1 REST API with an Application Password."
---

# GT Page Blocks Builder through Site Agent

GT Page Blocks Builder stores each page section as a `gt-page-block/page-block` block that holds
its own HTML, CSS and JavaScript. It can also store reusable sections in a library. A library
section can be placed on many pages, embedded with `[page_block slug="..."]`, or shown in a
theme position such as the header or footer.

Every command here runs `gt_pb_agent()` on the site as the WordPress user the agent is connected
as. Library commands go through the plugin's own REST API. Page commands read and write sections
with WordPress's block parser and serializer. You never hand-write the block comment or its
escaped JSON.

## Running a command

1. Call Site Agent's site-context tool once. Check that `site_url` is the site the user means and
   that `enabled_tools` includes `execute-php`.
2. Call the tool whose name ends in `execute-php`. Put the input in a nowdoc, so quotes, `$` and
   backslashes in your HTML, CSS or JavaScript need no escaping:

```php
return gt_pb_agent('page.sections', json_decode(<<<'JSON'
{"post_id": 42}
JSON, true));
```

Keep the closing `JSON` at the start of its own line. The tool's `return_value` is the result.
Every result has `ok`, and `error` explains a refusal. Pass that message to the user as written.

Site Agent caps a PHP call at 64 KiB, so build a large page one section per call.

| Command | Input | Result |
| --- | --- | --- |
| `context` | none | Plugin version, enabled post types, theme positions, library counts, and whether this user can write the library and save markup |
| `page.sections` | `post_id` | Sections in page order: index, name, linked library block, sizes. Also `content_sha256` and `editable` |
| `page.section` | `post_id`, `index` | One section's full attributes |
| `page.set_section` | `post_id`, `expected_sha256`, `section`, plus `index` (change), `after` (insert), or neither (append), and `publish` | Saves the change. A published post is staged as your autosave unless `publish` is true |
| `page.create` | `title`, `sections` (list), `canvas` | A draft page. `canvas: true` uses the blank-canvas template, with no theme header or footer |
| `section.markup` | `section` | The serialized block markup, to embed elsewhere |
| `blocks.list` | `search`, `status`, `page`, `per_page`, `orderby`, `order` | Library blocks with usage counts |
| `blocks.get` / `blocks.render` | `id` | One library block, or its rendered HTML, CSS and JS |
| `blocks.create` | `block` | A library block. It starts as a draft with no theme position unless you set them |
| `blocks.update` | `id`, `patch` | The updated block |
| `blocks.duplicate` / `blocks.trash` | `id` | A copy, or the block moved to trash |

A `section` takes `name`, `content` (HTML), `css`, `js`, `jsLocation` (`footer`, `header` or
`inline`) and `format`. To place a library block instead of inline code, pass `blockId` and
`blockSlug`. Agents cannot turn on PHP execution. A section that already runs PHP can only be
changed in the block editor.

## Workflows

**New page.** Run `context` first. Write each section as self-contained HTML. Scope its CSS
under a class unique to that section, such as `.acme-hero`, so it can't restyle the theme or
other sections. Use plain JavaScript that waits for its own elements. Then run `page.create`
with `canvas: true` if the page should have no theme header or footer. The page is always a
draft. Give the user its `preview_url` and let them publish it.

**Change a section.**
1. Run `page.sections` and pick the section by name.
2. Run `page.section` to read its code.
3. Run `page.set_section` with the changed fields only, the same `index`, and
   `expected_sha256` set to `content_sha256` from step 1.
4. Run `page.sections` again to confirm the change.

If the page changed in between, the save is refused, so read it again and retry. On a published
page the change is staged as your autosave. Publish it with `publish: true` only when the user
asks for the live page to change.

**Reusable library block.** Run `blocks.list` with a `search` first, to avoid creating a
duplicate. Then run `blocks.create`. Place the block on a page with `page.set_section` and
`section: {"blockId": ID, "blockSlug": "slug"}`, or with the shortcode. Setting `position` and
`status: publish` makes it appear site-wide, so ask the user first.

## Ask before

- Changing a published page (`publish: true`).
- Publishing a library block, or giving it a theme position.
- Trashing a library block. `blocks.list` shows how many pages use it.

Drafts, staged autosaves and reads need no approval.

## Without Site Agent

Library commands map to REST routes under `/wp-json/pbb/v1/blocks`. Reading needs a user who
can edit posts; writing needs an administrator. Use an Application Password over HTTPS. Page
sections are post content, so edit them in the block editor.
