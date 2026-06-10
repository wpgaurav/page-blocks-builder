<?php
/**
 * Theme building: render library page blocks into theme regions and hooks.
 *
 * Two placement systems share the block's `position` field:
 *
 * 1. Hook positions (wp_head, wp_body_open, wp_footer, loop_start, …)
 *    auto-render on their action; `the_content_before/after` apply via
 *    the the_content filter.
 *
 * 2. Theme regions (`region:header`, `region:footer`, …) render wherever
 *    the theme calls `gt_pb_region( 'header' )`. A minimal hybrid theme
 *    can be nothing but region calls:
 *
 *        <?php gt_pb_region( 'header' ); ?>
 *        <main><?php the_content(); ?></main>
 *        <?php gt_pb_region( 'footer' ); ?>
 *
 * Blocks render in `priority ASC` order within a position.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class gt_pb_theme_builder {

	/**
	 * @var gt_pb_db
	 */
	private $db;

	/**
	 * @var GT_Page_Blocks_Builder
	 */
	private $plugin;

	/**
	 * Published positioned blocks, grouped by position. Null until loaded.
	 *
	 * @var array<string, array<int, object>>|null
	 */
	private $positioned = null;

	public function __construct( gt_pb_db $db, $plugin ) {
		$this->db = $db;
		$this->plugin = $plugin;

		add_filter( 'gt_pb_positions', array( $this, 'add_region_positions' ) );

		// Front end only — wire hook positions just before the template loads.
		add_action( 'template_redirect', array( $this, 'wire_hooks' ), 5 );
	}

	/**
	 * Region positions offered in the position dropdown.
	 */
	public function add_region_positions( array $positions ): array {
		$regions = array(
			'region:header'         => __( 'Theme region: Header', 'page-blocks-builder' ),
			'region:hero'           => __( 'Theme region: Hero', 'page-blocks-builder' ),
			'region:before_content' => __( 'Theme region: Before content', 'page-blocks-builder' ),
			'region:after_content'  => __( 'Theme region: After content', 'page-blocks-builder' ),
			'region:sidebar'        => __( 'Theme region: Sidebar', 'page-blocks-builder' ),
			'region:footer'         => __( 'Theme region: Footer', 'page-blocks-builder' ),
			'region:404'            => __( 'Theme region: 404 page', 'page-blocks-builder' ),
		);
		return array_merge( $positions, $regions );
	}

	/**
	 * Lazy-load published positioned blocks grouped by position.
	 *
	 * @return array<string, array<int, object>>
	 */
	private function positioned_blocks(): array {
		if ( $this->positioned !== null ) {
			return $this->positioned;
		}

		$this->positioned = array();
		foreach ( $this->db->get_positioned_blocks() as $block ) {
			$this->positioned[ (string) $block->position ][] = $block;
		}

		return $this->positioned;
	}

	/**
	 * Hook positioned blocks into their actions/filters on the front end.
	 */
	public function wire_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		$grouped = $this->positioned_blocks();
		if ( empty( $grouped ) ) {
			return;
		}

		$that = $this;

		foreach ( $grouped as $position => $blocks ) {
			if ( strpos( $position, 'region:' ) === 0 ) {
				continue; // Regions render where the theme calls gt_pb_region().
			}

			if ( $position === 'the_content_before' || $position === 'the_content_after' ) {
				continue; // Handled by the the_content filter below.
			}

			add_action( $position, function() use ( $that, $position ) {
				$that->render_position( $position );
			}, 10 );
		}

		if ( isset( $grouped['the_content_before'] ) || isset( $grouped['the_content_after'] ) ) {
			add_filter( 'the_content', function( $content ) use ( $that ) {
				if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
					return $content;
				}
				return $that->capture_position( 'the_content_before' )
					. $content
					. $that->capture_position( 'the_content_after' );
			}, 12 );
		}
	}

	/**
	 * Echo all blocks assigned to a raw position key.
	 */
	public function render_position( string $position ): void {
		$grouped = $this->positioned_blocks();
		if ( empty( $grouped[ $position ] ) ) {
			return;
		}

		foreach ( $grouped[ $position ] as $block ) {
			// phpcs:ignore WordPress.Security.EscapeOutput -- block markup, escaped at render
			echo $this->plugin->render_library_block( $block );
		}
	}

	/**
	 * Return (not echo) the rendered output for a position.
	 */
	public function capture_position( string $position ): string {
		ob_start();
		$this->render_position( $position );
		return (string) ob_get_clean();
	}

	/**
	 * Whether any published block is assigned to a theme region.
	 */
	public function has_region( string $name ): bool {
		$grouped = $this->positioned_blocks();
		return ! empty( $grouped[ 'region:' . $name ] );
	}

	/**
	 * Render a theme region (all blocks assigned to region:<name>).
	 */
	public function render_region( string $name, array $args = array() ): void {
		if ( ! $this->has_region( $name ) ) {
			return;
		}

		$wrap = ! isset( $args['wrap'] ) || $args['wrap'];
		if ( $wrap ) {
			printf( '<div class="gt-pb-region gt-pb-region--%s">', esc_attr( sanitize_html_class( $name ) ) );
		}

		$this->render_position( 'region:' . $name );

		if ( $wrap ) {
			echo '</div>';
		}
	}
}

if ( ! function_exists( 'gt_pb_theme_builder' ) ) {
	/**
	 * Access the theme builder instance (set by the plugin at boot).
	 */
	function gt_pb_theme_builder(): ?gt_pb_theme_builder {
		global $gt_pb_theme_builder;
		return ( $gt_pb_theme_builder instanceof gt_pb_theme_builder ) ? $gt_pb_theme_builder : null;
	}
}

if ( ! function_exists( 'gt_pb_region' ) ) {
	/**
	 * Theme API: render a Page Blocks region.
	 *
	 *     gt_pb_region( 'header' );
	 *     gt_pb_region( 'footer', array( 'wrap' => false ) );
	 */
	function gt_pb_region( string $name, array $args = array() ): void {
		$builder = gt_pb_theme_builder();
		if ( $builder ) {
			$builder->render_region( $name, $args );
		}
	}
}

if ( ! function_exists( 'gt_pb_has_region' ) ) {
	/**
	 * Theme API: whether a region has published blocks.
	 */
	function gt_pb_has_region( string $name ): bool {
		$builder = gt_pb_theme_builder();
		return $builder ? $builder->has_region( $name ) : false;
	}
}
