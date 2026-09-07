<?php

namespace Formhawk\Outcomes;

use Formhawk\Infrastructure\Database;

final class ApiKeyRepository {
	public function create( $name, $user_id ) {
		global $wpdb;
		try {
			$key_id = substr( bin2hex( random_bytes( 12 ) ), 0, 24 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of cryptographically random API-key bytes, not executable code.
			$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'formhawk_key_random', __( 'A secure API key could not be generated.', 'formhawk' ) );
		}
		$plain = 'fhk_' . $key_id . '.' . $secret;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Formhawk API keys use a dedicated custom table and cannot be stored through a WordPress metadata API.
		$result = $wpdb->insert(
			Database::outcome_api_keys_table(),
			array(
				'key_id'         => $key_id,
				'name'           => sanitize_text_field( $name ),
				'secret_hash'    => wp_hash_password( $secret ),
				'capabilities'   => 'record_outcomes',
				'created_by'     => absint( $user_id ),
				'created_at_utc' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return false === $result ? new \WP_Error( 'formhawk_key_storage', __( 'The API key could not be created.', 'formhawk' ) ) : $plain;
	}

	public function authenticate( $authorization ) {
		global $wpdb;
		if ( ! preg_match( '/^Bearer\s+fhk_([a-f0-9]{24})\.([A-Za-z0-9_-]{43})$/i', trim( (string) $authorization ), $match ) ) {
			return null;
		}
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE key_id = %s AND revoked_at_utc IS NULL', Database::outcome_api_keys_table(), strtolower( $match[1] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared lookup of a Formhawk-owned credential record.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! $row || ! wp_check_password( $match[2], $row['secret_hash'] ) || false === strpos( ',' . $row['capabilities'] . ',', ',record_outcomes,' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit timestamp contains no visitor information.
		$wpdb->update( Database::outcome_api_keys_table(), array( 'last_used_at_utc' => current_time( 'mysql', true ) ), array( 'id' => absint( $row['id'] ) ), array( '%s' ), array( '%d' ) );
		return $row;
	}

	public function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded admin-only credential metadata; secret hashes are not selected.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, key_id, name, capabilities, created_at_utc, last_used_at_utc, revoked_at_utc FROM %i ORDER BY id DESC LIMIT 100', Database::outcome_api_keys_table() ), ARRAY_A );
	}

	public function revoke( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate revocation must bypass stale caches for this credential record.
		return false !== $wpdb->update( Database::outcome_api_keys_table(), array( 'revoked_at_utc' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ), array( '%s' ), array( '%d' ) );
	}
}
