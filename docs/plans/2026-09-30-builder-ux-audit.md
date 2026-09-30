# Builder visual and UX audit

## Scope and evidence

Review of the integrated canvas at commit `c410e69`, the user's toolbar-overlap screenshot, and the actual editing and persistence code. Helium is the browser for interactive checks. A separate local audit fixture (page 272) preserves the user's existing pages and drafts. Findings below distinguish source evidence from browser observations; verification results are added after fixes.

## Visual assessment

The existing WordPress-style workspace is appropriate and should be kept. Its main weaknesses are competing overlays, unclear state, and incomplete interaction behavior. A decorative redesign would not resolve these problems.

## Summary

25 findings addressed locally: 11 high, 13 medium, and 1 low. Toolbar access, editing correctness, preview reliability, geometry, and permission feedback received priority. The fixes keep the original workspace and save model. Browser observations below use Helium; automated tests cover failure and geometry cases that are difficult to reproduce consistently through UI actions.

## Findings

| ID | Severity | Finding and impact | Evidence / location | Implemented resolution |
| --- | --- | --- | --- | --- |
| UX01 | High | Floating canvas tools cover element actions, making Move/Edit inaccessible. | User screenshot; `canvas-editor.css` rail at top 42px; `canvas-bridge.js` toolbar above selected element. | Reserve space for canvas tools in the preview header. |
| UX02 | High | Element toolbar can extend beyond the viewport or remain visible when its element scrolls out of view. | `canvas-bridge.js` position uses only the element's left edge and a fixed 42px threshold. | Measure and clamp toolbar bounds; hide offscreen selections. |
| UX03 | High | Edit opens the inspector instead of editing; generated/protected text accepts temporary typing that cannot be saved. | `canvas-bridge.js` Edit callback and unconditional dblclick handler; `canvas-editor.js` protected-source guard. | Edit starts supported inline text editing; protected output offers inspection only. |
| UX04 | High | Changing plain text in the inspector removes existing emphasis and links. | `canvas-editor.js` content replaces markup with escaped plain text. | Preserve unchanged inline markup when replacing text. |
| UX05 | High | Inline text has inconsistent cleanup and no keyboard path to start editing or deselect. | `canvas-bridge.js` blur/key listeners and lack of Enter/Escape selection handling. | One editing lifecycle; Enter to edit, Escape to cancel/deselect. |
| UX06 | Medium | Inspector changes can add unnecessary history entries; custom font size can retain a conflicting preset. | Field `change` callback; native style and font preset assignments. | Avoid unchanged commits; clear conflicting font-size preset. |
| UX07 | Medium | Adding to an empty freeform group fails; default duplicate placement overlaps the original. | `canvas-editor.js` nonempty geometry guard, Math.max over empty array, duplicate offset of 24px. | Permit empty insertion; place ordinary duplicates below their source. |
| UX08 | Medium | Layer selection is conveyed only visually; reading-order buttons enable impossible moves or move a hidden CSS helper. | `canvas-editor.js` layer row and action rendering. | Selected ARIA state; disabled boundaries; skip helper blocks. |
| UX09 | Medium | Responsive scope and preview width are independent, making mobile overrides hard to see; arrangement restrictions appear only after an action. | Separate Apply to and preview viewport state. | Link explicit responsive scope to preview; explain/disable desktop-only arrangement. |
| UX10 | Medium | Add menu lacks expanded state, contextual guidance, and outside-click dismissal; stays open after switching to Code. | `canvas-editor.js` rail and palette listeners/render. | Accessible disclosure, contextual availability, dismiss on outside click/mode change. |
| UX11 | Medium | Persistent layout notices obscure page content; style errors are generic and disconnected from fields. | Overlay status and field aria-invalid only. | Notices outside artwork, timed success messages, persistent dismissible errors and field hints. |
| UX12 | High | Failed preview silently displays a fallback while claiming Live preview; snapshots could identify stale markup as current. | `builder-shell.js` preview catch builds fallback from stale foreign rendered content. | Visible preview error/retry; invalidate canvas selection snapshots until a current render succeeds. |
| UX13 | Medium | Unsaved state is not visible continuously; save errors use a browser alert. | `queueAutosave`, save success/catch, bottom save-status. | Persistent saved/unsaved/saving/error state with retry through Save. |
| UX14 | Medium | Native image URL replacement retains old size metadata; source image replacement can keep the old responsive source and alt text. Literal dollar sequences could corrupt replaced attributes. | `canvas-editor.js` image URL/media callbacks; `preview-dom.js` string replacement. | Clear stale metadata on URL replacement; update URL/alt atomically; retain literal dollars. |
| UX15 | Medium | Small resize handles and dense 12px controls are difficult on narrow/touch displays; styles inherit host theme details. | Preview-only chrome and canvas CSS. | Larger handle hit area, touch targets, explicit editor chrome styles, narrow-layout checks. |
| UX16 | High | Freeform padding/borders are included in drag coordinates, causing drift; a narrow Group in a desktop viewport is incorrectly rejected as mobile. | `geometry` uses outer bounds and `layoutCommit` checks Group width. | Measure the content box; use viewport width for responsive restrictions. |
| UX17 | High | A theme's sparse body-class array becomes a JSON object and blanks the preview. | Observed in Helium console: `previewBodyClasses.join is not a function`. | Normalize PHP array indices and accept arrays/objects/strings in the client. |
| UX18 | Low | Minimal frontend Page Block registration emits an API-version deprecation warning. | Observed in Helium console. | Register the frontend parser stub with API version 3. |
| UX19 | Medium | An unavailable template appears as Default in settings, making a save rejection confusing. | Settings options omit the current unavailable slug. | Show its actual unavailable value and explain how to choose a replacement. |
| UX20 | Medium | Settings call native blocks uneditable and mark the document unsaved even after an unchanged inspection. | Observed in Helium settings; unconditional close autosave. | Accurate WordPress-block counts and no change event for unchanged settings. |
| UX21 | Medium | CodeMirror source inputs have no meaningful accessible names. | Observed in Helium accessibility tree; editor initialization. | Name HTML/CSS/JS controls and set CodeMirror's screen-reader label. |
| UX22 | Medium | A fitted Desktop artboard may be narrower than 768px, making the restriction and recovery advice unclear. | Helium zoom/reflow checks; iframe width depends on available workspace. | Show actual canvas width and explain Desktop/Sections/window options. |
| UX23 | High | Drag ghosts inherit a grid area, and the synthesized release click clears selection after a successful move. | Helium movement check; ghost style and click capture. | Use an independent absolute ghost; suppress only the release click and retain selection. |
| UX24 | High | Vertical resize changes track space but fails to retain the selected element's requested box height. | Layout CSS lacks an element height/min-height. | Persist border-box minimum heights and account for measured margins; mobile clears minimum heights. |
| UX25 | High | Non-administrators could begin native edits that the existing save endpoint rejects. | Existing foreign-markup restriction vs unconditional client controls. | Match controls to existing permissions and offer the WordPress editor; code-section editing remains available. |

