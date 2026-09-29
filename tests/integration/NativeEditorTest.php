<?php
use PHPUnit\Framework\TestCase;

final class NativeEditorTest extends TestCase {
	public function test_prototype_uses_its_stored_fallback_without_a_custom_compiler(): void {
		$input = array(
			'content' => '<section id="kept">Saved prototype</section>',
			'css' => '#kept{color:red}',
			'visualData' => array( 'version' => 1, 'root' => array( 'type' => 'section' ) ),
		);
		$method = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'normalize_builder_section' );
		$result = $method->invoke( $GLOBALS['gt_page_blocks_builder'], $input );
		$this->assertSame( $input['content'], $result['content'] );
		$this->assertSame( $input['css'], $result['css'] );
		$this->assertSame( $input['visualData'], $result['visualData'] );
		$this->assertStringContainsString( 'Saved prototype', $GLOBALS['gt_page_blocks_builder']->render_block( $result ) );
	}

	public function test_builder_entry_url_selects_the_native_editor(): void {
		$url = gt_page_blocks_builder_url( 39, 'test-nonce' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'visual', $query['pb_mode'] );
		$this->assertSame( '39', $query['post_id'] );
		$this->assertSame( 'test-nonce', $query['pb_nonce'] );
	}
}
