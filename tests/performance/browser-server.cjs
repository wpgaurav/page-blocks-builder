// Local-only browser lab. Uses real PHP-generated stylesheet tags and shipped editor assets.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const port = Number(process.env.PBB_PERF_PORT || 9474);
const delay = Number(process.env.PBB_CSS_DELAY_MS || 1500);
if (!Number.isInteger(port) || port < 1024 || port > 65535 || !Number.isFinite(delay) || delay < 0 || delay > 10000) throw new Error('Invalid fixture configuration');
const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'browser-fixtures.php')], { env: { ...process.env, PBB_PERF_PORT: String(port) }, encoding: 'utf8' }));
const assets = Object.assign({}, ...Object.values(fixtures).map(x => x.assets));
const repo = path.resolve(__dirname, '../..');
const staticFiles = {
	'/deferred-css.js': ['assets/js/deferred-css.js', 'text/javascript'],
	'/builder.css': ['assets/css/builder-shell.css', 'text/css'],
	'/builder.js': ['assets/js/builder-shell.js', 'text/javascript'],
	'/preview.js': ['assets/js/preview-dom.js', 'text/javascript']
};
const measurements = `<script nonce="pbbfixture">
const report={mode:document.documentElement.dataset.mode,cssDelayMs:${delay},fcp:null,lcp:null,cls:0};
new PerformanceObserver(list=>{for(const x of list.getEntries())if(x.name==='first-contentful-paint')report.fcp=x.startTime;}).observe({type:'paint',buffered:true});
new PerformanceObserver(list=>{for(const x of list.getEntries())report.lcp=x.startTime;}).observe({type:'largest-contentful-paint',buffered:true});
new PerformanceObserver(list=>{for(const x of list.getEntries())if(!x.hadRecentInput)report.cls+=x.value;}).observe({type:'layout-shift',buffered:true});
window.addEventListener('load',()=>setTimeout(()=>{
report.stylesApplied=getComputedStyle(document.getElementById('secondary')).backgroundColor==='rgb(230, 245, 240)';
report.resources=performance.getEntriesByType('resource').filter(x=>x.name.includes('/assets/')).map(x=>({duration:x.duration,transferSize:x.transferSize}));
document.getElementById('results').textContent=JSON.stringify(report,null,2);
document.getElementById('results').dataset.ready='true';
},300));
</script>`;
http.createServer((req, res) => {
	const url = new URL(req.url, 'http://127.0.0.1:' + port);
	res.setHeader('Cache-Control', 'no-store');
	if (url.pathname === '/favicon.ico') { res.writeHead(204).end(); return; }
	if (staticFiles[url.pathname]) {
		const [file, type] = staticFiles[url.pathname];
		res.setHeader('Content-Type', type); res.end(fs.readFileSync(path.join(repo, file))); return;
	}
	if (url.pathname.startsWith('/assets/gt-page-blocks/')) {
		const css = assets[path.basename(url.pathname)];
		setTimeout(() => { res.writeHead(css && !url.searchParams.has('fail') ? 200 : 404, { 'Content-Type': 'text/css' }); res.end(url.searchParams.has('fail') ? '' : css || ''); }, delay);
		return;
	}
	res.setHeader('Content-Type', 'text/html; charset=utf-8');
	if (url.pathname === '/builder') {
		res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PBB UX fixture</title><link rel="stylesheet" href="/builder.css"></head><body class="md-page-blocks-builder-shell"><div id="md-pb-builder-app"></div><script>window.mdPbBuilder={initialSections:[{uid:'pb-hero',name:'Hero',content:'<section id="hero"><h1>Critical hero</h1></section>',css:'#hero{padding:40px;font:24px system-ui}',cssOutput:'inline'},{uid:'pb-later',name:'Lower section',content:'<section id="later"><h2>Lower section</h2></section>',css:'#later{padding:40px;background:#e6f5f0}',cssOutput:'file',cssDefer:true}]};</script><script src="/preview.js"></script><script src="/builder.js"></script></body></html>`);
		return;
	}
	const mode = url.searchParams.get('mode') || 'deferred';
	if (!fixtures[mode]) { res.writeHead(400).end('Unknown mode'); return; }
	if (url.searchParams.has('csp')) res.setHeader('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-pbbfixture'");
	let tag = fixtures[mode].tag;
	if (url.searchParams.has('fail')) tag = tag.replace(/\.css/g, '.css?fail=1');
	res.end(`<!doctype html><html lang="en" data-mode="${mode}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PBB CSS performance fixture</title><style>body{margin:0;font:20px system-ui;color:#123;padding:24px}h1{font-size:42px}#hero{height:240px}#secondary{height:160px;padding:24px;box-sizing:border-box}pre{font-size:13px;white-space:pre-wrap}</style>${tag}</head><body><main><section id="hero"><h1>Critical hero renders first</h1><p>Delayed non-critical CSS should not hold up this text.</p></section><section id="secondary"><h2>Deferred section</h2><p>This background comes from the generated CSS.</p></section></main><pre id="results" aria-label="Measurements">Measuring…</pre>${measurements}</body></html>`);
}).listen(port, '127.0.0.1', () => console.log(`PBB browser lab: http://127.0.0.1:${port}/?mode=deferred (CSS delay ${delay} ms); editor /builder`));
