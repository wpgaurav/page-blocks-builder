# Visual builder release compatibility

## Verified release inputs

- Current remote default branch: `main`, commit `b629978`, version 3.1.0.
- GitHub already has published stable `v3.0.0` and `v3.1.0` releases. Reusing 3.0.0 would replace an existing release and downgrade the updater version. Release version selection is pending.
- FluentCart product 1152523, GT Page Blocks Builder (`gt-page-blocks-builder`), serves 3.1.0 through R2 download 308. Prior rows and files remain intact.

## Compatibility verification

- Added tests calling the actual authenticated AJAX save handler. Pre-3.0 code sections and the legacy `marketers-delight/page-block` name retain their HTML, CSS, JS, and CSS loading flags. Linked library references and conditions survive. Core blocks, nested legacy sections, opaque third-party markup, and prototype fallback data remain in document order. Rendered content is equivalent before and after saving.
- Saves that omit foreign blocks or carry a stale content hash are refused without changing stored content.
- The isolated Studio site `Page Blocks Release Compatibility` at `http://localhost:8937` was installed with the published 3.1.0 GitHub ZIP. A fixture mixing legacy source, native content, nested sections, unknown blocks, and a linked library row was created under 3.1.0. Installing the packaged candidate preserved exact stored-content, rendered-output, library-row, and settings hashes. This does not modify the user's visual testing site.
- Fresh library creation exposed a pre-existing bug: cache-version option writes or integration hooks can change the shared database `insert_id` before the library insertion returns. Capture the library ID immediately after insertion; tests verify a saved hook can write another option without breaking the returned library reference. Existing library IDs are unchanged.
- WordPress 6.0 lacks the style-engine API introduced in 6.1. Template preview layout recovery now checks that API before using it, preserving the older preview path. An isolated-process test exercises the missing-API case. This is an API guard test, not a full WordPress 6.0 browser test.

## Gates completed

- 79 JavaScript tests, 29 PHP unit tests, and 78 real WordPress integration tests passed; 4 optional Functionalities integration tests skipped because that plugin is absent from the fixture.
- PHPStan, tracked JSON validation, all shipped JavaScript syntax checks, packaged PHP lint, ZIP integrity and inclusion/exclusion checks, and diff whitespace checks passed. Composer lockfile dry run passed with the configured PHP 8.1 platform floor.
- Focused PHPCS checks passed. Existing DB-class style debt was preserved to avoid unrelated formatting changes.

GitHub publication and the FluentCart upload, transaction, licensed updater, and maintenance-mode download verification remain required after version selection. No production installation is implied by the release request.
