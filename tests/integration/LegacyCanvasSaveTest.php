<?php
/** The canvas release must preserve old sections through the real save handler. */
use PHPUnit\Framework\TestCase;

final class LegacyCanvasSaveTest extends TestCase {
	private int $post_id    = 0;
	private int $library_id = 0;

	protected function tearDown(): void {
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true ); }
		if ( $this->library_id ) {
			( new gt_pb_db() )->delete( $this->library_id ); }
	}

	private function sections(): array {
		$method = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'get_builder_sections_from_post' );
		return $method->invoke( $GLOBALS['gt_page_blocks_builder'], $this->post_id );
	}

	private function save( array $sections, ?string $hash = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve test request state; the real handler verifies the generated nonce.
		$old_post = $_POST;
		$_POST    = wp_slash(
			array(
				'post_id'       => $this->post_id,
				'pb_nonce'      => wp_create_nonce( gt_page_blocks_builder_nonce_action( $this->post_id ) ),
				'sections'      => wp_json_encode( $sections ),
				'content_hash'  => $hash ?? hash( 'sha256', get_post_field( 'post_content', $this->post_id, 'raw' ) ),
				'page_template' => 'default-template',
			)
		);
		$handler  = static function () {
			return static function () {
				throw new RuntimeException( 'json-response' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $handler );
		ob_start();
		try {
			$GLOBALS['gt_page_blocks_builder']->ajax_builder_apply();
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

	public function test_library_creation_returns_its_id_when_saved_hook_writes_other_data(): void {
		$option = 'pbb-library-id-qa-' . wp_generate_uuid4();
		$hook   = static function () use ( $option ) {
			add_option( $option, 'A cache integration writes an option' );
		};
		add_action( 'gt_pb_block_saved', $hook );
		try {
			$this->library_id = (int) ( new gt_pb_db() )->insert(
				array(
					'title'   => 'Stable library identity fixture',
					'content' => 'Linked content',
				)
			);
			$row              = ( new gt_pb_db() )->get( $this->library_id );
			$this->assertNotNull( $row );
			$this->assertSame( 'Stable library identity fixture', $row->title );
		} finally {
			remove_action( 'gt_pb_block_saved', $hook );
			delete_option( $option );
		}
	}

	public function test_real_save_keeps_legacy_source_linked_and_opaque_blocks(): void {
		$this->library_id = (int) ( new gt_pb_db() )->insert(
			array(
				'title'   => 'Legacy canvas save fixture',
				'content' => '<p>Reusable legacy content</p>',
				'status'  => 'publish',
			)
		);
		$attrs            = array(
			'content'   => '<section class="old"><p>Café "quoted" &amp; linked</p></section>',
			'css'       => '.old{padding:2rem;background:url("/asset.svg")}',
			'js'        => 'window.legacyValue = "preserved";',
			'cssOutput' => 'file',
			'cssDefer'  => true,
		);
		$make             = static fn( $name, $attributes ) => serialize_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$opaque           = '<!-- wp:group --><div class="wp-block-group">' . $make( 'marketers-delight/page-block', array( 'content' => '<p>Nested legacy section</p>' ) ) . '<!-- wp:acme/custom {"arbitrary":{"keep":"exact"}} --><aside>Third-party content</aside><!-- /wp:acme/custom --></div><!-- /wp:group -->';
		$prototype        = array(
			'content'    => '<section>Prototype fallback</section>',
			'css'        => 'section{color:red}',
			'visualData' => array(
				'version' => 1,
				'root'    => array( 'type' => 'section' ),
			),
		);
		$raw              = implode(
			"\n\n",
			array(
				$make( 'marketers-delight/page-block', $attrs ),
				'<!-- wp:paragraph --><p>Core content</p><!-- /wp:paragraph -->',
				$opaque,
				$make(
					GT_Page_Blocks_Builder::BLOCK_NAME,
					array(
						'blockId'           => $this->library_id,
						'respectConditions' => true,
					)
				),
				$make( GT_Page_Blocks_Builder::BLOCK_NAME, $prototype ),
			)
		);
		$this->post_id    = (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Legacy canvas save QA',
					'post_content' => $raw,
				)
			)
		);
		$before           = $this->sections();
		$this->assertCount( 5, $before );
		$this->assertSame( $attrs['content'], $before[0]['content'] );
		$dedup          = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'inline_css_done' );
		$original_dedup = $dedup->getValue( $GLOBALS['gt_page_blocks_builder'] );
		$dedup->setValue( $GLOBALS['gt_page_blocks_builder'], array() );
		$rendered = do_blocks( get_post_field( 'post_content', $this->post_id, 'raw' ) );
		$result   = $this->save( $before );
		$this->assertTrue( $result['success'] );
		$after = $this->sections();
		$this->assertCount( 5, $after );
		foreach ( $attrs as $key => $value ) {
			$this->assertSame( $value, $after[0][ $key ], $key . ' changed during save' ); }
		$this->assertSame( $before[1]['serialized'], $after[1]['serialized'] );
		$this->assertSame( $opaque, $after[2]['serialized'] );
		$this->assertSame( $this->library_id, $after[3]['blockId'] );
		$this->assertTrue( $after[3]['respectConditions'] );
		$this->assertSame( $prototype['visualData'], $after[4]['visualData'] );
		$dedup->setValue( $GLOBALS['gt_page_blocks_builder'], array() );
		$this->assertSame( $rendered, do_blocks( get_post_field( 'post_content', $this->post_id, 'raw' ) ) );
		$dedup->setValue( $GLOBALS['gt_page_blocks_builder'], $original_dedup );
	}

	public function test_missing_foreign_blocks_and_stale_save_do_not_change_content(): void {
		$raw           = '<!-- wp:gt-page-block/page-block {"content":"Old section"} /-->' . "\n\n" . '<!-- wp:acme/custom --><p>Keep me</p><!-- /wp:acme/custom -->';
		$this->post_id = (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Legacy save guards QA',
					'post_content' => $raw,
				)
			)
		);
		$sections      = $this->sections();
		$this->assertFalse( $this->save( array( $sections[0] ) )['success'] );
		$this->assertSame( $raw, get_post_field( 'post_content', $this->post_id, 'raw' ) );
		$this->assertFalse( $this->save( $sections, str_repeat( '0', 64 ) )['success'] );
		$this->assertSame( $raw, get_post_field( 'post_content', $this->post_id, 'raw' ) );
	}
}
