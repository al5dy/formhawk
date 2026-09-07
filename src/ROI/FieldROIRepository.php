<?php

namespace Formhawk\ROI;

use Formhawk\Infrastructure\Database;
use Formhawk\Outcomes\OutcomeStatus;
use Formhawk\CRO\Experiments\ExperimentType;

final class FieldROIRepository {
	public function fields( $start, $end, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT fd.*, f.title AS form_title, f.provider,
			COALESCE(SUM(d.interactions),0) AS interactions,
			COALESCE(SUM(d.abandonments),0) AS abandonments,
			COALESCE(SUM(d.client_validation_errors),0) AS client_validation_errors,
			COALESCE(SUM(d.provider_validation_errors),0) AS provider_validation_errors,
			(SELECT COALESCE(SUM(x.starts),0) FROM %i x WHERE x.form_id = fd.form_id AND x.stat_date BETWEEN %s AND %s) AS starts
			FROM %i fd INNER JOIN %i f ON f.id = fd.form_id
			LEFT JOIN %i d ON d.form_id = fd.form_id AND d.field_key = fd.normalized_key AND d.stat_date BETWEEN %s AND %s
			GROUP BY fd.id ORDER BY fd.id ASC LIMIT %d OFFSET %d',
			Database::daily_table(),
			$start,
			$end,
			Database::field_definitions_table(),
			Database::forms_table(),
			Database::fields_table(),
			$start,
			$end,
			max( 1, min( 100, absint( $limit ) ) ),
			absint( $offset )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded scheduled aggregate query over indexed Formhawk tables.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function experiment_for_field( array $field, $start, $end ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT e.* FROM %i e WHERE e.form_id = %d AND e.created_at_utc <= %s AND (e.ended_at_utc IS NULL OR e.ended_at_utc >= %s) ORDER BY e.id DESC LIMIT 30',
			Database::experiments_table(),
			absint( $field['form_id'] ),
			$end . ' 23:59:59',
			$start . ' 00:00:00'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed experiment lookup.
		$experiments = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $experiments as $experiment ) {
			if ( ! in_array( $experiment['type'], array( ExperimentType::FIELD_ORDER, ExperimentType::PROGRESSIVE_DISCLOSURE ), true ) ) {
				continue;
			}
			$variants = $this->variants( $experiment['id'] );
			if ( count( $variants ) < 2 ) {
				continue; }
			$intervention = $this->field_intervention( $experiment['type'], $variants[1], $field['normalized_key'] );
			if ( $intervention ) {
				$experiment['variants']     = $variants;
				$experiment['intervention'] = $intervention;
				return $experiment;
			}
		}
		return null;
	}

