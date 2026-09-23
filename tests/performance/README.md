# Performance and UX verification

Run these against a disposable local WordPress install that loads this checkout. They create and delete fixture posts and generated files. Never point the integration suite at a production database. The performance scripts additionally reject non-local sites and a plugin loaded from a different checkout.

## Deterministic regression suites

```sh
npm test
npm run test:ux
vendor/bin/phpunit
GT_PB_WP_ROOT=/path/to/local/wordpress vendor/bin/phpunit -c phpunit-integration.xml
```

The normal npm and PHPUnit discovery automatically includes the new tests in existing CI jobs. Browser lab checks below are a separate, explicit run and are not claimed as CI coverage.

`builder-workflows.test.js` drives the shipped full-page builder through DOM events. A controlled transport deliberately holds and fails save responses. It covers:

- Save completion, section selection, and reconstruction from the server response.
- Edits during a pending save; an older response must not overwrite them.
- Duplicate submission prevention, network failures, permission errors, invalid JSON, and retry.
- Draft recovery with ordinary and foreign blocks, corrupt/obsolete drafts, and unavailable local storage.
- Duplication, keyboard reorder, undo/redo, and section-specific CSS settings.
- One pending preview update and one pending autosave after 100 rapid CSS edits.

`SectionCssLifecycleTest.php` uses real WordPress parsing, metadata, and storage. It covers:

- No database writes, file rewrites, or changed URLs during warm rendering of 20 sections.
- A genuinely unusable uploads path and the inline fallback.
- Separation between pages with identical CSS.
- CSS revisions and retention of the previous URL for cached HTML.
- Age-based pruning without deleting recent or unrelated files.
- Mixed file order, inline/library exclusions, and preview-route exclusions.

These complement the existing CSS mode, deduplication, cache retention, preview DOM, cursor scrolling, security, and save-path suites. DOM transport tests do not replace a real WordPress AJAX save/reload check. jsdom does not measure layout, paint timing, accessibility-tree exposure, or actual CSS downloads.

## PHP scaling benchmark

```sh
GT_PB_WP_ROOT=/path/to/local/wordpress npm run test:performance
# For JSON without npm's command banner:
GT_PB_WP_ROOT=/path/to/local/wordpress php tests/performance/section-css-benchmark.php > /tmp/pbb-scaling.json
```

Five samples each for 1, 10, 50, and 100 sections. Reports median elapsed time, page parse calls, and SQL query counts for the CSS head pass plus each body render call. Files and the WordPress object cache are warm. The measurement excludes WordPress bootstrap, HTTP, compression, CDN caching, and browser rendering. It checks correct link counts and no duplicate body output before accepting a sample.

Timing is informational; a hard millisecond threshold across developer machines would be unreliable. Compare the same runtime and fixture before/after a change. The parser counter can identify repeated work even when a fast machine masks its cost.

## Browser lab

```sh
GT_PB_WP_ROOT=/path/to/local/wordpress npm run test:performance:browser
```

Defaults: `http://127.0.0.1:9474`, with every external CSS response delayed by 1,500 ms. Override using `PBB_PERF_PORT` and `PBB_CSS_DELAY_MS`. Responses are `no-store`, so each run is a cold stylesheet fetch. The server binds to loopback only. Stop it with Ctrl+C.

The lab obtains real output from the plugin's PHP renderer, copies the generated CSS into memory, and removes the fixture posts/files. It serves no remote fonts or images. The deferred case also loads one small same-origin helper script; the Measurements resource count lists CSS files only. Its hero geometry is inline and its secondary geometry is reserved, so the comparison isolates stylesheet blocking rather than demonstrating that arbitrary CSS can be deferred without layout shifts.

| Route | Expected observation |
| --- | --- |
| `/?mode=inline` | Styled, zero external stylesheet requests. |
| `/?mode=blocking` | Styled, first paint held by the delayed stylesheet. |
| `/?mode=deferred` | First paint before stylesheet completion; styles apply afterward; one stylesheet request. |
| `/?mode=deferred` with JS disabled | The noscript link applies CSS; deferred paint timing is not promised. |
| `/?mode=deferred&csp=1` | Styles apply with inline event handlers blocked; the same-origin external loader is allowed. No CSP errors. Nonce-only policies must authorize the loader through `wp_script_attributes`. |
| `/?mode=deferred&fail=1` | Known limitation: a CSS HTTP 404 leaves the section unstyled. Noscript does not recover a failed network request. Expected 404 error. |
| `/builder` | Shipped editor assets with two sections for keyboard, responsive, and control-state checks. This fixture has no save endpoint; use real WordPress for an end-to-end save. |

The Measurements element reports FCP, LCP, CLS, stylesheet request count/duration, and computed style application after loading. LCP is sampled before interacting; CLS covers only this load window. These numbers are synthetic lab observations, not PageSpeed, CrUX, field INP, or a prediction of homepage savings.

With the Browser plugin, load its skill and reuse its selected browser. In its authorized Node session, import `browser-checks.mjs` and pass an existing test tab:

```js
const checks = await import('/absolute/path/to/page-blocks-builder/tests/performance/browser-checks.mjs');
const result = await checks.compareCssLoading(tab);
// result.clearsCriticalPath must be true at the default delay.
// Save result.samples and result.medians outside the repository.
```

The runner makes three visits per mode, requires correct styling and request counts, and compares median first-paint times relative to the injected delay. An inactive/background-throttled tab, a reduced delay, or a heavily loaded machine can invalidate timing; inspect raw samples before drawing conclusions. Do not run it through a different browser automation surface when the Browser plugin owns the session.

## UX and responsive acceptance

Use `/builder` at desktop, 768 px, and 390 px viewport widths. Select Lower section, then check:

1. File and defer controls retain their own state as sections change.
2. Turning file mode off removes Defer CSS from visual and keyboard navigation. Turning it back on restores the prior preference.
3. Tab/Space operates the checkboxes with visible focus.
4. Each control stays reachable without page-level horizontal overflow. `inspectBuilderLayout(tab, expectedWidth)` returns measured bounds and an explicit `passes` result. Supply the intended viewport width so a resize applied to a different tab cannot produce a false pass.
5. Preview renders the complete section CSS regardless of its frontend loading mode.
6. The normal page has no relevant console errors. The deliberate CSP/404 cases are assessed separately.

New regression suites also cover the external loader's cached/late load paths, request-cache invalidation and file recovery, read-only analysis permissions/payload bounds, and Performance dialog error/retry/focus behavior.

On real local WordPress, perform the same toggle in the Block Editor and the full-page builder, save, reload, inspect stored attributes, then reopen the other editor. Cover JavaScript-disabled frontend output separately. Keep screenshots and raw results outside the repository.

For future releases also test cached HTML with previous file URLs, printing, reduced motion, strict-CSP alternatives, and interaction with third-party CSS/JS optimization plugins. Do not mark these broader scenarios passed based on the synthetic lab alone.
