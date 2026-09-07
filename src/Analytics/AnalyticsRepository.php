<?php

namespace Formhawk\Analytics;

use Formhawk\Infrastructure\Database;

final class AnalyticsRepository {
	public function overview( $days = 30, $page = 1, $per_page = 50 ) {
		global $wpdb;
		list( $start, $end ) = $this->range( $days );
		$page                = max( 1, absint( $page ) );
		$per_page            = max( 1, min( 100, absint( $per_page ) ) );
		$offset              = ( $page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			'SELECT f.*,
				COALESCE(a.views, 0) AS views,
				COALESCE(a.starts, 0) AS starts,
				COALESCE(a.submit_attempts, 0) AS submit_attempts,
				COALESCE(a.submissions, 0) AS submissions,
				COALESCE(a.confirmed_successes, 0) AS confirmed_successes,
				COALESCE(a.abandons, 0) AS abandons,
				COALESCE(a.validation_failures, 0) AS validation_failures,
				COALESCE(a.failures, 0) AS failures,
				COALESCE(a.mail_successes, 0) AS mail_successes,
				COALESCE(a.mail_failures, 0) AS mail_failures,
				COALESCE(a.duration_total_ms, 0) AS duration_total_ms,
				COALESCE(a.duration_samples, 0) AS duration_samples
			FROM %i f
			LEFT JOIN (
				SELECT form_id,
					SUM(views) AS views,
					SUM(starts) AS starts,
					SUM(submit_attempts) AS submit_attempts,
					SUM(submissions) AS submissions,
					SUM(confirmed_successes) AS confirmed_successes,
					SUM(abandons) AS abandons,
					SUM(validation_failures) AS validation_failures,
					SUM(failures) AS failures,
					SUM(mail_successes) AS mail_successes,
					SUM(mail_failures) AS mail_failures,
					SUM(duration_total_ms) AS duration_total_ms,
					SUM(duration_samples) AS duration_samples
				FROM %i
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY form_id
			) a ON a.form_id = f.id
			ORDER BY f.last_seen DESC, f.id DESC
			LIMIT %d OFFSET %d',
			Database::forms_table(),
			Database::daily_table(),
			$start,
			$end,
			$per_page,
			$offset
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Real-time aggregate dashboard query against Formhawk custom tables.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function overview_totals( $days = 30 ) {
		global $wpdb;
		list( $start, $end ) = $this->range( $days );
		$sql                 = $wpdb->prepare(
			'SELECT COUNT(f.id) AS forms,
				COALESCE(SUM(a.views), 0) AS views,
				COALESCE(SUM(a.starts), 0) AS starts,
				COALESCE(SUM(a.submit_attempts), 0) AS submit_attempts,
				COALESCE(SUM(a.submissions), 0) AS submissions,
				COALESCE(SUM(a.confirmed_successes), 0) AS confirmed_successes,
				COALESCE(SUM(a.abandons), 0) AS abandons,
				COALESCE(SUM(a.validation_failures), 0) AS validation_failures,
				COALESCE(SUM(a.failures), 0) AS failures
			FROM %i f
			LEFT JOIN (
				SELECT form_id,
					SUM(views) AS views,
					SUM(starts) AS starts,
					SUM(submit_attempts) AS submit_attempts,
					SUM(submissions) AS submissions,
					SUM(confirmed_successes) AS confirmed_successes,
					SUM(abandons) AS abandons,
					SUM(validation_failures) AS validation_failures,
					SUM(failures) AS failures
				FROM %i
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY form_id
			) a ON a.form_id = f.id',
			Database::forms_table(),
			Database::daily_table(),
			$start,
			$end
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded aggregate query supplies totals independently from dashboard pagination.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? array_map( 'absint', $row ) : $this->totals( array() );
	}

	public function form_stats( $form_id, $days = 30, $previous = false ) {
		global $wpdb;
		list( $start, $end ) = $previous ? $this->previous_range( $days ) : $this->range( $days );
		$sql                 = $wpdb->prepare(
			'SELECT
				COALESCE(SUM(views), 0) AS views,
				COALESCE(SUM(starts), 0) AS starts,
				COALESCE(SUM(submit_attempts), 0) AS submit_attempts,
				COALESCE(SUM(submissions), 0) AS submissions,
				COALESCE(SUM(confirmed_successes), 0) AS confirmed_successes,
				COALESCE(SUM(abandons), 0) AS abandons,
				COALESCE(SUM(validation_failures), 0) AS validation_failures,
				COALESCE(SUM(failures), 0) AS failures,
				COALESCE(SUM(mail_successes), 0) AS mail_successes,
				COALESCE(SUM(mail_failures), 0) AS mail_failures,
				COALESCE(SUM(duration_total_ms), 0) AS duration_total_ms,
				COALESCE(SUM(duration_samples), 0) AS duration_samples
			FROM %i
			WHERE form_id = %d AND stat_date BETWEEN %s AND %s',
			Database::daily_table(),
			absint( $form_id ),
			$start,
			$end
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Real-time aggregate dashboard query against Formhawk custom tables.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	public function field_stats( $form_id, $days = 30 ) {
		global $wpdb;
		list( $start, $end ) = $this->range( $days );
		$sql                 = $wpdb->prepare(
			'SELECT field_key,
				MAX(field_label) AS field_label,
				MAX(field_type) AS field_type,
				SUM(interactions) AS interactions,
				SUM(abandonments) AS abandonments,
				SUM(validation_errors) AS validation_errors
			FROM %i
			WHERE form_id = %d AND stat_date BETWEEN %s AND %s
			GROUP BY field_key
			ORDER BY abandonments DESC, validation_errors DESC, interactions DESC
			LIMIT 100',
			Database::fields_table(),
			absint( $form_id ),
			$start,
			$end
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Real-time aggregate dashboard query against Formhawk custom tables.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function placement_stats( $form_id, $days = 30 ) {
		global $wpdb;
		list( $start, $end ) = $this->range( $days );
		$sql                 = $wpdb->prepare(
			'SELECT p.page_path, p.first_seen, p.last_seen,
				COALESCE(SUM(d.views), 0) AS views,
				COALESCE(SUM(d.starts), 0) AS starts,
				COALESCE(SUM(d.submit_attempts), 0) AS submit_attempts,
				COALESCE(SUM(d.submissions), 0) AS submissions,
				COALESCE(SUM(d.confirmed_successes), 0) AS confirmed_successes,
				COALESCE(SUM(d.abandons), 0) AS abandons,
				COALESCE(SUM(d.validation_failures), 0) AS validation_failures
			FROM %i p
			LEFT JOIN %i d
				ON d.placement_id = p.id AND d.stat_date BETWEEN %s AND %s
			WHERE p.form_id = %d
			GROUP BY p.id, p.page_path, p.first_seen, p.last_seen
			ORDER BY starts DESC, views DESC, p.last_seen DESC
			LIMIT 100',
			Database::placements_table(),
			Database::placement_daily_table(),
			$start,
			$end,
			absint( $form_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded placement aggregate query against Formhawk custom tables.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function daily_series( $form_id, $days = 30 ) {
		global $wpdb;
		list( $start, $end ) = $this->range( $days );
		$sql                 = $wpdb->prepare(
			'SELECT stat_date, views, starts, submit_attempts, submissions, confirmed_successes, abandons, validation_failures, failures
			FROM %i
			WHERE form_id = %d AND stat_date BETWEEN %s AND %s
			ORDER BY stat_date ASC',
			Database::daily_table(),
			absint( $form_id ),
			$start,
			$end
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Real-time aggregate dashboard query against Formhawk custom tables.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function totals( array $rows ) {
		$totals = array(
			'forms'               => count( $rows ),
			'views'               => 0,
			'starts'              => 0,
			'submit_attempts'     => 0,
			'submissions'         => 0,
			'abandons'            => 0,
			'validation_failures' => 0,
			'failures'            => 0,
			'confirmed_successes' => 0,
		);
		foreach ( $rows as $row ) {
			foreach ( array( 'views', 'starts', 'submit_attempts', 'submissions', 'abandons', 'validation_failures', 'failures', 'confirmed_successes' ) as $key ) {
				$totals[ $key ] += isset( $row[ $key ] ) ? absint( $row[ $key ] ) : 0;
			}
		}
		return $totals;
	}

	private function range( $days ) {
		$days  = max( 1, min( 365, absint( $days ) ) );
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$start = $today->modify( '-' . ( $days - 1 ) . ' days' );
		return array( $start->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
	}

	private function previous_range( $days ) {
		$days       = max( 1, min( 365, absint( $days ) ) );
		$today      = new \DateTimeImmutable( 'today', wp_timezone() );
		$prev_end   = $today->modify( '-' . $days . ' days' );
		$prev_start = $prev_end->modify( '-' . ( $days - 1 ) . ' days' );
		return array( $prev_start->format( 'Y-m-d' ), $prev_end->format( 'Y-m-d' ) );
	}
}
