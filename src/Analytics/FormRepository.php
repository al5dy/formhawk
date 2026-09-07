<?php

namespace Formhawk\Analytics;

use Formhawk\Domain\FormIdentity;
use Formhawk\Infrastructure\Database;
use Formhawk\Support\Sanitizer;

final class FormRepository {
	public function touch( array $data ) {
		$identity = $this->resolve( $data );
		return $identity['form_id'];
	}

	public function resolve( array $data ) {
		global $wpdb;

		$provider         = Sanitizer::provider( isset( $data['provider'] ) ? $data['provider'] : 'html' );
		$provider_form_id = Sanitizer::identifier( isset( $data['provider_form_id'] ) ? $data['provider_form_id'] : '' );
		$page_path        = Sanitizer::path( isset( $data['page_path'] ) ? $data['page_path'] : '/' );
		$title            = Sanitizer::title( isset( $data['title'] ) ? $data['title'] : '' );
		$form_key         = FormIdentity::key( $provider, $provider_form_id, $page_path );
		$now              = current_time( 'mysql' );
		$table            = Database::forms_table();

		$sql = $wpdb->prepare(
			'INSERT INTO %i
				(form_key, provider, provider_form_id, title, page_path, first_seen, last_seen)
			VALUES (%s, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				id = LAST_INSERT_ID(id),
				provider = VALUES(provider),
				provider_form_id = VALUES(provider_form_id),
				title = IF(VALUES(title) <> \'\', VALUES(title), title),
				page_path = IF(page_path = \'\' AND VALUES(page_path) <> \'\', VALUES(page_path), page_path),
				last_seen = VALUES(last_seen)',
			$table,
			$form_key,
			$provider,
			$provider_form_id,
			$title,
			$page_path,
			$now,
			$now
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic upsert into Formhawk's custom analytics table; object caching would make writes stale.
		$wpdb->query( $sql );
		$form_id      = (int) $wpdb->insert_id;
		$placement_id = $form_id > 0 ? $this->touch_placement( $form_id, $page_path, $now ) : 0;

		return array(
			'form_id'      => $form_id,
			'placement_id' => $placement_id,
		);
	}

	private function touch_placement( $form_id, $page_path, $now ) {
		global $wpdb;

		$table         = Database::placements_table();
		$placement_key = sha1( absint( $form_id ) . '|' . $page_path );
		$sql           = $wpdb->prepare(
			'INSERT INTO %i (form_id, placement_key, page_path, first_seen, last_seen)
			VALUES (%d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				id = LAST_INSERT_ID(id),
				last_seen = VALUES(last_seen)',
			$table,
			absint( $form_id ),
			$placement_key,
			$page_path,
			$now,
			$now
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic placement upsert into Formhawk's aggregate storage.
		$wpdb->query( $sql );

		return (int) $wpdb->insert_id;
	}

	public function mark_success( $form_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Writes to Formhawk's custom analytics table must be immediately visible.
		$wpdb->update(
			Database::forms_table(),
			array(
				'last_success_at'   => current_time( 'mysql' ),
				'last_failure_code' => '',
			),
			array( 'id' => absint( $form_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_failure( $form_id, $code, $is_mail = false ) {
		global $wpdb;
		$data    = array(
			'last_failure_at'   => current_time( 'mysql' ),
			'last_failure_code' => sanitize_key( (string) $code ),
		);
		$formats = array( '%s', '%s' );
		if ( $is_mail ) {
			$data['last_mail_failure_at'] = current_time( 'mysql' );
			$formats[]                    = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Writes to Formhawk's custom analytics table must be immediately visible.
		$wpdb->update(
			Database::forms_table(),
			$data,
			array( 'id' => absint( $form_id ) ),
			$formats,
			array( '%d' )
		);
	}

	public function mark_mail_success( $form_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to Formhawk's custom analytics table must be immediately visible.
		$wpdb->update(
			Database::forms_table(),
			array( 'last_mail_success_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $form_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function find( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Database::forms_table(),
			absint( $form_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Dashboard reads current custom analytics data; stale object-cache results are undesirable.
		return $wpdb->get_row( $sql, ARRAY_A );
	}
}
