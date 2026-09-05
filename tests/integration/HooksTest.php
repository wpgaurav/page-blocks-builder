<?php
/**
 * The extension points, and the guarantee that matters most about them: with no
 * filter attached, output is byte-identical to having no hook at all.
 */

use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase {

	private static int $row_id = 0;

	public static function setUpBeforeClass(): void {
		self::$row_id = (int) ( new gt_pb_db() )->insert( array(
			'title'   => 'Hooks fixture',
			'slug'    => 'gtpb-hooks-fixture',
			'content' => '<section id="hooked">HOOK-FIXTURE</section>',
			'css'     => '.hooked{color:red}',
			'status'  => 'publish',
		) );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$row_id ) {
			( new gt_pb_db() )->delete( self::$row_id );
		}
	}

	public function test_render_filter_is_inert_by_default(): void {
		$db  = new gt_pb_db();
		$row = $db->get( self::$row_id );

		// render_library_block() emits a block's CSS once per request, so two
		// calls differ for a reason that has nothing to do with the filter.
		// Reset that tracking between them or the comparison is meaningless.
		$reset = static function () {
			$p = new ReflectionProperty( GT_Page_Blocks_Builder::class, 'library_css_done' );
			$p->setValue( $GLOBALS['gt_page_blocks_builder'], array() );
		};

		remove_all_filters( 'gt_pb_block_rendered' );
		$reset();
		$unfiltered = $GLOBALS['gt_page_blocks_builder']->render_library_block( $row );

		// An identity filter must not change a single byte.
		add_filter( 'gt_pb_block_rendered', static fn( $html ) => $html );
		$reset();
		$identity = $GLOBALS['gt_page_blocks_builder']->render_library_block( $row );
		remove_all_filters( 'gt_pb_block_rendered' );

		$this->assertSame( $unfiltered, $identity,
			'attaching an identity filter changed the output, so the hook is not inert' );
		$this->assertStringContainsString( 'HOOK-FIXTURE', $unfiltered );
	}

	public function test_render_filter_receives_the_row_and_can_modify(): void {
		$db  = new gt_pb_db();
		$row = $db->get( self::$row_id );

		$seen = null;
		add_filter( 'gt_pb_block_rendered', static function ( $html, $block ) use ( &$seen ) {
			$seen = $block;
			return $html . '<!-- filtered -->';
		}, 10, 2 );

		$out = $GLOBALS['gt_page_blocks_builder']->render_library_block( $row );
		remove_all_filters( 'gt_pb_block_rendered' );

		$this->assertStringContainsString( '<!-- filtered -->', $out );
		$this->assertNotNull( $seen );
		$this->assertSame( self::$row_id, (int) $seen->id, 'the filter must receive the row it rendered' );
	}

	public function test_save_and_delete_actions_fire_with_their_documented_signature(): void {
		$db     = new gt_pb_db();
		$events = array();

		add_action( 'gt_pb_block_saved', static function ( $id, $data, $is_new ) use ( &$events ) {
			$events[] = array( 'saved', (int) $id, is_array( $data ), (bool) $is_new );
		}, 10, 3 );
		add_action( 'gt_pb_block_deleted', static function ( $id ) use ( &$events ) {
			$events[] = array( 'deleted', (int) $id );
		} );

		$id = (int) $db->insert( array( 'title' => 'Hook signature', 'slug' => 'gtpb-hook-sig', 'content' => 'x', 'status' => 'publish' ) );
		$db->update( $id, array( 'content' => 'y' ) );
		$db->delete( $id );

		remove_all_actions( 'gt_pb_block_saved' );
		remove_all_actions( 'gt_pb_block_deleted' );

		$this->assertContains( array( 'saved', $id, true, true ), $events, 'insert must report is_new = true' );
		$this->assertContains( array( 'saved', $id, true, false ), $events, 'update must report is_new = false' );
		$this->assertContains( array( 'deleted', $id ), $events );
	}
}
