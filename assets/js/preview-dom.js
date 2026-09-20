/** Section ownership without adding elements to the authored page structure. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbPreviewDom = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';

	function sectionHtml(html, section) {
		if (!/^pb-[a-z0-9]+$/.test(section.uid || '')) return html;
		var kind = section.kind === 'foreign' ? 'foreign' : (section.blockId ? 'linked' : 'block');
		return '<!--gt-pb:start:' + section.uid + ':' + kind + '-->' + html + '<!--gt-pb:end:' + section.uid + '-->';
	}

	function markSections(doc) {
		var walker = doc.createTreeWalker(doc.body, 129); // Elements and comments, in document order.
		var active = null, rootIndex = 0, node;
		while ((node = walker.nextNode())) {
			if (node.nodeType === 8) {
				var start = /^gt-pb:start:(pb-[a-z0-9]+):(block|foreign|linked)$/.exec(node.data);
				if (start) { active = { uid: start[1], kind: start[2] }; rootIndex = 0; }
				else if (active && node.data === 'gt-pb:end:' + active.uid) active = null;
				continue;
			}
			if (!active) continue;
			var parent = node.parentElement && node.parentElement.closest('[data-pb-section]');
			if (parent && parent.getAttribute('data-pb-section') === active.uid) continue;
			node.setAttribute('data-pb-section', active.uid);
			node.setAttribute('data-pb-root-index', String(rootIndex++));
			if (active.kind !== 'block') node.setAttribute('data-pb-' + active.kind, '1');
		}
	}

	// Locate a closed source element without serializing the entire fragment.
	// A section may deliberately leave an ancestor open for subsequent sections.
	function sourceElement(html, path) {
		var tags = /<!--[\s\S]*?(?:-->|$)|<\?[\s\S]*?(?:\?>|$)|<![^>]*>|<\/?[a-zA-Z][a-zA-Z0-9:-]*(?:\s+(?:"[^"]*"|'[^']*'|[^'">])*)?\s*\/?>/g;
		var voids = /^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/;
		var raw = /^(script|style|textarea|title)$/;
		var stack = [{ path: [], children: 0 }], found = null, token;
		while ((token = tags.exec(html))) {
			var name = /^<(\/)?([a-zA-Z][a-zA-Z0-9:-]*)/.exec(token[0]);
			if (!name) continue;
			var tag = name[2].toLowerCase();
			if (name[1]) {
				for (var i = stack.length - 1; i > 0; i--) {
					if (stack[i].tag !== tag) continue;
					stack[i].closeStart = token.index;
					stack.length = i;
					break;
				}
				continue;
			}
			var parent = stack[stack.length - 1];
			var element = { tag: tag, path: parent.path.concat(parent.children++), children: 0, openEnd: tags.lastIndex };
			if (element.path.join('.') === path.join('.')) found = element;
			if (voids.test(tag)) continue;
			if (raw.test(tag)) {
				var closing = new RegExp('</' + tag + '\\s*>', 'ig');
				closing.lastIndex = tags.lastIndex;
				var end = closing.exec(html);
				if (!end) break;
				element.closeStart = end.index;
				tags.lastIndex = closing.lastIndex;
			} else {
				stack.push(element);
			}
		}
		return found;
	}

	function replaceInnerHtml(html, edit, doc) {
		if (!Array.isArray(edit.path) || !edit.path.length || !edit.path.every(function(i) { return Number.isInteger(i) && i >= 0; }) ||
			typeof edit.oldHtml !== 'string' || typeof edit.newHtml !== 'string') return null;
		var element = sourceElement(html, edit.path);
		if (!element || typeof element.closeStart !== 'number' || element.tag !== edit.tagName ||
			!/^(h[1-6]|p|li|td|th|figcaption|blockquote|label|cite|dt|dd|summary|a)$/.test(element.tag)) return null;
		var original = html.slice(element.openEnd, element.closeStart);
		// Generated PHP/shortcode output has no reliable editable source range.
		if (original.indexOf('<?') !== -1) return null;
		var check = doc.createElement(element.tag);
		check.innerHTML = original;
		if (check.innerHTML !== edit.oldHtml) {
			// Server previews use gt_pb_text::minify_html. Compare that form as
			// well, while keeping the source outside this element untouched.
			var preserved = [];
			var minified = original.replace(/(<(?:pre|code|script|style|textarea)\b[^>]*>)([\s\S]*?)(<\/(?:pre|code|script|style|textarea)>)/gi, function(value) {
				return '<!--PRESERVED_' + (preserved.push(value) - 1) + '-->';
			}).replace(/<!--(?!\[if\s|PRESERVED_)[\s\S]*?-->/g, '').replace(/>\s+</g, '> <').replace(/\s+/g, ' ');
			minified = minified.replace(/<!--PRESERVED_(\d+)-->/g, function(_, index) { return preserved[Number(index)]; });
			check.innerHTML = minified;
			if (check.innerHTML !== edit.oldHtml) return null;
		}
		return html.slice(0, element.openEnd) + edit.newHtml + html.slice(element.closeStart);
	}

	return { sectionHtml: sectionHtml, markSections: markSections, replaceInnerHtml: replaceInnerHtml };
});
