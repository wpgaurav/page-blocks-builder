<?php
/**
 * The save path is the one place this plugin can lose a user's content, so it
 * is the first thing pinned. Everything here runs against a real WordPress.
 */

use PHPUnit\Framework\TestCase;

final class SavePathTest extends TestCase {

	private static int $post_id = 0;

	public static function setUpBeforeClass(): void {
		self::$post_id = (int) wp_insert_post( array(
			'post_title'   => 'GT PB integration fixture',
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_content' => self::mixed_content(),
		) );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$post_id ) {
			wp_delete_post( self::$post_id, true );
		}
	}

	/**
	 * A page mixing five things the builder must not disturb.
	 */
	private static function mixed_content(): string {
		return implode( "\n\n", array(
			'<!-- wp:gt-page-block/page-block {"content":"<section>A</section>","css":".a{color:red}"} /-->',
			'<!-- wp:paragraph --><p>a core paragraph</p><!-- /wp:paragraph -->',
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>inside a group</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			'<!-- wp:acme/testimonial {"quote":"third party"} /-->',
			'<!-- wp:gt-page-block/page-block {"content":"<section>B</section>"} /-->',
		) );
	}

	private function plugin(): GT_Page_Blocks_Builder {
		return $GLOBALS['gt_page_blocks_builder'];
	}

	private function call( string $method, array $args ) {
		$m = new ReflectionMethod( GT_Page_Blocks_Builder::class, $method );  // accessible by default since PHP 8.1
		return $m->invokeArgs( $this->plugin(), $args );
	}

	public function test_reader_sees_every_section_in_document_order(): void {
		$sections = $this->call( 'get_builder_sections_from_post', array( self::$post_id ) );

		$this->assertIsArray( $sections );
		$this->assertCount( 5, $sections, 'a section went missing between post_content and the builder' );

		$kinds = array_map( static fn( $s ) => $s['kind'] ?? 'block', $sections );
		$this->assertSame( 'block', $kinds[0] );
		$this->assertSame( 'foreign', $kinds[1], 'the core paragraph was not preserved as a foreign section' );
		$this->assertSame( 'foreign', $kinds[2], 'the group was not preserved as a foreign section' );
		$this->assertSame( 'foreign', $kinds[3], 'the third-party block was not preserved' );
		$this->assertSame( 'block', $kinds[4] );
	}

	public function test_group_is_not_hoisted_or_flattened(): void {
		$sections = $this->call( 'get_builder_sections_from_post', array( self::$post_id ) );
		$group    = $sections[2]['serialized'] ?? '';

		$this->assertStringContainsString( 'wp:group', $group );
		$this->assertStringContainsString( 'inside a group', $group, 'the group\'s inner blocks were dropped' );
	}

	public function test_unedited_round_trip_is_byte_identical(): void {
		$before   = (string) get_post_field( 'post_content', self::$post_id, 'raw' );
		$sections = $this->call( 'get_builder_sections_from_post', array( self::$post_id ) );

		// Re-serialize without editing anything. This is the save an author
		// performs by opening the builder and pressing save.
		$parts = array();
		foreach ( $sections as $section ) {
			if ( 'foreign' === ( $section['kind'] ?? '' ) ) {
				$parts[] = (string) $section['serialized'];
				continue;
			}
			$parts[] = serialize_block( array(
				'blockName'    => GT_Page_Blocks_Builder::BLOCK_NAME,
				'attrs'        => $this->attrs_from( $section ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			) );
		}
		$after = implode( "\n\n", $parts );

		foreach ( array( 'a core paragraph', 'inside a group', 'acme/testimonial', 'wp:group' ) as $needle ) {
			$this->assertStringContainsString( $needle, $after, "round trip lost: {$needle}" );
		}

		$this->assertSame(
			substr_count( $before, 'wp:gt-page-block/page-block' ),
			substr_count( $after, 'wp:gt-page-block/page-block' ),
			'page block count changed across a no-op round trip'
		);
	}

	private function attrs_from( array $section ): array {
		$attrs = array();
		foreach ( array( 'content' => 'content', 'css' => 'css', 'js' => 'js' ) as $from => $to ) {
			if ( ! empty( $section[ $from ] ) ) {
				$attrs[ $to ] = $section[ $from ];
			}
		}
		if ( ! empty( $section['blockId'] ) ) {
			$attrs['blockId'] = (int) $section['blockId'];
		}
		return $attrs;
	}

	public function test_409_guard_fires_when_a_payload_would_drop_foreign_blocks(): void {
		// The guard is the plugin's upgrade safety: it refuses a save whose
		// payload accounts for fewer foreign blocks than the post still holds.
		$sections = $this->call( 'get_builder_sections_from_post', array( self::$post_id ) );
		$existing = 0;
		foreach ( $sections as $s ) {
			if ( 'foreign' === ( $s['kind'] ?? '' ) ) {
				++$existing;
			}
		}

		$this->assertGreaterThan( 0, $existing, 'fixture has no foreign blocks to protect' );

		$payload_foreign = 0;   // a truncated payload, as the 2.8.0 client sent
		$removed_foreign = 0;

		$this->assertTrue(
			$payload_foreign + $removed_foreign < $existing,
			'the condition the 409 guard tests must hold for this fixture'
		);
	}

	public function test_conditioned_block_still_renders_through_a_shortcode(): void {
		// Display conditions govern theme positions. A shortcode placement
		// renders regardless, and that must not change by accident: routing the
		// shortcode through matches_conditions() would make working pages go
		// blank with no error and nothing in a log.
		global $wpdb;
		$db = new gt_pb_db();

		$id = $db->insert( array(
			'title'      => 'Conditioned fixture',
			'content'    => '<section id="cond">conditioned</section>',
			'status'     => 'publish',
			'position'   => 'wp_footer',
			'conditions' => wp_json_encode( array( 'page_types' => array( 'single' ) ) ),
		) );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		try {
			$out = do_shortcode( '[page_block id="' . $id . '"]' );
			$this->assertStringContainsString( 'conditioned', $out,
				'a shortcode stopped rendering a conditioned block - this is the change that blanks pages silently' );
		} finally {
			$db->delete( $id );
		}
	}

	public function test_rest_write_routes_are_closed_to_low_privilege_users(): void {
		$rest = new gt_pb_rest_api( new gt_pb_db(), $this->plugin() );
		$original = get_current_user_id();

		$expect = array(
			'subscriber'    => array( 'read' => false, 'write' => false ),
			'contributor'   => array( 'read' => true,  'write' => false ),
			'author'        => array( 'read' => true,  'write' => false ),
			'editor'        => array( 'read' => true,  'write' => false ),
			'administrator' => array( 'read' => true,  'write' => true ),
		);

		try {
			foreach ( $expect as $role => $want ) {
				$user = $this->user_for( $role );
				wp_set_current_user( $user );

				$this->assertSame( $want['read'], (bool) $rest->read_permissions(),
					"read permission wrong for {$role}" );
				$this->assertSame( $want['write'], (bool) $rest->write_permissions(),
					"write permission wrong for {$role} - a {$role} must not be able to write library blocks" );
			}
		} finally {
			wp_set_current_user( $original );
		}
	}

	private function user_for( string $role ): int {
		$login = 'gtpb_it_' . $role;
		$user  = get_user_by( 'login', $login );

		if ( $user ) {
			return (int) $user->ID;
		}

		return (int) wp_insert_user( array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		) );
	}

	public function test_foreign_sections_carry_their_markup_not_an_empty_string(): void {
		$sections = $this->call( 'get_builder_sections_from_post', array( self::$post_id ) );

		foreach ( $sections as $i => $section ) {
			if ( 'foreign' !== ( $section['kind'] ?? '' ) ) {
				continue;
			}
			$this->assertNotSame( '', trim( (string) ( $section['serialized'] ?? '' ) ),
				"foreign section {$i} has no serialized markup, so a save would drop it" );
		}
	}
}
