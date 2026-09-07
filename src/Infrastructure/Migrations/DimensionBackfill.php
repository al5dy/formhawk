<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

/** Registers existing structure without charging the new-dimension daily allowance. */
final class DimensionBackfill {
	const BATCH_SIZE = 500;
	const CHECKPOINT = 'migration_v4_dimensions';

	public static function run() {
		global $wpdb;
		// Share the admission lock; never commit a transaction owned by a form provider.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialize restart-safe structural migration for this site only.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', Database::dimension_lock_name() ) ) ) {
			return false;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One fixed migration checkpoint, not a visitor identifier.
			$state = $wpdb->get_row( $wpdb->prepare( 'SELECT window_id, used FROM %i WHERE scope_key = %s', Database::budgets_table(), self::CHECKPOINT ), ARRAY_A );
			if ( $wpdb->last_error ) {
				return false;
			}
			$stage  = $state ? (int) $state['window_id'] : 0;
			$cursor = $state ? (int) $state['used'] : 0;
			// At most one bounded batch per stage per invocation. Large sites resume on the next request.
			while ( $stage < 4 ) {
				$rows = self::rows( $stage, $cursor );
				if ( self::storage_failed() ) {
					return false;
				}
				foreach ( $rows as $row ) {
					if ( ! self::register_row( $stage, $row ) ) {
						return false;
					}
					$cursor = (int) $row['id'];
				}
				$more = count( $rows ) === self::BATCH_SIZE;
				if ( ! $more ) {
					++$stage;
					$cursor = 0;
				}
				if ( ! self::write_slot( self::CHECKPOINT, $stage, $cursor ) || $more ) {
					return false;
				}
			}
			if ( 4 === $stage ) {
				// A single SQL aggregation over the structural registry, independent of event volume.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Four bounded aggregate results initialize lifetime caps, including pre-upgrade inventory.
				$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, COUNT(*) AS total FROM %i GROUP BY kind', Database::dimensions_table() ), ARRAY_A );
				if ( self::storage_failed() ) {
					return false;
				}
				foreach ( $counts as $count ) {
					if ( ! self::write_slot( hash( 'sha256', 'dimensions|' . $count['kind'] . '|total' ), 0, (int) $count['total'] ) ) {
						return false;
					}
				}
				return self::write_slot( self::CHECKPOINT, 5, 0 );
			}
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this connection's migration lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', Database::dimension_lock_name() ) );
		}
	}

	private static function rows( $stage, $cursor ) {
		global $wpdb;
		if ( 0 === $stage || 3 === $stage ) {
			$sql = $wpdb->prepare( 'SELECT id, form_key, page_path FROM %i WHERE id > %d ORDER BY id LIMIT %d', Database::forms_table(), $cursor, self::BATCH_SIZE );
		} elseif ( 1 === $stage ) {
			$sql = $wpdb->prepare( 'SELECT p.id, f.form_key, p.page_path FROM %i p LEFT JOIN %i f ON f.id = p.form_id WHERE p.id > %d ORDER BY p.id LIMIT %d', Database::placements_table(), Database::forms_table(), $cursor, self::BATCH_SIZE );
		} else {
			$sql = $wpdb->prepare( 'SELECT d.id, f.form_key, d.field_key FROM %i d LEFT JOIN %i f ON f.id = d.form_id WHERE d.id > %d ORDER BY d.id LIMIT %d', Database::fields_table(), Database::forms_table(), $cursor, self::BATCH_SIZE );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared primary-key cursor scans; memory does not grow with historical analytics.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private static function register_row( $stage, array $row ) {
		global $wpdb;
		if ( empty( $row['form_key'] ) ) {
			// Orphaned historical aggregates stay untouched; do not invent a form definition.
			return true;
		}
		$form = $row['form_key'];
		if ( 3 === $stage ) {
			// Existing form/date and form_id indexes serve these counts once per migrated form.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count static definitions, not validation attempts or visitor events.
			$fields = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT field_key) FROM %i WHERE form_id = %d', Database::fields_table(), $row['id'] ) );
			if ( $wpdb->last_error ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existing placement form_id index bounds the lookup to one form.
			$placements = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d', Database::placements_table(), $row['id'] ) );
			return ! self::storage_failed()
				&& self::write_slot( hash( 'sha256', 'form|' . $form . '|fields' ), 0, (int) $fields )
				&& self::write_slot( hash( 'sha256', 'form|' . $form . '|placements' ), 0, (int) $placements );
		}
		$dimensions = array();
		if ( 0 === $stage ) {
			$dimensions[ 'form|' . $form ] = 'forms';
		} elseif ( 1 === $stage ) {
			$dimensions[ 'placement|' . $form . '|' . $row['page_path'] ] = 'placements';
		} else {
			$dimensions[ 'field|' . $form . '|' . $row['field_key'] ] = 'fields';
		}
		if ( isset( $row['page_path'] ) && '' !== $row['page_path'] ) {
			$dimensions[ 'path|' . $row['page_path'] ] = 'paths';
		}
		foreach ( $dimensions as $key => $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Replay after interruption cannot duplicate a dimension or fabricate analytics.
			if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (dimension_key, kind) VALUES (%s, %s)', Database::dimensions_table(), hash( 'sha256', $key ), $kind ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function write_slot( $key, $window, $used ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic assignment makes checkpoint and lifetime initialization replay-safe; ingestion is paused until upgrade completion.
		return false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (scope_key, window_id, used) VALUES (%s, %d, %d) ON DUPLICATE KEY UPDATE window_id = VALUES(window_id), used = VALUES(used)', Database::budgets_table(), $key, $window, $used ) );
	}

	/** @phpstan-impure Each database operation replaces wpdb's error state. */
	private static function storage_failed() {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}
}
