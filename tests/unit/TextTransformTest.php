<?php
/**
 * The four transforms decide what a visitor receives. Three of them have
 * shipped a regression that reached production, and the builder preview shows
 * the unminified source, so a break here is invisible to the author and total
 * for the visitor.
 *
 * These cases are written against correct behaviour, not current behaviour.
 */

use PHPUnit\Framework\TestCase;

final class TextTransformTest extends TestCase {

	// ---------------------------------------------------------------- minify_js

	public function test_leading_line_comment_does_not_swallow_the_file(): void {
		$js = "// set things up\nvar a = 1;\nconsole.log(a);";
		$out = gt_pb_text::minify_js( $js );
		$this->assertStringContainsString( 'var a', $out );
		// Containing the text is not enough: if the comment survives and the
		// newline after it does not, every statement is inside that comment.
		$this->assertTrue(
			! str_contains( $out, '//' ) || str_contains( substr( $out, (int) strpos( $out, '//' ) ), "\n" ),
			'a first-line // comment survived with no newline after it, so the whole script is commented out'
		);
	}

	public function test_semicolon_free_source_keeps_its_statement_breaks(): void {
		$js  = "let a = 1\nlet b = 2\nconsole.log(a + b)";
		$out = gt_pb_text::minify_js( $js );
		// Without a newline the three statements concatenate and ASI cannot save them.
		$this->assertStringContainsString( "\n", $out, 'newlines were stripped, so ASI-reliant code collapses to one statement' );
	}

	public function test_apostrophe_inside_a_comment_does_not_eat_the_rest(): void {
		$js  = "var a = 1; // don't do this\nvar b = 2;";
		$out = gt_pb_text::minify_js( $js );
		$this->assertStringContainsString( 'var b', $out );
	}

	public function test_double_slash_inside_a_string_is_not_a_comment(): void {
		$js  = "var url = 'http://example.com/x'; var after = 1;";
		$out = gt_pb_text::minify_js( $js );
		$this->assertStringContainsString( 'http://example.com/x', $out );
		$this->assertStringContainsString( 'after', $out );
	}

	public function test_double_slash_inside_a_regex_literal_is_not_a_comment(): void {
		$js  = "var re = /a\\/\\/b/; var after = 1;";
		$out = gt_pb_text::minify_js( $js );
		$this->assertStringContainsString( 'after', $out );
	}

	public function test_block_comments_are_still_removed(): void {
		$out = gt_pb_text::minify_js( "/* gone */ var a = 1;" );
		$this->assertStringNotContainsString( 'gone', $out );
		$this->assertStringContainsString( 'var a', $out );
	}

	// --------------------------------------------------------------- minify_css

	public function test_descendant_hover_selector_survives(): void {
		$out = gt_pb_text::minify_css( '.menu :hover { color: red }' );
		$this->assertStringContainsString( '.menu :hover', $out, '.menu :hover collapsed into .menu:hover, changing what it matches' );
	}

	public function test_unquoted_svg_data_uri_survives(): void {
		$css = '.x{background:url(data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>)}';
		$out = gt_pb_text::minify_css( $css );
		$this->assertStringContainsString( 'data:image/svg+xml', $out );
		$this->assertStringContainsString( '<svg', $out );
	}

	public function test_comment_open_inside_a_content_string_is_not_a_comment(): void {
		$out = gt_pb_text::minify_css( '.a{content:"/*"}.b{color:red}' );
		$this->assertStringContainsString( 'color:red', $out );
	}

	public function test_brace_inside_a_content_string_does_not_end_the_rule(): void {
		$out = gt_pb_text::minify_css( '.a{content:"}"}.b{color:red}' );
		$this->assertStringContainsString( 'color:red', $out );
	}

	public function test_attribute_selector_with_a_space_is_preserved(): void {
		$out = gt_pb_text::minify_css( '[style*="font-weight: 300"]{color:red}' );
		$this->assertStringContainsString( 'font-weight: 300', $out );
	}

	public function test_calc_and_clamp_keep_their_spaces(): void {
		$out = gt_pb_text::minify_css( '.a{width:calc(100% - 10px);height:clamp(1rem, 2vw, 3rem)}' );
		$this->assertStringContainsString( '100% - 10px', $out, 'calc() lost the spaces around its operator, which makes it invalid' );
		$this->assertStringContainsString( 'clamp(', $out );
	}

