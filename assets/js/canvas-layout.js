/* Edge clustering, grid solving and snapping adapted from Gogh Editor.
 * Copyright Jamie Marsland; GPL-2.0-or-later. See docs/third-party/gogh-editor.md.
 * Transient geometry becomes CSS; no separate layout model is saved. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbCanvasLayout = factory();
})(typeof window !== 'undefined' ? window : this, function factory() {
	'use strict';
	var TOL = 4;
	function cluster(values, tolerance) {
		var groups = [];
		values.slice().sort(function(a, b) { return a - b; }).forEach(function(value) {
			var group = groups[groups.length - 1];
			if (group && value - group[0] <= tolerance) group.push(value);
			else groups.push([value]);
		});
		return groups.map(function(group) { return group.reduce(function(a, b) { return a + b; }, 0) / group.length; });
	}
	function pin(lines, low, high, tolerance) { return [low].concat(lines.filter(function(line) { return line - low > tolerance && high - line > tolerance; }), [high]); }
	function nearest(value, lines) {
		var best = 0;
		lines.forEach(function(line, index) { if (Math.abs(line - value) < Math.abs(lines[best] - value)) best = index; });
		return best;
	}
	function solve(elements, width, height, precise) {
		var tolerance = precise ? 0 : TOL;
		width = Math.max(1, width);
		height = Math.max(1, height, ...elements.map(function(e) { return e.y + e.h; }));
		var xs = pin(cluster(elements.flatMap(function(e) { return [e.x, e.x + e.w]; }), tolerance), 0, width, tolerance);
		var ys = pin(cluster(elements.flatMap(function(e) { return [e.y, e.y + e.h]; }), tolerance), 0, height, tolerance);
		return {
			columns: xs.slice(1).map(function(x, i) { return +(100 * (x - xs[i]) / width).toFixed(4) + '%'; }),
			rows: ys.slice(1).map(function(y, i) { return 'minmax(' + Math.max(1, Math.round(y - ys[i])) + 'px,max-content)'; }),
			areas: elements.map(function(e) {
				var c1 = nearest(e.x, xs) + 1, r1 = nearest(e.y, ys) + 1;
				return { c1: c1, c2: Math.max(c1 + 1, nearest(e.x + e.w, xs) + 1), r1: r1, r2: Math.max(r1 + 1, nearest(e.y + e.h, ys) + 1) };
			})
		};
	}
	function snap(rect, others, width, height, free) {
		if (free) return { x: Math.round(rect.x), y: Math.round(rect.y), gx: null, gy: null };
		var xs = [0, width / 2, width], ys = [0, height / 2, height];
		others.forEach(function(e) { xs.push(e.x, e.x + e.w / 2, e.x + e.w); ys.push(e.y, e.y + e.h / 2, e.y + e.h); });
		function best(start, span, candidates) {
			var match = null, distance = 7;
			[0, span / 2, span].forEach(function(offset) {
				candidates.forEach(function(value) {
					var d = Math.abs(value - start - offset);
					if (d < distance) { distance = d; match = { value: value - offset, guide: value }; }
				});
			});
			return match;
		}
		var sx = best(rect.x, rect.w, xs), sy = best(rect.y, rect.h, ys);
		return { x: sx ? Math.round(sx.value) : Math.round(rect.x / 8) * 8, y: sy ? Math.round(sy.value) : Math.round(rect.y / 8) * 8, gx: sx ? sx.guide : null, gy: sy ? sy.guide : null };
	}
	function css(scope, elements, width, height, precise) {
		if (!/^pb-layout-[a-z0-9]+$/.test(scope) || !elements.length || elements.some(function(e) { return !/^pb-node-[a-z0-9]+$/.test(e.key) || ![e.x,e.y,e.w,e.h].every(Number.isFinite) || ['ml','mr','mt','mb'].some(function(key) { return e[key] != null && !Number.isFinite(e[key]); }); })) return null;
		var outer=elements.map(function(e) { return Object.assign({},e,{x:e.x - (e.ml || 0),y:e.y - (e.mt || 0),w:Math.max(1,e.w + (e.ml || 0) + (e.mr || 0)),h:Math.max(1,e.h + (e.mt || 0) + (e.mb || 0))}); });
		var grid = solve(outer, width, height, precise);
		var base = '.pb-freeform.' + scope;
		var out = ['/* Page Blocks canvas layout */', base + '{display:grid;position:relative;grid-template-columns:' + grid.columns.join(' ') + ';grid-template-rows:' + grid.rows.join(' ') + ';gap:0;padding:0;min-height:' + Math.round(height) + 'px}', base + '>style{display:none}'];
		elements.forEach(function(e, i) {
			var a = grid.areas[i], selector = base + '>.' + e.key;
			var margin=(e.ml || 0) + (e.mr || 0), boxWidth=margin ? 'calc(100% - ' + +margin.toFixed(3) + 'px)' : '100%';
			var margins=['mt','mr','mb','ml'].map(function(key) { return +(e[key] || 0).toFixed(3) + 'px'; }).join(' ');
			out.push(selector + '{grid-area:' + a.r1 + '/' + a.c1 + '/' + a.r2 + '/' + a.c2 + ';margin:' + margins + ';box-sizing:border-box;min-width:0;width:' + boxWidth + ';min-height:' + Math.round(e.h) + 'px;align-self:start}');
			if (e.type === 'core/image') out.push(selector + '{height:100%}' + selector + ' img{width:100%;height:100%;object-fit:cover}');
		});
		out.push('@media(max-width:768px){' + base + '{display:block;min-height:0;padding:24px}' + base + '> :not(style){width:auto;height:auto;min-height:0;margin:0 0 24px}' + base + '> :last-child{margin-bottom:0}' + base + '>.wp-block-image img{height:auto}}');
		out.push('/* Page Blocks canvas layout end */');
		return out.join('\n');
	}
	return { solve: solve, snap: snap, css: css, script: function() { return 'window.gtPbCanvasLayout=(' + factory.toString() + ')();'; } };
});
