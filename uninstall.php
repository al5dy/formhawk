<?php
/**
 * Formhawk uninstall routine.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'formhawk_daily_cleanup' );
wp_clear_scheduled_hook( 'formhawk_cleanup_continue' );
wp_clear_scheduled_hook( 'formhawk_cro_hourly_evaluation' );
wp_clear_scheduled_hook( 'formhawk_cro_context_cleanup' );
wp_clear_scheduled_hook( 'formhawk_cro_context_cleanup_continue' );
wp_clear_scheduled_hook( 'formhawk_field_roi_evaluate' );
wp_clear_scheduled_hook( 'formhawk_minimum_form_hourly_evaluation' );

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
	$wpdb->prefix . 'formhawk_ingestion_budgets',
	$wpdb->prefix . 'formhawk_dimensions',
	$wpdb->prefix . 'formhawk_cro_forms',
	$wpdb->prefix . 'formhawk_experiments',
	$wpdb->prefix . 'formhawk_variants',
	$wpdb->prefix . 'formhawk_experiment_daily',
	$wpdb->prefix . 'formhawk_cro_contexts',
	$wpdb->prefix . 'formhawk_optimization_history',
	$wpdb->prefix . 'formhawk_submissions',
	$wpdb->prefix . 'formhawk_outcomes',
	$wpdb->prefix . 'formhawk_submission_fields',
	$wpdb->prefix . 'formhawk_field_definitions',
	$wpdb->prefix . 'formhawk_form_versions',
	$wpdb->prefix . 'formhawk_field_value_daily',
	$wpdb->prefix . 'formhawk_field_roi_results',
	$wpdb->prefix . 'formhawk_field_roi_history',
	$wpdb->prefix . 'formhawk_outcome_api_keys',
	$wpdb->prefix . 'formhawk_business_audit',
	$wpdb->prefix . 'formhawk_minimum_form_runs',
	$wpdb->prefix . 'formhawk_minimum_form_baselines',
	$wpdb->prefix . 'formhawk_minimum_form_decisions',
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
delete_option( 'formhawk_field_roi_settings' );
delete_option( 'formhawk_field_roi_dirty' );
delete_option( 'formhawk_field_roi_cursor' );
delete_option( 'formhawk_field_roi_outcome_cursor' );
delete_option( 'formhawk_field_roi_lock' );
delete_option( 'formhawk_field_roi_last_evaluated' );
