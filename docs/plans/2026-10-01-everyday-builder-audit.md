# Everyday page building: audit and improvement plan

Scope: released 4.0.0 (`59e3538`), code review, reproducible interaction tests, and an isolated Helium audit page on the local Studio site. The intended user is someone building visually without knowing CSS. Existing freeform placement, arbitrary HTML/CSS/JS sections, library blocks, and native WordPress storage remain essential.

## Assessment

The existing interface has a coherent WordPress appearance. Its main problems are the work needed to find controls, the blank-page starting experience, and several editing/recovery correctness gaps. Adding decoration would not address those problems. No claim of universal accessibility or compatibility is made from this audit.

## Prioritized findings

| Priority | Finding and evidence | User impact | Planned response |
| --- | --- | --- | --- |
| P0 | Recovered drafts inherit the freshly loaded server hash rather than the hash they were based on (`builder-shell.js`, draft loading). | An older local draft can overwrite another editor's newer save. | Persist and restore the baseline hash; protect hashless recovery; retain page settings and deletion accounting. |
| P0 | Structural section operations omit draft/dirty scheduling (`builder-shell.js`, add/duplicate/delete). | A page can say it is saved after a structural edit, and omit recovery/unload protection. | Route structural changes through the same dirty and recovery path. |
| P0 | Native paragraph editing can resolve a nested link as the text target while referring to the full paragraph (`canvas-bridge.js`, editable). | Double-clicking a link can replace the paragraph with only its linked text. | Edit the native text block's complete content root. |
| P1 | Paragraph line breaks are flattened and inspector edits containing line breaks discard inline formatting (`canvas-editor.js`, cleanInline/editPlainText). | Pasted or edited rich text loses structure. | Preserve line breaks and unchanged emphasis/links through sanitization and text edits. |
| P1 | Responsive managed class rules lose to desktop inline block styles (`canvas-editor.js`, setStyle/setRule). | Tablet/mobile controls appear to save but do not change the artwork. | Fix precedence only inside scoped managed overrides. |
| P1 | Button alignment targets the wrong native attribute; selected alignment buttons remain stale. | Controls misrepresent the saved state. | Use the native button schema and refresh accessible selected state. |
| P1 | Empty-page Add disables every content type; only one hardcoded starter is available. Observed in Helium on page 544. | A new user needs to understand containers before adding a heading or image. | Searchable insertion and native starting sections, with one-step content insertion into a new section. |
| P1 | The complete layer tree sits above Content and Style on every selection. Observed in the released inspector and previous screenshots. | Styling a selected element requires repeated scrolling. | Separate Design and Layers views, preserve selection, and add parent navigation. |
| P2 | New content always appends to its container. | Adding after a selected item requires a separate reorder action. | Insert next to the current selection in Auto flow; preserve Freeform placement. |
| P2 | Color controls expect CSS text or variables. | Everyday users must know color syntax and theme token names. | Native color picker and named theme swatches while retaining editable values. |
| P2 | The empty artboard provides no direct starting action. | First use feels broken or unfinished. | An in-canvas starting state with clear section choices. |
| P2 | Duplicating a native section copies its managed style classes and anchors. | Styling one copy can affect both. | Clone native blocks with independent identities. |
| P2 | Nested code blocks queue footer scripts outside the preview response. | Script-driven content differs between preview and frontend. | Collect nested script queues, deduplicate linked placements, and restore the outer request queue. |
| P2 | Clicking an already-selected section after clearing selection does not select its root. | Returning to a section's controls can require an extra canvas click. | Explicitly select the native section root from the section list. |
| P2 | Concurrent title/slug/template edits are not part of the server content hash. | Another editor's metadata-only changes can still be overwritten. | Follow-up: extend concurrency checking to metadata with compatible older clients. |
| P2 | README still advertises 3.1.0 after the 4.0.0 release. | Product documentation understates the actual visual workflow. | Update current-version and workflow guidance. |

Source findings are backed by specific reproductions or direct control-flow evidence. Browser verification and implementation results are recorded below as work finishes.

## Product direction

