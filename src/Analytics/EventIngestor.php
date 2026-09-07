<?php

namespace Formhawk\Analytics;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Infrastructure\Database;
use Formhawk\Support\Sanitizer;

final class EventIngestor implements EventRecorderInterface {
	private $forms;
	private $normalizer;
	private $identity_cache = array();

	public function __construct( FormRepository $forms, EventNormalizer $normalizer = null ) {
		$this->forms      = $forms;
		$this->normalizer = $normalizer ? $normalizer : new EventNormalizer();
	}

	public function ingest_client( array $event ) {
		$normalized = $this->normalizer->client( $event );
		if ( ! is_array( $normalized ) ) {
			return false;
		}

		return $this->ingest( $normalized, false );
	}

	public function record_success( $provider, $provider_form_id, $title, $page_path, array $context = array() ) {
		return $this->ingest_server(
			'form_success',
			array_merge(
				$context,
				$this->provider_event( $provider, $provider_form_id, $title, $page_path )
			)
		);
	}

	public function record_failure( $provider, $provider_form_id, $title, $page_path, $code ) {
		$event                 = $this->provider_event( $provider, $provider_form_id, $title, $page_path );
		$event['failure_code'] = $code;

		return $this->ingest_server( 'form_failure', $event );
	}

	public function record_validation_failure( $provider, $provider_form_id, $title, $page_path, array $fields = array() ) {
		$event           = $this->provider_event( $provider, $provider_form_id, $title, $page_path );
		$event['fields'] = $fields;

		return $this->ingest_server( 'validation_failure', $event );
	}

	public function record_mail_success( $provider, $provider_form_id, $title, $page_path ) {
		return $this->ingest_server( 'mail_success', $this->provider_event( $provider, $provider_form_id, $title, $page_path ) );
	}

	public function record_mail_failure( $provider, $provider_form_id, $title, $page_path ) {
		return $this->ingest_server( 'mail_failure', $this->provider_event( $provider, $provider_form_id, $title, $page_path ) );
	}

	/**
	 * Backward-compatible wrappers for code using the 0.1.x integration seam.
	 */
	public function cf7_success( $form_id, $title, $page_path ) {
		return $this->record_success( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path, array( 'mail_success' => true ) );
	}

	public function cf7_validation_error( $form_id, $title, $page_path, $field_key ) {
		return $this->record_validation_failure(
			ProviderCatalog::CF7,
			(string) absint( $form_id ),
			$title,
			$page_path,
			array(
				array(
					'key'   => $field_key,
					'label' => $field_key,
					'type'  => '',
				),
			)
		);
	}

	public function cf7_failure( $form_id, $title, $page_path, $code = 'aborted' ) {
		return $this->record_failure( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path, $code );
	}

	public function cf7_mail_failure( $form_id, $title, $page_path ) {
		return $this->record_mail_failure( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path );
	}

	private function ingest_server( $type, array $event ) {
		return $this->ingest( $this->normalizer->server( $type, $event ), true );
	}

	private function provider_event( $provider, $provider_form_id, $title, $page_path ) {
		return array(
			'provider'         => $provider,
			'provider_form_id' => $provider_form_id,
			'title'            => $title,
			'page_path'        => $page_path,
		);
	}

