<?php
/**
 * gt_pb_agent(): library commands through REST, page sections through the block
 * parser and serializer, staging on published posts, and the Site Agent skill.
 */

use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase {

	private array $posts  = array();
	private array $blocks = array();

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$db = new gt_pb_db();
		foreach ( $this->blocks as $id ) {
			$db->delete( $id );
		}
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
	}

	private function tricky(): array {
		return array(
			'name'    => 'Hero "one"',
			'content' => '<section class="t-hero"><!-- note --><h1>Café & "quotes" \\ backslash -- dashes</h1><script>if (a < b && c > d) {}</script></section>',
			'css'     => '.t-hero::before{content:"\\201C"}',
			'js'      => 'console.log("$var", `tpl ${x}`);',
		);
	}

	private function attrs_of( string $content ): array {
		$found = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( GT_Page_Blocks_Builder::is_page_block_name( (string) $block['blockName'] ) ) {
				$found[] = $block['attrs'];
			}
		}
		return $found;
	}

	public function test_context_and_unknown_commands(): void {
		$context = gt_pb_agent( 'context' );
		$this->assertTrue( $context['ok'] );
		$this->assertSame( GT_PB_BUILDER_VERSION, $context['plugin_version'] );
		$this->assertFalse( gt_pb_agent( 'nope' )['ok'] );
	}

	public function test_section_markup_round_trips_hostile_code_and_refuses_php(): void {
		$result = gt_pb_agent( 'section.markup', array( 'section' => $this->tricky() ) );
		$this->assertTrue( $result['ok'], $result['error'] ?? '' );
		$attrs = $this->attrs_of( $result['markup'] )[0];
		foreach ( $this->tricky() as $key => $value ) {
			$this->assertSame( $value, $attrs[ $key ], $key );
		}
		$this->assertFalse( gt_pb_agent( 'section.markup', array( 'section' => array( 'content' => 'x', 'phpExec' => true ) ) )['ok'] );
		$this->assertFalse( gt_pb_agent( 'section.markup', array( 'section' => array( 'onclick' => 'x' ) ) )['ok'] );
		$this->assertFalse( gt_pb_agent( 'section.markup', array( 'section' => array( 'jsLocation' => 'body' ) ) )['ok'] );
	}

	public function test_page_create_list_change_insert_and_append(): void {
		$created = gt_pb_agent( 'page.create', array( 'title' => 'Agent page', 'canvas' => true, 'sections' => array( $this->tricky(), array( 'name' => 'Footer CTA', 'content' => '<p>Two</p>' ) ) ) );
		$this->assertTrue( $created['ok'], $created['error'] ?? '' );
		$id            = $created['post_id'];
		$this->posts[] = $id;
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'page-blocks-full-builder.php', get_post_meta( $id, '_wp_page_template', true ) );

		$list = gt_pb_agent( 'page.sections', array( 'post_id' => $id ) );
		$this->assertTrue( $list['editable'] );
		$this->assertSame( array( 'Hero "one"', 'Footer CTA' ), array_column( $list['sections'], 'name' ) );
		$this->assertSame( $this->tricky()['content'], gt_pb_agent( 'page.section', array( 'post_id' => $id, 'index' => 0 ) )['attrs']['content'] );

		$stale = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'index' => 1, 'expected_sha256' => str_repeat( '0', 64 ), 'section' => array( 'content' => '<p>Changed</p>' ) ) );
		$this->assertFalse( $stale['ok'], 'A stale hash is refused.' );

		$changed = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'index' => 1, 'expected_sha256' => $list['content_sha256'], 'section' => array( 'content' => '<p>Changed</p>' ) ) );
		$this->assertSame( 'post', $changed['saved'], $changed['error'] ?? '' );
		$attrs = $this->attrs_of( get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( '<p>Changed</p>', $attrs[1]['content'] );
		$this->assertSame( 'Footer CTA', $attrs[1]['name'], 'Unchanged attributes are kept.' );
		$this->assertSame( $this->tricky()['js'], $attrs[0]['js'], 'Other sections are untouched.' );

		$inserted = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'after' => 0, 'expected_sha256' => $changed['content_sha256'], 'section' => array( 'name' => 'Middle', 'content' => '<p>Mid</p>' ) ) );
		$appended = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'expected_sha256' => $inserted['content_sha256'], 'section' => array( 'name' => 'Last', 'content' => '<p>End</p>' ) ) );
		$this->assertTrue( $appended['ok'], $appended['error'] ?? '' );
		$this->assertSame( array( 'Hero "one"', 'Middle', 'Footer CTA', 'Last' ), array_column( gt_pb_agent( 'page.sections', array( 'post_id' => $id ) )['sections'], 'name' ) );
	}

	public function test_nested_sections_and_published_staging(): void {
		$a       = serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => array( 'name' => 'A', 'content' => '<p>A</p>' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$b       = serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => array( 'name' => 'B', 'content' => '<p>B</p>' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$content = "<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">" . $a . "\n\n" . $b . "</main>\n<!-- /wp:group -->";
		$id      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Nested agent page', 'post_content' => wp_slash( $content ) ) );
		$this->posts[] = $id;

		$list = gt_pb_agent( 'page.sections', array( 'post_id' => $id ) );
		$this->assertTrue( $list['editable'] );
		$this->assertSame( array( 'A', 'B' ), array_column( $list['sections'], 'name' ) );

		$staged = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'after' => 0, 'expected_sha256' => $list['content_sha256'], 'section' => array( 'name' => 'Inserted', 'content' => '<p>I</p>' ) ) );
		$this->assertSame( 'autosave', $staged['saved'], $staged['error'] ?? '' );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ), 'The live page is unchanged.' );
		$autosave = wp_get_post_autosave( $id, get_current_user_id() );
		$this->assertSame( array( 'A', 'Inserted', 'B' ), array_column( $this->nested_attrs( $autosave->post_content ), 'name' ) );

		$again = gt_pb_agent( 'page.sections', array( 'post_id' => $id ) );
		$this->assertSame( 'your newer autosave', $again['reading'] );
		$live = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'index' => 2, 'publish' => true, 'expected_sha256' => $again['content_sha256'], 'section' => array( 'content' => '<p>B2</p>' ) ) );
		$this->assertSame( 'post', $live['saved'], $live['error'] ?? '' );
		$this->assertSame( array( 'A', 'Inserted', 'B' ), array_column( $this->nested_attrs( get_post_field( 'post_content', $id, 'raw' ) ), 'name' ) );
		$this->assertStringContainsString( '<main class="wp-block-group">', get_post_field( 'post_content', $id, 'raw' ) );
	}

	private function nested_attrs( string $content ): array {
		$found = array();
		$walk  = function ( array $blocks ) use ( &$walk, &$found ) {
			foreach ( $blocks as $block ) {
				if ( GT_Page_Blocks_Builder::is_page_block_name( (string) $block['blockName'] ) ) {
					$found[] = $block['attrs'];
				} elseif ( $block['innerBlocks'] ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return $found;
	}

	public function test_php_sections_are_left_to_the_editor(): void {
		$php = serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => array( 'name' => 'PHP', 'content' => '<?php echo 1; ?>', 'phpExec' => true ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$id  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PHP agent page', 'post_content' => wp_slash( $php ) ) );
		$this->posts[] = $id;
		$list = gt_pb_agent( 'page.sections', array( 'post_id' => $id ) );
		$this->assertTrue( $list['sections'][0]['php'] );
		$result = gt_pb_agent( 'page.set_section', array( 'post_id' => $id, 'index' => 0, 'expected_sha256' => $list['content_sha256'], 'section' => array( 'content' => 'x' ) ) );
		$this->assertFalse( $result['ok'] );
	}

	public function test_library_commands_and_linking(): void {
		$this->assertFalse( gt_pb_agent( 'blocks.create', array( 'block' => array( 'title' => 'PHP', 'php_exec' => true ) ) )['ok'] );
		$created = gt_pb_agent( 'blocks.create', array( 'block' => array( 'title' => 'Agent promo', 'slug' => 'agent-promo-' . wp_rand(), 'content' => '<aside>Promo</aside>' ) ) );
		$this->assertTrue( $created['ok'], $created['error'] ?? '' );
		$block          = $created['block'];
		$this->blocks[] = (int) $block['id'];
		$this->assertSame( 'draft', $block['status'] );
		$this->assertSame( '', (string) $block['position'] );

		$updated = gt_pb_agent( 'blocks.update', array( 'id' => $block['id'], 'patch' => array( 'content' => '<aside>Promo 2</aside>' ) ) );
		$this->assertSame( '<aside>Promo 2</aside>', $updated['block']['content'] );
		$this->assertContains( (int) $block['id'], array_map( 'intval', array_column( gt_pb_agent( 'blocks.list', array( 'search' => 'Agent promo' ) )['blocks'], 'id' ) ) );
		$this->assertStringContainsString( 'Promo 2', gt_pb_agent( 'blocks.render', array( 'id' => $block['id'] ) )['render']['html'] ?? wp_json_encode( gt_pb_agent( 'blocks.render', array( 'id' => $block['id'] ) ) ) );

		$page = gt_pb_agent( 'page.create', array( 'title' => 'Linked', 'sections' => array( array( 'name' => 'Promo', 'blockId' => $block['id'], 'blockSlug' => $block['slug'] ) ) ) );
		$this->posts[] = $page['post_id'];
		$linked = gt_pb_agent( 'page.sections', array( 'post_id' => $page['post_id'] ) )['sections'][0]['linked'];
		$this->assertSame( (int) $block['id'], $linked['blockId'] );

		$this->assertTrue( gt_pb_agent( 'blocks.trash', array( 'id' => $block['id'] ) )['ok'] );
	}

	public function test_permissions_and_site_agent_skill(): void {
		$subscriber = wp_insert_user( array( 'user_login' => 'pb-agent-sub-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( gt_pb_agent( 'blocks.list' )['ok'] );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber );
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

		$skills = apply_filters( 'site_agent_skills', array() );
		$this->assertFileExists( $skills['gt-page-blocks']['directory'] . '/SKILL.md' );
		if ( class_exists( 'SiteAgent\\Skills' ) && method_exists( 'SiteAgent\\Skills', 'registered' ) ) {
			$this->assertStringContainsString( "gt_pb_agent('page.sections'", SiteAgent\Skills::read( array( 'skill' => 'gt-page-blocks' ) )['content'] );
		}
	}
}
