# Placement modes and filesystem media

- New visual sections default to Fixed / Auto placement using native Group flow layout.
- The Placement selector is available for a selected Group or an element inside it. Auto dragging reorders sibling blocks and shows an insertion guide; it does not save coordinates. Reading-order buttons remain available for keyboard use.
- Freeform uses the existing measured grid solver, snapping, and resize handles. Switching to Auto removes the matching managed layout region and freeform classes, retaining content, native layout attributes, CSS overrides, and authored CSS. The change is undoable through document history.
- File drops upload through the authenticated WordPress media REST endpoint and associate attachments with the edited page. Native Image, Video, Audio and File blocks hold the resulting URLs/attachment IDs. Files are inserted into the target Group/Column, or a new Auto media section beside a code section.
- A batch supports up to 20 files. WordPress validates file types; the client also checks the site's upload size limit and current capabilities. Errors identify failed files. A section changed during upload is retained intact; uploaded media stays available in the library.
- Freeform drops require a desktop artboard. Auto insertion works in narrow previews. Dropping into a Code preview is intercepted with a Visual-mode instruction rather than navigating the iframe to the local file.

Verification: Helium showed an existing Freeform section switching to Fixed / Auto, and a controlled browser file-drop event using bytes read from a disposable filesystem GIF uploaded attachment 350 and inserted a native Image in the target Auto group. Automated checks cover mode conversion/style retention, native reordering, conversion to Freeform, multipart upload, WordPress rejection, stale insertion protection, and insertion-guide gesture messages. The physical Finder-to-browser gesture was not automated.

Checks: 76 JavaScript tests pass, 28 PHP unit tests pass, and 78 WordPress integration tests run with 4 optional skips. PHPStan, the canvas PHP class's coding standards, JavaScript syntax, and diff whitespace checks pass. The QA canvas edits remain an unsaved local draft after the QA tab was closed; the uploaded attachment was independently verified in the media library. No production changes or release were made.
