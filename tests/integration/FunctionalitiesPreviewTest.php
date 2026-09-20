<?php
use PHPUnit\Framework\TestCase;

/** Runs against the real optional plugin when installed in the WP fixture. */
final class FunctionalitiesPreviewTest extends TestCase {
	private array $options = array();
	private int $post_id = 0;

	protected function setUp(): void {
		if ( ! defined( 'FUNCTIONALITIES_DIR' ) ) $this->markTestSkipped( 'Install Functionalities to exercise the real integration.' );
		$values = array(
			'fonts' => array( 'enabled' => true, 'assign_enabled' => true, 'body_font' => 'PBB Fixture', 'items' => array( array( 'family' => 'PBB Fixture', 'woff2_url' => 'https://example.test/fixture.woff2', 'weight' => '400', 'style' => 'normal' ) ) ),
			'components' => array( 'enabled' => true, 'items' => array( array( 'class' => '.compat-card', 'css' => 'color:rgb(1,2,3);' ) ) ),
			'snippets' => array( 'enabled' => true, 'header' => array( array( 'enabled' => true, 'code' => '<script>window.compatHead=true;</script>' ) ), 'body_open' => array( array( 'enabled' => true, 'code' => '<div id="compat-body"></div>' ) ), 'footer' => array( array( 'enabled' => true, 'code' => '<script>window.compatFooter=true;</script>' ) ) ),
			'link_management' => array( 'enabled' => true, 'nofollow_external' => true, 'open_external_new_tab' => true, 'exception_presets' => array() ),
		);
		foreach ( $values as $name => $value ) {
			$this->options[ $name ] = get_option( 'functionalities_' . $name, null );
			update_option( 'functionalities_' . $name, $value );
			$class = '\\Functionalities\\Features\\' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $name ) ) );
			if ( class_exists( $class ) && property_exists( $class, 'options' ) ) ( new ReflectionProperty( $class, 'options' ) )->setValue( null, null );
			\Functionalities\Core\Module_Registry::boot_module( str_replace( '_', '-', $name ) );
		}
		$this->post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Compatibility fixture', 'post_status' => 'draft' ) );
	}

	protected function tearDown(): void {
		foreach ( $this->options as $name => $value ) {
			if ( null === $value ) delete_option( 'functionalities_' . $name ); else update_option( 'functionalities_' . $name, $value );
			$class = '\\Functionalities\\Features\\' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $name ) ) );
			if ( class_exists( $class ) && property_exists( $class, 'options' ) ) ( new ReflectionProperty( $class, 'options' ) )->setValue( null, null );
		}
		if ( $this->post_id ) wp_delete_post( $this->post_id, true );
		remove_all_filters( 'gt_page_blocks_builder_functionalities_compatibility' );
	}

	private function injection(): array {
		return gt_pb_functionalities_compat::preview_injection( array( 'css' => '.user-custom{color:red}' ), $this->post_id );
	}

	public function test_fonts_assignments_components_and_snippet_locations_reach_preview(): void {
		$out = $this->injection();
		$this->assertStringContainsString( '@font-face', $out['css'] );
		$this->assertStringContainsString( 'PBB Fixture', $out['css'] );
		$this->assertStringContainsString( 'body,p,li,td,input,textarea,select,button', $out['css'] );
		$this->assertStringContainsString( '.compat-card', $out['css'] );
		$this->assertStringEndsWith( '.user-custom{color:red}', $out['css'] );
		$this->assertStringContainsString( 'compatHead', $out['headHtml'] );
		$this->assertStringContainsString( 'compat-body', $out['bodyStartHtml'] );
		$this->assertStringContainsString( 'compatFooter', $out['bodyEndHtml'] );
	}

	public function test_query_hooks_and_outer_asset_queues_are_restored(): void {
		$styles = wp_styles(); $scripts = wp_scripts(); $query = $GLOBALS['wp_query'];
		$head_count = did_action( 'wp_head' ); $footer_count = did_action( 'wp_footer' );
		$head_hook = $GLOBALS['wp_filter']['wp_head'];
		wp_enqueue_style( 'unrelated-test-style', 'https://example.test/unrelated.css' );
		$out = $this->injection();
		$this->assertStringNotContainsString( 'unrelated.css', implode( '', $out ) );
		$this->assertSame( $styles, wp_styles() ); $this->assertSame( $scripts, wp_scripts() );
		$this->assertSame( $query, $GLOBALS['wp_query'] ); $this->assertSame( $head_hook, $GLOBALS['wp_filter']['wp_head'] );
		$this->assertSame( $head_count, did_action( 'wp_head' ) ); $this->assertSame( $footer_count, did_action( 'wp_footer' ) );
		wp_dequeue_style( 'unrelated-test-style' );
	}

	public function test_plugin_filters_and_bridge_optout_are_respected(): void {
		add_filter( 'functionalities_fonts_enabled', '__return_false' );
		try { $this->assertStringNotContainsString( '@font-face', $this->injection()['css'] ); }
		finally { remove_filter( 'functionalities_fonts_enabled', '__return_false' ); }
		add_filter( 'gt_page_blocks_builder_functionalities_compatibility', '__return_false' );
		$this->assertSame( array( 'css' => '.user-custom{color:red}' ), $this->injection() );
	}

	public function test_content_filters_use_the_target_post_without_saving_it(): void {
		$before = get_post_field( 'post_content', $this->post_id );
		$html = gt_pb_functionalities_compat::filter_html( '<a href="https://external.test/">Link</a>', $this->post_id );
		$this->assertStringContainsString( 'nofollow', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertSame( $before, get_post_field( 'post_content', $this->post_id ) );
	}
}
