<?php
/**
 * Step 1 of 2 — upload the verified v2.7.2 ZIP to R2 and read it back.
 *
 * Writes NOTHING to the database. Safe to re-run: the object key is the plain
 * versioned filename, so a repeat run replaces the same object with the same
 * bytes.
 *
 *   cd ~/files && php8.5 $(which wp) eval-file ~/pbb-release-staging/01-upload-r2.php
 *
 * Only proceed to 02-publish.php if this prints PASS.
 */

$PID    = 1152523;                                  // GT Page Blocks Builder
$KEY    = 'page-blocks-builder-v2.7.2.zip';         // bucket-root key, FluentCart's own convention
$BUCKET = 'gauravtiwari-org-fluentcart';
$LOCAL  = getenv( 'HOME' ) . '/pbb-release-staging/pbb-2.7.2.zip';

// The published GitHub asset, verified locally and again after transfer.
$EXPECT_SIZE = 146377;
$EXPECT_SHA  = '8062e3fa53baa86f3730d3ceee65a62056fcda87ca3cc671d77963369f8eba95';

function pbb_fail( $msg ) { echo "FAIL: {$msg}\n"; }

if ( ! file_exists( $LOCAL ) ) {
	pbb_fail( "staged file missing at {$LOCAL}" );
	return;
}
if ( (int) filesize( $LOCAL ) !== $EXPECT_SIZE || hash_file( 'sha256', $LOCAL ) !== $EXPECT_SHA ) {
	pbb_fail( 'staged file does not match the published GitHub asset' );
	return;
}
echo "OK   staged file matches the GitHub asset ({$EXPECT_SIZE} bytes)\n";

global $wpdb;

// Take only the bucket from the prior row. Its legacy epoch-suffixed
// file_path is NOT the shape FluentCart's uploader produces, and copying it
// would create an object the admin UI would never write.
$prior = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, settings FROM {$wpdb->prefix}fct_product_downloads WHERE post_id = %d ORDER BY id DESC LIMIT 1",
	$PID
) );
if ( ! $prior ) {
	pbb_fail( 'no existing download row for this product' );
	return;
}
$prior_settings = json_decode( (string) $prior->settings, true );
if ( ( $prior_settings['bucket'] ?? '' ) !== $BUCKET ) {
	pbb_fail( 'prior row bucket is ' . var_export( $prior_settings['bucket'] ?? null, true ) . ", expected {$BUCKET}" );
	return;
}
printf( "OK   bucket %s confirmed from row %d\n", $BUCKET, (int) $prior->id );

$driverClass = 'FluentCartPro\\App\\Services\\FileSystem\\Drivers\\R2\\R2Driver';
if ( ! class_exists( $driverClass ) ) {
	pbb_fail( 'R2 driver not available — is FluentCart Pro active?' );
	return;
}
$driver = new $driverClass();

// uploadFile() reads only ['size_in_bytes'] off the file object.
$fileObj = new class( $EXPECT_SIZE ) {
	private $s;
	public function __construct( $s ) { $this->s = $s; }
	public function toArray() { return array( 'size_in_bytes' => $this->s ); }
};

$res = $driver->uploadFile( $LOCAL, $KEY, $fileObj, array( 'bucket' => $BUCKET ) );
if ( ! is_array( $res ) ) {
	pbb_fail( 'driver returned no result' );
	return;
}
// uploadFile() returns { message, path, file: { driver, size, bucket, region, name } }.
$uploaded_driver = $res['file']['driver'] ?? '';
$uploaded_path   = $res['path'] ?? ( $res['file']['name'] ?? '' );
$uploaded_bucket = $res['file']['bucket'] ?? '';
$uploaded_size   = (int) ( $res['file']['size'] ?? 0 );

printf( "OK   uploaded: driver=%s path=%s bucket=%s size=%d\n", $uploaded_driver, $uploaded_path, $uploaded_bucket, $uploaded_size );

if ( $uploaded_driver !== 'r2' ) {
	pbb_fail( 'driver did not report r2' );
	return;
}
if ( $uploaded_path !== $KEY ) {
	pbb_fail( "driver stored key {$uploaded_path}, expected {$KEY}" );
	return;
}
if ( $uploaded_bucket !== $BUCKET ) {
	pbb_fail( "driver used bucket {$uploaded_bucket}, expected {$BUCKET}" );
	return;
}
if ( $uploaded_size !== $EXPECT_SIZE ) {
	pbb_fail( "driver reported size {$uploaded_size}, expected {$EXPECT_SIZE}" );
	return;
}

// Read the stored object back through a signed URL. The URL is never printed.
$signed = $driver->getSignedDownloadUrl( $KEY, $BUCKET );
if ( ! $signed || ! is_string( $signed ) ) {
	pbb_fail( 'could not generate a signed read URL' );
	return;
}

$tmp  = wp_tempnam( 'pbb-readback' );
$resp = wp_remote_get( $signed, array( 'timeout' => 180, 'stream' => true, 'filename' => $tmp ) );
if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
	pbb_fail( 'read-back request failed' );
	@unlink( $tmp );
	return;
}
$size = (int) filesize( $tmp );
$sha  = hash_file( 'sha256', $tmp );
@unlink( $tmp );

printf( "     read-back size   : %d (expected %d)\n", $size, $EXPECT_SIZE );
printf( "     read-back sha256 : %s\n", $sha === $EXPECT_SHA ? 'match' : 'MISMATCH' );

if ( $size === $EXPECT_SIZE && $sha === $EXPECT_SHA ) {
	echo "\nPASS: the object in R2 is byte-identical to the GitHub asset.\n";
	echo "Next: php8.5 \$(which wp) eval-file ~/pbb-release-staging/02-publish.php\n";
} else {
	echo "\nFAIL: stored object does not match. Do NOT run 02-publish.php.\n";
}
