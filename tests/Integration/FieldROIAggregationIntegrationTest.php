<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\Infrastructure\Database;
use Formhawk\Outcomes\OutcomeManager;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\ROI\FieldROIRepository;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class FieldROIAggregationIntegrationTest extends IsolatedStorageTestCase {
	public function test_daily_projection_is_restart_safe_currency_separated_and_correction_aware() {
		global $wpdb;
		$identity   = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'cf7',
				'provider_form_id' => '610',
				'title'            => 'Daily ROI',
				'page_path'        => '/daily-roi',
			)
		);
		$repository = new OutcomeRepository();
		$first      = $this->submission( $repository, $identity, 'fh_HHHHHHHHHHHHHHHHHHHHHH' );
		$second     = $this->submission( $repository, $identity, 'fh_IIIIIIIIIIIIIIIIIIIIII' );
		$manager    = new OutcomeManager();
		$manager->record(
			array(
				'submission_id'   => $first['public_id'],
				'status'          => 'qualified',
				'idempotency_key' => 'qualified-daily',
			),
			'api'
		);
		$manager->record(
			array(
				'submission_id'   => $first['public_id'],
				'status'          => 'won',
				'value_minor'     => 10000,
				'currency'        => 'USD',
				'idempotency_key' => 'won-daily',
			),
			'api'
		);
		$manager->record(
			array(
				'submission_id'   => $second['public_id'],
				'status'          => 'lost',
				'idempotency_key' => 'lost-daily',
			),
			'api'
		);

		$daily = new FieldROIRepository();
		$date  = current_time( 'Y-m-d' );
		$this->assertTrue( $daily->rebuild_daily( $date, $date ) );
		$this->assertTrue( $daily->rebuild_daily( $date, $date ), 'A repeated projection must replace the same date, not double it.' );
		$totals = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(submissions) submissions,SUM(qualified) qualified,SUM(won) won,SUM(revenue_minor) revenue FROM %i', Database::field_value_daily_table() ), ARRAY_A );
		$this->assertSame( '2', $totals['submissions'] );
		$this->assertSame( '1', $totals['qualified'] );
		$this->assertSame( '1', $totals['won'] );
		$this->assertSame( '10000', $totals['revenue'] );
		$this->assertSame( array( 'USD', 'XXX' ), $wpdb->get_col( $wpdb->prepare( 'SELECT currency FROM %i ORDER BY currency', Database::field_value_daily_table() ) ) );

		$manager->record(
			array(
				'submission_id'   => $first['public_id'],
				'status'          => 'value_adjustment',
				'value_minor'     => -2000,
				'currency'        => 'USD',
				'idempotency_key' => 'refund-daily',
			),
			'api'
		);
		$this->assertGreaterThan( 0, $daily->rebuild_changed_days( 0 ) );
		$this->assertSame( '8000', $wpdb->get_var( $wpdb->prepare( "SELECT revenue_minor FROM %i WHERE currency='USD'", Database::field_value_daily_table() ) ) );
	}

	private function submission( OutcomeRepository $repository, array $identity, $public_id ) {
		return $repository->create_submission(
			array(
				'public_id'         => $public_id,
				'form_id'           => $identity['form_id'],
				'placement_id'      => $identity['placement_id'],
				'provider'          => 'cf7',
				'provider_form_id'  => '610',
				'provider_entry_id' => '',
				'experiment_id'     => 0,
				'variant_id'        => 0,
				'device_class'      => 'desktop',
			),
			array(
				array(
					'key'      => 'phone',
					'label'    => 'Phone',
					'type'     => 'tel',
					'required' => 1,
					'position' => 1,
				),
			)
		);
	}
}
