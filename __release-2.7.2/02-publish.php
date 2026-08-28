<?php
/**
 * Step 2 of 2 — point the FluentCart updater at v2.7.1.
 *
 *   cd ~/files && php8.5 $(which wp) eval-file ~/pbb-release-staging/02-publish.php
 *
 * Run 01-upload-r2.php first and only continue if it printed PASS.
 *
 * Every precondition is re-read inside this script rather than trusted from
 * an earlier session: another agent or a human can ship a release between
 * discovery and mutation, so a row id read minutes ago is a hypothesis. If
 * anything fails the expected-state check the transaction rolls back and
 * nothing changes.
 */

$PID        = 1152523;
$VERSION    = '2.7.2';
$PREV_VER   = '2.7.1';
$PREV_FILE  = 255;
$KEY        = 'page-blocks-builder-v2.7.2.zip';
$BUCKET     = 'gauravtiwari-org-fluentcart';
$SIZE       = 146377;

$CHANGELOG = '<h4>2.7.2</h4><ul>'
	. '<li>Fixed: scrolling was slow, or stalled entirely, while blocks showed their visual preview. Each preview is a full document carrying the whole theme stylesheet set, so a page of eight blocks kept roughly 200 stylesheets alive at once and scrolling paid for all of it.</li>'
	. '<li>Previews now mount only while near the viewport and unmount beyond it. On a twelve-block page at most three are live instead of twelve. Preview fidelity is unchanged.</li>'
	. '</ul>';

global $wpdb;

function pbb_abort( $msg ) { echo "ABORT: {$msg}\nNothing was changed.\n"; }

// ---------------------------------------------------------------- checks ---
$product = get_post( $PID );
if ( ! $product || $product->post_type !== 'fluent-products' || $product->post_status !== 'publish' ) {
	pbb_abort( 'product 1152523 is not a published fluent-products post' );
	return;
}
printf( "OK   product %d: %s\n", $PID, $product->post_title );

$ls_raw = $wpdb->get_var( $wpdb->prepare(
	"SELECT meta_value FROM {$wpdb->prefix}fct_product_meta WHERE object_id = %d AND meta_key = 'license_settings'",
	$PID
) );
$ls = is_string( $ls_raw ) ? json_decode( $ls_raw, true ) : null;
if ( ! is_array( $ls ) ) {
	pbb_abort( 'license_settings is unreadable' );
	return;
}
if ( ( $ls['enabled'] ?? '' ) !== 'yes' ) {
	pbb_abort( 'licensing is not enabled for this product' );
	return;
}
if ( (string) ( $ls['version'] ?? '' ) !== $PREV_VER ) {
	pbb_abort( sprintf( 'expected current version %s, found %s — someone else may have released', $PREV_VER, $ls['version'] ?? 'none' ) );
	return;
}
if ( (string) ( $ls['global_update_file'] ?? '' ) !== (string) $PREV_FILE ) {
	pbb_abort( sprintf( 'expected updater file %d, found %s', $PREV_FILE, $ls['global_update_file'] ?? 'none' ) );
	return;
}
printf( "OK   license_settings: version=%s global_update_file=%s\n", $ls['version'], $ls['global_update_file'] );

$dupe = $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}fct_product_downloads WHERE post_id = %d AND file_path = %s",
	$PID, $KEY
) );
if ( $dupe ) {
	pbb_abort( "a row for {$KEY} already exists (id {$dupe}); this version appears to be published already" );
	return;
}

// The object must actually be in R2 and match, before any row is written.
$driverClass = 'FluentCartPro\\App\\Services\\FileSystem\\Drivers\\R2\\R2Driver';
if ( ! class_exists( $driverClass ) ) {
	pbb_abort( 'R2 driver unavailable' );
	return;
}
$driver = new $driverClass();
$signed = $driver->getSignedDownloadUrl( $KEY, $BUCKET );
if ( ! $signed ) {
	pbb_abort( 'could not sign a read URL for the uploaded object — run 01-upload-r2.php first' );
	return;
}
$tmp  = wp_tempnam( 'pbb-precheck' );
$resp = wp_remote_get( $signed, array( 'timeout' => 180, 'stream' => true, 'filename' => $tmp ) );
$ok   = ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) === 200
	&& (int) filesize( $tmp ) === $SIZE
	&& hash_file( 'sha256', $tmp ) === '8062e3fa53baa86f3730d3ceee65a62056fcda87ca3cc671d77963369f8eba95';
@unlink( $tmp );
if ( ! $ok ) {
	pbb_abort( 'the object in R2 is missing or does not match the release artifact' );
	return;
}
echo "OK   object in R2 matches the release artifact\n";

$serial = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(MAX(serial),0)+1 FROM {$wpdb->prefix}fct_product_downloads WHERE post_id = %d", $PID
) );

// ------------------------------------------------------------- mutation ---
$wpdb->query( 'START TRANSACTION' );
try {
	$row = \FluentCart\App\Models\ProductDownload::create( array(
		'post_id'              => $PID,
		'product_variation_id' => '[]',
		'download_identifier'  => wp_generate_uuid4(),
		'title'                => $KEY,
		'type'                 => 'zip',
		'driver'               => 'r2',
		'file_name'            => $KEY,
		'file_path'            => $KEY,
		'file_url'             => $KEY,
		'file_size'            => (string) $SIZE,
		'settings'             => wp_json_encode( array(
			'download_limit'  => '',
			'download_expiry' => '',
			'bucket'          => $BUCKET,
		) ),
		'serial'               => $serial,
	) );

	$new_id = (int) $row->id;
	if ( ! $new_id ) {
		throw new Exception( 'download row was not created' );
	}

	// Change only these two keys; everything else in license_settings stays.
	$next = $ls;
	$next['version']            = $VERSION;
	$next['global_update_file'] = (string) $new_id;

	// updateProductMeta() is an instance method: ( $metaKey, $metaValue ).
	$product_model = \FluentCart\App\Models\Product::find( $PID );
	if ( ! $product_model ) {
		throw new Exception( 'could not load the Product model' );
	}
	$product_model->updateProductMeta( 'license_settings', $next );
	$product_model->updateProductMeta( '_fluent_sl_changelog', $CHANGELOG );

	// Read back before committing.
	$check_raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->prefix}fct_product_meta WHERE object_id = %d AND meta_key = 'license_settings'",
		$PID
	) );
	$check = is_string( $check_raw ) ? json_decode( $check_raw, true ) : null;
	if ( ! is_array( $check )
		|| (string) ( $check['version'] ?? '' ) !== $VERSION
		|| (string) ( $check['global_update_file'] ?? '' ) !== (string) $new_id ) {
		throw new Exception( 'license_settings did not read back as expected' );
	}

	$wpdb->query( 'COMMIT' );
	printf( "\nPASS: new download row %d, version %s.\n", $new_id, $VERSION );
	printf( "Rollback target retained: row %d (v%s).\n", $PREV_FILE, $PREV_VER );
	echo "Next: php8.5 \$(which wp) eval-file ~/pbb-release-staging/03-verify-updater.php\n";
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	pbb_abort( $e->getMessage() );
}
