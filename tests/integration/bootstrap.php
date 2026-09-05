<?php
/**
 * Integration bootstrap.
 *
 * Loads a real WordPress so these tests exercise parse_blocks, serialize_block,
 * the REST permission callbacks and the live database - none of which can be
 * faked usefully.
 *
 * Point GT_PB_WP_ROOT at any WordPress install:
 *
 *   GT_PB_WP_ROOT=/path/to/wp vendor/bin/phpunit --testsuite integration
 *
 * wp-env satisfies this too; it is not required, which matters because Docker
 * is not available everywhere this suite needs to run.
 */

$root = getenv( 'GT_PB_WP_ROOT' );

if ( ! $root || ! file_exists( rtrim( $root, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "GT_PB_WP_ROOT must point at a WordPress install containing wp-load.php\n" );
	exit( 1 );
}

// PHP execution is part of what these tests cover.
if ( ! defined( 'GT_PB_ALLOW_PHP' ) ) {
	define( 'GT_PB_ALLOW_PHP', true );
}

define( 'WP_USE_THEMES', false );
require_once rtrim( $root, '/' ) . '/wp-load.php';

if ( ! isset( $GLOBALS['gt_page_blocks_builder'] ) ) {
	fwrite( STDERR, "The plugin is not active on the WordPress install at {$root}\n" );
	exit( 1 );
}

// Act as an administrator. Without a user, wp_insert_post runs KSES and
// escapes the block delimiters in any fixture whose attributes contain markup,
// so the fixture stops being the thing under test. A builder save is always
// made by a logged-in user with unfiltered_html.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

if ( ! $admins ) {
	fwrite( STDERR, "No administrator on the WordPress install at {$root}\n" );
	exit( 1 );
}

wp_set_current_user( (int) $admins[0] );

if ( ! current_user_can( 'unfiltered_html' ) ) {
	fwrite( STDERR, "The chosen administrator lacks unfiltered_html; fixtures would be KSES-escaped\n" );
	exit( 1 );
}
