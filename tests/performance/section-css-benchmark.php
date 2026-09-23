<?php
/** Informational scaling benchmark; timings are not CI pass/fail budgets. */
require __DIR__ . '/bootstrap-local.php';
$results = array();
$previous_query = $GLOBALS['wp_query'];
$printed = new ReflectionProperty( gt_pb_section_css::class, 'printed' );
$previous_printed = $printed->getValue();
try {
	foreach ( array( 1, 10, 50, 100 ) as $count ) {
		$sections = array();
		for ( $i = 0; $i < $count; $i++ ) $sections[] = array( 'css' => '.benchmark-' . $i . '{color:#123456;padding:16px}', 'cssOutput' => 'file', 'cssDefer' => true );
		$id = pbb_performance_post( $sections );
		try {
			$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id, 'post_status' => 'draft' ) );
			$samples = array();
			for ( $run = 0; $run < 5; $run++ ) {
				$printed->setValue( null, array() );
				if ( method_exists( gt_pb_section_css::class, 'invalidate' ) ) gt_pb_section_css::invalidate( $id );
				$parses = 0; $queries = 0;
				$parser = static function ( $class ) use ( &$parses ) { $parses++; return $class; };
				$query = static function ( $sql ) use ( &$queries ) { $queries++; return $sql; };
				add_filter( 'block_parser_class', $parser ); add_filter( 'query', $query );
				$start = hrtime( true );
				try {
					ob_start(); gt_pb_section_css::print_head(); $head = ob_get_clean();
					$body = '';
					foreach ( $sections as $section ) $body .= gt_pb_section_css::render( $section['css'], $id, true );
				} finally {
					$ms = ( hrtime( true ) - $start ) / 1e6;
					remove_filter( 'block_parser_class', $parser ); remove_filter( 'query', $query );
				}
				if ( $body !== '' || substr_count( $head, 'media="print"' ) !== $count ) throw new RuntimeException( 'Fixture correctness failed' );
				$samples[] = array( 'ms' => round( $ms, 3 ), 'parse_calls' => $parses, 'sql_queries' => $queries );
			}
			$times = array_column( $samples, 'ms' ); sort( $times );
			$results[] = array( 'sections' => $count, 'median_ms' => $times[2], 'samples' => $samples );
		} finally { wp_delete_post( $id, true ); }
	}
} finally { $GLOBALS['wp_query'] = $previous_query; $printed->setValue( null, $previous_printed ); }
echo json_encode( array( 'version' => GT_PB_BUILDER_VERSION, 'php' => PHP_VERSION, 'measurement' => 'Warm generated files; head pass plus all section render calls. Excludes WordPress bootstrap, HTTP and browser rendering.', 'results' => $results ), JSON_PRETTY_PRINT ) . "\n";
