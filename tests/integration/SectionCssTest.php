<?php
use PHPUnit\Framework\TestCase;

final class SectionCssTest extends TestCase {
	private array $posts = array();

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) wp_delete_post( $id, true );
	}

	private function block( array $attrs ): string {
		return serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	}

	private function post( string $content ): int {
		$id = wp_insert_post( array( 'post_title' => 'Section CSS fixture', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => wp_slash( $content ) ) );
		$this->posts[] = $id;
		return $id;
	}

	private function path( string $name ): string {
		return wp_upload_dir()['basedir'] . '/gt-page-blocks/' . $name;
	}

	public function test_inline_is_default_and_gutenberg_keeps_the_new_attribute(): void {
		$id = $this->post( $this->block( array( 'content' => '<p>Inline</p>', 'css' => '.inline{color:red}' ) ) );
		$this->assertEmpty( get_post_meta( $id, gt_pb_section_css::META_KEY, true ) );
		foreach ( array( GT_Page_Blocks_Builder::BLOCK_NAME, GT_Page_Blocks_Builder::LEGACY_BLOCK_NAME ) as $name ) {
			$this->assertArrayHasKey( 'cssOutput', WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes );
		}
	}

	public function test_identical_sections_have_separate_files_and_reads_keep_the_names(): void {
		$attrs = array( 'content' => '<p>Duplicate</p>', 'css' => '.duplicate { color: red; }', 'cssOutput' => 'file' );
		$id = $this->post( $this->block( $attrs ) . "\n\n" . $this->block( $attrs ) );
		$assets = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		$this->assertCount( 2, $assets );
		$this->assertNotSame( $assets[0]['file'], $assets[1]['file'] );
		foreach ( $assets as $asset ) {
			$this->assertMatchesRegularExpression( '/^page-' . $id . '-[a-z0-9]{5}\.css$/', $asset['file'] );
			$this->assertSame( '.duplicate{color:red}', file_get_contents( $this->path( $asset['file'] ) ) );
		}
		gt_pb_section_css::render( $attrs['css'], $id );
		$this->assertSame( $assets, get_post_meta( $id, gt_pb_section_css::META_KEY, true ) );
	}

	public function test_title_only_update_rotates_each_file_and_retains_cached_urls(): void {
		$id = $this->post( $this->block( array( 'content' => '<p>A</p>', 'css' => '.a{color:red}', 'cssOutput' => 'file' ) ) );
		$before = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'New title' ) );
		$after = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		$this->assertNotSame( $before[0]['file'], $after[0]['file'] );
		$this->assertFileExists( $this->path( $before[0]['file'] ) );
		$this->assertFileExists( $this->path( $after[0]['file'] ) );
	}

	public function test_switching_back_to_inline_removes_the_active_file_mapping(): void {
		$attrs = array( 'content' => '<p>A</p>', 'css' => '.a{color:red}', 'cssOutput' => 'file' );
		$id = $this->post( $this->block( $attrs ) );
		$attrs['cssOutput'] = 'inline';
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $this->block( $attrs ) ) ) );
		$this->assertEmpty( get_post_meta( $id, gt_pb_section_css::META_KEY, true ) );
		$this->assertStringContainsString( '<style>', $GLOBALS['gt_page_blocks_builder']->render_block( $attrs ) );
	}

	public function test_missing_file_is_rebuilt_at_the_same_url_and_page_delete_cleans_files(): void {
		$css = '.rebuild{color:blue}';
		$id = $this->post( $this->block( array( 'css' => $css, 'cssOutput' => 'file' ) ) );
		$asset = get_post_meta( $id, gt_pb_section_css::META_KEY, true )[0];
		wp_delete_file( $this->path( $asset['file'] ) );
		gt_pb_section_css::render( $css, $id );
		$this->assertFileExists( $this->path( $asset['file'] ) );
		wp_delete_post( $id, true );
		$this->assertFileDoesNotExist( $this->path( $asset['file'] ) );
	}

	public function test_server_preview_retains_uids_foreign_blocks_and_hidden_state(): void {
		$method = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'build_preview_payload' );
		$payload = $method->invoke( $GLOBALS['gt_page_blocks_builder'], array(
			array( 'uid' => 'pb-first', 'content' => '<p>First</p>' ),
			array( 'uid' => 'pb-foreign', 'kind' => 'foreign', 'serialized' => '<!-- wp:paragraph --><p>Core paragraph</p><!-- /wp:paragraph -->' ),
			array( 'uid' => 'pb-hidden', 'content' => '<p>Hidden</p>', 'css' => '.hidden{}', 'collapsed' => true ),
		) );
		$this->assertStringContainsString( 'data-pb-section="pb-first"', $payload['html'] );
		$this->assertStringContainsString( 'data-pb-section="pb-foreign" data-pb-foreign="1"', $payload['html'] );
		$this->assertStringContainsString( 'Core paragraph', $payload['html'] );
		$this->assertStringNotContainsString( 'Hidden', $payload['html'] );
		$this->assertStringNotContainsString( '.hidden', $payload['css'] );
	}
}
