<?php
/** Preview-only native block markers and canvas assets. Saved blocks stay native. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_PB_Canvas_Editor {
	public static function icons() {
		$icons = array();
		foreach ( array( 'layout', 'code', 'external-link', 'eye', 'layout-sidebar', 'settings', 'x', 'plus', 'typography', 'text-caption', 'photo', 'click', 'arrows-move', 'arrow-back-up', 'arrow-forward-up', 'copy', 'trash', 'grid-dots', 'chevron-up', 'chevron-down', 'bold', 'italic', 'link' ) as $name ) {
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
		wp_enqueue_script( 'gt-pb-canvas-editor', GT_PB_BUILDER_URL . 'assets/js/canvas-editor.js', array( 'wp-blocks', 'wp-block-library', 'gt-page-block-preview-dom', 'gt-pb-canvas-layout', 'gt-pb-canvas-bridge', 'gt-pb-prototype-conversion' ), filemtime( GT_PB_BUILDER_DIR . 'assets/js/canvas-editor.js' ), true );
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
