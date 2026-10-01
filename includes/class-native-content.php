<?php
/**
 * Static WordPress markup retained inside an editable Page Block.
 *
 * @package PageBlocksBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_PB_Native_Content {
	/**
	 * Validate the opt-in content without running any render callbacks.
	 *
	 * @param array $attributes Page Block attributes.
	 * @return true|WP_Error
	 */
	public static function validate_section( array $attributes ) {
		if ( empty( $attributes['nativeContent'] ) ) {
			return true;
		}
		if ( ! empty( $attributes['phpExec'] ) || ! empty( $attributes['blockId'] ) || ! empty( $attributes['blockSlug'] ) ) {
			return self::error( __( 'Converted block code cannot run PHP or link to a library block.', 'page-blocks-builder' ) );
		}
		$content = isset( $attributes['content'] ) ? (string) $attributes['content'] : '';
		if ( '' === trim( $content ) || strlen( $content ) > 2 * MB_IN_BYTES ) {
			return self::error( __( 'Converted block code must contain WordPress blocks and be smaller than 2 MB.', 'page-blocks-builder' ) );
		}
		$count = 0;
		return self::validate_blocks( parse_blocks( $content ), $count, 0 );
	}

	/**
	 * Check a parsed tree before handing it to do_blocks().
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $count Total blocks visited.
	 * @param int   $depth Current nesting level.
	 * @return true|WP_Error
	 */
	private static function validate_blocks( array $blocks, int &$count, int $depth ) {
		$allowed = array(
			'core/group',
			'core/columns',
			'core/column',
			'core/heading',
			'core/paragraph',
			'core/buttons',
			'core/button',
			'core/image',
			'core/video',
			'core/audio',
			'core/file',
		);
		foreach ( $blocks as $block ) {
			++$count;
			if ( $depth > 40 || $count > 1000 ) {
				return self::error( __( 'Converted block code contains too many nested blocks.', 'page-blocks-builder' ) );
			}
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( self::has_executable_text( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				return self::error( __( 'Converted block code cannot contain PHP or executable shortcodes.', 'page-blocks-builder' ) );
			}
			if ( ! empty( $attrs['metadata']['bindings'] ) ) {
				return self::error( __( 'Blocks with dynamic bindings must stay in Visual mode.', 'page-blocks-builder' ) );
			}
			if ( GT_Page_Blocks_Builder::is_page_block_name( $name ) ) {
				if ( ! empty( $attrs['phpExec'] ) || ! empty( $attrs['blockId'] ) || ! empty( $attrs['blockSlug'] ) || ! empty( $attrs['nativeContent'] ) || ! empty( $attrs['visualData'] ) || ! empty( $block['innerBlocks'] ) ) {
					return self::error( __( 'Nested Page Blocks cannot run PHP, link to a library block, or contain another conversion.', 'page-blocks-builder' ) );
				}
				$source = (string) ( $attrs['content'] ?? '' );
				if ( self::has_executable_text( $source ) || has_blocks( $source ) ) {
					return self::error( __( 'Nested Page Block code must contain static HTML, CSS, or JavaScript.', 'page-blocks-builder' ) );
				}
			} elseif ( ! in_array( $name, $allowed, true ) ) {
				return self::error( __( 'This code contains an unsupported or dynamic block. Keep that block in Visual mode.', 'page-blocks-builder' ) );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$valid = self::validate_blocks( $block['innerBlocks'], $count, $depth + 1 );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
		}
		return true;
	}

	/**
	 * Registered shortcodes can execute application code, including nested PHP.
	 * Ordinary bracketed prose and JavaScript array indexing remain valid.
	 *
	 * @param string $content Source to examine.
	 * @return bool
	 */
	private static function has_executable_text( string $content ): bool {
		if ( false !== strpos( $content, '<?' ) ) {
			return true;
		}
		global $shortcode_tags;
		foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
			if ( has_shortcode( $content, (string) $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A validation failure keeps the stored document intact.
	 *
	 * @param string $message User-facing reason.
	 * @return WP_Error
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'gt_pb_native_content', $message );
	}
}
