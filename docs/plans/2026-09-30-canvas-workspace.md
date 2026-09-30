# Integrated canvas workspace

Date: September 30, 2026. Local development only; plugin version remains 3.1.0.

## Direction

User review rejected embedding the WordPress admin editor as Visual mode. The replacement keeps the original Page Blocks frontend workspace and adds direct selection, inline editing, formatting, media selection, style controls, native layers, and freeform arrangement. Visual/Code switches are local view changes with no save or navigation. The existing CodeMirror editors, AI, section tools, library, page settings, templates, import/export, performance controls, draft recovery, and save conflict checks remain.

## Persistence and compatibility

- New visual sections use WordPress's own block parser, factories, clone operation, and serializer. Supported native blocks are Group, Columns, Column, Heading, Paragraph, Buttons, Button, and Image.
- Geometry is measured from the rendered canvas. The layout solver produces scoped CSS saved in an existing `gt-page-block/page-block` inside the native Group. No canvas geometry tree is saved in metadata or block attributes.
- Source-backed code edits use exact element offsets, including documents whose open ancestors span sections. Generated, linked, formatted, and prototype output is protected from ambiguous source changes.
- Native previews use temporary render attributes for section and block paths, removed with the preview-only render filter. Saved content contains no editor markers or wrappers.
- Freeform drag/resize requires a preview wider than 768px. Smaller previews stack elements in DOM reading order and retain text/style editing. Desktop layout is never regenerated from mobile geometry.
- Prototype conversion remains explicit and undoable in both the canvas and WordPress editor. Existing prototype records continue rendering their saved HTML/CSS until converted.
- Other native and third-party blocks remain in place. Their advanced editing tools are available in the ordinary WordPress editor.

## Reference

Small snapping, edge-clustering, boundary-pinning, nearest-line, and grid-solving routines are adapted from Jamie Marsland's GPLv2-or-later Gogh Editor. See [source attribution](../third-party/gogh-editor.md). Its editor and saved document model are not imported. Icons are a small bundled MIT-licensed subset from the user's local Tabler library.

## Verification

Studio site: Page Blocks Visual QA, WordPress 7.1.2, `http://localhost:8925`. Page 198 is the untouched demonstration fixture with an existing HTML/CSS/JS section and native paragraph. Page 199 is the automation fixture.

Observed in the browser:

- Precise legacy text/color edits with original JavaScript still running.
- New native section, heading drag and resize, duplicate, undo/redo, one-pixel keyboard nudge, and Media Library image insertion.
- Floating Bold toolbar, inline text commit, and Ctrl+S save from the preview.
- Core button and theme styles rendered in the preview.
- At 360px, the native freeform section stacks without document overflow; movement and resize handles are hidden.
- Reopening the saved automation page in the regular WordPress editor yields valid blocks throughout and no dirty state. Saved content retains the original JavaScript and native paragraph, with no `visualData` or preview markers.
- The authenticated bookmarkable admin entry redirects to Visual mode with a fresh session nonce.
- Reselecting a source element shows its saved color; duplicating a native element retains its scoped mobile color rule with an independent class.

Automated gates: 46 JavaScript tests pass; 28 PHP unit tests pass; 78 WordPress integration tests run with 4 optional Functionalities skips. PHPStan and the new canvas PHP class's coding standards pass. No release or production deployment was performed.
