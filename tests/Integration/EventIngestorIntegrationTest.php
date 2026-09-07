<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Domain\FormIdentity;
use Formhawk\Infrastructure\Database;
use Formhawk\Integrations\ElementorForms;
use Formhawk\Integrations\WPForms;
use Formhawk\Tests\Fixtures\ElementorHandlerDouble;
use Formhawk\Tests\Fixtures\ElementorRecordDouble;
use PHPUnit\Framework\TestCase;

final class EventIngestorIntegrationTest extends TestCase {
	private $created_form_ids = array();

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->created_form_ids as $form_id ) {
			// Test cleanup is deliberately limited to the unique rows created here.
			$placement_ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE form_id = %d', Database::placements_table(), $form_id )
			);
			foreach ( $placement_ids as $placement_id ) {
				$wpdb->delete( Database::placement_daily_table(), array( 'placement_id' => $placement_id ), array( '%d' ) );
			}
			$wpdb->delete( Database::placements_table(), array( 'form_id' => $form_id ), array( '%d' ) );
			$wpdb->delete( Database::fields_table(), array( 'form_id' => $form_id ), array( '%d' ) );
			$wpdb->delete( Database::daily_table(), array( 'form_id' => $form_id ), array( '%d' ) );
			$wpdb->delete( Database::forms_table(), array( 'id' => $form_id ), array( '%d' ) );
		}
		unset( $_SERVER['HTTP_REFERER'] );

		parent::tearDown();
	}

	public function test_wpforms_browser_attempt_and_repeated_complete_hook_count_one_submission() {
		global $wpdb;

		$provider_form_id = '900001';
		$ingestor         = new EventIngestor( new FormRepository() );
		$integration      = new WPForms( $ingestor );
		$event            = array(
			'type'             => 'form_submit',
			'provider'         => 'wpforms',
			'provider_form_id' => $provider_form_id,
			'title'            => 'Integration test form',
			'page_path'        => '/formhawk-integration-test',
			'duration_ms'      => 2500,
		);

		$this->assertTrue( $ingestor->ingest_client( $event ) );
		$form_data               = array(
			'id'       => (int) $provider_form_id,
			'settings' => array( 'form_title' => 'Integration test form' ),
			'fields'   => array(
				2 => array(
					'id'    => 2,
					'label' => 'Email',
					'type'  => 'email',
				),
			),
		);
		$private_values          = array( 2 => array( 'value' => 'visitor@example.test' ) );
		$_SERVER['HTTP_REFERER'] = 'https://example.test/formhawk-integration-test';
		$integration->process_complete( $private_values, array( 'fields' => $private_values ), $form_data, 0 );
		$integration->process_complete( $private_values, array( 'fields' => $private_values ), $form_data, 0 );

		$form = $this->find_form( 'wpforms', $provider_form_id, '/formhawk-integration-test' );
		$this->assertNotNull( $form );
		$this->created_form_ids[] = (int) $form['id'];
		$daily                    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', Database::daily_table(), $form['id'] ),
			ARRAY_A
		);

		$this->assertSame( '1', $daily['submit_attempts'] );
		$this->assertSame( '0', $daily['submissions'] );
		$this->assertSame( '1', $daily['confirmed_successes'] );
		$this->assertStringNotContainsString( 'visitor@example.test', wp_json_encode( array( $form, $daily ) ) );
	}

	public function test_first_class_form_keeps_one_definition_and_separate_placements() {
		global $wpdb;

		$provider_form_id = '900002';
		$ingestor         = new EventIngestor( new FormRepository() );
		foreach ( array( '/landing-a', '/landing-b' ) as $page_path ) {
			$this->assertTrue(
				$ingestor->ingest_client(
					array(
						'type'             => 'form_view',
						'provider'         => 'wpforms',
						'provider_form_id' => $provider_form_id,
						'title'            => '',
						'page_path'        => $page_path,
					)
				)
			);
		}

		$form = $this->find_form( 'wpforms', $provider_form_id, '/landing-a' );
		$this->assertNotNull( $form );
		$this->created_form_ids[] = (int) $form['id'];
		$this->assertSame( '/landing-a', $form['page_path'] );
		$this->assertSame(
			'2',
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d', Database::placements_table(), $form['id'] )
			)
		);
		$this->assertSame(
			'2',
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT SUM(views) FROM %i WHERE form_id = %d', Database::daily_table(), $form['id'] )
			)
		);
	}

	public function test_elementor_attempt_and_repeated_server_hooks_count_one_private_success() {
		global $wpdb;

		$provider_form_id = '900004:widget900004';
		$ingestor         = new EventIngestor( new FormRepository() );
		$integration      = new ElementorForms( $ingestor );
		$this->assertTrue(
			$ingestor->ingest_client(
				array(
					'type'             => 'form_submit',
					'provider'         => 'elementor',
					'provider_form_id' => $provider_form_id,
					'title'            => 'Elementor integration test',
					'page_path'        => '/elementor-integration-test',
				)
			)
		);

		$record                  = new ElementorRecordDouble(
			'widget900004',
			'Elementor integration test',
			900004,
			array(
				array(
					'custom_id'   => 'email',
					'field_label' => 'Work email',
					'field_type'  => 'email',
				),
			),
			array(
				'email'   => 'visitor@example.test',
				'name'    => 'John Smith',
				'phone'   => '+1 555 010 2000',
				'message' => 'Synthetic private message contents',
			)
		);
		$handler                 = new ElementorHandlerDouble();
		$_SERVER['HTTP_REFERER'] = 'https://example.test/elementor-integration-test';
		$integration->mail_sent( array( 'email_to' => 'recipient@example.test' ), $record );
		$integration->new_record( $record, $handler );
		$integration->new_record( $record, $handler );

		$form = $this->find_form( 'elementor', $provider_form_id, '/elementor-integration-test' );
		$this->assertNotNull( $form );
		$this->created_form_ids[] = (int) $form['id'];
		$daily                    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', Database::daily_table(), $form['id'] ),
			ARRAY_A
		);
		$stored                   = wp_json_encode( array( $form, $daily ) );

		$this->assertSame( '1', $daily['submit_attempts'] );
		$this->assertSame( '0', $daily['submissions'] );
		$this->assertSame( '1', $daily['confirmed_successes'] );
		$this->assertSame( '1', $daily['mail_successes'] );
		$this->assertStringNotContainsString( 'visitor@example.test', $stored );
		$this->assertStringNotContainsString( 'John Smith', $stored );
		$this->assertStringNotContainsString( '+1 555', $stored );
		$this->assertStringNotContainsString( 'Synthetic private message', $stored );
		$this->assertStringNotContainsString( 'recipient@example.test', $stored );
	}

	public function test_generic_submit_remains_one_observed_submission_without_confirmation() {
		global $wpdb;

		$provider_form_id = 'generic-integration-test';
		$ingestor         = new EventIngestor( new FormRepository() );
		$this->assertTrue(
			$ingestor->ingest_client(
				array(
					'type'             => 'form_submit',
					'provider'         => 'html',
					'provider_form_id' => $provider_form_id,
					'title'            => 'Generic integration form',
					'page_path'        => '/formhawk-integration-test',
				)
			)
		);

		$form = $this->find_form( 'html', $provider_form_id, '/formhawk-integration-test' );
		$this->assertNotNull( $form );
		$this->created_form_ids[] = (int) $form['id'];
		$daily                    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', Database::daily_table(), $form['id'] ),
			ARRAY_A
		);

		$this->assertSame( '1', $daily['submit_attempts'] );
		$this->assertSame( '0', $daily['submissions'] );
		$this->assertSame( '0', $daily['confirmed_successes'] );
	}

	public function test_validation_fields_are_batched_and_static_metadata_replaces_key_fallback() {
		global $wpdb;

		$provider_form_id = '900003';
		$ingestor         = new EventIngestor( new FormRepository() );
		$this->assertTrue(
			$ingestor->ingest_client(
				array(
					'type'             => 'validation_failure',
					'provider'         => 'elementor',
					'provider_form_id' => $provider_form_id,
					'title'            => 'Validation integration form',
					'page_path'        => '/validation-integration-test',
					'fields'           => array(
						array(
							'key'   => 'email',
							'label' => 'email',
							'type'  => '',
						),
						array(
							'key'   => 'phone',
							'label' => 'phone',
							'type'  => '',
						),
					),
				)
			)
		);
		$this->assertTrue(
			$ingestor->ingest_client(
				array(
					'type'             => 'field_interaction',
					'provider'         => 'elementor',
					'provider_form_id' => $provider_form_id,
					'title'            => 'Validation integration form',
					'page_path'        => '/validation-integration-test',
					'field'            => array(
						'key'   => 'email',
						'label' => 'Work email',
						'type'  => 'email',
					),
				)
			)
		);

		$form = $this->find_form( 'elementor', $provider_form_id, '/validation-integration-test' );
		$this->assertNotNull( $form );
		$this->created_form_ids[] = (int) $form['id'];
		$fields                   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT field_key, field_label, client_validation_errors FROM %i WHERE form_id = %d ORDER BY field_key', Database::fields_table(), $form['id'] ),
			ARRAY_A
		);

		$this->assertCount( 2, $fields );
		$this->assertSame( 'Work email', $fields[0]['field_label'] );
		$this->assertSame( '1', $fields[0]['client_validation_errors'] );
		$this->assertSame( '1', $fields[1]['client_validation_errors'] );
	}

	private function find_form( $provider, $provider_form_id, $page_path ) {
		global $wpdb;
		$key = FormIdentity::key( $provider, $provider_form_id, $page_path );

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_key = %s', Database::forms_table(), $key ),
			ARRAY_A
		);
	}
}
