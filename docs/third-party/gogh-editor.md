# Gogh Editor reference and adapted algorithms

Source: https://github.com/jamiemarsland/gogh-editor
Reference commit: b0c9f785443c1e818d04a2b689579229a3099111
Copyright Jamie Marsland. GPL version 2 or later, matching this plugin's license (see the root LICENSE).

The small edge-clustering, boundary-pinning, nearest-line, grid-area and alignment-magnet routines in `assets/js/canvas-layout.js` are adapted from Gogh's `gogh-editor.js` (`cluster`, `pinLines`, `nearest`, `solve`, and `snapPos`). They have been reduced to the Page Blocks canvas use case.

The direct selection, drag ghost, resize handles and inspector interactions are inspired by Gogh's frontend canvas. The editor, server endpoints, site generation, commerce features and saved Gogh model are not bundled. Page Blocks uses WordPress blocks and its existing code/CSS section storage.
