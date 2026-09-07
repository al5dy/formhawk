<?php

namespace Formhawk\Infrastructure;

final class Cleanup {
	const CONTINUE_HOOK = 'formhawk_cleanup_continue';
	const BATCH_SIZE    = 5000;

	public function register() {
		add_action( Activator::CRON_HOOK, array( $this, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( Activator::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Activator::CRON_HOOK );
		}
	}

	public function run() {
		global $wpdb;
		$settings = get_option( 'formhawk_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$days     = isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 90;
		$days     = max( 30, min( 365, $days ) );
		$today    = new \DateTimeImmutable( 'today', wp_timezone() );
		$cutoff   = $today->modify( '-' . $days . ' days' )->format( 'Y-m-d' );

		$more = false;
		foreach ( array( Database::daily_table(), Database::fields_table(), Database::placement_daily_table() ) as $table ) {
			$deleted = $this->delete_batch( $table, $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
		}
		if ( Database::cro_schema_is_current() ) {
			$deleted = $this->delete_cro_batch( $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
		}
		if ( Database::field_roi_schema_is_current() ) {
			$deleted = $this->delete_batch( Database::field_value_daily_table(), $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
			$deleted = $this->delete_expired_linkage();
			$more    = self::BATCH_SIZE === $deleted || $more;
			$deleted = $this->delete_audit_batch( $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
		}

		if ( $more && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}
	}

	private function delete_batch( $table, $cutoff ) {
		global $wpdb;

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE stat_date < %s LIMIT %d', $table, $cutoff, self::BATCH_SIZE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded retention deletion from an allowlisted Formhawk aggregate table.
		$deleted = $wpdb->query( $sql );

		return is_int( $deleted ) ? $deleted : 0;
	}

	private function delete_cro_batch( $cutoff ) {
		global $wpdb;

		// Active/paused experiments retain their whole analysis window. Finished
		// arm aggregates follow normal retention; the immutable decision survives.
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE id IN (
				SELECT id FROM (
					SELECT d.id FROM %i d LEFT JOIN %i e ON e.id = d.experiment_id
					WHERE d.stat_date < %s AND (e.id IS NULL OR e.status NOT IN (%s, %s, %s, %s, %s, %s))
					LIMIT %d
				) stale
			)',
			Database::experiment_daily_table(),
			Database::experiment_daily_table(),
			Database::experiments_table(),
			$cutoff,
			'suggested',
			'awaiting_approval',
			'running',
			'paused_guardrail',
			'paused_manual',
			'promoted_monitoring',
			self::BATCH_SIZE
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded aggregate retention with explicit active-experiment protection.
		$deleted = $wpdb->query( $sql );
		return is_int( $deleted ) ? $deleted : 0;
	}

	private function delete_expired_linkage() {
		global $wpdb;
		$outcome_cursor = absint( get_option( 'formhawk_field_roi_outcome_cursor', 0 ) );
		$ids_sql        = $wpdb->prepare( 'SELECT s.id,s.public_id FROM %i s WHERE s.attribution_expires_at_utc < %s AND NOT EXISTS (SELECT 1 FROM %i o WHERE o.submission_id=s.id AND o.id>%d) ORDER BY s.id ASC LIMIT %d', Database::submissions_table(), current_time( 'mysql', true ), Database::outcomes_table(), $outcome_cursor, self::BATCH_SIZE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded privacy-retention candidate query.
		$rows = $wpdb->get_results( $ids_sql, ARRAY_A );
		if ( ! $rows ) {
			return 0; }
		$ids          = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
		$public_ids   = array_map( 'strval', wp_list_pluck( $rows, 'public_id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Children and parent are removed as one restart-safe unit.
		$wpdb->query( 'START TRANSACTION' );
		foreach ( array( Database::outcomes_table(), Database::submission_fields_table() ) as $table ) {
			$args = array_merge( array( $table ), $ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count is bounded by BATCH_SIZE and IDs came from Formhawk storage.
			$sql = $wpdb->prepare( "DELETE FROM %i WHERE submission_id IN ({$placeholders})", $args );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Deletes only expired Formhawk linkage children.
			if ( false === $wpdb->query( $sql ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve all linkage rows after a partial failure.
				$wpdb->query( 'ROLLBACK' );
				return 0;
			}
		}
		$public_placeholders = implode( ',', array_fill( 0, count( $public_ids ), '%s' ) );
		$audit_args          = array_merge( array( Database::business_audit_table(), 'submission' ), $public_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is bounded and opaque IDs came from Formhawk storage; the sniff cannot count the generated list.
		$audit_sql = $wpdb->prepare( "DELETE FROM %i WHERE object_type=%s AND object_id IN ({$public_placeholders})", $audit_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Expired opaque linkage must not survive in the structural audit journal.
		if ( false === $wpdb->query( $audit_sql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve all linkage rows after a partial failure.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		$args = array_merge( array( Database::submissions_table() ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count is bounded by BATCH_SIZE.
		$sql = $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Deletes only the resolved expired submission rows after their aggregates survive.
		$deleted = $wpdb->query( $sql );
		if ( ! is_int( $deleted ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve all linkage rows after a partial failure.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publish the complete privacy deletion batch.
		return false === $wpdb->query( 'COMMIT' ) ? 0 : $deleted;
	}

	private function delete_audit_batch( $cutoff ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE created_at_utc < %s LIMIT %d', Database::business_audit_table(), $cutoff . ' 00:00:00', self::BATCH_SIZE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Structural audit retention is bounded and never retains opaque submission linkage indefinitely.
		$deleted = $wpdb->query( $sql );
		return is_int( $deleted ) ? $deleted : 0;
	}
}
