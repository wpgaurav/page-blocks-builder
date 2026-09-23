<?php
use PHPUnit\Framework\TestCase;
class PbbPerformanceJsonExit extends RuntimeException {}

final class PerformanceEndpointTest extends TestCase {
	private int $post_id;
	protected function setUp(): void {
		$this->post_id = wp_insert_post( array( 'post_title' => 'Performance endpoint fixture', 'post_type' => 'page', 'post_status' => 'draft' ) );
	}
	protected function tearDown(): void { wp_delete_post( $this->post_id, true ); }

	private function request( array $changes = array() ): array {
		$before = $_POST;
		$_POST = array_merge( array( 'post_id' => $this->post_id, 'pb_nonce' => wp_create_nonce( gt_page_blocks_builder_nonce_action( $this->post_id ) ), 'sections' => wp_slash( '[{"css":".fixture { color:red; }"}]' ) ), $changes );
		$ajax = static function () { return true; };
		$exit = static function () { return static function () { throw new PbbPerformanceJsonExit(); }; };
		add_filter( 'wp_doing_ajax', $ajax ); add_filter( 'wp_die_ajax_handler', $exit );
		ob_start();
		try {
			try { $GLOBALS['gt_page_blocks_builder']->ajax_performance(); } catch ( PbbPerformanceJsonExit $done ) {}
			return json_decode( ob_get_contents(), true );
		} finally { ob_end_clean(); $_POST = $before; remove_filter( 'wp_doing_ajax', $ajax ); remove_filter( 'wp_die_ajax_handler', $exit ); }
	}

	public function test_authorized_analysis_is_read_only_and_returns_sizes_not_source(): void {
		$before = get_post( $this->post_id )->post_content;
		$out = $this->request();
		$this->assertTrue( $out['success'] );
		$this->assertGreaterThan( 0, $out['data']['rows'][0]['css_bytes'] );
		$this->assertArrayNotHasKey( 'css', $out['data']['rows'][0] );
		$this->assertSame( $before, get_post( $this->post_id )->post_content );
	}
	public function test_invalid_nonce_is_rejected(): void {
		$this->assertFalse( $this->request( array( 'pb_nonce' => 'invalid' ) )['success'] );
	}
	public function test_logged_out_requests_are_rejected_even_if_callback_is_called_directly(): void {
		$before = get_current_user_id();
		try { wp_set_current_user( 0 ); $this->assertFalse( $this->request()['success'] ); }
		finally { wp_set_current_user( $before ); }
	}
	public function test_valid_nonce_does_not_grant_a_subscriber_edit_permission(): void {
		$before = get_current_user_id();
		$user = wp_insert_user( array( 'user_login' => 'pbb-perf-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		try { wp_set_current_user( $user ); $this->assertFalse( $this->request()['success'] ); }
		finally { wp_set_current_user( $before ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
	}
	public function test_malformed_or_oversized_payloads_are_rejected(): void {
		$this->assertFalse( $this->request( array( 'sections' => '{broken' ) )['success'] );
		$this->assertFalse( $this->request( array( 'sections' => wp_slash( json_encode( array_fill( 0, 251, array() ) ) ) ) )['success'] );
		$this->assertFalse( $this->request( array( 'sections' => str_repeat( ' ', 2 * MB_IN_BYTES + 1 ) ) )['success'] );
	}
}