## Strengths to preserve

- Native WordPress block parsing and serialization, with scoped CSS in the existing Page Block.
- Original HTML/CSS/JS editing, section/library/AI/page tools and loading settings.
- Source offsets preserve code outside the selected element.
- Existing draft recovery, save retry, content conflict protection, and document history.
- Freeform desktop arrangement and DOM reading order on mobile.

## Improvement order

1. Toolbar access, preview reliability, and editing correctness.
2. Keyboard and menu behavior, selection feedback, field/state clarity.
3. Responsive controls, layout insertion/duplication, media metadata, and visual spacing.

Theming, contrast, asset loading, and layout work are reviewed within this scope. This is not a certification of all third-party blocks, every device, or WordPress's media dialog.

## Verification

Observed in Helium on the isolated Studio audit page:

- Canvas controls occupy the header; selected-element controls remain separate and within the artwork viewport.
- Edit text enters inline editing, formatting controls appear, and committed text is reflected in the inspector.
- Inspector text edits, non-overlapping duplication, drag, resize, selection retention, and save/reopen work. A vertical resize persisted a 78px minimum height.
- 360px and 480px previews stack blocks and disable desktop-only arrangement with explanatory hints. Mobile style scope switches the preview to 480px, and numeric font sizes save as pixels.
- Browser zoom/reflow was checked up to 400%, then restored to the prior 100% setting. This is a desktop reflow check, not a physical touch-device test.
- Code mode retains original HTML/CSS/JS and loading controls. The AI panel opens without reducing canvas width; no AI requests were sent.
- Save errors retained the local draft and displayed a retry instruction. A successful retry and reopen showed All changes saved.
- The regular WordPress editor opens the saved code block, native Group, headings, paragraph, Buttons/Button and stylesheet helper, with Save disabled and no invalid-block warnings.
- Stored content retains the original JavaScript and native paragraph, with no prototype model or preview markers.

Automated gates: 68 JavaScript tests, 28 PHP unit tests, and 78 WordPress integration tests (4 optional Functionalities skips). PHPStan, JavaScript syntax, and diff whitespace checks pass. Regression coverage includes toolbar bounds, ghost isolation, post-drag selection, content-box coordinates, margins/minimum height, empty-group insertion, rich text retention, permissions, menu behavior, template feedback, preview failure/retry, and save retry/draft preservation.

## Bounds and follow-up opportunities

- The current save policy reserves native canvas markup changes for administrators. Other roles now see the appropriate WordPress-editor handoff instead of unsavable controls; the policy was not broadened.
- Unsupported native/third-party blocks remain intact and have a WordPress-editor handoff. Layer navigation stays available when one is selected.
- Actual mobile/touch hardware and every installed third-party block are outside this local verification scope. No release or production deployment was performed.
