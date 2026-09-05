<?php
/**
 * How render_block() resolves a library reference.
 *
 * 3.0.0 made blockSlug win over blockId so a linked block survives being copied
 * to another site. That precedence is correct and must not change. What it
 * exposed is a client-side gap: the builder writes blockSlug when you link, and
 * the block editor never clears it when you detach — so a detached block keeps
 * rendering the library row over the user's own copy.
 *
 * These pin the server contract the editor has to satisfy.
 */

use PHPUnit\Framework\TestCase;

final class LinkedBlockResolutionTest extends TestCase {

	private static int $row_id = 0;
	private static string $slug = 'gtpb-linkres-fixture';

	public static function setUpBeforeClass(): void {
		$db = new gt_pb_db();
		$existing = $db->get_by_slug( self::$slug );
		if ( $existing ) {
			$db->delete( (int) $existing->id );
		}
		self::$row_id = (int) $db->insert( array(
			'title'   => 'Link resolution fixture',
			'slug'    => self::$slug,
			'content' => '<section id="from-library">LIBRARY-VERSION</section>',
			'status'  => 'publish',
		) );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$row_id ) {
			( new gt_pb_db() )->delete( self::$row_id );
		}
	}

	private function render( array $attrs ): string {
		return (string) $GLOBALS['gt_page_blocks_builder']->render_block( $attrs );
	}

	public function test_slug_only_resolves_to_the_library_row(): void {
		$out = $this->render( array( 'blockSlug' => self::$slug ) );
		$this->assertStringContainsString( 'LIBRARY-VERSION', $out );
	}

	public function test_id_only_resolves_to_the_library_row(): void {
		$out = $this->render( array( 'blockId' => self::$row_id ) );
		$this->assertStringContainsString( 'LIBRARY-VERSION', $out );
	}

	public function test_id_and_slug_agreeing_resolve_once(): void {
		$out = $this->render( array( 'blockId' => self::$row_id, 'blockSlug' => self::$slug ) );
		$this->assertSame( 1, substr_count( $out, 'LIBRARY-VERSION' ) );
	}

	/**
	 * The live bug.
	 *
	 * blockId 0 means the user detached this block and owns the code now. A
	 * blockSlug left behind by the link makes render_block() serve the library
	 * row instead, so their edits never reach a visitor and nothing reports it.
	 */
	public function test_detached_block_with_a_stale_slug_renders_its_own_content(): void {
		$out = $this->render( array(
			'blockId'   => 0,
			'blockSlug' => self::$slug,
			'content'   => '<section id="mine">MY-DETACHED-COPY</section>',
		) );

		$this->assertStringContainsString( 'MY-DETACHED-COPY', $out,
			'a detached block must render its own content, not the library row it was copied from' );
		$this->assertStringNotContainsString( 'LIBRARY-VERSION', $out );
	}

	public function test_a_slug_that_no_longer_exists_falls_back_to_the_id(): void {
		$out = $this->render( array( 'blockId' => self::$row_id, 'blockSlug' => 'gtpb-deleted-slug' ) );
		$this->assertStringContainsString( 'LIBRARY-VERSION', $out,
			'a renamed or deleted slug must fall back to the id rather than rendering nothing' );
	}
}