	public function test_ordinary_css_is_still_minified(): void {
		$out = gt_pb_text::minify_css( ".a {\n  color: red;\n}\n\n/* c */\n.b { color: blue }" );
		$this->assertStringNotContainsString( '/* c */', $out );
		$this->assertLessThan( 45, strlen( $out ) );
	}

	// ------------------------------------------------------------- sanitize_css

	public function test_sanitize_strips_script_and_behaviour_vectors(): void {
		$out = gt_pb_text::sanitize_css( 'a{color:red}</style><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script', $out );
	}

	public function test_sanitize_keeps_an_unquoted_svg_data_uri(): void {
		$css = '.x{background:url(data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg"></svg>)}';
		$out = gt_pb_text::sanitize_css( $css );
		$this->assertStringContainsString( 'data:image/svg+xml', $out );
		$this->assertStringContainsString( '<svg', $out, 'the sanitiser emptied a legitimate inline SVG background, leaving url(data:image/svg+xml;utf8,)' );
	}

	/**
	 * The data: stash added for inline SVG must not become a way to smuggle
	 * an html payload past the sanitiser.
	 *
	 * @dataProvider blockedDataUris
	 */
	public function test_sanitize_still_blocks_dangerous_data_uris( string $css ): void {
		$out = gt_pb_text::sanitize_css( $css );
		$this->assertStringNotContainsString( 'data:text/html', $out );
	}

	public static function blockedDataUris(): array {
		return [
			'plain'        => [ '.x{background:url(data:text/html,<script>alert(1)</script>)}' ],
			'quoted'       => [ '.x{background:url("data:text/html,<b>x</b>")}' ],
			'spaced'       => [ '.x{background:url( data:text/html,x )}' ],
			'mixed case'   => [ '.x{background:url(DATA:TEXT/HTML,x)}' ],
		];
	}

	public function test_sanitize_still_strips_script_tags_and_vectors(): void {
		$this->assertStringNotContainsString( '<script', gt_pb_text::sanitize_css( 'a{}<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( 'javascript:', gt_pb_text::sanitize_css( 'a{background:url(javascript:alert(1))}' ) );
		$this->assertStringNotContainsString( 'expression(', gt_pb_text::sanitize_css( 'a{width:expression(alert(1))}' ) );
		$this->assertStringNotContainsString( '-moz-binding:', gt_pb_text::sanitize_css( 'a{-moz-binding:url(x)}' ) );
	}

	public function test_minified_css_is_still_actually_minified(): void {
		$out = gt_pb_text::minify_css( ".a {\n  color: red;\n  margin: 0 auto;\n}" );
		$this->assertSame( '.a{color:red;margin:0 auto}', $out );
	}

	public function test_minified_js_still_removes_a_leading_comment(): void {
		$out = gt_pb_text::minify_js( "// set up\nvar a = 1;\nconsole.log(a);" );
		$this->assertSame( 'var a = 1;console.log(a);', $out );
	}

	// --------------------------------------------------------------- minify_html

	public function test_pre_content_is_not_collapsed(): void {
		$out = gt_pb_text::minify_html( "<pre>  keep\n  this  </pre>" );
		$this->assertStringContainsString( "keep\n  this", $out, 'whitespace inside <pre> is significant and was collapsed' );
	}

	public function test_textarea_content_is_not_collapsed(): void {
		$out = gt_pb_text::minify_html( "<textarea>  a  b  </textarea>" );
		$this->assertStringContainsString( '  a  b  ', $out );
	}

	public function test_ordinary_markup_is_collapsed(): void {
		$out = gt_pb_text::minify_html( "<div>   <p>a</p>\n\n   <span>b</span>   </div>" );
		$this->assertStringNotContainsString( "\n\n", $out );
		$this->assertStringContainsString( '<p>a</p>', $out );
	}

	// --------------------------------------------------------------------- misc

	/** @dataProvider emptyish */
	public function test_transforms_survive_empty_input( string $in ): void {
		foreach ( [ 'sanitize_css', 'minify_css', 'minify_js', 'minify_html' ] as $m ) {
			$this->assertIsString( gt_pb_text::$m( $in ) );
		}
	}

	public static function emptyish(): array {
		return [ 'empty' => [ '' ], 'space' => [ ' ' ], 'newlines' => [ "\n\n" ] ];
	}
}
