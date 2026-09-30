# Inspector and preview follow-up

## Findings and changes

- Osmium's `page-wide` template constrains post content to its 72rem wide size. The standalone preview omitted that container, so the saved Freeform Group and 100% image filled the 1412px artboard. Preview now reads the selected block template's post-content layout, uses WordPress's layout CSS, and applies theme root horizontal padding. One container wraps the complete document to retain ancestors shared between code sections. Saved content is not rewritten.
- Native section selection activates Visual mode; ordinary Page Block selection activates Code mode, including reselecting the current section after manually switching modes. Switching does not save or navigate.
- The inspector follows the property-row organization of [Figma's properties panel](https://help.figma.com/hc/en-us/articles/360039832014-Design-prototype-and-explore-layer-properties-in-the-right-sidebar): Layout, Typography, Appearance, and Fill. Related spacing fields share a row; fills have full-width swatch fields. Breakpoints and alignment use icon buttons with accessible names, pressed states, and tooltips. Existing theme presets, values, and responsive editing remain available. Icons are bundled from the local Tabler library.
- Scoped editor typography prevents the frontend theme from changing inspector font sizes. Controls use 13px on desktop and 16px on narrow screens. Preview typography continues to follow the theme.
- Hidden code and splitter rows caused the status bar to occupy a zero-height grid row. Explicit row assignments reserve the footer. The workspace is fixed to the viewport, with scrolling inside the canvas and panels.

## Local verification

- Studio site: `http://localhost:8925`, WordPress 7.1.2, Osmium. Used Helium through Computer Use, with isolated page 423 cloned from the saved page 199. The user's page 199 and its open draft were not edited or reloaded.
- Native/code section clicks activated the appropriate mode. Heading selection exposed existing text, heading level, theme font presets, and alignment controls.
- Live computed metrics: 1412px canvas, 1152px section (72rem), 13px inspector input font, and 34px footer. After workspace containment, the full toolbar and footer remained visible while the inspector scrolled to Appearance and Fill. Compact inputs had no nested borders or background boxes.
- JavaScript: 79 passing tests. PHP unit: 28 passing. WordPress integration: 75 passing, 4 optional Functionalities integration skips. PHPStan and PHPCS for the changed canvas class passed. Diff whitespace check passed.

## Limits

- Preview bounds are recovered from the selected block template's directly nested post-content block. This does not reproduce all surrounding template parts or custom classic-theme PHP layouts. Classic themes retain their existing preview behavior.
- No production deployment, release, or page 199 save was performed.
