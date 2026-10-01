# Blank canvas, library, conversion, movement, and clipboard

This release builds on the everyday-editor improvements while retaining native WordPress storage, arbitrary code sections, and desktop Freeform placement.

## Behavior

- **Blank canvas (no header or footer)** is available in classic and block themes, using the existing `page-blocks-full-builder.php` template slug. It omits the theme header, footer, and page title; keeps semantic main content and WordPress head/body/footer hooks; and removes inherited outer width/padding. Existing page template choices are not changed automatically.
- The library is available from both the main toolbar and the canvas Add menu. Search, sort, pagination, static isolated previews, loading/error states, source links and explicit independent-copy/linked insertion support choosing the right section. The selected section at opening is the insertion anchor. Ordinary code can still be saved to the library.
- Selected-section actions convert Visual content to editable native block code and code back to Visual. `nativeContent` is an optional Page Block flag, omitted for ordinary legacy sections. It renders retained native markup through WordPress. Semantic HTML converts to native blocks; unsupported fragments remain exact code leaves. CSS and JS retain their order and settings. Conversion refuses ambiguous shared containers, PHP, linked/dynamic blocks and unsupported execution rather than discarding content. Conversions are undoable and require Save.
- Converted native block code currently uses the canvas clipboard for reuse. The legacy library stores ordinary code rows and refuses native-content saves, preventing loss of the rendering flag.
- Element and section Copy/Cut/Paste support buttons and Command/Ctrl+C/X/V outside text/code inputs. Native copies get independent styling identities and retain managed responsive styles. Clipboard writes must succeed before Cut removes content; stale selections are retained. An editor-local copy supports Paste if system clipboard read access is blocked. Text and code editing retain ordinary clipboard shortcuts. Source-backed HTML element copying includes its CSS; whole-section copying includes scripts as well.
- Auto dragging uses a visible insertion indicator, current bounds, Columns/wrapped/RTL-aware targeting, an activation threshold, edge scrolling, and cancellation cleanup. Selected images/container surfaces can drag directly; text and touch use Move. Freeform snapping, axis lock, alternate-drag duplication and resizing remain. Auto movement still reorders within the current container; clipboard Cut/Paste moves between containers/sections.
- The inspector uses one 308px sidebar width in Visual and Code mode. Canvas selection is resynchronized after section selection so the element outline and Move handle match the inspector.

## Verification

Local Studio fixture page 692, with a published synthetic library row 226, preserves the user's page 544 and its unsaved work. Helium was used for interaction checks.

- Native Introduction converted to Code and saved as a Page Block containing original native markup with nativeContent true; server rendering retained the heading. Conversion back restored Visual editing.
- Copy/Paste duplicated a native heading. Browser clipboard read permission was dismissed, and the editor-local fallback completed Paste without losing the original.
- The new library displayed a real isolated preview; Insert copy opened Code mode; conversion to Visual retained the fixture HTML, CSS and JavaScript.
- Blank template saved correctly. The public local fixture returned authored content and scripts with semantic main, no header element, and no footer element.
- A physical Move-handle drag completed with Block order updated and unsaved state. Pointer lifecycle, insertion targets, Columns/RTL, cancellation and scrolling are additionally covered by interaction tests.

## Compatibility and release gates

Existing code rendering stays unchanged unless nativeContent is explicitly enabled by conversion. Converted code validates static supported blocks before rendering or saving; nested PHP, linked/dynamic blocks, block bindings and registered shortcodes are refused. Native helper CSS/JS remain discoverable by asset collection. Failed conversions leave the source unchanged.

Final local checks: 167 JavaScript tests, 29 PHP unit tests, and 91 real WordPress integration tests passed (4 optional integration skips). PHPStan and packaged PHP syntax passed. Helium measured the sidebar at exactly 308px in both Visual and Code. The packaged upgrade preserved stored content, rendered content, library data, and settings hashes. Distribution verification is recorded in the release report. No production installation is implied by publishing the plugin release.
