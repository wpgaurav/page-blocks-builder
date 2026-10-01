<?php
/** Preview-only native block markers and canvas assets. Saved blocks stay native. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_PB_Canvas_Editor {
	/** Recover the post-content layout omitted by the standalone editing shell. */
	public static function template_layout( $post_id, $slug ) {
		if ( 'page-blocks-full-builder.php' === $slug ) {
			return array(
				'css'       => 'body{margin:0;padding:0;}.pb-preview-content{box-sizing:border-box;display:flow-root;width:100%;max-width:none;margin:0;padding:0;}',
				'className' => 'pb-preview-content',
			);
		}
		if ( ! wp_is_block_theme() || ! function_exists( 'wp_get_layout_style' ) || ! function_exists( 'wp_style_engine_get_styles' ) ) {
			return array();
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$slugs = 'default-template' === $slug || 'default' === $slug ? array( 'page-' . $post->post_name, 'page', 'singular', 'index' ) : array( $slug );
		if ( 'page' !== $post->post_type && count( $slugs ) > 1 ) {
			$slugs = array( 'single-' . $post->post_type, 'single', 'singular', 'index' );
		}
		$find = static function ( $blocks ) use ( &$find ) {
			foreach ( $blocks as $block ) {
				if ( 'core/post-content' === $block['blockName'] ) {
					return $block['attrs'];
				}
				if ( 'core/query' !== $block['blockName'] ) {
					$found = $find( $block['innerBlocks'] );
					if ( null !== $found ) {
						return $found;
					}
				}
			}
			return null;
		};
		foreach ( $slugs as $candidate ) {
			$template = get_block_template( get_stylesheet() . '//' . $candidate, 'wp_template' );
			if ( ! $template ) {
				continue;
			}
			$attrs = $find( parse_blocks( $template->content ) );
			if ( null === $attrs ) {
				return array();
			}
			$layout  = $attrs['layout'] ?? array( 'type' => 'constrained' );
			$css     = wp_get_layout_style( '.pb-preview-content', $layout );
			$padding = wp_get_global_styles( array( 'spacing', 'padding' ) );
			$spacing = wp_style_engine_get_styles(
				array(
					'spacing' => array(
						'padding' => array(
							'left'  => is_array( $padding ) ? ( $padding['left'] ?? '0' ) : '0',
							'right' => is_array( $padding ) ? ( $padding['right'] ?? '0' ) : '0',
						),
					),
				)
			);
			$css    .= '.pb-preview-content{box-sizing:border-box;display:flow-root;' . ( $spacing['css'] ?? '' ) . '}';
			return array(
				'css'       => $css,
				'className' => 'wp-block-post-content pb-preview-content',
			);
		}
		return array();
	}

	public static function icons() {
		$icons = array();
		foreach ( array( 'layout', 'code', 'external-link', 'eye', 'layout-sidebar', 'settings', 'x', 'plus', 'typography', 'text-caption', 'photo', 'click', 'arrows-move', 'arrow-back-up', 'arrow-forward-up', 'copy', 'trash', 'grid-dots', 'chevron-up', 'chevron-down', 'bold', 'italic', 'link', 'letter-a', 'paint', 'text-size', 'box-padding', 'box-margin', 'arrows-horizontal', 'border-radius', 'align-left', 'align-center', 'align-right', 'device-desktop', 'device-tablet', 'device-mobile', 'info-circle', 'cut', 'clipboard', 'arrows-exchange', 'books' ) as $name ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Trusted bundled local SVG, never a remote URL.
			$icons[ $name ] = str_replace( '<svg', '<svg aria-hidden="true" focusable="false"', (string) file_get_contents( GT_PB_BUILDER_DIR . 'assets/icons/tabler/' . $name . '.svg' ) );
		}
		return $icons;
	}

	public static function enqueue() {
		wp_enqueue_media();
		wp_enqueue_style( 'gt-pb-canvas-editor', GT_PB_BUILDER_URL . 'assets/css/canvas-editor.css', array( 'gt-page-block-builder-shell' ), filemtime( GT_PB_BUILDER_DIR . 'assets/css/canvas-editor.css' ) );
		wp_enqueue_script( 'gt-pb-canvas-layout', GT_PB_BUILDER_URL . 'assets/js/canvas-layout.js', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/js/canvas-layout.js' ), true );
		wp_enqueue_script( 'gt-pb-prototype-conversion', GT_PB_BUILDER_URL . 'assets/js/prototype-conversion.js', array( 'wp-blocks' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/prototype-conversion.js' ), true );
		wp_enqueue_script( 'gt-pb-canvas-bridge', GT_PB_BUILDER_URL . 'assets/js/canvas-bridge.js', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/js/canvas-bridge.js' ), true );
		wp_enqueue_script( 'gt-pb-canvas-presets', GT_PB_BUILDER_URL . 'assets/js/canvas-presets.js', array( 'wp-blocks' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/canvas-presets.js' ), true );
		foreach ( array( 'canvas-conversion', 'canvas-clipboard' ) as $module ) {
			wp_enqueue_script( 'gt-pb-' . $module, GT_PB_BUILDER_URL . 'assets/js/' . $module . '.js', array( 'wp-blocks' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/' . $module . '.js' ), true );
		}
		wp_enqueue_style( 'gt-pb-canvas-library', GT_PB_BUILDER_URL . 'assets/css/canvas-library.css', array(), filemtime( GT_PB_BUILDER_DIR . 'assets/css/canvas-library.css' ) );
		wp_enqueue_script( 'gt-pb-canvas-editor', GT_PB_BUILDER_URL . 'assets/js/canvas-editor.js', array( 'wp-blocks', 'wp-block-library', 'gt-page-block-preview-dom', 'gt-pb-canvas-layout', 'gt-pb-canvas-bridge', 'gt-pb-prototype-conversion', 'gt-pb-canvas-presets', 'gt-pb-canvas-conversion', 'gt-pb-canvas-clipboard' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/canvas-editor.js' ), true );
	}

	/** Mark supported blocks while rendering a draft; never modify the saved markup. */
	public static function preview( $raw, $uid ) {
		if ( ! is_string( $uid ) || ! preg_match( '/^pb-[a-z0-9]+$/', $uid ) ) {
			return do_blocks( $raw );
		}
		$mark   = static function ( $blocks, $parent_path = array() ) use ( &$mark ) {
			$position = 0;
			foreach ( $blocks as &$block ) {
				if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
					continue;
				}
				$path = array_merge( $parent_path, array( $position++ ) );
				if ( in_array( $block['blockName'], array( 'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button', 'core/image', 'core/video', 'core/audio', 'core/file' ), true ) ) {
					$block['attrs']['_pbCanvasPath'] = implode( '.', $path );
				}
				$block['innerBlocks'] = $mark( $block['innerBlocks'], $path );
			}
			return $blocks;
		};
		$filter = static function ( $html, $block ) use ( $uid ) {
			if ( ! isset( $block['attrs']['_pbCanvasPath'] ) ) {
				return $html;
			}
			$attributes = ' data-pb-canvas-section="' . esc_attr( $uid ) . '" data-pb-canvas-path="' . esc_attr( $block['attrs']['_pbCanvasPath'] ) . '" data-pb-canvas-type="' . esc_attr( $block['blockName'] ) . '"';
			return preg_replace( '/<(?!style\b|script\b)([a-z][a-z0-9:-]*)(?=[\s>])/i', '<$1' . $attributes, $html, 1 );
		};
		add_filter( 'render_block', $filter, 100, 2 );
		try {
			return implode( '', array_map( 'render_block', $mark( parse_blocks( $raw ) ) ) );
		} finally {
			remove_filter( 'render_block', $filter, 100 );
		}
	}
}
