<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\Analytics\EventIngestor;
use Formhawk\CRO\Attribution\AttributingEventRecorder;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\Database;
use Formhawk\Integrations\WPForms;
use Formhawk\Outcomes\OutcomeAttribution;
use Formhawk\Outcomes\OutcomeManager;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Outcomes\SubmissionContext;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class OutcomeAttributionIntegrationTest extends IsolatedStorageTestCase {
	private function submission( $suffix = 'AAAAAAAAAAAAAAAAAAAAAA', $provider_entry_id = '991' ) {
		$identity = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'wpforms',
				'provider_form_id' => '55',
				'title'            => 'Quote',
				'page_path'        => '/quote',
			)
		);
		return ( new OutcomeRepository() )->create_submission(
			array(
				'public_id'         => 'fh_' . $suffix,
				'form_id'           => $identity['form_id'],
				'placement_id'      => $identity['placement_id'],
				'provider'          => 'wpforms',
				'provider_form_id'  => '55',
				'provider_entry_id' => $provider_entry_id,
				'experiment_id'     => 0,
				'variant_id'        => 0,
				'device_class'      => 'mobile',
			),
			array(
				array(
					'key'      => 'phone',
					'label'    => 'Phone',
					'type'     => 'tel',
					'required' => 1,
					'position' => 2,
				),
				array(
					'key'      => 'company',
					'label'    => 'Company',
					'type'     => 'text',
					'required' => 0,
					'position' => 3,
				),
			)
		);
	}

	public function test_provider_retries_are_idempotent_but_independent_ids_keep_distinct_entries() {
		global $wpdb;
		$public_id_a = OutcomeManager::generate_submission_id();
		$public_id_b = OutcomeManager::generate_submission_id();
		$this->assertNotSame( $public_id_a, $public_id_b );
		$first = $this->submission( substr( $public_id_a, 3 ), '991' );
		$this->assertIsArray( $first );
		$previous = $wpdb->suppress_errors();
		try {
			$retry = $this->submission( substr( $public_id_a, 3 ), '991' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( $first, $retry );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ) );
		$second = $this->submission( substr( $public_id_b, 3 ), '992' );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertSame( array( '991', '992' ), $wpdb->get_col( $wpdb->prepare( 'SELECT provider_entry_id FROM %i ORDER BY id', Database::submissions_table() ) ) );
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ) );
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE outcome_type=%s', Database::outcomes_table(), 'submitted' ) ) );
		$this->assertSame( '4', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submission_fields_table() ) ) );
	}

	public function test_reused_public_id_with_a_different_nonempty_provider_entry_is_a_conflict() {
		global $wpdb;
		$first    = $this->submission();
		$previous = $wpdb->suppress_errors();
		try {
			$conflict = $this->submission( 'AAAAAAAAAAAAAAAAAAAAAA', '992' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->assertInstanceOf( '\WP_Error', $conflict );
		$this->assertSame( 'formhawk_submission_conflict', $conflict->get_error_code() );
		$this->assertSame( 409, $conflict->get_error_data()['status'] );
		$this->assertSame( $first, ( new OutcomeRepository() )->submission( $first['public_id'] ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ) );
		$details = $wpdb->get_var( $wpdb->prepare( 'SELECT details_json FROM %i WHERE event_type=%s', Database::business_audit_table(), 'submission_conflict' ) );
		$this->assertSame(
			array(
				'provider' => 'wpforms',
				'form_id'  => (int) $first['form_id'],
				'reason'   => 'provider_entry_mismatch',
			),
			json_decode( $details, true )
		);
	}

	public function test_missing_provider_entry_ids_remain_valid_idempotent_retries() {
		global $wpdb;
		$first    = $this->submission( 'AAAAAAAAAAAAAAAAAAAAAA', '' );
		$previous = $wpdb->suppress_errors();
		try {
			$retry = $this->submission( 'AAAAAAAAAAAAAAAAAAAAAA', '' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->assertIsArray( $first );
		$this->assertSame( $first, $retry );
	}

	public function test_append_only_transitions_are_idempotent_and_revenue_adjustments_do_not_duplicate() {
		global $wpdb;
		$submission = $this->submission();
		$this->assertIsArray( $submission );
		$manager   = new OutcomeManager();
		$qualified = array(
			'submission_id'   => $submission['public_id'],
			'status'          => 'qualified',
			'idempotency_key' => 'crm-qualified',
			'occurred_at'     => '2026-09-01T10:00:00Z',
		);
		$won       = array(
			'submission_id'   => $submission['public_id'],
			'status'          => 'won',
			'value_minor'     => 100000,
			'currency'        => 'USD',
			'idempotency_key' => 'crm-won',
			'occurred_at'     => '2026-09-02T10:00:00Z',
		);
		$this->assertFalse( $manager->record( $qualified, 'api_key_1' )['duplicate'] );
		$this->assertFalse( $manager->record( $won, 'api_key_1' )['duplicate'] );
		$this->assertTrue( $manager->record( $won, 'api_key_1' )['duplicate'] );
		$idempotency_conflict = $manager->record( array_merge( $won, array( 'value_minor' => 200000 ) ), 'api_key_1' );
		$this->assertInstanceOf( '\WP_Error', $idempotency_conflict );
		$this->assertSame( 'formhawk_idempotency_conflict', $idempotency_conflict->get_error_code() );
		$this->assertTrue( $manager->record( array_merge( $won, array( 'idempotency_key' => 'crm-retry-different' ) ), 'api_key_2' )['duplicate'] );
		$adjustment = array(
			'submission_id'   => $submission['public_id'],
			'status'          => 'value_adjustment',
			'value_minor'     => 20000,
			'currency'        => 'USD',
			'idempotency_key' => 'crm-adjustment',
			'occurred_at'     => '2026-09-03T10:00:00Z',
		);
		$this->assertFalse( $manager->record( $adjustment, 'api_key_1' )['duplicate'] );
		$refund = array(
			'submission_id'   => $submission['public_id'],
			'status'          => 'value_adjustment',
			'value_minor'     => -20000,
			'currency'        => 'USD',
			'idempotency_key' => 'crm-refund',
			'occurred_at'     => '2026-09-04T10:00:00Z',
		);
		$this->assertFalse( $manager->record( $refund, 'api_key_1' )['duplicate'] );
		$this->assertTrue( $manager->record( $refund, 'api_key_2' )['duplicate'] );
		$this->assertInstanceOf(
			'\WP_Error',
			$manager->record(
				array_merge(
					$won,
					array(
						'value_minor'     => 120000,
						'idempotency_key' => 'wrong-correction',
					)
				),
				'api_key_1'
			)
		);
		$this->assertSame( 'won', ( new OutcomeRepository() )->submission( $submission['public_id'] )['status'] );
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(value_minor) FROM %i WHERE submission_id=%d AND outcome_type IN ('won','value_adjustment')", Database::outcomes_table(), $submission['id'] ) );
		$this->assertSame( '100000', $total );
		$this->assertSame( '5', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE submission_id=%d', Database::outcomes_table(), $submission['id'] ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE submission_id=%d AND terminal_value_key IS NOT NULL', Database::outcomes_table(), $submission['id'] ) ) );
	}

	public function test_out_of_order_events_project_latest_occurrence_and_unknown_is_not_lost() {
		$submission = $this->submission( 'BBBBBBBBBBBBBBBBBBBBBB' );
		$manager    = new OutcomeManager();
		$manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'won',
				'value_minor'     => 0,
				'currency'        => 'USD',
				'idempotency_key' => 'won-new',
				'occurred_at'     => '2026-09-05T10:00:00Z',
			),
			'api'
		);
		$manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'lost',
				'idempotency_key' => 'lost-old',
				'occurred_at'     => '2026-09-04T10:00:00Z',
			),
			'api'
		);
		$this->assertSame( 'won', ( new OutcomeRepository() )->submission( $submission['public_id'] )['status'] );
		$pending = $this->submission( 'CCCCCCCCCCCCCCCCCCCCCC' );
		$manager->record(
			array(
				'submission_id'   => $pending['public_id'],
				'status'          => 'unknown',
				'idempotency_key' => 'pending',
			),
			'api'
		);
		$this->assertSame( 'unknown', ( new OutcomeRepository() )->submission( $pending['public_id'] )['status'] );
	}

	public function test_lost_to_won_and_reopened_won_transitions_do_not_duplicate_revenue() {
		global $wpdb;
		$submission = $this->submission( 'GGGGGGGGGGGGGGGGGGGGGG' );
		$manager    = new OutcomeManager();
		$this->assertFalse(
			$manager->record(
				array(
					'submission_id'   => $submission['public_id'],
					'status'          => 'lost',
					'idempotency_key' => 'lost-1',
					'occurred_at'     => '2026-09-01T10:00:00Z',
				),
				'api'
			)['duplicate']
		);
		$this->assertFalse(
			$manager->record(
				array(
					'submission_id'   => $submission['public_id'],
					'status'          => 'won',
					'value_minor'     => 10000,
					'currency'        => 'USD',
					'idempotency_key' => 'won-1',
					'occurred_at'     => '2026-09-02T10:00:00Z',
				),
				'api'
			)['duplicate']
		);
		$this->assertSame( 'won', ( new OutcomeRepository() )->submission( $submission['public_id'] )['status'] );
		$manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'lost',
				'idempotency_key' => 'lost-2',
				'occurred_at'     => '2026-09-03T10:00:00Z',
			),
			'api'
		);
		$this->assertSame( 'lost', ( new OutcomeRepository() )->submission( $submission['public_id'] )['status'] );
		$manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'won',
				'idempotency_key' => 'won-reopened',
				'occurred_at'     => '2026-09-04T10:00:00Z',
			),
			'api'
		);
		$this->assertSame( 'won', ( new OutcomeRepository() )->submission( $submission['public_id'] )['status'] );
		$this->assertSame( '10000', $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(value_minor) FROM %i WHERE submission_id=%d', Database::outcomes_table(), $submission['id'] ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE submission_id=%d AND terminal_value_key IS NOT NULL', Database::outcomes_table(), $submission['id'] ) ) );
	}

	public function test_currency_conflicts_are_rejected_and_storage_contains_no_field_values() {
		global $wpdb;
		$submission = $this->submission( 'DDDDDDDDDDDDDDDDDDDDDD' );
		$manager    = new OutcomeManager();
		$manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'won',
				'value_minor'     => 50000,
				'currency'        => 'USD',
				'idempotency_key' => 'usd',
			),
			'api'
		);
		$error = $manager->record(
			array(
				'submission_id'   => $submission['public_id'],
				'status'          => 'value_adjustment',
				'value_minor'     => 100,
				'currency'        => 'EUR',
				'idempotency_key' => 'eur',
			),
			'api'
		);
		$this->assertInstanceOf( '\WP_Error', $error );
		$this->assertSame( 'formhawk_currency_conflict', $error->get_error_code() );
		$forbidden = array( 'Anton', 'anton@example.test', '+375291234567', 'secret-test-value' );
		foreach ( array( Database::submissions_table(), Database::outcomes_table(), Database::submission_fields_table(), Database::field_definitions_table(), Database::form_versions_table(), Database::business_audit_table() ) as $table ) {
			$data = wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) );
			foreach ( $forbidden as $value ) {
				$this->assertStringNotContainsString( $value, $data ); }
		}
	}

	public function test_provider_confirmed_success_creates_opaque_linkage_and_stable_field_definitions() {
		global $wpdb;
		$public_id          = 'fh_FFFFFFFFFFFFFFFFFFFFFF';
		$submission_context = new SubmissionContext();
		$submission_context->set_verified( $public_id );
		$cro_context = new RequestContext();
		$forms       = new FormRepository();
		$recorder    = new AttributingEventRecorder( new EventIngestor( $forms ), $cro_context, new ExperimentRepository(), new OutcomeAttribution( $submission_context, $cro_context, $forms ) );
		$provider    = new WPForms( $recorder );
		$form_data   = array(
			'id'       => 77,
			'settings' => array( 'form_title' => 'Sales' ),
			'fields'   => array(
				9 => array(
					'id'       => 9,
					'label'    => 'Phone',
					'type'     => 'phone',
					'required' => 1,
				),
			),
		);
		$provider->process_complete( array( 9 => array( 'value' => '+375-private-phone' ) ), array(), $form_data, 123 );
		$submission = ( new OutcomeRepository() )->submission( $public_id );
		$this->assertIsArray( $submission );
		$this->assertSame( '123', $submission['provider_entry_id'] );
		$field = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id=%d', Database::field_definitions_table(), $submission['form_id'] ), ARRAY_A );
		$this->assertSame( '9', $field['provider_field_id'] );
		$this->assertSame( 'Phone', $field['label'] );
		$this->assertStringNotContainsString( '+375-private-phone', wp_json_encode( array( $submission, $field ) ) );
	}
}
