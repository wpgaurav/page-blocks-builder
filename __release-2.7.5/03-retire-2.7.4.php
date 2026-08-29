<?php
/**
 * Retire the superseded v2.7.4 download row, so only the current release
 * remains in the bucket.
 *
 *   cd ~/files && php8.5 $(which wp) eval-file - < __release-2.7.5/03-retire-2.7.4.php
 *
 * Then collect the object it leaves behind:
 *
 *   cd ~/files && R2_RECONCILE_SLUGS='page-blocks-builder' R2_RECONCILE_DELETE=yes \
 *     php8.5 $(which wp) eval-file - < ~/.claude/skills/release-plugin-github-fluentcart/scripts/r2-reconcile.php
 *
 * Refuses unless the row is the expected one, is no longer the live updater
 * target, and no customer download permission references it. Only a
 * superseded, unreferenced row can go.
 *
 * After this the bucket holds v2.7.5 alone. Rolling back means re-uploading
 * an earlier ZIP from the GitHub releases page and creating a row for it —
 * every version is still published there, so nothing is lost for good.
 */
global $wpdb; $p = $wpdb->prefix;
$PID         = 1152523;
$ROW         = 258;
$EXPECT_NAME = 'page-blocks-builder-v2.7.4.zip';

function pbb_abort( $m ) { echo "ABORT: {$m}\nNothing was changed.\n"; }

$row = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, post_id, file_name, file_path FROM {$p}fct_product_downloads WHERE id = %d", $ROW ) );
if ( ! $row ) { pbb_abort( "row {$ROW} does not exist" ); return; }
if ( (int) $row->post_id !== $PID ) { pbb_abort( 'row belongs to another product' ); return; }
if ( $row->file_name !== $EXPECT_NAME ) { pbb_abort( "row {$ROW} is {$row->file_name}, expected {$EXPECT_NAME}" ); return; }

$ls = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT meta_value FROM {$p}fct_product_meta WHERE object_id=%d AND meta_key='license_settings'", $PID ) ), true );
if ( ! is_array( $ls ) ) { pbb_abort( 'license_settings unreadable' ); return; }
if ( (string) $ls['global_update_file'] === (string) $ROW ) {
	pbb_abort( "the updater still points at row {$ROW} — this is the live release" );
	return;
}
echo "OK   updater points at row {$ls['global_update_file']} (v{$ls['version']}), not {$ROW}\n";

$perm = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$p}fct_order_download_permissions WHERE download_id = %d", $ROW ) );
if ( $perm > 0 ) { pbb_abort( "{$perm} customer download permission(s) still reference row {$ROW}" ); return; }
echo "OK   no customer download permissions reference row {$ROW}\n";

$wpdb->query( 'START TRANSACTION' );
$deleted = $wpdb->delete( $p . 'fct_product_downloads', array( 'id' => $ROW ), array( '%d' ) );
if ( 1 !== (int) $deleted ) {
	$wpdb->query( 'ROLLBACK' );
	pbb_abort( 'delete affected ' . var_export( $deleted, true ) . ' rows, expected 1' );
	return;
}
$still = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}fct_product_downloads WHERE id = %d", $ROW ) );
$live  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}fct_product_downloads WHERE id = %d", (int) $ls['global_update_file'] ) );
if ( $still || ! $live ) {
	$wpdb->query( 'ROLLBACK' );
	pbb_abort( 'post-delete state was not what it should be' );
	return;
}
$wpdb->query( 'COMMIT' );
echo "\nPASS: row {$ROW} ({$EXPECT_NAME}) removed. Live row {$live} intact.\n";
echo "Next: run the reconciler with R2_RECONCILE_DELETE=yes to collect the object.\n";