	public function cohorts( array $experiment, $start, $end, $currency, $maturity_days ) {
		$variants   = $experiment['variants'];
		$control_id = absint( $variants[0]['id'] );
		$variant_id = absint( $variants[1]['id'] );
		// Exclude the boundary date so a late submission is never counted with a
		// full-day traffic denominator before its exact maturity timestamp.
		$cutoff_timestamp = time() - ( max( 1, absint( $maturity_days ) ) + 1 ) * DAY_IN_SECONDS;
		$cutoff_date      = wp_date( 'Y-m-d', $cutoff_timestamp, wp_timezone() );
		$mature_end       = min( $end, $cutoff_date );
		if ( $mature_end < $start ) {
			return null; }
		$base     = $this->experiment_traffic( $experiment['id'], $control_id, $variant_id, $start, $mature_end );
		$outcomes = $this->experiment_outcomes( $experiment['id'], $control_id, $variant_id, $start, $mature_end, $currency );
		$maturing = $this->maturing_counts( $experiment['id'], $control_id, $variant_id, $start, $end );
		foreach ( array( $control_id, $variant_id ) as $id ) {
			if ( ! isset( $base[ $id ] ) ) {
				$base[ $id ] = array(
					'visitors'        => 0,
					'submissions'     => 0,
					'qualified'       => 0,
					'won'             => 0,
					'known'           => 0,
					'revenue_samples' => 0,
					'revenue_values'  => array(),
					'maturing'        => 0,
				); }
			$base[ $id ]['qualified']       = isset( $outcomes[ $id ] ) ? $outcomes[ $id ]['qualified'] : 0;
			$base[ $id ]['won']             = isset( $outcomes[ $id ] ) ? $outcomes[ $id ]['won'] : 0;
			$base[ $id ]['known']           = isset( $outcomes[ $id ] ) ? $outcomes[ $id ]['known'] : 0;
			$base[ $id ]['revenue_samples'] = isset( $outcomes[ $id ] ) ? $outcomes[ $id ]['revenue_samples'] : 0;
			$base[ $id ]['revenue_values']  = isset( $outcomes[ $id ] ) ? $outcomes[ $id ]['revenue_values'] : array();
			$base[ $id ]['coverage']        = $base[ $id ]['submissions'] ? min( 1, $base[ $id ]['known'] / $base[ $id ]['submissions'] ) : 0;
			$base[ $id ]['maturing']        = isset( $maturing[ $id ] ) ? $maturing[ $id ] : 0;
			$base[ $id ]['seed']            = absint( $experiment['id'] ) * 1009 + $id;
		}
		return array(
			'control'                => $base[ $control_id ],
			'variant'                => $base[ $variant_id ],
			'balance_cells'          => $this->experiment_balance_cells( $experiment['id'], $control_id, $variant_id, $start, $mature_end ),
			'mature_through'         => $mature_end,
			'control_id'             => $control_id,
			'variant_id'             => $variant_id,
			'expected_variant_share' => max( 0, min( 1, absint( $variants[1]['traffic_weight'] ) / 100 ) ),
		);
	}

