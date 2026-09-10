<?php

namespace Formhawk\Outcomes;

use Formhawk\Infrastructure\Database;
use Formhawk\Support\Sanitizer;

final class OutcomeRepository {
	private $duplicate = false;

	public function create_submission( array $submission, array $fields ) {
		global $wpdb;
		$public_id = (string) $submission['public_id'];
		if ( ! preg_match( '/^fh_[A-Za-z0-9_-]{22,43}$/', $public_id ) ) {
			return new \WP_Error( 'formhawk_invalid_submission_id', __( 'Submission attribution requires a valid opaque ID.', 'formhawk' ) );
		}
		$now             = current_time( 'mysql', true );
		$window          = $this->attribution_window();
		$settings        = get_option( 'formhawk_field_roi_settings', array() );
		$configured_days = is_array( $settings ) && isset( $settings['maturity_days'] ) ? absint( $settings['maturity_days'] ) : 14;
		$maturity_days   = max( 1, min( $window, absint( apply_filters( 'formhawk_field_roi_maturity_days', $configured_days ) ) ) );
		$form_version_id = $this->register_schema( absint( $submission['form_id'] ), $fields );

		$submitted_time = new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) );
		$data           = array(
			'public_id'                  => $public_id,
			'form_id'                    => absint( $submission['form_id'] ),
			'placement_id'               => absint( $submission['placement_id'] ),
			'provider'                   => Sanitizer::provider( $submission['provider'] ),
			'provider_form_id'           => Sanitizer::identifier( $submission['provider_form_id'] ),
			'provider_entry_id'          => empty( $submission['provider_entry_id'] ) ? null : Sanitizer::identifier( $submission['provider_entry_id'], '' ),
			'form_version_id'            => $form_version_id ? $form_version_id : null,
			'experiment_id'              => empty( $submission['experiment_id'] ) ? null : absint( $submission['experiment_id'] ),
			'variant_id'                 => empty( $submission['variant_id'] ) ? null : absint( $submission['variant_id'] ),
			'minimum_form_baseline_id'   => empty( $submission['minimum_form_baseline_id'] ) ? null : absint( $submission['minimum_form_baseline_id'] ),
			'device_class'               => in_array( $submission['device_class'], array( 'desktop', 'mobile', 'tablet' ), true ) ? $submission['device_class'] : 'unknown',
			'status'                     => OutcomeStatus::SUBMITTED,
			'submitted_at_utc'           => $now,
			'stat_date'                  => current_time( 'Y-m-d' ),
			'mature_after_utc'           => $submitted_time->modify( '+' . $maturity_days . ' days' )->format( 'Y-m-d H:i:s' ),
			'attribution_expires_at_utc' => $submitted_time->modify( '+' . $window . ' days' )->format( 'Y-m-d H:i:s' ),
		);
		$formats        = array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Attribution, its structural fields and initial state publish atomically.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique public_id makes provider retries idempotent.
		$inserted = $wpdb->insert( Database::submissions_table(), $data, $formats );
		if ( false === $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- End a duplicate or failed attribution attempt.
			$wpdb->query( 'ROLLBACK' );
			$existing = $this->submission( $public_id );
			if ( $existing && ! empty( $existing['provider_entry_id'] ) && ! empty( $data['provider_entry_id'] ) && (string) $existing['provider_entry_id'] !== (string) $data['provider_entry_id'] ) {
				// Different provider entries cannot be a retry of the same lead. Keep
				// the original immutable and retain only structural conflict evidence.
				$this->audit(
					'submission_conflict',
					'submission',
					$public_id,
					array(
						'provider' => $data['provider'],
						'form_id'  => $data['form_id'],
						'reason'   => 'provider_entry_mismatch',
					)
				);
				return new \WP_Error( 'formhawk_submission_conflict', __( 'The submission ID was already used for a different provider entry.', 'formhawk' ), array( 'status' => 409 ) );
			}
			return $existing ? $existing : new \WP_Error( 'formhawk_submission_storage', __( 'Submission attribution could not be stored.', 'formhawk' ) );
		}
		$submission_id = (int) $wpdb->insert_id;
		if ( ! $this->attach_fields( $submission_id, absint( $submission['form_id'] ), $fields ) || ! $this->insert_initial_outcome( $submission_id, $public_id, $now ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Never leave a partially attributable submission.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'formhawk_submission_storage', __( 'Submission attribution could not be stored.', 'formhawk' ) );
		}
		$this->audit(
			'submission_attributed',
			'submission',
			$public_id,
			array(
				'provider' => $data['provider'],
				'form_id'  => $data['form_id'],
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publish the complete attribution unit.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Best-effort rollback if commit itself failed.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'formhawk_submission_storage', __( 'Submission attribution could not be stored.', 'formhawk' ) );
		}
		return $this->submission( $public_id );
	}

	public function record( array $outcome ) {
		global $wpdb;
		$this->duplicate = false;
		$submission      = $this->submission( $outcome['submission_id'] );
		if ( ! $submission ) {
			return new \WP_Error( 'formhawk_submission_not_found', __( 'Submission not found.', 'formhawk' ), array( 'status' => 404 ) );
		}
		if ( strtotime( $submission['attribution_expires_at_utc'] . ' UTC' ) < time() ) {
			return new \WP_Error( 'formhawk_attribution_expired', __( 'The attribution window for this submission has expired.', 'formhawk' ), array( 'status' => 410 ) );
		}
		if ( null !== $outcome['value_minor'] ) {
			$currency_sql = $wpdb->prepare( 'SELECT DISTINCT currency FROM %i WHERE submission_id=%d AND currency IS NOT NULL LIMIT 2', Database::outcomes_table(), absint( $submission['id'] ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Currency-consistency guard for one attributed submission.
			$currencies = $wpdb->get_col( $currency_sql );
			if ( $currencies && ! in_array( $outcome['currency'], $currencies, true ) ) {
				return new \WP_Error( 'formhawk_currency_conflict', __( 'A submission cannot mix currencies. Send a separate conversion configuration instead of implicit FX.', 'formhawk' ), array( 'status' => 409 ) );
			}
		}
		$duplicate_sql = $wpdb->prepare( 'SELECT id,submission_id,outcome_type,value_minor,currency,external_reference_hash FROM %i WHERE idempotency_hash=%s LIMIT 1', Database::outcomes_table(), $outcome['idempotency_hash'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Global replay guard survives API-key rotation.
		$duplicate = $wpdb->get_row( $duplicate_sql, ARRAY_A );
		if ( $duplicate ) {
			if ( ! $this->same_outcome_payload( $duplicate, $outcome, $submission['id'] ) ) {
				return new \WP_Error( 'formhawk_idempotency_conflict', __( 'The idempotency key was already used for a different outcome payload.', 'formhawk' ), array( 'status' => 409 ) );
			}
			$this->duplicate = true;
			return $submission;
		}
		if ( OutcomeStatus::WON === $outcome['status'] && null !== $outcome['value_minor'] ) {
			$won_sql = $wpdb->prepare( 'SELECT id,value_minor,currency,external_reference_hash FROM %i WHERE submission_id=%d AND outcome_type=%s ORDER BY id ASC LIMIT 1', Database::outcomes_table(), absint( $submission['id'] ), OutcomeStatus::WON );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prevents repeated WON callbacks from duplicating revenue.
			$won = $wpdb->get_row( $won_sql, ARRAY_A );
			if ( $won ) {
				if ( (string) $won['value_minor'] === (string) $outcome['value_minor'] && (string) $won['currency'] === (string) $outcome['currency'] ) {
					$this->duplicate = true;
					return $submission;
				}
				return new \WP_Error( 'formhawk_revenue_correction_required', __( 'A WON value already exists. Record only the difference as value_adjustment.', 'formhawk' ), array( 'status' => 409 ) );
			}
		}

		$data = array(
			'submission_id'           => absint( $submission['id'] ),
			'outcome_type'            => $outcome['status'],
			'value_minor'             => $outcome['value_minor'],
			'currency'                => $outcome['currency'] ? $outcome['currency'] : null,
			'source'                  => $outcome['source'],
			'external_reference_hash' => $outcome['external_reference_hash'],
			'idempotency_hash'        => $outcome['idempotency_hash'],
			'terminal_value_key'      => OutcomeStatus::WON === $outcome['status'] && null !== $outcome['value_minor'] ? hash( 'sha256', 'won|' . absint( $submission['id'] ) ) : null,
			'occurred_at_utc'         => $outcome['occurred_at_utc'],
			'created_at_utc'          => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only outcome journal with a unique idempotency key.
		$inserted = $wpdb->insert( Database::outcomes_table(), $data, array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) {
			$sql = $wpdb->prepare( 'SELECT id,submission_id,outcome_type,value_minor,currency,external_reference_hash FROM %i WHERE idempotency_hash = %s', Database::outcomes_table(), $outcome['idempotency_hash'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared idempotency lookup.
			$replay = $wpdb->get_row( $sql, ARRAY_A );
			if ( $replay ) {
				if ( $this->same_outcome_payload( $replay, $outcome, $submission['id'] ) ) {
					$this->duplicate = true;
					return $submission;
				}
				return new \WP_Error( 'formhawk_idempotency_conflict', __( 'The idempotency key was already used for a different outcome payload.', 'formhawk' ), array( 'status' => 409 ) );
			}
			if ( OutcomeStatus::WON === $outcome['status'] && null !== $outcome['value_minor'] ) {
				$won_sql = $wpdb->prepare( 'SELECT value_minor,currency FROM %i WHERE terminal_value_key=%s LIMIT 1', Database::outcomes_table(), $data['terminal_value_key'] );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The unique terminal key closes the concurrent duplicate-WON race.
				$won = $wpdb->get_row( $won_sql, ARRAY_A );
				if ( $won && (string) $won['value_minor'] === (string) $outcome['value_minor'] && (string) $won['currency'] === (string) $outcome['currency'] ) {
					$this->duplicate = true;
					return $submission;
				}
				if ( $won ) {
					return new \WP_Error( 'formhawk_revenue_correction_required', __( 'A WON value already exists. Record only the difference as value_adjustment.', 'formhawk' ), array( 'status' => 409 ) );
				}
			}
			return new \WP_Error( 'formhawk_outcome_storage', __( 'Outcome could not be stored.', 'formhawk' ), array( 'status' => 503 ) );
		}

		if ( OutcomeStatus::is_state( $outcome['status'] ) ) {
			$this->refresh_current_status( absint( $submission['id'] ) );
		}
		update_option( 'formhawk_field_roi_dirty', current_time( 'mysql', true ), false );
		$this->audit(
			'outcome_recorded',
			'submission',
			$submission['public_id'],
			array(
				'status'      => $outcome['status'],
				'value_minor' => $outcome['value_minor'],
				'currency'    => $outcome['currency'],
				'source'      => $outcome['source'],
			)
		);
		return $this->submission( $outcome['submission_id'] );
	}

	public function was_duplicate() {
		return $this->duplicate; }

	public function submission( $public_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', Database::submissions_table(), $public_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared lookup by opaque public identifier.
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	public function recent_submissions( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$sql   = $wpdb->prepare( 'SELECT s.public_id, s.status, s.submitted_at_utc, s.provider, f.title FROM %i s INNER JOIN %i f ON f.id = s.form_id ORDER BY s.id DESC LIMIT %d', Database::submissions_table(), Database::forms_table(), $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded admin list with no field values.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private function refresh_current_status( $submission_id ) {
		global $wpdb;
		$projected_states = array_values( array_diff( OutcomeStatus::states(), array( OutcomeStatus::SUBMITTED ) ) );
		$placeholders     = implode( ',', array_fill( 0, count( $projected_states ), '%s' ) );
		$args             = array_merge( array( Database::outcomes_table(), $submission_id ), $projected_states );
		$query            = "SELECT outcome_type FROM %i WHERE submission_id = %d AND outcome_type IN ({$placeholders}) ORDER BY occurred_at_utc DESC, id DESC LIMIT 1";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list length is fixed by the internal canonical status allowlist.
		$sql = $wpdb->prepare( $query, $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared latest-state projection from append-only history.
		$status = $wpdb->get_var( $sql );
		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Projection update; history remains immutable.
			$wpdb->update( Database::submissions_table(), array( 'status' => $status ), array( 'id' => $submission_id ), array( '%s' ), array( '%d' ) );
		}
	}

	private function insert_initial_outcome( $submission_id, $public_id, $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Initial append-only state, keyed deterministically to the submission.
		return false !== $wpdb->insert(
			Database::outcomes_table(),
			array(
				'submission_id'    => $submission_id,
				'outcome_type'     => OutcomeStatus::SUBMITTED,
				'source'           => 'provider',
				'idempotency_hash' => hash( 'sha256', 'submission|' . $public_id ),
				'occurred_at_utc'  => $now,
				'created_at_utc'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function attach_fields( $submission_id, $form_id, array $fields ) {
		global $wpdb;
		foreach ( array_slice( $this->normalize_fields( $fields ), 0, 50 ) as $field ) {
			$field_id = $this->upsert_field( $form_id, $field );
			if ( ! $field_id ) {
				continue; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structural submission-field link; no field value is stored.
			$stored = $wpdb->replace(
				Database::submission_fields_table(),
				array(
					'submission_id'          => $submission_id,
					'field_definition_id'    => $field_id,
					'was_present'            => 1,
					'was_required'           => $field['required'],
					'had_validation_failure' => 0,
					'correction_count'       => 0,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%d' )
			);
			if ( false === $stored ) {
				return false;
			}
		}
		return true;
	}

	private function upsert_field( $form_id, array $field ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			'INSERT INTO %i (form_id, provider_field_id, normalized_key, label, field_type, required, position, first_seen_utc, last_seen_utc) VALUES (%d,%s,%s,%s,%s,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), normalized_key = VALUES(normalized_key), label = IF(VALUES(label) <> \'\', VALUES(label), label), field_type = IF(VALUES(field_type) <> \'\', VALUES(field_type), field_type), required = VALUES(required), position = VALUES(position), last_seen_utc = VALUES(last_seen_utc)',
			Database::field_definitions_table(),
			$form_id,
			$field['key'],
			$field['key'],
			$field['label'],
			$field['type'],
			$field['required'],
			$field['position'],
			$now,
			$now
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared stable structural field upsert.
		$result = $wpdb->query( $sql );
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	private function register_schema( $form_id, array $fields ) {
		global $wpdb;
		$schema = array();
		foreach ( $this->normalize_fields( $fields ) as $field ) {
			$schema[] = array(
				'id'         => $field['key'],
				'type'       => $field['type'],
				'required'   => (bool) $field['required'],
				'position'   => $field['position'],
				'label_hash' => hash( 'sha256', strtolower( $field['label'] ) ),
			);
		}
		$json        = wp_json_encode( $schema );
		$fingerprint = hash( 'sha256', (string) $json );
		$sql         = $wpdb->prepare( 'INSERT INTO %i (form_id, fingerprint, schema_json, created_at_utc) VALUES (%d,%s,%s,%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', Database::form_versions_table(), $form_id, $fingerprint, $json, current_time( 'mysql', true ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immutable structural snapshot upsert.
		$result = $wpdb->query( $sql );
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	private function normalize_fields( array $fields ) {
		$normalized = array();
		foreach ( array_slice( $fields, 0, 50 ) as $position => $field ) {
			if ( ! is_array( $field ) ) {
				continue; }
			$key = Sanitizer::identifier( isset( $field['key'] ) ? $field['key'] : '', '' );
			if ( '' === $key || isset( $normalized[ $key ] ) ) {
				continue; }
			$normalized[ $key ] = array(
				'key'      => $key,
				'label'    => Sanitizer::field_label( isset( $field['label'] ) ? $field['label'] : $key ),
				'type'     => Sanitizer::field_type( isset( $field['type'] ) ? $field['type'] : '' ),
				'required' => empty( $field['required'] ) ? 0 : 1,
				'position' => isset( $field['position'] ) ? min( 1000, absint( $field['position'] ) ) : $position,
			);
		}
		return array_values( $normalized );
	}

	private function attribution_window() {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		$days     = is_array( $settings ) && isset( $settings['attribution_window'] ) ? absint( $settings['attribution_window'] ) : 90;
		$days     = in_array( $days, array( 30, 60, 90, 180 ), true ) ? $days : 90;
		return max( 30, min( 180, absint( apply_filters( 'formhawk_field_roi_attribution_window', $days ) ) ) );
	}

	private function same_outcome_payload( array $stored, array $outcome, $submission_id ) {
		return absint( $stored['submission_id'] ) === absint( $submission_id )
			&& $stored['outcome_type'] === $outcome['status']
			&& (string) $stored['value_minor'] === (string) $outcome['value_minor']
			&& (string) $stored['currency'] === (string) $outcome['currency']
			&& (string) $stored['external_reference_hash'] === (string) $outcome['external_reference_hash'];
	}

	private function audit( $event, $object_type, $object_id, array $details ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Structural audit record; callers pass only allowlisted non-PII details.
		$wpdb->insert(
			Database::business_audit_table(),
			array(
				'event_type'     => $event,
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'details_json'   => wp_json_encode( $details ),
				'created_at_utc' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
