<?php
/**
 * The 3.0 attribute widening is additive at storage but rewrites delimiters on
 * the first save. These tests pin what must not change.
 */

use PHPUnit\Framework\TestCase;

final class SectionIdentityTest extends TestCase {

	private array $created = array();

	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
	}

	private function make_post( string $content ): int {
		$id = (int) wp_insert_post( array(
			'post_title'   => 'GT PB identity fixture',
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_content' => $content,
		) );
		$this->created[] = $id;
		return $id;
	}

	public function test_new_attributes_are_registered_on_both_block_names(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( GT_Page_Blocks_Builder::BLOCK_NAME, GT_Page_Blocks_Builder::LEGACY_BLOCK_NAME ) as $name ) {
			$type = $registry->get_registered( $name );
			$this->assertNotNull( $type, "{$name} is not registered" );

			foreach ( array( 'name', 'blockSlug', 'respectConditions' ) as $attr ) {
				$this->assertArrayHasKey( $attr, $type->attributes,
					"{$attr} missing from {$name}; a client re-save would drop it" );
			}
		}
	}

	public function test_new_attributes_default_falsy_so_old_content_parses_unchanged(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( GT_Page_Blocks_Builder::BLOCK_NAME );

		$this->assertSame( '', $type->attributes['name']['default'] );
		$this->assertSame( '', $type->attributes['blockSlug']['default'] );
		$this->assertFalse( $type->attributes['respectConditions']['default'] );
	}

	public function test_a_pre_3_0_page_reads_without_the_new_attributes(): void {
		// Exactly what 2.8.0 wrote: no name, no blockSlug, no respectConditions.
		$id = $this->make_post(
			'<!-- wp:gt-page-block/page-block {"content":"<section>old</section>"} /-->' . "\n\n" .
			'<!-- wp:paragraph --><p>core</p><!-- /wp:paragraph -->'
		);

		$m = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'get_builder_sections_from_post' );
		$sections = $m->invoke( $GLOBALS['gt_page_blocks_builder'], $id );

		$this->assertCount( 2, $sections );
		$this->assertStringContainsString( 'old', (string) $sections[0]['content'] );
		$this->assertSame( 'foreign', $sections[1]['kind'] );
	}

	public function test_normalize_carries_the_new_fields(): void {
		$m = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'normalize_builder_section' );

		$out = $m->invoke( $GLOBALS['gt_page_blocks_builder'], array(
			'content'           => '<p>x</p>',
			'name'              => '  Hero  section ',
			'blockSlug'         => 'My Hero Block',
			'respectConditions' => true,
		) );

		// sanitize_text_field trims and collapses internal whitespace; that is
		// the intended behaviour, not a lossy surprise.
		$this->assertSame( 'Hero section', $out['name'] );
		$this->assertSame( 'my-hero-block', $out['blockSlug'], 'blockSlug should be a slug' );
		$this->assertTrue( $out['respectConditions'] );
	}

	public function test_normalize_defaults_the_new_fields_when_absent(): void {
		$m   = new ReflectionMethod( GT_Page_Blocks_Builder::class, 'normalize_builder_section' );
		$out = $m->invoke( $GLOBALS['gt_page_blocks_builder'], array( 'content' => '<p>x</p>' ) );

		$this->assertSame( '', $out['name'] );
		$this->assertSame( '', $out['blockSlug'] );
		$this->assertFalse( $out['respectConditions'] );
	}

	public function test_empty_new_attributes_are_not_serialized(): void {
		// An ordinary section must serialize as it did in 2.8.0, or every page
		// in the installed base gains three empty attributes on first save.
		$block = serialize_block( array(
			'blockName'    => GT_Page_Blocks_Builder::BLOCK_NAME,
			'attrs'        => array( 'content' => '<p>x</p>' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		) );

		$this->assertStringNotContainsString( 'blockSlug', $block );
		$this->assertStringNotContainsString( 'respectConditions', $block );
		$this->assertStringNotContainsString( '"name"', $block );
	}
}
