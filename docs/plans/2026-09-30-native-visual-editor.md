# Native WordPress Visual mode

Superseded by [the integrated canvas workspace](2026-09-30-canvas-workspace.md). The iframe approach below was implemented locally and rejected during user review; it is retained here as a historical decision record.

The September 30 direction replaces the unreleased structured visual editor with WordPress's actual block editor. Page Blocks provides a compact workspace and a Visual/Code switch; WordPress owns the block tree, inserter, inspector, patterns, media, undo, revisions, and post saves.

## Implementation

- New builder links use `pb_mode=visual`. Legacy links without a mode still open Code. Visual mode hosts the authenticated WordPress post editor in an iframe so core and third-party editor assets and settings initialize through their normal WordPress lifecycle.
- A small bridge checks message origin and source. It calls the native `core/editor.savePost()` before switching to Code. Failed saves, changed content during save, saving locks, and unsaved template/pattern entities stop the handoff. The shell has no separate visual document state or autosave format.
- Code-to-Visual handoff waits for the existing section save, retains its content hash check, and refuses to navigate if newer edits remain unsaved.
- The custom visual editor and PHP compiler are removed. The `visualData` attribute remains only to preserve existing prototype records until the author uses Convert to WordPress blocks. Conversion uses `wp.blocks.createBlock()` and `core/block-editor.replaceBlocks()`, with native Undo. Generated CSS is retained in an ordinary Page Block named Imported section styles. Unsupported prototype nodes leave the original section in place.
- Seven Tabler SVGs from `~/Icons/svg/outline` are bundled with their MIT license. No icon font is added.

## Verification

Studio: Page Blocks Visual QA, WordPress 7.1.2, local page 152. Verified native text insertion, prototype conversion, Undo/Redo, Visual-to-Code save, Code-to-Visual save, reopening with valid native blocks, retained image alt text, and no visualData in the converted page. A forced HTTP 500 on the native save kept the editor open and dirty; retry succeeded after restoring the request. The retained mobile heading rule computed to 22px in the native canvas. Visual and Code workspace widths stayed within the 390px viewport.

Automated checks: 43 JavaScript tests; 77 WordPress integration tests with four optional Functionalities skips; 28 PHP unit tests; PHPStan; syntax and diff whitespace checks. PHP checks used the local PHP 8.5 runtime. Other WordPress/browser versions have not been rechecked in this pass.

The changes are local to the feature branch. No release, production deployment, or content migration was performed outside the isolated Studio QA site.
