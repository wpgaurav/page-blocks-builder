<?php
/** Converted code keeps the native block tree and refuses hidden execution. */
use PHPUnit\Framework\TestCase;

final class NativeCodeContentTest extends TestCase {
	private function helper( array $attrs ): string {
		return serialize_block(
			array(
				'blockName'    => GT_Page_Blocks_Builder::BLOCK_NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	private function native( string $inner ): string {
		return '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
	}

	private function call( string $method, array $args ) {
		return ( new ReflectionMethod( GT_Page_Blocks_Builder::class, $method ) )->invokeArgs( $GLOBALS['gt_page_blocks_builder'], $args );
	}

	public function test_opt_in_preserves_serialized_escapes_and_native_rendering(): void {
		$raw        = $this->native( '<!-- wp:paragraph {"metadata":{"name":"Quote \\u0022 stays escaped"}} --><p>A <strong>native</strong> paragraph</p><!-- /wp:paragraph -->' );
		$normalized = $this->call(
			'normalize_builder_section',
			array(
				array(
					'nativeContent' => true,
					'content'       => $raw,
					'format'        => true,
				),
			)
		);
		$this->assertSame( $raw, $normalized['content'] );
		$this->assertTrue( $normalized['nativeContent'] );
		$this->assertTrue( GT_PB_Native_Content::validate_section( $normalized ) );
		$this->assertSame( do_blocks( $raw ), $GLOBALS['gt_page_blocks_builder']->render_block( $normalized ) );
		$this->assertArrayNotHasKey( 'nativeContent', $this->call( 'normalize_builder_section', array( array( 'content' => '<p>Old code</p>' ) ) ) );
		$this->assertSame( '<p>Old code</p>', $GLOBALS['gt_page_blocks_builder']->render_block( array( 'content' => '<p>Old code</p>' ) ) );
	}

	public function test_preview_keeps_helper_styles_scripts_and_outer_queue(): void {
		$plugin       = $GLOBALS['gt_page_blocks_builder'];
		$queue        = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'footer_scripts' );
		$css          = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'inline_css_done' );
		$original     = $queue->getValue( $plugin );
		$original_css = $css->getValue( $plugin );
		$helper       = $this->helper(
			array(
				'content'    => '<p class="native-copy">Helper</p>',
				'css'        => '.native-copy{color:tomato}',
				'js'         => 'window.nativeHelper=[1,2];',
				'jsLocation' => 'footer',
			)
		);
		$raw          = $this->native( $helper . $helper );
		$outer        = array( 'outer-script' => 'window.outerPreserved=true;' );
		$queue->setValue( $plugin, $outer );
		$css->setValue( $plugin, array( md5( '.native-copy{color:tomato}' ) => true ) );
		try {
			$result = $this->call(
				'build_preview_payload',
				array(
					array(
						array(
							'nativeContent' => true,
							'content'       => $raw,
							'css'           => '.outer-copy{color:blue}',
							'js'            => 'window.outerCopy=true;',
						),
					),
				)
			);
			$this->assertStringContainsString( '.native-copy{color:tomato}', $result['html'] );
			$this->assertStringContainsString( '.outer-copy{color:blue}', $result['css'] );
			$this->assertSame( 1, substr_count( $result['jsFooter'], 'window.nativeHelper' ) );
			$this->assertStringContainsString( 'window.outerCopy', $result['jsFooter'] );
			$this->assertSame( $outer, $queue->getValue( $plugin ) );
			$this->assertSame( array( md5( '.native-copy{color:tomato}' ) => true ), $css->getValue( $plugin ) );
			$queue->setValue( $plugin, array() );
			$css->setValue( $plugin, array() );
			$plugin->render_block(
				array(
					'nativeContent' => true,
					'content'       => $raw,
				)
			);
			$this->assertCount( 1, $queue->getValue( $plugin ) );
		} finally {
			$queue->setValue( $plugin, $original );
			$css->setValue( $plugin, $original_css );
		}
	}

	public function test_dynamic_php_linked_and_recursive_blocks_never_execute(): void {
		$executions = 0;
		register_block_type(
			'pbb-test/dynamic',
			array(
				'render_callback' => static function () use ( &$executions ) {
					++$executions;
					return 'Executed';
				},
			)
		);
		add_shortcode(
			'pbb_native_execution',
			static function () use ( &$executions ) {
				++$executions;
				return 'Executed';
			}
		);
		try {
			$sources = array(
				'<!-- wp:pbb-test/dynamic /-->',
				$this->helper(
					array(
						'content' => '<?php echo "Executed";',
						'phpExec' => true,
					)
				),
				$this->helper( array( 'blockId' => 123 ) ),
				$this->helper( array( 'blockSlug' => 'unsafe-reference' ) ),
				$this->helper(
					array(
						'nativeContent' => true,
						'content'       => '<!-- wp:paragraph --><p>Recursive</p><!-- /wp:paragraph -->',
					)
				),
				$this->helper( array( 'content' => '[pbb_native_execution]' ) ),
				'<!-- wp:paragraph --><p>[pbb_native_execution]</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pbb-test/run"}}}} --><p>Binding</p><!-- /wp:paragraph -->',
			);
			foreach ( $sources as $source ) {
				$section = array(
					'nativeContent' => true,
					'content'       => $this->native( $source ),
				);
				$this->assertInstanceOf( WP_Error::class, GT_PB_Native_Content::validate_section( $section ) );
				$this->assertStringNotContainsString( 'Executed', $GLOBALS['gt_page_blocks_builder']->render_block( $section ) );
				$result = $this->call( 'build_preview_payload', array( array( $section ), true ) );
				$this->assertSame( '', $result['html'] );
				$this->assertNotSame( '', $result['notice'] );
			}
			$this->assertSame( 0, $executions );
			$this->assertTrue(
				GT_PB_Native_Content::validate_section(
					array(
						'nativeContent' => true,
						'content'       => $this->native(
							$this->helper(
								array(
									'content' => '<p>[ordinary prose]</p>',
									'js'      => 'window.values=[pbb_native_execution];',
								)
							)
						),
					)
				)
			);
		} finally {
			unregister_block_type( 'pbb-test/dynamic' );
			remove_shortcode( 'pbb_native_execution' );
		}
	}

	public function test_asset_scan_finds_only_validated_native_helpers(): void {
		$valid  = array(
			'nativeContent' => true,
			'content'       => $this->native(
				$this->helper(
					array(
						'css'    => '.file-copy{color:red}',
						'output' => 'file',
						'js'     => 'window.fileCopy=true;',
					)
				)
			),
		);
		$blocks = GT_Page_Blocks_Builder::find_page_blocks( parse_blocks( $this->helper( $valid ) ) );
		$this->assertCount( 2, $blocks );
		$this->assertSame( 'file', $blocks[1]['attrs']['output'] );
		$invalid = array(
			'nativeContent' => true,
			'content'       => $this->native( $this->helper( array( 'blockId' => 123 ) ) ),
		);
		$this->assertCount( 1, GT_Page_Blocks_Builder::find_page_blocks( parse_blocks( $this->helper( $invalid ) ) ) );
	}

	public function test_real_save_preserves_native_source_and_rejects_invalid_edits(): void {
		$raw     = $this->native( '<!-- wp:paragraph --><p>Keep converted content</p><!-- /wp:paragraph -->' );
		$section = array(
			'nativeContent' => true,
			'content'       => $raw,
		);
		$post_id = (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Native code save QA',
					'post_content' => $this->helper( $section ),
				)
			)
		);
		try {
			$response = $this->request( 'ajax_builder_apply', $post_id, array( $section ) );
			$this->assertTrue( $response['success'] );
			$saved = get_post_field( 'post_content', $post_id, 'raw' );
			$attrs = parse_blocks( $saved )[0]['attrs'];
			$this->assertTrue( $attrs['nativeContent'] );
			$this->assertSame( $raw, $attrs['content'] );
			$section['content'] = '<!-- wp:query /-->';
			$this->assertFalse( $this->request( 'ajax_builder_apply', $post_id, array( $section ) )['success'] );
			$this->assertSame( $saved, get_post_field( 'post_content', $post_id, 'raw' ) );
			$this->assertFalse( $this->request( 'ajax_builder_preview', $post_id, array( $section ) )['success'] );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}

