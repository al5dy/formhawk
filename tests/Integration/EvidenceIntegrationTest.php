<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Admin\Admin;
use Formhawk\Analytics\AnalyticsRepository;
use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Infrastructure\Database;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class EvidenceIntegrationTest extends IsolatedStorageTestCase {
	public function test_browser_and_provider_validation_are_separate_in_form_placement_and_field_aggregates() {
		global $wpdb;
		$ingestor = new EventIngestor( new FormRepository() );
		$fields   = array(
			array(
				'key'   => 'email',
				'label' => 'Email',
			),
			array(
				'key'   => 'email',
				'label' => 'Email',
			),
		);
		$event    = array(
			'provider'         => 'wpforms',
			'provider_form_id' => '42',
			'page_path'        => '/lead',
			'type'             => 'validation_failure',
			'fields'           => $fields,
		);
		$this->assertTrue( $ingestor->ingest_client( $event ) );
		$this->assertTrue( $ingestor->record_validation_failure( 'wpforms', '42', '', '/lead', $fields ) );
		$this->assertTrue( $ingestor->record_success( 'wpforms', '42', '', '/lead' ) );
		foreach ( array( Database::daily_table(), Database::placement_daily_table() ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $table ), ARRAY_A );
			$this->assertSame( '1', $row['client_validation_failures'] );
			$this->assertSame( '1', $row['provider_validation_failures'] );
			$this->assertSame( '2', $row['provider_validation_outcomes'] );
			$this->assertSame( '0', $row['validation_failures'] );
			$this->assertSame( '0', $row['submit_attempts'] );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', Database::fields_table() ), ARRAY_A );
		$this->assertSame( '1', $row['client_validation_errors'] );
		$this->assertSame( '1', $row['provider_validation_errors'] );
		$this->assertSame( '0', $row['validation_errors'] );
	}

	public function test_mixed_dashboard_and_queries_exclude_generic_starts_from_confirmed_conversion() {
		$ingestor = new EventIngestor( new FormRepository() );
		foreach ( array( 'html', 'cf7' ) as $provider ) {
			$event = array(
				'type'             => 'form_start',
				'provider'         => $provider,
				'provider_form_id' => '42',
				'page_path'        => '/mixed',
			);
			for ( $index = 0; $index < 10; ++$index ) {
				$this->assertTrue( $ingestor->ingest_client( $event ) );
			}
			$event['type'] = 'form_submit';
			$this->assertTrue( $ingestor->ingest_client( $event ) );
		}
		$this->assertTrue( $ingestor->record_success( 'cf7', '42', '', '/mixed' ) );
		$totals = ( new AnalyticsRepository() )->overview_totals();
		$this->assertSame( 20, $totals['starts'] );
		$this->assertSame( 10, $totals['eligible_starts'] );
		$this->assertSame( 1, $totals['eligible_confirmed_successes'] );
		$this->assertSame( 1, $totals['observed_generic_attempts'] );
		$this->assertSame( 0, $totals['submissions'] );

		$user   = get_current_user_id();
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		$this->assertNotEmpty( $admins );
		wp_set_current_user( $admins[0]->ID );
		try {
			ob_start();
			( new Admin( new FormRepository() ) )->render();
			$html = ob_get_clean();
		} finally {
			wp_set_current_user( $user );
		}
		$this->assertStringContainsString( 'supported providers only', $html );
		$this->assertStringContainsString( '10.0%', $html );
		$this->assertStringContainsString( 'N/A', $html );
		$this->assertStringNotContainsString( 'Submitted', $html );
	}
}
