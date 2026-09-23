// Import from the authorized Browser Node session and pass its existing tab.
// This module never selects a browser, launches one, or changes production state.
const median = values => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];
export async function compareCssLoading(tab, origin = 'http://127.0.0.1:9474') {
	const samples = [];
	for (let run = 0; run < 3; run++) {
		for (const mode of ['inline', 'blocking', 'deferred']) {
			await tab.goto(origin + '/?mode=' + mode);
			await tab.playwright.locator('#results[data-ready="true"]').waitFor({ state: 'visible', timeoutMs: 15000 });
			const result = JSON.parse(await tab.playwright.getByLabel('Measurements').innerText());
			if (!result.stylesApplied || !Number.isFinite(result.fcp) || !Number.isFinite(result.lcp)) throw new Error('Incomplete or unstyled fixture: ' + mode);
			if (result.resources.length !== (mode === 'inline' ? 0 : 1)) throw new Error('Unexpected stylesheet request count: ' + mode);
			samples.push(result);
		}
	}
	const medians = Object.fromEntries(['inline', 'blocking', 'deferred'].map(mode => {
		const group = samples.filter(x => x.mode === mode);
		return [mode, { fcp: median(group.map(x => x.fcp)), lcp: median(group.map(x => x.lcp)), cls: median(group.map(x => x.cls)) }];
	}));
	const delay = samples[0].cssDelayMs;
	// A relative assertion in this controlled slow-resource lab, not a website score budget.
	const clearsCriticalPath = delay >= 1000 && medians.blocking.fcp - medians.deferred.fcp > delay / 2;
	return { samples, medians, clearsCriticalPath };
}

export async function inspectBuilderLayout(tab, expectedWidth) {
	return tab.playwright.evaluate(expectedWidth => {
		const width = innerWidth;
		const controls = Array.from(document.querySelectorAll('.md-pb-options label')).filter(x => !x.hidden).map(x => {
			const box = x.getBoundingClientRect();
			return { label: x.textContent.trim(), left: box.left, right: box.right, insideViewport: box.left >= 0 && box.right <= width };
		});
		const widthMatches = expectedWidth === undefined || width === expectedWidth;
		return { width, widthMatches, documentWidth: document.documentElement.scrollWidth, controls, passes: widthMatches && controls.length > 0 && controls.every(x => x.insideViewport) };
	}, expectedWidth);
}
