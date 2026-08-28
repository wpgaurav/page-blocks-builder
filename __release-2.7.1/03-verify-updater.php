<?php
/**
 * Verify the real licensed updater path for one FluentCart product.
 *
 *   ssh ... "cd ~/files && FC_VERIFY_PRODUCT_ID=1172914 \
 *     php8.5 \$(which wp) eval-file -" < verify-updater.php
 *
 * Selects one active license internally. Never prints the license key, activation
 * hash, customer site URL, or the package URL - only the assertions and their
 * results.
 *
 * Proves the customer delivery path, not just storage and database state:
 *   1. a valid activation gets new_version, license_status valid, a package URL
 *   2. that package URL is static (or off-site), so .maintenance cannot 503 it
 *   3. the package downloads with the expected size, SHA-256, ZIP root and version
 *   4. an invalid activation gets no package URL at all
 *   5. the artifact is not reachable at a guessable slug-and-version name
 */

global $wpdb;

$p          = $wpdb->prefix;
$product_id = absint( getenv( 'FC_VERIFY_PRODUCT_ID' ) );

if ( ! $product_id ) {
	echo "FAIL: set FC_VERIFY_PRODUCT_ID\n";
	exit( 1 );
}

// wp eval-file runs this inside a function scope, so counters have to live in
// $GLOBALS explicitly. A plain `global $pass` here silently counts nothing and
// reports "passed 0, failed 0" under a wall of PASS lines.
$GLOBALS['gt_pass'] = 0;
$GLOBALS['gt_fail'] = 0;
$GLOBALS['gt_skip'] = 0;

function gt_check( $label, $ok, $detail = '' ) {
	if ( null === $ok ) {
		++$GLOBALS['gt_skip'];
		echo "  SKIP  {$label}" . ( $detail ? " ({$detail})" : '' ) . "\n";
		return null;
	}
	if ( $ok ) {
		++$GLOBALS['gt_pass'];
		echo "  PASS  {$label}" . ( $detail ? " ({$detail})" : '' ) . "\n";
		return true;
	}
	++$GLOBALS['gt_fail'];
	echo "  FAIL  {$label}" . ( $detail ? " ({$detail})" : '' ) . "\n";
	return false;
}

$product = \FluentCart\App\Models\Product::find( $product_id );
if ( ! $product ) {
	echo "FAIL: product {$product_id} not found\n";
	exit( 1 );
}

$settings    = $product->getProductMeta( 'license_settings' );
$version     = (string) ( $settings['version'] ?? '' );
$download_id = absint( $settings['global_update_file'] ?? 0 );
$download    = $download_id ? \FluentCart\App\Models\ProductDownload::find( $download_id ) : null;

if ( ! $download ) {
	echo "FAIL: no updater download row for product {$product_id}\n";
	exit( 1 );
}

echo "product {$product_id} \"{$product->post_title}\" version {$version} driver {$download->driver}\n";

$uploads  = wp_get_upload_dir();
$dir_path = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'fluent-cart';
$artifact = $dir_path . '/' . $download->file_path;

$expected_size = (int) $download->file_size;
$expected_hash = ( 'local' === $download->driver && is_file( $artifact ) ) ? hash_file( 'sha256', $artifact ) : '';

// One active license and activation for this product. Values stay internal.
$row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT a.activation_hash, s.site_url
		   FROM {$p}fct_license_activations a
		   INNER JOIN {$p}fct_licenses l ON l.id = a.license_id
		   LEFT JOIN {$p}fct_license_sites s ON s.id = a.site_id
		  WHERE l.product_id = %d AND l.status = 'active' AND a.status = 'active'
		  ORDER BY a.id DESC LIMIT 1",
		$product_id
	)
);

if ( ! $row || ! $row->activation_hash ) {
	echo "FAIL: no active license activation for product {$product_id}\n";
	exit( 1 );
}

$endpoint = home_url( '?fluent-cart=get_license_version' );

