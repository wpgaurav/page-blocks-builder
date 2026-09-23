<?php
use PHPUnit\Framework\TestCase;

final class SectionCssLifecycleTest extends TestCase {
	private array $posts = array();

	private function create( array $sections ): int {
		$blocks = array_map( static function ( $attrs ) {
			return serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		}, $sections );
		$id = wp_insert_post( wp_slash( array( 'post_title' => 'CSS lifecycle fixture', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => implode( "\n", $blocks ) ) ) );
		$this->posts[] = $id;
		return $id;
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) wp_delete_post( $id, true );
	}

	private function path( string $file ): string {
		return wp_upload_dir()['basedir'] . '/gt-page-blocks/' . $file;
	}

	public function test_warm_render_does_not_rewrite_files_or_metadata(): void {
		$sections = array();
		for ( $i = 0; $i < 20; $i++ ) $sections[] = array( 'css' => '.warm-' . $i . '{color:red}', 'cssOutput' => 'file', 'cssDefer' => (bool) ( $i % 2 ) );
		$id = $this->create( $sections );
		$assets = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		$stamp = time() - 120;
		foreach ( $assets as $asset ) touch( $this->path( $asset['file'] ), $stamp );
		$writes = 0;
		$observe = static function ( $sql ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) $writes++;
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			foreach ( $sections as $section ) gt_pb_section_css::render( $section['css'], $id, $section['cssDefer'] );
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( 0, $writes, 'A warm view must not write to the database.' );
		$this->assertSame( $assets, get_post_meta( $id, gt_pb_section_css::META_KEY, true ) );
		foreach ( $assets as $asset ) {
			clearstatcache( true, $this->path( $asset['file'] ) );
			$this->assertSame( $stamp, filemtime( $this->path( $asset['file'] ) ) );
		}
	}

	public function test_unavailable_upload_directory_falls_back_to_inline_without_broken_links(): void {
		$blocker = tempnam( sys_get_temp_dir(), 'pbb-upload-blocker-' );
		$override = static function ( $dir ) use ( $blocker ) { $dir['basedir'] = $blocker; return $dir; };
		add_filter( 'upload_dir', $override );
		try {
			$css = '.storage-fallback{color:green}';
			$id = $this->create( array( array( 'css' => $css, 'cssOutput' => 'file', 'cssDefer' => true ) ) );
			$output = gt_pb_section_css::render( $css, $id, true );
			$this->assertEmpty( get_post_meta( $id, gt_pb_section_css::META_KEY, true ) );
			$this->assertSame( '<style>' . $css . '</style>' . "\n", $output );
		} finally {
			remove_filter( 'upload_dir', $override );
			unlink( $blocker );
		}
	}

	public function test_same_css_on_different_pages_never_reuses_the_other_pages_url(): void {
		$attrs = array( 'css' => '.shared-page{color:red}', 'cssOutput' => 'file', 'cssDefer' => true );
		$a = $this->create( array( $attrs ) );
		$b = $this->create( array( $attrs ) );
		$this->assertStringContainsString( '/page-' . $a . '-', gt_pb_section_css::render( $attrs['css'], $a, true ) );
		$this->assertStringContainsString( '/page-' . $b . '-', gt_pb_section_css::render( $attrs['css'], $b, true ) );
	}

	public function test_changing_css_keeps_cached_file_valid_but_serves_new_content(): void {
		$attrs = array( 'css' => '.revision{color:red}', 'cssOutput' => 'file', 'cssDefer' => true );
		$id = $this->create( array( $attrs ) );
		$before = get_post_meta( $id, gt_pb_section_css::META_KEY, true )[0];
		$post = get_post( $id );
		$blocks = parse_blocks( $post->post_content );
		$blocks[0]['attrs']['css'] = '.revision{color:blue}';
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ) ) );
		$after = get_post_meta( $id, gt_pb_section_css::META_KEY, true )[0];
		$this->assertNotSame( $before['file'], $after['file'] );
		$this->assertSame( '.revision{color:red}', file_get_contents( $this->path( $before['file'] ) ) );
		$this->assertSame( '.revision{color:blue}', file_get_contents( $this->path( $after['file'] ) ) );
	}

	public function test_pruning_removes_only_expired_files_owned_by_this_post(): void {
		$id = $this->create( array( array( 'css' => '.prune{color:red}', 'cssOutput' => 'file' ) ) );
		$old = get_post_meta( $id, gt_pb_section_css::META_KEY, true )[0]['file'];
		touch( $this->path( $old ), time() - WEEK_IN_SECONDS - 60 );
		$recent = 'page-' . $id . '-abcde.css';
		$foreign = 'unrelated-' . $id . '.css';
		file_put_contents( $this->path( $recent ), 'recent' );
		file_put_contents( $this->path( $foreign ), 'not ours' );
		touch( $this->path( $foreign ), time() - WEEK_IN_SECONDS - 60 );
		try {
			wp_update_post( array( 'ID' => $id, 'post_title' => 'Rotate' ) );
			$this->assertFileDoesNotExist( $this->path( $old ) );
			$this->assertFileExists( $this->path( $recent ) );
			$this->assertFileExists( $this->path( $foreign ) );
		} finally {
			unlink( $this->path( $foreign ) );
		}
	}

	public function test_head_preserves_mixed_file_order_and_never_includes_library_or_inline_css(): void {
		$sections = array(
			array( 'css' => '.first{color:red}', 'cssOutput' => 'file' ),
			array( 'css' => '.inline{color:red}', 'cssOutput' => 'inline' ),
			array( 'css' => '.library{color:red}', 'cssOutput' => 'file', 'blockId' => 999999 ),
			array( 'css' => '.last{color:blue}', 'cssOutput' => 'file', 'cssDefer' => true ),
		);
		$id = $this->create( $sections );
		$assets = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		$this->assertSame( array( 0, 3 ), array_keys( $assets ) );
		$previous = $GLOBALS['wp_query'];
		try {
			$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id, 'post_status' => 'draft' ) );
			ob_start(); gt_pb_section_css::print_head(); $out = ob_get_clean();
			$this->assertLessThan( strpos( $out, $assets[3]['file'] ), strpos( $out, $assets[0]['file'] ) );
			$this->assertSame( 3, substr_count( $out, '<link ' ), 'Two live links and one noscript fallback.' );
			$this->assertStringNotContainsString( '.inline', $out );
			$this->assertStringNotContainsString( '.library', $out );
		} finally { $GLOBALS['wp_query'] = $previous; }
	}

	public function test_builder_and_server_preview_requests_do_not_emit_frontend_file_links(): void {
		$id = $this->create( array( array( 'css' => '.preview-skip{color:red}', 'cssOutput' => 'file', 'cssDefer' => true ) ) );
		$previous_query = $GLOBALS['wp_query']; $previous_get = $_GET;
		try {
			$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id, 'post_status' => 'draft' ) );
			foreach ( array( array( 'build' => 'page-blocks' ), array( 'gt_pb_preview' => '1' ) ) as $query ) {
				$_GET = $query;
				ob_start(); gt_pb_section_css::print_head(); $out = ob_get_clean();
				$this->assertSame( '', $out );
			}
		} finally { $GLOBALS['wp_query'] = $previous_query; $_GET = $previous_get; }
	}
	public function test_one_parse_covers_head_and_all_body_sections(): void {
		$sections = array();
		for ( $i = 0; $i < 12; $i++ ) $sections[] = array( 'css' => '.parse-' . $i . '{color:red}', 'cssOutput' => 'file' );
		$id = $this->create( $sections );
		clean_post_cache( $id );
		$previous = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id, 'post_status' => 'draft' ) );
		$parses = 0;
		$observe = static function ( $class ) use ( &$parses ) { $parses++; return $class; };
		add_filter( 'block_parser_class', $observe );
		try {
			ob_start(); gt_pb_section_css::print_head(); ob_end_clean();
			foreach ( $sections as $section ) $this->assertSame( '', gt_pb_section_css::render( $section['css'], $id ) );
			$this->assertSame( 1, $parses );
		} finally { remove_filter( 'block_parser_class', $observe ); $GLOBALS['wp_query'] = $previous; }
	}

	public function test_deferred_output_uses_a_filterable_external_script_without_inline_handlers(): void {
		$id = $this->create( array( array( 'css' => '.csp{color:red}', 'cssOutput' => 'file', 'cssDefer' => true ) ) );
		$script_filter = static function ( $attrs ) { $attrs['nonce'] = 'fixture-nonce'; return $attrs; };
		add_filter( 'wp_script_attributes', $script_filter );
		try {
			// Simulate a fresh request even when another test printed the loader.
			if ( property_exists( gt_pb_section_css::class, 'loader_printed' ) ) ( new ReflectionProperty( gt_pb_section_css::class, 'loader_printed' ) )->setValue( null, false );
			$out = gt_pb_section_css::render( '.csp{color:red}', $id, true );
			$this->assertStringContainsString( 'data-gt-pb-deferred', $out );
			$this->assertStringContainsString( 'deferred-css.js', $out );
			$this->assertStringContainsString( 'nonce="fixture-nonce"', $out );
			$this->assertStringNotContainsString( ' onload=', $out );
			$this->assertStringContainsString( '<noscript><link rel="stylesheet"', $out );
		} finally { remove_filter( 'wp_script_attributes', $script_filter ); }
	}

	public function test_metadata_change_invalidates_the_request_manifest(): void {
		$id = $this->create( array( array( 'css' => '.meta-refresh{color:red}', 'cssOutput' => 'file' ) ) );
		$assets = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
		$replacement = 'page-' . $id . '-12345.css';
		copy( $this->path( $assets[0]['file'] ), $this->path( $replacement ) );
		$assets[0]['file'] = $replacement;
		update_post_meta( $id, gt_pb_section_css::META_KEY, $assets );
		$this->assertStringContainsString( $replacement, gt_pb_section_css::render( '.meta-refresh{color:red}', $id ) );
	}

	public function test_missing_file_after_a_cached_render_is_repaired(): void {
		$id = $this->create( array( array( 'css' => '.cached-repair{color:red}', 'cssOutput' => 'file' ) ) );
		$file = get_post_meta( $id, gt_pb_section_css::META_KEY, true )[0]['file'];
		gt_pb_section_css::render( '.cached-repair{color:red}', $id );
		wp_delete_file( $this->path( $file ) );
		$this->assertSame( '', gt_pb_section_css::render( '.cached-repair{color:red}', $id ) );
		$this->assertFileExists( $this->path( $file ) );
	}

	public function test_save_in_same_request_changes_the_cached_loading_mode(): void {
		$id = $this->create( array( array( 'css' => '.mode-refresh{color:red}', 'cssOutput' => 'file' ) ) );
		gt_pb_section_css::render( '.mode-refresh{color:red}', $id );
		$blocks = parse_blocks( get_post( $id )->post_content );
		$blocks[0]['attrs']['cssDefer'] = true;
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ) ) );
		$this->assertStringContainsString( 'data-gt-pb-deferred', gt_pb_section_css::render( '.mode-refresh{color:red}', $id, true ) );
	}

}
