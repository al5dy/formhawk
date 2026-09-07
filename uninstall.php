<?php
/**
 * Formhawk uninstall routine.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'formhawk_daily_cleanup' );
wp_clear_scheduled_hook( 'formhawk_cleanup_continue' );

$formhawk_settings = get_option( 'formhawk_settings', array() );
if ( empty( $formhawk_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$formhawk_tables = array(
	$wpdb->prefix . 'formhawk_forms',
	$wpdb->prefix . 'formhawk_daily',
	$wpdb->prefix . 'formhawk_field_daily',
	$wpdb->prefix . 'formhawk_placements',
	$wpdb->prefix . 'formhawk_placement_daily',
);

foreach ( $formhawk_tables as $formhawk_table ) {
	$formhawk_drop_sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $formhawk_table );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Explicit user-approved uninstall cleanup for Formhawk-owned custom tables.
	$wpdb->query( $formhawk_drop_sql );
}

delete_option( 'formhawk_db_version' );
delete_option( 'formhawk_settings' );
delete_option( 'formhawk_mail_health' );
delete_option( 'formhawk_install_time' );
