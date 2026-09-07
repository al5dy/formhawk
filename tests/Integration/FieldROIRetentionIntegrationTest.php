<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\Infrastructure\Cleanup;
use Formhawk\Infrastructure\Database;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class FieldROIRetentionIntegrationTest extends IsolatedStorageTestCase {
	public function test_expired_linkage_is_deleted_after_aggregates_and_history_survive() {
		global $wpdb;
		$identity   = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'cf7',
				'provider_form_id' => '901',
				'title'            => 'Retention',
				'page_path'        => '/retention',
			)
		);
		$submission = ( new OutcomeRepository() )->create_submission(
			array(
				'public_id'         => 'fh_RRRRRRRRRRRRRRRRRRRRRR',
				'form_id'           => $identity['form_id'],
				'placement_id'      => $identity['placement_id'],
				'provider'          => 'cf7',
				'provider_form_id'  => '901',
				'provider_entry_id' => '',
				'experiment_id'     => 0,
				'variant_id'        => 0,
				'device_class'      => 'unknown',
			),
			array(
				array(
					'key'      => 'email',
					'label'    => 'Email',
					'type'     => 'email',
					'required' => 1,
					'position' => 1,
				),
			)
		);
		$field_id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT field_definition_id FROM %i WHERE submission_id=%d', Database::submission_fields_table(), $submission['id'] ) );
		$wpdb->insert(
			Database::field_roi_history_table(),
			array(
				'field_definition_id' => $field_id,
				'result_hash'         => hash( 'sha256', 'retained' ),
				'before_metrics_json' => '{}',
				'after_metrics_json'  => '{}',
				'decision'            => 'keep',
				'confidence'          => 'high',
				'evidence_level'      => 'experimental',
				'currency'            => 'USD',
				'model_version'       => 'test',
				'created_at_utc'      => current_time( 'mysql', true ),
			)
		);
		$wpdb->update( Database::submissions_table(), array( 'attribution_expires_at_utc' => '2020-01-01 00:00:00' ), array( 'id' => $submission['id'] ), array( '%s' ), array( '%d' ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type=%s AND object_id=%s', Database::business_audit_table(), 'submission', $submission['public_id'] ) ) );

		$method = new \ReflectionMethod( Cleanup::class, 'delete_expired_linkage' );
		$method->setAccessible( true );
		update_option( 'formhawk_field_roi_outcome_cursor', 0, false );
		$this->assertSame( 0, $method->invoke( new Cleanup() ), 'Unaggregated outcome rows must block linkage deletion.' );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ) );
		update_option( 'formhawk_field_roi_outcome_cursor', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Database::outcomes_table() ) ), false );
		$this->assertSame( 1, $method->invoke( new Cleanup() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::outcomes_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submission_fields_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type=%s AND object_id=%s', Database::business_audit_table(), 'submission', $submission['public_id'] ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::field_roi_history_table() ) ) );
	}
}
