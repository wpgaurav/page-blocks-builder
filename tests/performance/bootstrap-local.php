<?php
require dirname( __DIR__ ) . '/integration/bootstrap.php';
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) && wp_get_environment_type() !== 'local' ) {
	throw new RuntimeException( 'Performance fixtures require a local WordPress install. Do not run against production.' );
}
$loaded = ( new ReflectionClass( GT_Page_Blocks_Builder::class ) )->getFileName();
if ( realpath( $loaded ) !== realpath( dirname( __DIR__, 2 ) . '/page-blocks-builder.php' ) ) {
	throw new RuntimeException( 'Local WordPress must load this checkout, not a different installed copy.' );
}
function pbb_performance_post( array $sections ): int {
	$blocks = array_map( static function ( $attrs ) {
		return serialize_block( array( 'blockName' => GT_Page_Blocks_Builder::BLOCK_NAME, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	}, $sections );
	$id = wp_insert_post( wp_slash( array( 'post_title' => 'Disposable PBB performance fixture', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => implode( "\n", $blocks ) ) ), true );
	if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
	return $id;
}
