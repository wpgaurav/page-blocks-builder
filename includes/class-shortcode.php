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
			'id'         => 0,
			'slug'       => '',
			// Opt in to the block's display conditions for this placement.
			// Off by default and it must stay that way: conditions govern
			// theme positions today, and honouring them here by default would
			// make pages that render now go blank, with no error and nothing
			// in any log.
			'conditions' => '',
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

		if ( in_array( strtolower( (string) $atts['conditions'] ), array( '1', 'true', 'yes' ), true ) ) {
			$theme_builder = function_exists( 'gt_pb_theme_builder' ) ? gt_pb_theme_builder() : null;
			if ( $theme_builder && ! $theme_builder->matches_conditions( $block ) ) {
				return '';
			}
		}

		return $this->plugin->render_library_block( $block );
	}
}
