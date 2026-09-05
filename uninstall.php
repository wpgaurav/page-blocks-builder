<?php
/**
 * Uninstall handler.
 *
 * Deleting the plugin used to leave everything behind: the library table, a
 * weekly cron event, and three AI provider API keys sitting in wp_options in
 * plain text. A site owner who removed the plugin to stop using a service kept
 * paying for the storage and kept the credentials.
 *
 * The blocks themselves are the user's content, so the table is only dropped
 * when they have explicitly asked for that in Settings. Everything else -
 * options, transients, cron, secrets - goes unconditionally, because none of it
 * is content and all of it is this plugin's litter.
 *
 * @since 3.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's traces from the current site.
 *
 * @param bool $drop_tables Whether the site owner opted in to dropping the
 *                          library. Passed in rather than read here, because
 *                          the option it comes from is deleted below.
 */
function gt_pb_uninstall_site( bool $drop_tables ): void {
	global $wpdb;

	$options = array(
		'gt_pb_builder_post_types',
		'gt_pb_ai_openai_key',
		'gt_pb_ai_anthropic_key',
		'gt_pb_ai_gemini_key',
		'gt_pb_ai_default_model',
		'gt_pb_preview_css',
		'gt_pb_preview_head_html',
		'gt_pb_preview_js_footer',
		'gt_pb_load_reset',
		'gt_pb_load_typography',
		'gt_pb_load_utilities',
		'gt_pb_utilities_auto_disabled',
		'gt_pb_table_version',
		'gt_pb_asset_version',
		'gt_pb_upgrading',
		'gt_pb_upgrade_cursor',
		'gt_pb_builder_license',
		'gt_pb_builder_license_last_check',
		'gt_pb_delete_data_on_uninstall',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'gt_pb_usage_counts', 'gt_pb_usage_posts', 'gt_pb_builder_update_info', 'gt_pb_library_migration_notice' ) as $transient ) {
		delete_transient( $transient );
	}

	// Per-user and versioned transients have generated names, so they are
	// matched rather than listed.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_gt_pb_%'
		    OR option_name LIKE '_transient_timeout_gt_pb_%'"
	);

	delete_metadata( 'user', 0, 'gt_pb_license_notice_dismissed', '', true );

	wp_clear_scheduled_hook( 'gt_pb_builder_verify_license' );
	wp_clear_scheduled_hook( 'gt_pb_continue_upgrade' );

	// The library is the user's own content, so dropping it is opt-in.
	if ( $drop_tables ) {
		$table = $wpdb->prefix . 'gt_page_blocks';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}_revisions`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		// phpcs:enable
	}
}

// Read the opt-in before anything clears it.
$gt_pb_drop_data = (bool) get_option( 'gt_pb_delete_data_on_uninstall' );

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $gt_pb_site_id ) {
		switch_to_blog( (int) $gt_pb_site_id );
		gt_pb_uninstall_site( (bool) get_option( 'gt_pb_delete_data_on_uninstall' ) );
		restore_current_blog();
	}
} else {
	gt_pb_uninstall_site( $gt_pb_drop_data );
}