Evolve the current workspace around direct manipulation. A replacement editor would threaten the existing code workflow; a mandatory wizard would add steps for returning users. The selected approach adds discoverable starting points and contextual controls to the current canvas.

### This implementation

1. Fix content loss, draft conflicts, structural dirty tracking, and responsive styles.
2. Add native Blank, Introduction, Three columns, Call to action, and Questions and answers starters; searchable block and section insertion; predictable insertion position.
3. Put the selected element's Design controls first, give Layers its own view, and expose a parent breadcrumb and practical color controls.
4. Verify empty-page creation, nested text editing, styles, Auto/Freeform, code sections, undo/redo, save/reopen, and narrow previews. Preserve old content and use local fixtures.

### Next milestones

- More native layout controls: gap, directional padding/margins, row/stack alignment, width behavior, and responsive inheritance/reset indicators.
- Full section library integration: preview before inserting, searchable native patterns, and clear independent-copy versus linked-section behavior.
- Page navigation: named/collapsible section and layer trees, reliable nested reorder, duplicate across sections, and keyboard navigation.
- Better change review: human-readable undo/history, explicit recovery comparison, save-a-copy for conflicting drafts, and safe preview/publish distinction.
- Broader quality matrix: minimum WordPress, classic and block themes, additional native/third-party blocks, screen readers, touch devices, and larger pages.
- Performance: incremental native preview updates and measured input-to-preview latency on pages with many sections.

These are sequenced product milestones, not a promise that one pass makes the plugin the best builder in every scenario. Acceptance is based on concrete editing tasks completed with less friction and no loss of content.

## Strengths to preserve

Native WordPress serialization; independently editable code sections; Freeform and Auto placement; safe source-offset edits; reusable library references; existing AI and performance tools; content-conflict refusal; packaged upgrade compatibility and rollback history.

## Implemented and verified

All findings above except metadata-only concurrency and the longer roadmap items are addressed in this local implementation. Placeholder replacement checks HTML, CSS, and JS independently and preserves named/configured code sections. The default Design view is paired with keyboard-accessible Layers tabs and parent navigation. Presets create core blocks directly; there is no new saved visual tree or conversion of existing code.

Helium observations on isolated draft page 544:

- Empty page shows a starting action. Introduction inserts as the first native section, replacing only the unsaved empty placeholder; its controls open directly and the page becomes unsaved.
- Design/Layers navigation and parent selection work after saving and reopening. Desktop custom font size remains 40px in the inspector; the computed mobile heading size is 24px at a 478px canvas, overriding WordPress's generated fluid desktop inline style.
- Footer is 34px tall and ends at the viewport bottom. Desktop workspace has no horizontal overflow. Browser zoom was restored to 100% after reflow checks.
- Add code section opens Code mode immediately. Pasted HTML and JavaScript save alongside native blocks unchanged. Physical simulated character typing triggered CodeMirror's tag completion; normal paste was used for exact source verification.
- Freeform still activates from Placement. Undo restores the saved Auto document and correctly reports All changes saved.
- Stored mixed content initially had two sections, exact custom code/JS, and the mobile rule. Searching “services” then added a Three columns section; it saved as a second native Group beside the existing native section and code section, and stacked in the 358px mobile canvas. No preview markers or prototype visualData tree were stored.

Automated verification: 115 JavaScript tests, 29 PHP unit tests, and 80 real WordPress integration tests passed; 4 optional Functionalities tests skipped. PHPStan, syntax checks, focused coding standards, and diff whitespace checks passed. Tests cover responsive precedence/order, button targets, rich text and nested links, draft baselines, page metadata recovery, structural undo/dirty state, insertion position, independent duplication, and nested preview scripts.

## Remaining bounds

At extreme 400% browser zoom, the existing multi-row top toolbar and stacked inspector leave very little artboard space. A dedicated narrow-screen inspector drawer and compact toolbar should be the next responsive UI milestone. Desktop and mobile artwork presets work, but this is not a claim of full touch-device or screen-reader certification. Metadata-only concurrent edits also need a server/API follow-up. No public release or production deployment is part of this pass.
