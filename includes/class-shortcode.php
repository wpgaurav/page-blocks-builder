<?php
/**
 * Page Blocks Shortcode Handler
 *
 * Registers the [page_block] shortcode for embedding reusable page blocks.
 *
 * Usage:
 *   [page_block id="123"]
 *   [page_block slug="hero-section"]
 *
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class gt_pb_shortcode {

	private gt_pb_db $db;
	private $plugin;

	public function __construct( gt_pb_db $db, $plugin ) {
		$this->db     = $db;
		$this->plugin = $plugin;
	}

	/**
	 * Register the shortcode.
	 */
	public function init(): void {
		add_shortcode( 'page_block', array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered block HTML.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( array(
			'id'   => 0,
			'slug' => '',
		), $atts, 'page_block' );

		$block = null;

		if ( ! empty( $atts['id'] ) ) {
			$block = $this->db->get( (int) $atts['id'] );
		} elseif ( ! empty( $atts['slug'] ) ) {
			$block = $this->db->get_by_slug( sanitize_title( $atts['slug'] ) );
		}

		if ( ! $block || $block->status !== 'publish' ) {
			return '';
		}

		return $this->plugin->render_library_block( $block );
	}
}
