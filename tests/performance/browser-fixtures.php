<?php
/** Export real plugin-generated tags and CSS; remove the temporary WP posts/files. */
require __DIR__ . '/bootstrap-local.php';
$port = (int) ( getenv( 'PBB_PERF_PORT' ) ?: 9474 );
if ( $port < 1024 || $port > 65535 ) throw new RuntimeException( 'Invalid port' );
$upload = static function ( $dir ) use ( $port ) { $dir['baseurl'] = 'http://127.0.0.1:' . $port . '/assets'; return $dir; };
add_filter( 'upload_dir', $upload );
$script_url = static function ( $attrs ) use ( $port ) {
	if ( ( $attrs['id'] ?? '' ) === 'gt-pb-deferred-css' ) $attrs['src'] = 'http://127.0.0.1:' . $port . '/deferred-css.js';
	return $attrs;
};
add_filter( 'wp_script_attributes', $script_url );
$fixtures = array();
try {
	foreach ( array( 'inline', 'blocking', 'deferred' ) as $mode ) {
		$css = '#secondary{background:rgb(230,245,240);color:rgb(15,70,55)}';
		$attrs = array( 'css' => $css, 'cssOutput' => $mode === 'inline' ? 'inline' : 'file', 'cssDefer' => $mode === 'deferred' );
		$id = pbb_performance_post( array( $attrs ) );
		try {
			$tag = $mode === 'inline' ? $GLOBALS['gt_page_blocks_builder']->render_block( $attrs ) : gt_pb_section_css::render( $css, $id, $mode === 'deferred' );
			$fixtures[$mode] = array( 'tag' => $tag, 'assets' => array() );
			$assets = get_post_meta( $id, gt_pb_section_css::META_KEY, true );
			foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
				$fixtures[$mode]['assets'][$asset['file']] = file_get_contents( wp_upload_dir()['basedir'] . '/gt-page-blocks/' . $asset['file'] );
			}
		} finally { wp_delete_post( $id, true ); }
	}
} finally { remove_filter( 'upload_dir', $upload ); remove_filter( 'wp_script_attributes', $script_url ); }
echo json_encode( $fixtures, JSON_PRETTY_PRINT ) . "\n";
