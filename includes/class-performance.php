<?php
/** Read-only analysis of authored sections. Never render PHP, shortcodes or blocks. */
if ( ! defined( 'ABSPATH' ) ) exit;

class gt_pb_performance {
	public static function analyze( array $sections, gt_pb_db $db, bool $page_scope = true ): array {
		$expanded = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) continue;
			if ( 'foreign' === ( $section['kind'] ?? '' ) ) {
				$raw = is_string( $section['serialized'] ?? null ) ? $section['serialized'] : '';
				foreach ( GT_Page_Blocks_Builder::find_page_blocks( parse_blocks( $raw ) ) as $nested ) $expanded[] = $nested['attrs'];
			} else {
				$expanded[] = $section;
			}
		}
		$rows = array(); $seen_css = array(); $files = array(); $font_preloads = array(); $high_priority = 0; $deferred = 0;
		$totals = array( 'css_bytes' => 0, 'css_minified_bytes' => 0, 'js_bytes' => 0, 'js_minified_bytes' => 0, 'css_requests' => 0, 'loader_requests' => 0 );
		foreach ( $expanded as $index => $section ) {
			$label = is_string( $section['name'] ?? null ) && '' !== $section['name'] ? sanitize_text_field( $section['name'] ) : sprintf( __( 'Section %d', 'page-blocks-builder' ), $index + 1 );
			$row = array( 'label' => $label, 'css_bytes' => 0, 'css_minified_bytes' => 0, 'js_bytes' => 0, 'js_minified_bytes' => 0, 'mode' => __( 'No CSS', 'page-blocks-builder' ), 'notes' => array() );
			$library = null;
			if ( ! empty( $section['blockId'] ) || ! empty( $section['blockSlug'] ) ) {
				$slug = is_string( $section['blockSlug'] ?? null ) ? $section['blockSlug'] : '';
				$library = $slug !== '' ? $db->get_by_slug( $slug ) : $db->get( (int) ( $section['blockId'] ?? 0 ) );
				if ( ! $library || 'publish' !== $library->status ) {
					$row['mode'] = __( 'Unavailable library block', 'page-blocks-builder' );
					$rows[] = $row;
					continue;
				}
				$section = array( 'css' => $library->css, 'js' => $library->js, 'content' => $library->content, 'output' => $library->output );
				$row['notes'][] = __( 'Uses the published library source; display conditions can change whether it appears.', 'page-blocks-builder' );
			}
			$css = is_string( $section['css'] ?? null ) ? $section['css'] : '';
			$js = is_string( $section['js'] ?? null ) ? $section['js'] : '';
			$html = is_string( $section['content'] ?? null ) ? $section['content'] : '';
			$row['css_bytes'] = strlen( $css );
			$row['css_minified_bytes'] = strlen( GT_Page_Blocks_Builder::minify_css( GT_Page_Blocks_Builder::sanitize_css( $css ) ) );
			$row['js_bytes'] = strlen( $js );
			$row['js_minified_bytes'] = strlen( GT_Page_Blocks_Builder::minify_js( $js ) );
			if ( $css !== '' ) {
				$output = $section['cssOutput'] ?? '';
				if ( 'file' === $output ) {
					$is_deferred = ! empty( $section['cssDefer'] );
					$row['mode'] = $is_deferred ? __( 'Deferred file', 'page-blocks-builder' ) : __( 'File', 'page-blocks-builder' );
					$files['section:' . $index] = true;
					if ( $is_deferred ) {
						$deferred++;
						if ( $page_scope && $index === 0 ) $row['notes'][] = __( 'The first section is deferred. Keep hero and shared layout styles available for the first paint.', 'page-blocks-builder' );
					}
				} elseif ( 'inline' !== $output && 'file' === ( $section['output'] ?? '' ) ) {
					$row['mode'] = $library ? __( 'Library file', 'page-blocks-builder' ) : __( 'Combined file', 'page-blocks-builder' );
					$files[$library ? 'library:' . $library->id : 'combined'] = true;
				} else {
					$row['mode'] = __( 'Inline', 'page-blocks-builder' );
				}
				$hash = hash( 'sha256', $css );
				if ( isset( $seen_css[$hash] ) ) $row['notes'][] = sprintf( __( 'Exact CSS duplicate of %s. Shared rules may belong in one section.', 'page-blocks-builder' ), $seen_css[$hash] );
				else $seen_css[$hash] = $label;
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$tags = new WP_HTML_Tag_Processor( $html ); $missing = 0; $lazy = 0; $blocking = 0;
				while ( $tags->next_tag() ) {
					$tag = $tags->get_tag();
					if ( 'IMG' === $tag ) {
						if ( (int) $tags->get_attribute( 'width' ) <= 0 || (int) $tags->get_attribute( 'height' ) <= 0 ) $missing++;
						if ( 'lazy' === strtolower( (string) $tags->get_attribute( 'loading' ) ) ) $lazy++;
						if ( 'high' === strtolower( (string) $tags->get_attribute( 'fetchpriority' ) ) ) $high_priority++;
					}
					if ( 'SCRIPT' === $tag && $tags->get_attribute( 'src' ) && null === $tags->get_attribute( 'defer' ) && null === $tags->get_attribute( 'async' ) && 'module' !== strtolower( (string) $tags->get_attribute( 'type' ) ) ) $blocking++;
					if ( 'LINK' === $tag && 'preload' === strtolower( (string) $tags->get_attribute( 'rel' ) ) && 'font' === strtolower( (string) $tags->get_attribute( 'as' ) ) ) {
						$href = (string) $tags->get_attribute( 'href' );
						if ( $href !== '' && isset( $font_preloads[$href] ) ) $row['notes'][] = __( 'Repeated font preload. Keep one matching preload for this resource.', 'page-blocks-builder' );
						$font_preloads[$href] = true;
					}
				}
				if ( $missing ) $row['notes'][] = sprintf( __( '%d image(s) have no positive width and height attributes. Reserve their layout space.', 'page-blocks-builder' ), $missing );
				if ( $page_scope && $index === 0 && $lazy ) $row['notes'][] = __( 'The first section has lazy-loaded images. Check whether the main visible image should load eagerly.', 'page-blocks-builder' );
				if ( $blocking ) $row['notes'][] = sprintf( __( '%d external script(s) in the HTML have no async or defer strategy. Check dependencies before changing them.', 'page-blocks-builder' ), $blocking );
			}
			foreach ( array( 'css_bytes', 'css_minified_bytes', 'js_bytes', 'js_minified_bytes' ) as $field ) $totals[$field] += $row[$field];
			$rows[] = $row;
		}
		$totals['css_requests'] = count( $files ); $totals['loader_requests'] = $deferred ? 1 : 0;
		$notes = array( __( 'Code sizes are UTF-8 bytes before compression, summed across authored sections. They are not network transfer sizes or speed scores.', 'page-blocks-builder' ), __( 'Expected CSS requests exclude theme assets and other plugins. Missing files, display conditions, and optimization plugins can change delivery.', 'page-blocks-builder' ), __( 'Media checks inspect authored HTML only. PHP, shortcodes, and third-party blocks may add assets at render time.', 'page-blocks-builder' ) );
		if ( $high_priority > 1 ) $notes[] = __( 'Several images request high priority. Usually only the measured LCP image needs this hint.', 'page-blocks-builder' );
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) $notes[] = __( 'Image and script markup checks require WordPress 6.2 or later.', 'page-blocks-builder' );
		return array( 'rows' => $rows, 'totals' => $totals, 'notes' => $notes );
	}
}