	public function save( $field_id, $start, $end, $currency, $evidence, $confidence, array $decision, array $metrics, $model_version, $data_through ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$json = wp_json_encode( $metrics );
		$sql  = $wpdb->prepare(
			'INSERT INTO %i (field_definition_id,period_start,period_end,currency,evidence_level,confidence,verdict,recommendation,score,metrics_json,model_version,evaluated_at_utc,data_through_utc) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE period_start=VALUES(period_start),period_end=VALUES(period_end),evidence_level=VALUES(evidence_level),confidence=VALUES(confidence),verdict=VALUES(verdict),recommendation=VALUES(recommendation),score=VALUES(score),metrics_json=VALUES(metrics_json),model_version=VALUES(model_version),evaluated_at_utc=VALUES(evaluated_at_utc),data_through_utc=VALUES(data_through_utc)',
			Database::field_roi_results_table(),
			absint( $field_id ),
			$start,
			$end,
			$currency,
			$evidence,
			$confidence,
			$decision['verdict'],
			$decision['recommendation'],
			absint( $decision['score'] ),
			$json,
			$model_version,
			$now,
			$data_through . ' 23:59:59'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Atomic result projection update.
		$result      = $wpdb->query( $sql );
		$hash        = hash( 'sha256', $currency . '|' . $evidence . '|' . $confidence . '|' . $decision['verdict'] . '|' . $decision['recommendation'] . '|' . $json . '|' . $model_version );
		$history_sql = $wpdb->prepare(
			'INSERT IGNORE INTO %i (field_definition_id,result_hash,before_metrics_json,after_metrics_json,decision,confidence,evidence_level,currency,model_version,created_at_utc) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
			Database::field_roi_history_table(),
			absint( $field_id ),
			$hash,
			wp_json_encode( isset( $metrics['control'] ) ? $metrics['control'] : array() ),
			wp_json_encode( isset( $metrics['variant'] ) ? $metrics['variant'] : array() ),
			$decision['recommendation'],
			$confidence,
			$evidence,
			$currency,
			$model_version,
			$now
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Immutable deduplicated decision snapshot.
		$wpdb->query( $history_sql );
		return false !== $result;
	}

	public function results( $limit = 100, $currency = null ) {
		global $wpdb;
		if ( null === $currency ) {
			$settings = get_option( 'formhawk_field_roi_settings', array() );
			$currency = is_array( $settings ) && isset( $settings['currency'] ) ? strtoupper( $settings['currency'] ) : 'USD';
		}
		$sql = $wpdb->prepare( 'SELECT r.*, fd.form_id, fd.label, fd.normalized_key, fd.field_type, fd.required, f.title AS form_title, f.provider FROM %i r INNER JOIN %i fd ON fd.id=r.field_definition_id INNER JOIN %i f ON f.id=fd.form_id WHERE r.currency=%s ORDER BY r.score DESC, r.evaluated_at_utc DESC LIMIT %d', Database::field_roi_results_table(), Database::field_definitions_table(), Database::forms_table(), strtoupper( sanitize_text_field( $currency ) ), max( 1, min( 200, absint( $limit ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded dashboard projection.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['metrics'] = json_decode( $row['metrics_json'], true ); }
		unset( $row );
		usort(
			$rows,
			static function ( $left, $right ) {
				$left_upside  = isset( $left['metrics']['revenue']['impact_rpv_minor'] ) ? abs( $left['metrics']['revenue']['impact_rpv_minor'] ) : 0;
				$right_upside = isset( $right['metrics']['revenue']['impact_rpv_minor'] ) ? abs( $right['metrics']['revenue']['impact_rpv_minor'] ) : 0;
				$upside_order = $right_upside <=> $left_upside;
				return 0 !== $upside_order ? $upside_order : absint( $right['score'] ) <=> absint( $left['score'] );
			}
		);
		return $rows;
	}

	public function result( $field_id ) {
		foreach ( $this->results( 200 ) as $row ) {
			if ( absint( $row['field_definition_id'] ) === absint( $field_id ) ) {
				return $row; }
		}
		return null;
	}

	public function history( $field_id, $limit = 30 ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE field_definition_id=%d ORDER BY created_at_utc DESC,id DESC LIMIT %d', Database::field_roi_history_table(), absint( $field_id ), max( 1, min( 100, absint( $limit ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded immutable decision-history lookup.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function form_summary( $form_id, $start, $end, $currency ) {
		global $wpdb;
		$settings      = get_option( 'formhawk_field_roi_settings', array() );
		$maturity_days = is_array( $settings ) && isset( $settings['maturity_days'] ) ? max( 1, min( 90, absint( $settings['maturity_days'] ) ) ) : 14;
		$cutoff        = wp_date( 'Y-m-d', time() - ( $maturity_days + 1 ) * DAY_IN_SECONDS, wp_timezone() );
		$mature_end    = min( $end, $cutoff );
		if ( $mature_end < $start ) {
			return array(
				'views'           => 0,
				'submissions'     => 0,
				'attributed'      => 0,
				'qualified'       => 0,
				'won'             => 0,
				'known'           => 0,
				'revenue_minor'   => 0,
				'revenue_samples' => 0,
			);
		}
		$traffic_sql = $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) views,COALESCE(SUM(confirmed_successes),0) submissions FROM %i WHERE form_id=%d AND stat_date BETWEEN %s AND %s', Database::daily_table(), absint( $form_id ), $start, $mature_end );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Single bounded form-summary aggregate.
		$traffic     = $wpdb->get_row( $traffic_sql, ARRAY_A );
		$outcome_sql = $wpdb->prepare(
			"SELECT COUNT(*) attributed, SUM(x.qualified) qualified, SUM(x.won) won, SUM(x.known) known, SUM(x.revenue_minor) revenue_minor, SUM(x.has_revenue) revenue_samples
			FROM (SELECT s.id, MAX(o.outcome_type='qualified') qualified, MAX(s.status='won') won, MAX(o.outcome_type IN ('qualified','unqualified','won','lost','spam','duplicate')) known, SUM(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.currency=%s THEN COALESCE(o.value_minor,0) ELSE 0 END) revenue_minor, MAX(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.currency=%s AND o.value_minor IS NOT NULL THEN 1 ELSE 0 END) has_revenue FROM %i s LEFT JOIN %i o ON o.submission_id=s.id WHERE s.form_id=%d AND s.stat_date BETWEEN %s AND %s AND s.mature_after_utc<=%s AND s.status NOT IN ('spam','duplicate') GROUP BY s.id) x",
			$currency,
			$currency,
			Database::submissions_table(),
			Database::outcomes_table(),
			absint( $form_id ),
			$start,
			$mature_end,
			current_time( 'mysql', true )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Mature outcome rollup for one form and one bounded period.
		$outcomes = $wpdb->get_row( $outcome_sql, ARRAY_A );
		return array_merge(
			array(
				'views'       => 0,
				'submissions' => 0,
			),
			is_array( $traffic ) ? $traffic : array(),
			array(
				'attributed'      => 0,
				'qualified'       => 0,
				'won'             => 0,
				'known'           => 0,
				'revenue_minor'   => 0,
				'revenue_samples' => 0,
			),
			is_array( $outcomes ) ? $outcomes : array()
		);
	}

	public function rebuild_daily( $start, $end ) {
		global $wpdb;
		$outcome_work = $this->build_outcome_work_table( $start, $end );
		if ( ! $outcome_work ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled projection rebuild owns this short transaction.
		$wpdb->query( 'START TRANSACTION' );
		$delete = $wpdb->prepare( 'DELETE FROM %i WHERE stat_date BETWEEN %s AND %s', Database::field_value_daily_table(), $start, $end );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded, restart-safe aggregate rebuild.
		$deleted = $wpdb->query( $delete );
		if ( false === $deleted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the prior complete projection on failure.
			$wpdb->query( 'ROLLBACK' );
			$this->drop_outcome_work_table( $outcome_work );
			return false;
		}
		$sql = $wpdb->prepare(
			"INSERT INTO %i (field_definition_id,stat_date,cohort,placement_id,device_class,form_version_id,experiment_id,variant_id,currency,submissions,qualified,won,spam,duplicates,unknown_outcomes,revenue_minor,revenue_samples)
			SELECT sf.field_definition_id,s.stat_date,'present',s.placement_id,s.device_class,COALESCE(s.form_version_id,0),COALESCE(s.experiment_id,0),COALESCE(s.variant_id,0),COALESCE(oa.currency,'XXX'),COUNT(*),SUM(COALESCE(oa.qualified,0)),SUM(s.status='won'),SUM(s.status='spam'),SUM(s.status='duplicate'),SUM(COALESCE(oa.known,0)=0),SUM(COALESCE(oa.revenue_minor,0)),SUM(COALESCE(oa.revenue_samples,0))
			FROM %i s FORCE INDEX (stat_date) STRAIGHT_JOIN %i sf ON sf.submission_id=s.id LEFT JOIN %i oa ON oa.submission_id=s.id
			WHERE s.stat_date BETWEEN %s AND %s
			GROUP BY sf.field_definition_id,s.stat_date,s.placement_id,s.device_class,COALESCE(s.form_version_id,0),COALESCE(s.experiment_id,0),COALESCE(s.variant_id,0),COALESCE(oa.currency,'XXX')",
			Database::field_value_daily_table(),
			Database::submissions_table(),
			Database::submission_fields_table(),
			$outcome_work,
			$start,
			$end
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded aggregate rebuild; no visitor values exist in source tables.
		$inserted = $wpdb->query( $sql );
		if ( false === $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the prior complete projection on failure.
			$wpdb->query( 'ROLLBACK' );
			$this->drop_outcome_work_table( $outcome_work );
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publish delete/insert atomically.
		$committed = false !== $wpdb->query( 'COMMIT' );
		$this->drop_outcome_work_table( $outcome_work );
		return $committed;
	}

	private function build_outcome_work_table( $start, $end ) {
		global $wpdb;
		$table = $wpdb->prefix . 'formhawk_roi_outcome_work';
		$this->drop_outcome_work_table( $table );
		$create = $wpdb->prepare( 'CREATE TEMPORARY TABLE %i (submission_id bigint(20) unsigned NOT NULL,currency char(3) DEFAULT NULL,qualified tinyint(1) unsigned NOT NULL,known tinyint(1) unsigned NOT NULL,revenue_minor bigint(20) NOT NULL,revenue_samples tinyint(1) unsigned NOT NULL,PRIMARY KEY (submission_id)) ENGINE=InnoDB', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Connection-local bounded work table prevents a quadratic derived-table join and is never reusable across requests.
		if ( false === $wpdb->query( $create ) ) {
			return false;
		}
		$sql = $wpdb->prepare(
			"INSERT INTO %i (submission_id,currency,qualified,known,revenue_minor,revenue_samples)
			SELECT s.id,MAX(o.currency),MAX(o.outcome_type='qualified'),MAX(o.outcome_type IN ('qualified','unqualified','won','lost','spam','duplicate')),SUM(CASE WHEN o.outcome_type IN ('won','value_adjustment') THEN COALESCE(o.value_minor,0) ELSE 0 END),MAX(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.value_minor IS NOT NULL THEN 1 ELSE 0 END)
			FROM %i s FORCE INDEX (stat_date) LEFT JOIN %i o ON o.submission_id=s.id
			WHERE s.stat_date BETWEEN %s AND %s GROUP BY s.id",
			$table,
			Database::submissions_table(),
			Database::outcomes_table(),
			$start,
			$end
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Date-bounded population of a connection-local aggregate must reflect the current transaction.
		if ( false === $wpdb->query( $sql ) ) {
			$this->drop_outcome_work_table( $table );
			return false;
		}
		return $table;
	}

	private function drop_outcome_work_table( $table ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Removes only the fixed connection-local Field ROI work table, which is never cached.
		$wpdb->query( $sql );
	}

	public function rebuild_changed_days( $outcome_cursor, $event_limit = 10000, $day_limit = 7 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT o.id,s.stat_date FROM %i o INNER JOIN %i s ON s.id=o.submission_id WHERE o.id>%d ORDER BY o.id ASC LIMIT %d',
			Database::outcomes_table(),
			Database::submissions_table(),
			absint( $outcome_cursor ),
			max( 1, min( 50000, absint( $event_limit ) ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded append-only outcome cursor scan.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! $rows ) {
			return absint( $outcome_cursor );
		}
		$dates       = array();
		$next_cursor = absint( $outcome_cursor );
		foreach ( $rows as $row ) {
			$date = (string) $row['stat_date'];
			if ( ! isset( $dates[ $date ] ) && count( $dates ) >= max( 1, min( 31, absint( $day_limit ) ) ) ) {
				break;
			}
			$dates[ $date ] = true;
			$next_cursor    = absint( $row['id'] );
		}
		foreach ( array_keys( $dates ) as $date ) {
			if ( ! $this->rebuild_daily( $date, $date ) ) {
				return false;
			}
		}
		return $next_cursor;
	}

	private function variants( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE experiment_id=%d ORDER BY id ASC LIMIT 10', Database::variants_table(), absint( $experiment_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed lookup.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['config'] = json_decode( $row['mutation_config'], true ); }
		return $rows;
	}

	private function config_targets_field( $config, $key ) {
		if ( is_string( $config ) ) {
			return $config === $key; }
		if ( ! is_array( $config ) ) {
			return false; }
		foreach ( $config as $name => $value ) {
			if ( in_array( $name, array( 'field_order', 'fields', 'field_key' ), true ) && $this->config_targets_field( $value, $key ) ) {
				return true; }
			if ( is_array( $value ) && $this->config_targets_field( $value, $key ) ) {
				return true; }
		}
		return false;
	}

	private function field_intervention( $experiment_type, array $variant, $field_key ) {
		if ( $variant['mutation_type'] !== $experiment_type || empty( $variant['config']['mutations'] ) || ! is_array( $variant['config']['mutations'] ) ) {
			return null;
		}
		$candidate = end( $variant['config']['mutations'] );
		if ( ! is_array( $candidate ) || ! isset( $candidate['type'], $candidate['config'] ) || $candidate['type'] !== $experiment_type || ! $this->config_targets_field( $candidate['config'], $field_key ) ) {
			return null;
		}
		return array(
			'type'         => $experiment_type,
			'effect_scope' => 'current_field_presentation',
			'alternative'  => ExperimentType::FIELD_ORDER === $experiment_type ? 'move_later' : 'progressive_disclosure',
		);
	}

	private function experiment_traffic( $experiment_id, $control_id, $variant_id, $start, $end ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT variant_id,SUM(views) visitors,SUM(confirmed_successes) submissions FROM %i WHERE experiment_id=%d AND variant_id IN (%d,%d) AND stat_date BETWEEN %s AND %s GROUP BY variant_id', Database::experiment_daily_table(), absint( $experiment_id ), $control_id, $variant_id, $start, $end );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Indexed aggregate experiment query.
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$output = array();
		foreach ( $rows as $row ) {
			$output[ absint( $row['variant_id'] ) ] = array(
				'visitors'    => absint( $row['visitors'] ),
				'submissions' => absint( $row['submissions'] ),
			); }
		return $output;
	}

	private function experiment_balance_cells( $experiment_id, $control_id, $variant_id, $start, $end ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT stat_date,segment,variant_id,SUM(views) visitors FROM %i WHERE experiment_id=%d AND variant_id IN (%d,%d) AND stat_date BETWEEN %s AND %s GROUP BY stat_date,segment,variant_id', Database::experiment_daily_table(), absint( $experiment_id ), $control_id, $variant_id, $start, $end );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded day/device balance diagnostic protects experimental inference from assignment leakage.
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$cells = array();
		foreach ( $rows as $row ) {
			foreach ( array( $row['stat_date'] . '|' . $row['segment'], 'segment|' . $row['segment'] ) as $cell_key ) {
				if ( ! isset( $cells[ $cell_key ] ) ) {
					$cells[ $cell_key ] = array(
						'control' => 0,
						'variant' => 0,
					);
				}
				$arm                         = absint( $row['variant_id'] ) === absint( $variant_id ) ? 'variant' : 'control';
				$cells[ $cell_key ][ $arm ] += absint( $row['visitors'] );
			}
		}
		return array_values( $cells );
	}

	private function experiment_outcomes( $experiment_id, $control_id, $variant_id, $start, $end, $currency ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT s.variant_id,s.id,s.status,MAX(o.outcome_type='qualified') qualified,MAX(s.status='won') won,MAX(o.outcome_type IN ('qualified','unqualified','won','lost','spam','duplicate')) known,SUM(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.currency=%s THEN COALESCE(o.value_minor,0) ELSE 0 END) revenue_minor,MAX(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.currency=%s AND o.value_minor IS NOT NULL THEN 1 ELSE 0 END) has_revenue FROM %i s LEFT JOIN %i o ON o.submission_id=s.id WHERE s.experiment_id=%d AND s.variant_id IN (%d,%d) AND s.stat_date BETWEEN %s AND %s AND s.mature_after_utc<=%s GROUP BY s.variant_id,s.id,s.status",
			$currency,
			$currency,
			Database::submissions_table(),
			Database::outcomes_table(),
			absint( $experiment_id ),
			$control_id,
			$variant_id,
			$start,
			$end,
			current_time( 'mysql', true )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared indexed mature-cohort outcome query.
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$output = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['variant_id'] );
			if ( ! isset( $output[ $id ] ) ) {
					$output[ $id ] = array(
						'qualified'       => 0,
						'won'             => 0,
						'known'           => 0,
						'revenue_samples' => 0,
						'revenue_values'  => array(),
					); }
			$excluded                    = in_array( $row['status'], array( OutcomeStatus::SPAM, OutcomeStatus::DUPLICATE ), true );
			$output[ $id ]['qualified'] += $excluded ? 0 : absint( $row['qualified'] );
			$output[ $id ]['won']       += $excluded ? 0 : absint( $row['won'] );
			$output[ $id ]['known']     += absint( $row['known'] );
			if ( ! $excluded && absint( $row['has_revenue'] ) ) {
				++$output[ $id ]['revenue_samples'];
				$output[ $id ]['revenue_values'][] = (int) $row['revenue_minor']; }
		}
		return $output;
	}

	private function maturing_counts( $experiment_id, $control_id, $variant_id, $start, $end ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT variant_id,COUNT(*) count FROM %i WHERE experiment_id=%d AND variant_id IN (%d,%d) AND stat_date BETWEEN %s AND %s AND mature_after_utc>%s GROUP BY variant_id', Database::submissions_table(), absint( $experiment_id ), $control_id, $variant_id, $start, $end, current_time( 'mysql', true ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Indexed maturing-cohort diagnostic.
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$output = array();
		foreach ( $rows as $row ) {
			$output[ absint( $row['variant_id'] ) ] = absint( $row['count'] );
		} return $output;
	}
}
