# Page Blocks Builder performance plan

Date: September 23, 2026
Status: the following improvements are included in the 3.1.0 release candidate:

- Request-local CSS manifest reuse with invalidation on post/meta changes, separate blog/upload contexts, and missing-file recovery.
- A filterable external deferred-CSS loader that works without inline event handlers and supports cached/early stylesheet loads.
- Wrapping mobile controls and an editor-only Performance panel for source/minified sizes, loading modes, duplicate CSS, and media/script hints.

The remaining bundling, critical-CSS splitting, JavaScript scheduling, automated resource hints, and content-visibility items below remain proposals. They need explicit dependency/ownership rules and their own measurements; no automatic content transformations are implemented.

The blank Gutenberg canvas was reproduced with a standalone blob iframe outside WordPress; srcdoc rendered normally. This is a limitation of the current test browser, not evidence of a Page Blocks rendering defect.

## Starting evidence

- The supplied homepage PageSpeed report lists six generated CSS files as render-blocking, totaling 14.6 KiB transferred, with an estimated 150 ms saving. That estimate is not a measured improvement from 3.0.4.
- A fresh read of homepage 7172 found nine sections. Six explicitly use `cssOutput: file`, all with deferral off. They are Books, Resources, Latest writing, About, Services, and Newsletter.
- The first section owns the animated introduction and shared page foundation. Its authored CSS is 18,226 bytes and JS is 7,246 bytes, before minification or compression. The other sections currently contain no authored JS.
- 3.0.4 adds opt-in deferred CSS to both editors. Deployment alone does not enable it on existing sections.
- Section file generation is called from both the head pass and individual block rendering. Each call reparses the page and visits the section files.
- Current per-section filenames rotate on every save, including title-only saves, as explicitly requested earlier. Existing combined assets use content hashes. Changing the section convention must be an opt-in policy, not a silent migration.

## Recommended order

### 1. Measure and explain each section's cost

Add an editor-only Performance panel listing authored/minified CSS and JS size, inline/file/deferred mode, total generated requests, duplicate exact CSS, and selected image loading attributes. Flag a deferred first section or shared layout stylesheet for review rather than changing it automatically.

The panel should distinguish local byte estimates from actual transfer size and browser timing. Do not label a section's code size as its LCP contribution or promise a PageSpeed score.

Acceptance: the inventory agrees with emitted files, linked blocks resolve to their real source, and the panel adds no public-facing script. Establish mobile/desktop baselines with three comparable cold-load runs and a warm-load comparison. Record median LCP, FCP, CLS, TBT, bytes, requests, and console errors; use field INP where available.

### 2. Avoid repeated work while generating CSS files

Build one asset manifest per post and content revision per request, and reuse it in head/body rendering. Retain recovery for missing files and unavailable storage. Invalidate the request cache after saves or content changes in the same request. Keep stylesheet order and existing deduplication semantics.

Acceptance: a multi-section page performs one parse/manifest construction for normal rendering, with identical output. Cover mixed posts in a loop, identical CSS with different loading modes, missing files, and an in-request save. Measure uncached PHP time separately from cached page delivery.

### 3. Offer explicit critical and deferred CSS groups

Make the existing loading choices easier to understand as Inline, File, and Deferred file, while preserving stored attributes and old content. Add a deliberate critical-CSS field only if authors need to split a large section's immediate layout from its remaining styles. Do not infer critical CSS by deleting selectors.

An optional bundle mode can reduce several small deferred requests. Bundle only contiguous compatible stylesheet runs; preserve cascade order relative to every inline and external style, including layers and media queries. Keep relative `url()` behavior and `@import` ordering correct. Do not assume bundling wins over HTTP/2 or HTTP/3; compare measured cold and repeat visits before recommending it.

Acceptance: hero styles are available at first paint; delayed files remain non-blocking on screen; no-JavaScript, print, failed download, dark mode, responsive layout, and strict-CSP behavior are documented and tested. Existing pages must not silently change modes.

### 4. Add deliberate JavaScript scheduling

Start with an optional ordered `defer` strategy for external section scripts, using WordPress's dependency-aware script API where supported. The plugin currently supports WordPress 6.0, so define a tested fallback for pre-6.3 versions or make a separate compatibility decision before using newer strategy arguments.

Keep the current execution mode as the default. Never convert arbitrary code to `async`, move it across dependencies, or delay a site's navigation, forms, accessibility controls, or consent handling automatically. Inline scripts do not gain deferred execution from a `defer` attribute.

A later opt-in visibility/interaction trigger should require an explicit initializer contract: run once, retain document order where needed, support reduced motion, and define cleanup. The homepage hero animation is an evidence-based candidate for pause-when-offscreen, subject to profiling.

Acceptance: dependency ordering and DOM-ready behavior remain correct, controls respond immediately, and CPU work decreases in traces without errors or a worse first interaction. Test other optimization plugins' script processing.

### 5. Help authors prioritize images and fonts

Add editor diagnostics for likely above-the-fold images marked lazy, missing image dimensions, oversized image sources, and duplicate preloads. Let the author designate a primary image; preserve explicit markup and avoid globally assigning high priority. Use responsive preload attributes only when the actual LCP resource is known. Check font ownership in the theme or Functionalities before proposing preload changes.

Acceptance: the selected resource starts earlier without duplicate downloads, layout space is reserved, and unrelated image priorities remain unchanged. Compare LCP resource delay before and after, not just total bytes.

### 6. Experiment with skipping offscreen rendering

Offer `content-visibility: auto` and an author-supplied intrinsic size only on a section with an independent existing root. Do not add wrappers: authored sections can open a shared container that closes in another section.

Exclude critical sections by default. Test anchor navigation, find-in-page, keyboard focus, screen-reader traversal, sticky descendants, print, dark mode, and differing mobile heights. Keep this experimental until rendering traces show a benefit without new layout shifts.

## Delivery sequence

- First follow-up: diagnostics and request-local asset memoization. These make future decisions measurable and reduce work without rewriting author content.
- Second follow-up: CSS grouping prototype and ordered external-JS deferral, each behind separate opt-ins with their own before/after evidence.
- Third follow-up: media hints, offscreen animation controls, and content-visibility experiments.
- Separate compatibility proposal: optional stable content-hashed section filenames. Preserve today's rotate-on-save default; retain old URLs for the site's actual cache lifetime and garbage-collect only unreferenced plugin-owned files.

## Release and rollout checks

Use local fixtures for shared containers, nested/foreign blocks, linked library sections, mixed loading modes, and failed storage. Run PHP/JS suites, static analysis, and browser checks with JavaScript enabled and disabled. Exercise CSP restrictions where event handlers are involved.

For a live opt-in rollout, snapshot the exact page first, change only selected performance attributes, verify the fresh rendered page on mobile and desktop, and invalidate only its cache if needed. Check layout and interactions before rerunning PageSpeed. Keep plugin rollback and page rollback separate. Never claim a score or Core Web Vitals improvement without measuring it.

## References

- Google: https://web.dev/articles/defer-non-critical-css
- Google: https://web.dev/articles/optimize-lcp
- Google: https://web.dev/articles/content-visibility
- WordPress: https://developer.wordpress.org/reference/functions/wp_enqueue_script/
