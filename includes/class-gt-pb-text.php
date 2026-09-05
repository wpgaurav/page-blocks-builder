<?php
/**
 * Pure text transforms for page block output.
 *
 * These four functions decide what a visitor actually receives, and three of
 * them have shipped a regression that reached production. They live here, with
 * no WordPress dependency and no ABSPATH guard, so a bare PHPUnit process can
 * load this one file and exercise them: the main plugin file exits without
 * ABSPATH and constructs the plugin at file scope, which made the cheapest and
 * highest-value tests in the codebase impossible to write.
 *
 * String in, string out. Do not add WordPress calls here.
 *
 * @since 3.0.0
 */

class gt_pb_text {

	/**
	 * Sanitize CSS to strip XSS vectors.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize_css( $css ) {
		$css = (string) $css;
		$css = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $css );

		// Stash url(data:...) payloads before the tag strip below. An inline
		// SVG background is markup by nature, and the generic strip emptied it
		// to url(data:image/svg+xml;utf8,) - a valid-looking rule that renders
		// nothing. text/html payloads are excluded and stay blockable.
		$stash = array();
		$css   = preg_replace_callback(
			'/url\s*\(\s*(["\']?)\s*data\s*:(?!\s*text\/html)[^)]*\1\s*\)/i',
			function ( $matches ) use ( &$stash ) {
				$token           = '___GTPBDATA' . count( $stash ) . '___';
				$stash[ $token ] = $matches[0];
				return $token;
			},
			$css
		);

		$css = preg_replace( '/<[a-z\/!][^>]*>/i', '', $css );
		$css = str_replace( array( 'javascript:', 'expression(', '-moz-binding:', 'behavior:' ), '', $css );
		$css = preg_replace( '/@import\s+url\s*\(\s*["\']?\s*(?:javascript|data)\s*:/i', '@import url(blocked:', $css );
		$css = preg_replace( '/url\s*\(\s*["\']?\s*data\s*:\s*text\/html/i', 'url(blocked:', $css );

		return $stash ? strtr( $css, $stash ) : $css;
	}

	/**
	 * Minify CSS.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function minify_css( $css ) {
		$css = (string) $css;

		// Comments go first, before anything is stashed, so a commented-out
		// quote cannot unbalance the string scan below.
		$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );

		// Quoted strings and url() payloads are literal values: whitespace
		// inside them is significant. Collapsing it silently rewrites
		// selectors — [style*="font-weight: 300"] and
		// [style*="font-weight:300"] are different selectors, and squashing
		// the first into the second drops every element it used to match.
		// Stash them, minify the structure, then put them back verbatim.
		$stash = array();

		// Math functions must be stashed BEFORE the structural pass: the space
		// around `+` is required inside calc()/clamp()/min()/max(), but `+` is
		// also the sibling combinator, where collapsing it is correct. Removing
		// it inside a clamp silently invalidates the whole declaration — that
		// is how `clamp(6.75rem, 6rem + 2.2vw, 9rem)` became `6rem+2.2vw` and
		// section padding computed to 0. (?1) recurses the parenthesised group
		// so nested var()/calc() are captured whole.
		$css = preg_replace_callback(
			'/\b(?:calc|clamp|min|max|minmax)\s*(\((?:[^()]++|(?1))*\))/i',
			static function ( $m ) use ( &$stash ) {
				$token = "\x01" . count( $stash ) . "\x02";
				$stash[ $token ] = $m[0];
				return $token;
			},
			$css
		);

		$css   = preg_replace_callback(
			'/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\burl\([^)\'"]*\)/s',
			static function ( $m ) use ( &$stash ) {
				$token = "\x01" . count( $stash ) . "\x02";
				$stash[ $token ] = $m[0];
				return $token;
			},
			$css
		);

		$css = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([\{\};,~+])\s*/', '$1', $css );
		// ':' is deliberately not in that class. A space before a colon is
		// meaningful in a selector - '.menu :hover' matches a descendant,
		// '.menu:hover' matches the element itself - so only trailing space is
		// removed, which is what 'color: red' needs.
		$css = preg_replace( '/:[ ]+/', ':', $css );
		$css = preg_replace( '/\s*>(?!=)\s*/', '>', $css );
		$css = preg_replace( '/;}/', '}', $css );
		$css = trim( (string) $css );

		return $stash ? strtr( $css, $stash ) : $css;
	}

	/**
	 * Minify JS.
	 *
	 * @param string $js JS.
	 * @return string
	 */
	public static function minify_js( $js ) {
		$js = (string) $js;

		$preserved = array();
		$js = preg_replace_callback(
			'/([\'"`])(?:(?!\\1)[^\\\\]|\\\\.)*\\1/s',
			function ( $matches ) use ( &$preserved ) {
				$key               = '___JSSTR_' . count( $preserved ) . '___';
				$preserved[ $key ] = $matches[0];
				return $key;
			},
			$js
		);

		$js = preg_replace( '#/\*(?!!).*?\*/#s', '', $js );
		// (^|...) rather than a lookbehind: a lookbehind cannot match at offset
		// 0, so a comment on the first line survived the strip.
		$js = preg_replace( '#(^|[\s;{}(,=])//(?!/)[^\n]*#m', '$1', $js );

		// Collapse horizontal whitespace only, and keep one newline. Newlines
		// are statement terminators in semicolon-free JavaScript: turning them
		// into spaces silently concatenated every statement in the file.
		$js = str_replace( array( "\r\n", "\r" ), "\n", $js );
		$js = str_replace( "\t", ' ', $js );
		$js = preg_replace( '/[ ]+/', ' ', $js );
		$js = preg_replace( '/ *\n[ \n]*/', "\n", $js );
		$js = preg_replace( '/[ \n]*([{};,])[ \n]*/', '$1', $js );

		$js = str_replace( array_keys( $preserved ), array_values( $preserved ), $js );

		return trim( (string) $js );
	}

	/**
	 * Minify HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function minify_html( $html ) {
		$html      = (string) $html;
		$preserved = array();

		$html = preg_replace_callback(
			'#(<(?:pre|code|script|style|textarea)\\b[^>]*>)(.*?)(</(?:pre|code|script|style|textarea)>)#si',
			function ( $matches ) use ( &$preserved ) {
				$key             = '<!--PRESERVED_' . count( $preserved ) . '-->';
				$preserved[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		$html = preg_replace( '/<!--(?!\\[if\\s|PRESERVED_).*?-->/s', '', $html );
		$html = preg_replace( '/>\\s+</', '> <', $html );
		$html = preg_replace( '/\\s+/', ' ', $html );
		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );

		return trim( (string) $html );
	}
}
