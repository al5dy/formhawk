<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\BusinessValueWinnerSelector;
use Formhawk\Infrastructure\Database;
use Formhawk\Outcomes\OutcomeManager;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class BusinessValueAutopilotIntegrationTest extends IsolatedStorageTestCase {
	public function test_lower_submission_variant_wins_on_mature_revenue_per_visitor() {
		global $wpdb;
		$identity      = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'wpforms',
				'provider_form_id' => '740',
				'title'            => 'Value objective',
				'page_path'        => '/value-objective',
			)
		);
		$experiment_id = 740;
		$control_id    = 741;
		$variant_id    = 742;
		$stat_date     = wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS, wp_timezone() );
		foreach ( array(
			$control_id => 30,
			$variant_id => 25,
		) as $arm_id => $submissions ) {
			$wpdb->insert(
				Database::experiment_daily_table(),
				array(
					'experiment_id'       => $experiment_id,
					'variant_id'          => $arm_id,
					'stat_date'           => $stat_date,
					'segment'             => 'desktop',
					'views'               => 1000,
					'confirmed_successes' => $submissions,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d' )
			);
		}

		$manager = new OutcomeManager();
		for ( $index = 0; $index < 40; ++$index ) {
			$is_variant = $index >= 20;
			$public_id  = 'fh_' . str_pad( (string) $index, 22, 'A', STR_PAD_LEFT );
			$submission = ( new OutcomeRepository() )->create_submission(
				array(
					'public_id'         => $public_id,
					'form_id'           => $identity['form_id'],
					'placement_id'      => $identity['placement_id'],
					'provider'          => 'wpforms',
					'provider_form_id'  => '740',
					'provider_entry_id' => (string) $index,
					'experiment_id'     => $experiment_id,
					'variant_id'        => $is_variant ? $variant_id : $control_id,
					'device_class'      => 'desktop',
				),
				array()
			);
			$is_won     = $is_variant || $index < 2;
			$outcome    = $manager->record(
				array(
					'submission_id'   => $public_id,
					'status'          => $is_won ? 'won' : 'unqualified',
					'value_minor'     => $is_won ? 10000 : null,
					'currency'        => $is_won ? 'USD' : '',
					'idempotency_key' => 'value-objective-' . $index,
					'occurred_at'     => $stat_date . 'T12:00:00Z',
				),
				'test'
			);
			$this->assertFalse( is_wp_error( $outcome ) );
			$wpdb->update(
				Database::submissions_table(),
				array(
					'stat_date'        => $stat_date,
					'mature_after_utc' => $stat_date . ' 23:59:59',
				),
				array( 'id' => $submission['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		update_option(
			'formhawk_field_roi_settings',
			array(
				'enabled'       => 1,
				'maturity_days' => 7,
				'currency'      => 'USD',
			),
			false
		);
		$result = ( new BusinessValueWinnerSelector() )->select(
			array(
				'id'             => $experiment_id,
				'started_at_utc' => $stat_date . ' 00:00:00',
				'primary_metric' => 'business_value',
			),
			array(
				array(
					'id'             => $control_id,
					'traffic_weight' => 50,
				),
				array(
					'id'             => $variant_id,
					'traffic_weight' => 50,
				),
			),
			array(
				'minimum_views_per_variant' => 100,
				'minimum_conversions'       => 20,
				'minimum_runtime_days'      => 3,
				'probability_to_be_best'    => 0.95,
				'maximum_runtime_days'      => 60,
			),
			array(
				'currency'               => 'USD',
				'optimization_objective' => 'business_value',
			),
			30
		);
		$this->assertSame( 'winner', $result['decision'] );
		$this->assertSame( 'revenue_per_visitor', $result['metric'] );
		$this->assertGreaterThan( $result['control_rate'], $result['variant_rate'] );
	}
}