	public function test_library_save_refuses_to_lose_native_content_flag(): void {
		$section = array(
			'nativeContent' => true,
			'content'       => $this->native( '<!-- wp:paragraph --><p>Keep source</p><!-- /wp:paragraph -->' ),
		);
		$result  = $this->request( 'ajax_save_to_library', 0, array( $section ) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'cannot be saved to the code library', $result['data']['message'] );
	}

	private function request( string $method, int $post_id, array $sections ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The real handler verifies the generated test nonce.
		$old_post = $_POST;
		$_POST    = wp_slash(
			array(
				'post_id'      => $post_id,
				'pb_nonce'     => wp_create_nonce( 'ajax_builder_apply' === $method ? gt_page_blocks_builder_nonce_action( $post_id ) : gt_page_blocks_preview_nonce_action( $post_id ) ),
				'sections'     => wp_json_encode( $sections ),
				'content_hash' => hash( 'sha256', get_post_field( 'post_content', $post_id, 'raw' ) ),
			)
		);
		if ( 'ajax_save_to_library' === $method ) {
			$_POST = wp_slash(
				array_merge(
					$sections[0],
					array(
						'nonce' => wp_create_nonce( 'gt_pb_save_to_library' ),
						'title' => 'Native flag preservation QA',
					)
				)
			);
		}
		$handler = static function () {
			return static function () {
				throw new RuntimeException( 'json-response' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			$GLOBALS['gt_page_blocks_builder']->$method();
		} catch ( RuntimeException $error ) {
			if ( 'json-response' !== $error->getMessage() ) {
				throw $error; }
		} finally {
			$output = ob_get_clean();
			$_POST  = $old_post;
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $handler );
		}
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
