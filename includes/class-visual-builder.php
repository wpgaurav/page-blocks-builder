<?php
/** Structured, opt-in Page Block sections. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_PB_Visual_Builder {
	private const TYPES            = array( 'section', 'container', 'columns', 'heading', 'text', 'image', 'button' );
	private const CONTAINERS       = array( 'section', 'container', 'columns' );
	private const STYLE_PROPERTIES = array(
		'color'           => 'color',
		'backgroundColor' => 'background-color',
		'fontSize'        => 'font-size',
		'fontWeight'      => 'font-weight',
		'textAlign'       => 'text-align',
		'padding'         => 'padding',
		'margin'          => 'margin',
		'gap'             => 'gap',
		'width'           => 'width',
		'maxWidth'        => 'max-width',
		'minHeight'       => 'min-height',
		'borderRadius'    => 'border-radius',
		'alignItems'      => 'align-items',
		'justifyContent'  => 'justify-content',
	);

	/**
	 * Return normalized data and the compiled fallback stored in legacy fields.
	 * Invalid visual data never gets interpreted as arbitrary HTML or CSS.
	 *
	 * @return array|WP_Error
	 */
	public static function compile( $data ) {
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! isset( $data['root'] ) ) {
			return new WP_Error( 'gt_pb_visual_schema', __( 'Invalid visual section format.', 'page-blocks-builder' ) );
		}
		if ( strlen( (string) wp_json_encode( $data ) ) > 128 * 1024 ) {
			return new WP_Error( 'gt_pb_visual_size', __( 'Visual section is too large.', 'page-blocks-builder' ) );
		}
		$count = 0;
		$ids   = array();
		$root  = self::normalize_node( $data['root'], 0, $count, $ids );
		if ( is_wp_error( $root ) || 'section' !== $root['type'] ) {
			return new WP_Error( 'gt_pb_visual_root', __( 'A visual section needs one Section root.', 'page-blocks-builder' ) );
		}
		$scope = 'gt-pb-v-root-' . $root['id'];
		$rules = array();
		$html  = self::render_node( $root, $scope, $rules );
		$css   = array(
			'.' . $scope . ',.' . $scope . ' *{box-sizing:border-box}',
		);
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $breakpoint ) {
			if ( empty( $rules[ $breakpoint ] ) ) {
				continue;
			}
			$joined = implode( '', $rules[ $breakpoint ] );
			if ( 'tablet' === $breakpoint ) {
				$joined = '@media(max-width:768px){' . $joined . '}';
			} elseif ( 'mobile' === $breakpoint ) {
				$joined = '@media(max-width:480px){' . $joined . '}';
			}
			$css[] = $joined;
		}
		return array(
			'visualData' => array(
				'version' => 1,
				'root'    => self::storage_node( $root ),
			),
			'content'    => $html,
			'css'        => implode( "\n", $css ),
		);
	}

	/** Preserve empty maps as JSON objects in block comments and AJAX. */
	private static function storage_node( array $node ): array {
		$node['props'] = (object) $node['props'];
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $breakpoint ) {
			$node['styles'][ $breakpoint ] = (object) $node['styles'][ $breakpoint ];
		}
		$node['children'] = array_map( array( __CLASS__, 'storage_node' ), $node['children'] );
		return $node;
	}

	/** @return array|WP_Error */
	private static function normalize_node( $source, $depth, &$count, &$ids ) {
		if ( ! is_array( $source ) || $depth > 8 || ++$count > 120 ) {
			return new WP_Error( 'gt_pb_visual_size', __( 'Visual section exceeds its node or depth limit.', 'page-blocks-builder' ) );
		}
		$id   = $source['id'] ?? null;
		$type = $source['type'] ?? null;
		if ( ! is_string( $id ) || ! is_string( $type ) || ! preg_match( '/^v[a-z0-9]{6,19}$/', $id ) || isset( $ids[ $id ] ) || ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'gt_pb_visual_node', __( 'Visual section contains an invalid or duplicate element.', 'page-blocks-builder' ) );
		}
		$ids[ $id ] = true;
		if ( isset( $source['props'] ) && ! is_array( $source['props'] ) && ! is_object( $source['props'] ) ) {
			return new WP_Error( 'gt_pb_visual_props', __( 'Visual element properties are invalid.', 'page-blocks-builder' ) );
		}
		$raw_props  = isset( $source['props'] ) ? (array) $source['props'] : array();
		$raw_styles = isset( $source['styles'] ) && ( is_array( $source['styles'] ) || is_object( $source['styles'] ) ) ? (array) $source['styles'] : array();
		foreach ( $raw_props as $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				return new WP_Error( 'gt_pb_visual_props', __( 'Visual element properties must be plain values.', 'page-blocks-builder' ) );
			}
		}
		$props = array();
		if ( in_array( $type, array( 'heading', 'text', 'button' ), true ) ) {
			$props['text'] = str_replace( "\0", '', wp_check_invalid_utf8( substr( (string) ( $raw_props['text'] ?? '' ), 0, 10000 ) ) );
		}
		if ( 'heading' === $type ) {
			$props['level'] = min( 6, max( 1, (int) ( $raw_props['level'] ?? 2 ) ) );
		}
		if ( 'button' === $type ) {
			$props['url'] = esc_url_raw( (string) ( $raw_props['url'] ?? '' ) );
		}
		if ( 'image' === $type ) {
			$props['attachmentId'] = absint( $raw_props['attachmentId'] ?? 0 );
			$props['url']          = esc_url_raw( (string) ( $raw_props['url'] ?? '' ) );
			$props['alt']          = sanitize_text_field( (string) ( $raw_props['alt'] ?? '' ) );
		}
		$styles = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $breakpoint ) {
			$raw                   = isset( $raw_styles[ $breakpoint ] ) && ( is_array( $raw_styles[ $breakpoint ] ) || is_object( $raw_styles[ $breakpoint ] ) ) ? (array) $raw_styles[ $breakpoint ] : array();
			$styles[ $breakpoint ] = array();
			foreach ( $raw as $key => $value ) {
				if ( 'gridColumns' === $key && 'columns' === $type && is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 4 ) {
					$styles[ $breakpoint ][ $key ] = (int) $value;
				} elseif ( isset( self::STYLE_PROPERTIES[ $key ] ) && self::valid_style( $key, $value ) ) {
					$styles[ $breakpoint ][ $key ] = trim( (string) $value );
				}
			}
		}
		$children = array();
		if ( isset( $source['children'] ) && ! is_array( $source['children'] ) ) {
			return new WP_Error( 'gt_pb_visual_children', __( 'Visual element children are invalid.', 'page-blocks-builder' ) );
		}
		$raw_children = isset( $source['children'] ) ? $source['children'] : array();
		if ( ! in_array( $type, self::CONTAINERS, true ) && $raw_children ) {
			return new WP_Error( 'gt_pb_visual_children', __( 'This visual element cannot contain other elements.', 'page-blocks-builder' ) );
		}
		foreach ( $raw_children as $child ) {
			$normalized = self::normalize_node( $child, $depth + 1, $count, $ids );
			if ( is_wp_error( $normalized ) || 'section' === $normalized['type'] ) {
				return new WP_Error( 'gt_pb_visual_children', __( 'Visual section has an invalid child element.', 'page-blocks-builder' ) );
			}
			$children[] = $normalized;
		}
		return array(
			'id'       => $id,
			'type'     => $type,
			'props'    => $props,
			'styles'   => $styles,
			'children' => $children,
		);
	}

	private static function valid_style( $key, $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 120 || preg_match( '/[;{}<>\\\\]/', $value ) ) {
			return false;
		}
		if ( in_array( $key, array( 'color', 'backgroundColor' ), true ) ) {
			return (bool) preg_match( '/^(#[0-9a-fA-F]{3,8}|var\(--[a-zA-Z0-9_-]+\)|transparent|currentColor)$/', $value );
		}
		if ( in_array( $key, array( 'textAlign', 'alignItems', 'justifyContent', 'fontWeight' ), true ) ) {
			return in_array( $value, array( 'left', 'center', 'right', 'start', 'end', 'stretch', 'flex-start', 'flex-end', 'space-between', 'space-around', 'normal', '400', '500', '600', '700', '800' ), true );
		}
		return (bool) preg_match( '/^(?:auto|0|(?:-?[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh))(?:\s+(?:-?[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh))){0,3}|var\(--[a-zA-Z0-9_-]+\))$/', $value );
	}

	private static function render_node( $node, $scope, &$rules ) {
		$type     = $node['type'];
		$id       = $node['id'];
		$selector = '.' . $scope . ' .gt-pb-v-' . $id;
		if ( 'section' === $type ) {
			$selector = '.' . $scope;
		}
		$base               = array(
			'section'   => 'padding:64px 24px',
			'container' => 'max-width:1200px;margin-inline:auto',
			'columns'   => 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px',
			'heading'   => 'margin:0 0 16px',
			'text'      => 'margin:0 0 16px',
			'image'     => 'display:block;max-width:100%;height:auto',
			'button'    => 'display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;text-decoration:none',
		);
		$rules['desktop'][] = $selector . '{' . $base[ $type ] . '}';
		if ( 'columns' === $type && empty( $node['styles']['tablet']['gridColumns'] ) ) {
			$rules['tablet'][] = $selector . '{grid-template-columns:minmax(0,1fr)}';
		}
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $breakpoint ) {
			$declarations = array();
			foreach ( $node['styles'][ $breakpoint ] as $key => $value ) {
				if ( 'gridColumns' === $key ) {
					$declarations[] = 'grid-template-columns:repeat(' . (int) $value . ',minmax(0,1fr))';
				} else {
					$declarations[] = self::STYLE_PROPERTIES[ $key ] . ':' . $value;
				}
			}
			if ( $declarations ) {
				$rules[ $breakpoint ][] = $selector . '{' . implode( ';', $declarations ) . '}';
			}
		}
		$class = 'gt-pb-v-' . $id . ' gt-pb-v-type-' . $type;
		$attr  = ( 'section' === $type ? ' id="gt-pb-v-' . esc_attr( $id ) . '"' : '' ) .
			' class="' . esc_attr( $class . ( 'section' === $type ? ' ' . $scope : '' ) ) . '" data-pb-v-id="' . esc_attr( $id ) . '"';
		if ( 'heading' === $type ) {
			$tag = 'h' . $node['props']['level'];
			return '<' . $tag . $attr . '>' . esc_html( $node['props']['text'] ) . '</' . $tag . '>';
		}
		if ( 'text' === $type ) {
			return '<p' . $attr . '>' . nl2br( esc_html( $node['props']['text'] ) ) . '</p>';
		}
		if ( 'button' === $type ) {
			return '<a' . $attr . ' href="' . esc_url( '' !== $node['props']['url'] ? $node['props']['url'] : '#' ) . '">' . esc_html( $node['props']['text'] ) . '</a>';
		}
		if ( 'image' === $type ) {
			$src = $node['props']['url'];
			if ( $node['props']['attachmentId'] ) {
				$attachment = wp_get_attachment_image(
					$node['props']['attachmentId'],
					'full',
					false,
					array(
						'class'        => $class,
						'data-pb-v-id' => $id,
						'alt'          => $node['props']['alt'],
					)
				);
				if ( $attachment ) {
					return $attachment;
				}
			}
			if ( ! $src ) {
				return '<div' . $attr . '></div>';
			}
			return '<img' . $attr . ' src="' . esc_url( $src ) . '" alt="' . esc_attr( $node['props']['alt'] ) . '">';
		}
		$tag  = 'section' === $type ? 'section' : 'div';
		$html = '<' . $tag . $attr . '>';
		foreach ( $node['children'] as $child ) {
			$html .= self::render_node( $child, $scope, $rules );
		}
		return $html . '</' . $tag . '>';
	}
}