	private function ingest( array $event, $trusted ) {
		$type         = isset( $event['type'] ) ? $event['type'] : '';
		$identity_key = implode(
			'|',
			array(
				isset( $event['provider'] ) ? $event['provider'] : '',
				isset( $event['provider_form_id'] ) ? $event['provider_form_id'] : '',
				isset( $event['page_path'] ) ? $event['page_path'] : '/',
			)
		);
		if ( ! isset( $this->identity_cache[ $identity_key ] ) ) {
			$this->identity_cache[ $identity_key ] = $this->forms->resolve( $event );
		}
		$identity     = $this->identity_cache[ $identity_key ];
		$form_id      = $identity['form_id'];
		$placement_id = $identity['placement_id'];
		if ( ! $form_id ) {
			return false;
		}

		$duration = Sanitizer::duration_ms( isset( $event['duration_ms'] ) ? $event['duration_ms'] : 0 );
		$field    = isset( $event['field'] ) && is_array( $event['field'] ) ? $event['field'] : array();
		$fields   = isset( $event['fields'] ) && is_array( $event['fields'] ) ? $event['fields'] : array();

		switch ( $type ) {
			case 'form_view':
				$this->increment_aggregates( $form_id, $placement_id, array( 'views' => 1 ) );
				break;
			case 'form_start':
				$this->increment_aggregates( $form_id, $placement_id, array( 'starts' => 1 ) );
				break;
			case 'field_interaction':
				$this->increment_field( $form_id, $field, 'interactions' );
				break;
			case 'validation_error':
				$this->increment_field( $form_id, $field, 'validation_errors' );
				break;
			case 'validation_failure':
				$this->increment_aggregates( $form_id, $placement_id, array( 'validation_failures' => 1 ) );
				$this->increment_fields( $form_id, $fields, 'validation_errors' );
				break;
			case 'form_abandon':
				$increments = array( 'abandons' => 1 );
				if ( $duration > 0 ) {
					$increments['duration_total_ms'] = $duration;
					$increments['duration_samples']  = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				if ( ! empty( $field ) ) {
					$this->increment_field( $form_id, $field, 'abandonments' );
				}
				break;
			case 'form_submit':
				$increments = array( 'submit_attempts' => 1 );
				if ( ! ProviderCatalog::has_server_success( $event['provider'] ) ) {
					$increments['submissions'] = 1;
				}
				if ( $duration > 0 ) {
					$increments['duration_total_ms'] = $duration;
					$increments['duration_samples']  = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				break;
			case 'form_success':
				if ( ! $trusted ) {
					return false;
				}
				$increments = array(
					'submissions'         => 1,
					'confirmed_successes' => 1,
				);
				if ( ! empty( $event['mail_success'] ) ) {
					$increments['mail_successes'] = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				$this->forms->mark_success( $form_id );
				if ( ! empty( $event['mail_success'] ) ) {
					$this->forms->mark_mail_success( $form_id );
				}
				break;
			case 'form_failure':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates( $form_id, $placement_id, array( 'failures' => 1 ) );
				$this->forms->mark_failure( $form_id, isset( $event['failure_code'] ) ? $event['failure_code'] : 'form_failure', false );
				break;
			case 'mail_success':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates( $form_id, $placement_id, array( 'mail_successes' => 1 ) );
				$this->forms->mark_mail_success( $form_id );
				break;
			case 'mail_failure':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates(
					$form_id,
					$placement_id,
					array(
						'failures'      => 1,
						'mail_failures' => 1,
					)
				);
				$this->forms->mark_failure( $form_id, 'mail_failure', true );
				break;
			default:
				return false;
		}

		do_action( 'formhawk_event_recorded', $type, $form_id, $event );
		return true;
	}

	private function increment_aggregates( $form_id, $placement_id, array $increments ) {
		$this->increment_daily( Database::daily_table(), 'form_id', $form_id, $increments );
		if ( $placement_id > 0 ) {
			$this->increment_daily( Database::placement_daily_table(), 'placement_id', $placement_id, $increments );
		}
	}

	private function increment_daily( $table, $identity_column, $identity_id, array $increments ) {
		global $wpdb;

		$allowed    = array(
			'views',
			'starts',
			'submit_attempts',
			'submissions',
			'confirmed_successes',
			'abandons',
			'validation_failures',
			'failures',
			'mail_successes',
			'mail_failures',
			'duration_total_ms',
			'duration_samples',
		);
		$increments = array_intersect_key( $increments, array_flip( $allowed ) );
		if ( empty( $increments ) ) {
			return;
		}

		$counters = array_fill_keys( $allowed, 0 );
		foreach ( $increments as $column => $value ) {
			$counters[ $column ] = absint( $value );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter upsert into Formhawk's custom analytics table.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (
					%i, stat_date, views, starts, submit_attempts, submissions,
					confirmed_successes, abandons, validation_failures, failures,
					mail_successes, mail_failures, duration_total_ms, duration_samples
				) VALUES ( %d, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d )
				ON DUPLICATE KEY UPDATE
					views = views + VALUES(views),
					starts = starts + VALUES(starts),
					submit_attempts = submit_attempts + VALUES(submit_attempts),
					submissions = submissions + VALUES(submissions),
					confirmed_successes = confirmed_successes + VALUES(confirmed_successes),
					abandons = abandons + VALUES(abandons),
					validation_failures = validation_failures + VALUES(validation_failures),
					failures = failures + VALUES(failures),
					mail_successes = mail_successes + VALUES(mail_successes),
					mail_failures = mail_failures + VALUES(mail_failures),
					duration_total_ms = duration_total_ms + VALUES(duration_total_ms),
					duration_samples = duration_samples + VALUES(duration_samples)',
				$table,
				$identity_column,
				absint( $identity_id ),
				current_time( 'Y-m-d' ),
				$counters['views'],
				$counters['starts'],
				$counters['submit_attempts'],
				$counters['submissions'],
				$counters['confirmed_successes'],
				$counters['abandons'],
				$counters['validation_failures'],
				$counters['failures'],
				$counters['mail_successes'],
				$counters['mail_failures'],
				$counters['duration_total_ms'],
				$counters['duration_samples']
			)
		);
	}

	private function increment_field( $form_id, array $field, $column ) {
		$this->increment_fields( $form_id, array( $field ), $column );
	}

	private function increment_fields( $form_id, array $fields, $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'interactions', 'abandonments', 'validation_errors' ), true ) || empty( $fields ) ) {
			return;
		}

		$values = array();
		$args   = array( Database::fields_table(), $column );
		$date   = current_time( 'Y-m-d' );
		foreach ( array_slice( $fields, 0, 50 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key      = Sanitizer::identifier( isset( $field['key'] ) ? $field['key'] : '', 'unknown' );
			$label    = Sanitizer::field_label( isset( $field['label'] ) ? $field['label'] : $key );
			$type     = Sanitizer::field_type( isset( $field['type'] ) ? $field['type'] : '' );
			$values[] = '(%d, %s, %s, %s, %s, 1)';
			array_push( $args, absint( $form_id ), $date, $key, $label, $type );
		}
		if ( empty( $values ) ) {
			return;
		}
		$args[] = $column;
		$args[] = $column;

		$query = 'INSERT INTO %i (form_id, stat_date, field_key, field_label, field_type, %i) VALUES '
			. implode( ', ', $values )
			. ' ON DUPLICATE KEY UPDATE
				field_label = IF(
					field_label = \'\' OR (field_label = field_key AND VALUES(field_label) <> VALUES(field_key)),
					VALUES(field_label),
					field_label
				),
				field_type = IF(VALUES(field_type) <> \'\', VALUES(field_type), field_type),
				%i = %i + 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragment is a bounded list of fixed placeholder groups; values remain prepared below.
		$sql = $wpdb->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic counter upsert; the table/column identifiers are allowlisted and all field values were prepared above.
		$wpdb->query( $sql );
	}
}