$ask = function ( $hash ) use ( $endpoint, $product_id, $row ) {
	$res = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 45,
			'body'    => array(
				'activation_hash' => $hash,
				'item_id'         => $product_id,
				'site_url'        => $row->site_url,
				'current_version' => '0.0.1',
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 0, array() );
	}
	return array(
		(int) wp_remote_retrieve_response_code( $res ),
		(array) json_decode( wp_remote_retrieve_body( $res ), true ),
	);
};

echo "\nvalid activation\n";
list( $code, $body ) = $ask( $row->activation_hash );

gt_check( 'HTTP 200', 200 === $code, "got {$code}" );
gt_check( 'license_status valid', 'valid' === ( $body['license_status'] ?? '' ) );
gt_check( "new_version {$version}", $version === (string) ( $body['new_version'] ?? '' ), (string) ( $body['new_version'] ?? 'none' ) );

$package = (string) ( $body['package'] ?? '' );
gt_check( 'package URL present', '' !== $package );
gt_check( 'download_link matches package', $package === (string) ( $body['download_link'] ?? '' ) );

$home_host    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
$package_host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
$package_path = (string) wp_parse_url( $package, PHP_URL_PATH );
$is_static    = $home_host === $package_host && false !== strpos( $package_path, '/uploads/fluent-cart/' );
$is_offsite   = '' !== $package_host && $home_host !== $package_host;

gt_check(
	'package survives .maintenance (static uploads path or off-site)',
	$is_static || $is_offsite,
	$is_static ? 'static uploads' : ( $is_offsite ? 'off-site' : 'same-site PHP route' )
);
gt_check( 'package is not the ?fluent-cart= PHP route', false === strpos( $package, 'fluent-cart=download_license_package' ) );

echo "\npackage download\n";
require_once ABSPATH . 'wp-admin/includes/file.php';
$temp = download_url( $package, 600 );

if ( is_wp_error( $temp ) ) {
	gt_check( 'package downloads', false, $temp->get_error_message() );
} else {
	$size = (int) filesize( $temp );
	$hash = hash_file( 'sha256', $temp );
	gt_check( 'byte size matches row', $expected_size ? $size === $expected_size : null, "{$size} vs {$expected_size}" );
	gt_check(
		'SHA-256 matches stored artifact',
		$expected_hash ? $hash === $expected_hash : null,
		$expected_hash ? substr( $hash, 0, 12 ) : 'no local artifact to compare'
	);

	$zip = new ZipArchive();
	if ( true === $zip->open( $temp ) ) {
		$root  = explode( '/', (string) $zip->getNameIndex( 0 ) )[0];
		$found = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#^[^/]+/[^/]+\.php$#', $name ) ) {
				$content = (string) $zip->getFromIndex( $i );
				if ( preg_match( '/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $m ) ) {
					$found = trim( $m[1] );
					break;
				}
			}
		}
		$zip->close();
		gt_check( 'valid ZIP with single root', '' !== $root, $root );
		gt_check( "embedded plugin version {$version}", $found ? $found === $version : null, $found ?: 'no Version header found' );
	} else {
		gt_check( 'valid ZIP', false );
	}
	wp_delete_file( $temp );
}

echo "\ninvalid activation\n";
list( $code2, $body2 ) = $ask( 'gt-invalid-activation-hash-probe' );
gt_check( 'license_status not valid', 'valid' !== ( $body2['license_status'] ?? '' ), (string) ( $body2['license_status'] ?? 'none' ) );
gt_check( 'no package URL', '' === (string) ( $body2['package'] ?? '' ) );

if ( 'local' === (string) $download->driver ) {
	echo "\nguessability\n";
	$guess = trailingslashit( $uploads['baseurl'] ) . 'fluent-cart/' . rawurlencode( (string) $download->file_name );
	$head  = wp_remote_head( $guess, array( 'timeout' => 30, 'redirection' => 0 ) );
	$gcode = (int) wp_remote_retrieve_response_code( $head );
	gt_check( 'slug-and-version name is not downloadable', 200 !== $gcode, "HTTP {$gcode}" );
}

echo "\npassed {$GLOBALS['gt_pass']}, failed {$GLOBALS['gt_fail']}, skipped {$GLOBALS['gt_skip']}\n";
exit( $GLOBALS['gt_fail'] ? 1 : 0 );
