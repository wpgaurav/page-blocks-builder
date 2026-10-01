<?php
use PHPUnit\Framework\TestCase;

final class NativePreviewScriptsTest extends TestCase {
	private function block( array $attrs ): string {
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

	private function section( string $content, string $uid = 'pb-scripts' ): array {
		return array(
			'uid'        => $uid,
			'kind'       => 'foreign',
			'serialized' => '<!-- wp:group --><div class="wp-block-group">' . $content . '</div><!-- /wp:group -->',
		);
	}

	public function test_native_preview_collects_nested_footer_scripts_once_and_preserves_outer_queue(): void {
		$plugin = $GLOBALS['gt_page_blocks_builder'];
		$db     = new gt_pb_db();
		$id     = $db->insert(
			array(
				'title'       => 'Nested footer preview fixture',
				'status'      => 'publish',
				'content'     => '<p>Linked content</p>',
				'js'          => 'window.nestedLinked=true;',
				'js_location' => 'footer',
			)
		);
		$this->assertIsInt( $id );
		$queue    = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'footer_scripts' );
		$original = $queue->getValue( $plugin );
		$outer    = array(
			'outer'        => 'window.outerPage=true;',
			'block-' . $id => 'window.outerLink=true;',
		);
		$queue->setValue( $plugin, $outer );
		try {
			$inline_block  = $this->block(
				array(
					'content'    => '<p>Nested content</p>',
					'js'         => 'window.nestedInline=true;',
					'jsLocation' => 'footer',
				)
			);
			$link          = $this->block( array( 'blockId' => $id ) );
			$inline_script = $this->block(
				array(
					'content'    => '<p>Inline script</p>',
					'js'         => 'window.staysInline=true;',
					'jsLocation' => 'inline',
				)
			);
			$sections      = array( $this->section( $inline_block . $link . $inline_block . $link . $inline_script ), $this->section( $link, 'pb-repeat' ) );
			$preview       = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'build_preview_payload' );
			$result        = $preview->invoke( $plugin, $sections );
			$this->assertStringContainsString( 'Nested content', $result['html'] );
			$this->assertStringContainsString( 'Linked content', $result['html'] );
			$this->assertSame( 1, substr_count( $result['jsFooter'], 'window.nestedInline=true' ) );
			$this->assertSame( 1, substr_count( $result['jsFooter'], 'window.nestedLinked=true' ) );
			$this->assertStringNotContainsString( 'window.outer', $result['jsFooter'] );
			$this->assertStringContainsString( 'window.staysInline=true', $result['html'] );
			$this->assertStringNotContainsString( 'window.staysInline', $result['jsFooter'] );
			$this->assertSame( $outer, $queue->getValue( $plugin ) );
			$mixed = $preview->invoke( $plugin, array_merge( $sections, array( array( 'blockId' => $id ) ) ) );
			$this->assertSame( 1, substr_count( $mixed['jsFooter'], 'window.nestedLinked=true' ) );
		} finally {
			$queue->setValue( $plugin, $original );
			$db->delete( $id );
		}
	}

	public function test_native_preview_restores_outer_queue_when_rendering_throws(): void {
		$plugin   = $GLOBALS['gt_page_blocks_builder'];
		$queue    = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'footer_scripts' );
		$original = $queue->getValue( $plugin );
		$outer    = array( 'outer' => 'window.outerPage=true;' );
		$queue->setValue( $plugin, $outer );
		$fail = static function ( $html, $block ) {
			if ( 'core/group' === $block['blockName'] ) {
				throw new RuntimeException( 'Preview fixture failure' );
			}
			return $html;
		};
		add_filter( 'render_block', $fail, 99, 2 );
		try {
			$preview = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'build_preview_payload' );
			$preview->invoke(
				$plugin,
				array(
					$this->section(
						$this->block(
							array(
								'js'         => 'window.nestedBeforeFailure=true;',
								'jsLocation' => 'footer',
							)
						)
					),
				)
			);
			$this->fail( 'The render fixture should throw.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Preview fixture failure', $error->getMessage() );
			$this->assertSame( $outer, $queue->getValue( $plugin ) );
		} finally {
			remove_filter( 'render_block', $fail, 99 );
			$queue->setValue( $plugin, $original );
		}
	}
}
