/** Apply opted-in styles in place, including files loaded before this script. */
(function() {
	'use strict';
	var links = document.querySelectorAll('link[data-gt-pb-deferred]');
	Array.prototype.forEach.call(links, function(link) {
		function apply() {
			link.media = 'all';
			link.removeAttribute('data-gt-pb-deferred');
			link.removeEventListener('load', apply);
		}
		link.addEventListener('load', apply);
		// Stylesheet.sheet is available after a cached or early load. Reading
		// cssRules would fail on a CDN; sheet itself is safe across origins.
		if (link.sheet) apply();
	});
}());
