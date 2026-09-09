<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\AttributingEventRecorder;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\CRO\AutopilotManager;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Cleanup;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;
use Formhawk\Tests\Fixtures\RecordingEventRecorder;

final class AutopilotIntegrationTest extends IsolatedStorageTestCase {
	public function test_client_errors_validation_and_latency_only_request_review() {
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'assignments'         => 100,
				'views'               => 100,
				'starts'              => 100,
				'confirmed_successes' => 10,
				'latency_samples'     => 30,
				'latency_total_ms'    => 3000,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'assignments'                => 100,
				'views'                      => 100,
				'starts'                     => 100,
				'confirmed_successes'        => 10,
				'js_errors'                  => 3,
				'client_validation_failures' => 90,
				'latency_samples'            => 30,
				'latency_total_ms'           => 90000,
			)
		);
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$current = $repository->find( $experiment['id'] );
		$this->assertSame( ExperimentStatus::RUNNING, $current['status'] );
		$this->assertSame( 'client_telemetry_review', $current['integrity_warning'] );
		$this->assertStringContainsString( 'Client-only errors, validation and latency do not authorize automatic rejection or rollback.', $this->autopilot_html( $repository, $experiment['form_id'] ) );
		$this->assertSame( 50, absint( $repository->variants( $experiment['id'] )[1]['traffic_weight'] ) );
		$this->assertSame( array(), $repository->history( $experiment['form_id'] ) );
		$this->assertSame( array(), $repository->settings( $experiment['form_id'] )['baseline'] );
	}

	public function test_client_only_anomaly_cannot_roll_back_a_promoted_baseline() {
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$baseline = $variants[1]['config']['mutations'];
		$this->assertTrue( $repository->promote_experiment( $experiment['form_id'], $experiment['id'], $variants[1]['id'], $baseline ) );
		$repository->set_status( $experiment['id'], ExperimentStatus::PROMOTED_MONITORING, array( 'ended_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'assignments'         => 100,
				'views'               => 100,
				'confirmed_successes' => 10,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'assignments'         => 100,
				'views'               => 100,
				'confirmed_successes' => 10,
				'js_errors'           => 3,
			)
		);
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::PROMOTED_MONITORING, $repository->find( $experiment['id'] )['status'] );
		$this->assertSame( $baseline, $repository->settings( $experiment['form_id'] )['baseline'] );
		$this->assertSame( array(), $repository->history( $experiment['form_id'] ) );
	}

	public function test_selectively_reported_views_cannot_change_the_server_assignment_denominator() {
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$repository->set_status( $experiment['id'], ExperimentStatus::RUNNING, array( 'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ) );
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'assignments'         => 10000,
				'views'               => 1000000,
				'confirmed_successes' => 500,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'assignments'         => 10000,
				'views'               => 1000,
				'confirmed_successes' => 500,
			)
		);
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::RUNNING, $repository->find( $experiment['id'] )['status'] );
		$this->assertSame( array(), $repository->history( $experiment['form_id'] ) );
		$this->assertSame( array(), $repository->settings( $experiment['form_id'] )['baseline'] );
	}

	public function test_generic_full_mode_requires_manual_start_and_never_promotes_browser_conversions() {
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment( 'html' );
		$this->assertSame( ExperimentStatus::AWAITING_APPROVAL, $experiment['status'] );
		$this->assertSame( 'observed_submit_rate', $experiment['primary_metric'] );
		$html = $this->autopilot_html( $repository, $experiment['form_id'] );
		$this->assertStringContainsString( 'autonomous promotion is disabled', $html );
		$this->assertMatchesRegularExpression( '/<option value="full"[^>]*\bdisabled=/', $html );
		$this->assertStringContainsString( 'Promote variant', $html );
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::AWAITING_APPROVAL, $repository->find( $experiment['id'] )['status'] );
		$this->assertTrue( $repository->start( $experiment['id'] ) );
		$repository->set_status( $experiment['id'], ExperimentStatus::RUNNING, array( 'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'assignments'      => 10000,
				'views'            => 10000,
				'observed_submits' => 100,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'assignments'      => 10000,
				'views'            => 10000,
				'observed_submits' => 9000,
				'js_errors'        => 9999,
			)
		);
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::RUNNING, $repository->find( $experiment['id'] )['status'] );
		$this->assertSame( 'confirmed_evidence_required', $repository->find( $experiment['id'] )['integrity_warning'] );
		$this->assertSame( array(), $repository->history( $experiment['form_id'] ) );
		$this->assertSame( array(), $repository->settings( $experiment['form_id'] )['baseline'] );
		// Explicit owner approval remains available; the restriction is autonomous authority.
		$this->assertTrue( $repository->promote_experiment( $experiment['form_id'], $experiment['id'], $variants[1]['id'], $variants[1]['config']['mutations'] ) );
	}

	public function test_legacy_experiments_remain_manual_safe_even_after_new_assignments_arrive() {
		global $wpdb;
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$wpdb->update( Database::experiments_table(), array( 'integrity_version' => 1 ), array( 'id' => $experiment['id'] ) );
		$repository->set_status( $experiment['id'], ExperimentStatus::RUNNING, array( 'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'assignments'         => 10000,
				'views'               => 10000,
				'confirmed_successes' => 500,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'assignments'         => 10000,
				'views'               => 10000,
				'confirmed_successes' => 9000,
				'js_errors'           => 9000,
			)
		);
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::RUNNING, $repository->find( $experiment['id'] )['status'] );
		$this->assertSame( 'legacy_experiment_review', $repository->find( $experiment['id'] )['integrity_warning'] );
		$this->assertSame( array(), $repository->history( $experiment['form_id'] ) );
	}

	public function test_full_autopilot_creates_runs_and_promotes_a_confirmed_conversion_experiment() {
		global $wpdb;
		$forms      = new FormRepository();
		$identity   = $forms->resolve(
			array(
				'provider'         => 'cf7',
				'provider_form_id' => '77',
				'title'            => 'Request information',
				'page_path'        => '/lead',
			)
		);
		$form_id    = $identity['form_id'];
		$repository = new ExperimentRepository();
		$wpdb->insert(
			Database::daily_table(),
			array(
				'form_id'         => $form_id,
				'stat_date'       => current_time( 'Y-m-d' ),
				'views'           => 500,
				'starts'          => 300,
				'submit_attempts' => 100,
			),
			array( '%d', '%s', '%d', '%d', '%d' )
		);
		$this->assertTrue(
			$repository->enable(
				$form_id,
				array(
					'mode'           => 'full',
					'aggressiveness' => 'balanced',
				)
			)
		);
		$manager = new AutopilotManager( $repository, $forms );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$experiment = $repository->active_for_form( $form_id );
		$this->assertSame( ExperimentStatus::RUNNING, $experiment['status'] );
		$this->assertSame( 'confirmed_conversion', $experiment['primary_metric'] );
		$variants = $repository->variants( $experiment['id'] );
		$this->assertCount( 2, $variants );
		$this->assertSame( 100, absint( $variants[0]['traffic_weight'] ) + absint( $variants[1]['traffic_weight'] ) );

		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'views'               => 10000,
				'assignments'         => 10000,
				'confirmed_successes' => 5000,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'views'               => 10000,
				'assignments'         => 10000,
				'confirmed_successes' => 5500,
			)
		);
		$wpdb->update( Database::experiments_table(), array( 'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ), array( 'id' => $experiment['id'] ), array( '%s' ), array( '%d' ) );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$promoted = $repository->active_for_form( $form_id );
		$this->assertSame( ExperimentStatus::PROMOTED_MONITORING, $promoted['status'] );
		$this->assertSame( absint( $variants[1]['id'] ), absint( $promoted['winner_variant_id'] ) );
		$this->assertNotEmpty( $repository->settings( $form_id )['baseline'] );
		$this->assertSame( 'winner', $repository->history( $form_id )[0]['decision'] );
	}

	public function test_signed_request_context_attributes_provider_success_to_the_correct_variant_only() {
		global $wpdb;
		$forms      = new FormRepository();
		$identity   = $forms->resolve(
			array(
				'provider'         => 'wpforms',
				'provider_form_id' => '88',
				'title'            => 'Lead',
				'page_path'        => '/lead',
			)
		);
		$form_id    = $identity['form_id'];
		$repository = new ExperimentRepository();
		$repository->enable( $form_id, array( 'mode' => 'full' ) );
		$policy        = ( new \Formhawk\CRO\OptimizationPolicy() )->for_form( array() );
		$experiment_id = $repository->create(
			array(
				'form_id'        => $form_id,
				'type'           => 'submit_button',
				'status'         => ExperimentStatus::RUNNING,
				'hypothesis'     => 'Test',
				'primary_metric' => 'confirmed_conversion',
				'evidence_level' => 'provider_confirmed',
				'policy'         => $policy,
			),
			array(
				array(
					'name'          => 'Control',
					'mutation_type' => 'baseline',
					'config'        => array( 'mutations' => array() ),
				),
				array(
					'name'          => 'Variant',
					'mutation_type' => 'submit_button',
					'config'        => array(
						'mutations' => array(
							array(
								'type'   => 'submit_button',
								'config' => array( 'text' => 'Send request' ),
							),
						),
					),
				),
			)
		);
		$repository->set_status( $experiment_id, ExperimentStatus::RUNNING, array( 'started_at_utc' => current_time( 'mysql', true ) ) );
		$variants = $repository->variants( $experiment_id );
		$context  = new RequestContext();
		$context->set_verified_context(
			array(
				'experiment_id'    => $experiment_id,
				'variant_id'       => absint( $variants[1]['id'] ),
				'form_id'          => $form_id,
				'provider'         => 'wpforms',
				'provider_form_id' => '88',
				'segment'          => 'mobile',
			)
		);
		$inner    = new RecordingEventRecorder();
		$recorder = new AttributingEventRecorder( $inner, $context, $repository );
		$this->assertTrue( $recorder->record_success( 'wpforms', '88', 'Lead', '/lead' ) );
		$totals = $repository->aggregate( $experiment_id );
		$this->assertSame( 1, $totals[ $variants[1]['id'] ]['confirmed_successes'] );
		$this->assertArrayNotHasKey( $variants[0]['id'], $totals );
		$this->assertTrue( $recorder->record_success( 'cf7', '88', 'Wrong provider', '/lead' ) );
		$totals = $repository->aggregate( $experiment_id );
		$this->assertSame( 1, $totals[ $variants[1]['id'] ]['confirmed_successes'] );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::experiment_daily_table() ) ) );
	}

	public function test_cro_migration_does_not_fabricate_pre_assignment_history() {
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::experiments_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::experiment_daily_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::optimization_history_table() ) ) );
	}

	public function test_mobile_regression_stops_variant_even_when_aggregate_result_looks_positive() {
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'views'               => 10000,
				'assignments'         => 10000,
				'confirmed_successes' => 5000,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'views'               => 10000,
				'assignments'         => 10000,
				'confirmed_successes' => 5500,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'mobile',
			array(
				'views'               => 200,
				'assignments'         => 200,
				'confirmed_successes' => 100,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'mobile',
			array(
				'views'               => 200,
				'assignments'         => 200,
				'confirmed_successes' => 10,
			)
		);

		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertNull( $repository->active_for_form( $experiment['form_id'] ) );
		$history = $repository->history( $experiment['form_id'] );
		$this->assertSame( 'stopped_guardrail', $history[0]['decision'] );
		$this->assertStringStartsWith( 'segment_mobile_', $history[0]['reason'] );
		$this->assertSame( 100, absint( $repository->variants( $experiment['id'] )[0]['traffic_weight'] ) );
	}

	public function test_promoted_variant_is_rolled_back_after_credible_post_promotion_regression() {
		global $wpdb;
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$mutation = $variants[1]['config']['mutations'];
		$this->assertTrue( $repository->promote_baseline( $experiment['form_id'], $mutation ) );
		$repository->set_status(
			$experiment['id'],
			ExperimentStatus::PROMOTED_MONITORING,
			array(
				'winner_variant_id' => $variants[1]['id'],
				'ended_at_utc'      => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'views'               => 1000,
				'assignments'         => 1000,
				'confirmed_successes' => 100,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'views'               => 1000,
				'assignments'         => 1000,
				'confirmed_successes' => 20,
			)
		);

		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertNull( $repository->active_for_form( $experiment['form_id'] ) );
		$this->assertSame( array(), $repository->settings( $experiment['form_id'] )['baseline'] );
		$this->assertSame( 'rollback', $repository->history( $experiment['form_id'] )[0]['decision'] );
		$this->assertSame( 'rolled_back', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Database::experiments_table(), $experiment['id'] ) ) );
	}

	public function test_promoted_variant_is_rolled_back_when_provider_failures_cross_guardrail() {
		global $wpdb;
		list( $manager, $repository, $experiment, $variants ) = $this->running_experiment();
		$mutation = $variants[1]['config']['mutations'];
		$this->assertTrue( $repository->promote_baseline( $experiment['form_id'], $mutation ) );
		$repository->set_status(
			$experiment['id'],
			ExperimentStatus::PROMOTED_MONITORING,
			array(
				'winner_variant_id' => $variants[1]['id'],
				'ended_at_utc'      => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[0]['id'],
			'desktop',
			array(
				'views'               => 500,
				'assignments'         => 500,
				'confirmed_successes' => 50,
			)
		);
		$repository->increment(
			$experiment['id'],
			$variants[1]['id'],
			'desktop',
			array(
				'views'               => 500,
				'assignments'         => 500,
				'confirmed_successes' => 50,
				'provider_failures'   => 40,
			)
		);

		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( array(), $repository->settings( $experiment['form_id'] )['baseline'] );
		$this->assertSame( 'rollback', $repository->history( $experiment['form_id'] )[0]['decision'] );
		$this->assertSame( 'post_promotion_provider_failure_rate', $repository->history( $experiment['form_id'] )[0]['reason'] );
		$this->assertSame( 'rolled_back', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Database::experiments_table(), $experiment['id'] ) ) );
	}

	public function test_manual_pause_is_not_automatically_resumed_in_full_mode() {
		list( $manager, $repository, $experiment ) = $this->running_experiment();
		$this->assertTrue( $repository->set_status( $experiment['id'], ExperimentStatus::PAUSED_MANUAL ) );
		$this->assertTrue( $manager->evaluate_form( $experiment['form_id'] ) );
		$this->assertSame( ExperimentStatus::PAUSED_MANUAL, $repository->active_for_form( $experiment['form_id'] )['status'] );
	}

	public function test_manual_resume_restores_the_policy_allocation_atomically() {
		list( , $repository, $experiment, $variants ) = $this->running_experiment();
		$this->assertTrue( $repository->route_to_control( $experiment['id'] ) );
		$this->assertTrue( $repository->set_status( $experiment['id'], ExperimentStatus::PAUSED_MANUAL ) );
		$this->assertSame( 100, absint( $repository->variants( $experiment['id'] )[0]['traffic_weight'] ) );

		$this->assertTrue( $repository->start( $experiment['id'] ) );
		$resumed = $repository->variants( $experiment['id'] );
		$this->assertSame( 50, absint( $resumed[0]['traffic_weight'] ) );
		$this->assertSame( 50, absint( $resumed[1]['traffic_weight'] ) );
		$this->assertSame( ExperimentStatus::RUNNING, $repository->active_for_form( $experiment['form_id'] )['status'] );
		$this->assertFalse( $repository->start( $experiment['id'] ), 'A duplicate start must not reset runtime or allocation.' );
	}

	public function test_one_click_enable_uses_balanced_safety_defaults() {
		$forms      = new FormRepository();
		$identity   = $forms->resolve(
			array(
				'provider'         => 'html',
				'provider_form_id' => 'balanced-defaults',
				'title'            => 'Defaults',
				'page_path'        => '/defaults',
			)
		);
		$repository = new ExperimentRepository();
		$this->assertTrue( $repository->enable( $identity['form_id'] ) );
		$settings = $repository->settings( $identity['form_id'] );
		$this->assertSame( 'approve', $settings['mode'] );
		$this->assertSame( 'balanced', $settings['aggressiveness'] );
		$this->assertSame( '50', $settings['max_experimental_traffic'] );
		$this->assertSame( '7', $settings['min_duration_days'] );
		$this->assertSame( '50', $settings['min_conversions'] );
	}

	public function test_promotion_monitoring_starts_on_the_next_site_calendar_day() {
		$original_timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Minsk' );
		try {
			$method = new \ReflectionMethod( AutopilotManager::class, 'day_after_utc' );
			$method->setAccessible( true );
			$this->assertSame( '2026-09-09', $method->invoke( new AutopilotManager(), '2026-09-07 23:30:00' ) );
		} finally {
			update_option( 'timezone_string', $original_timezone );
		}
	}

	public function test_retention_preserves_active_experiment_window_then_removes_finished_aggregates() {
		global $wpdb;
		list( , $repository, $experiment, $variants ) = $this->running_experiment();
		$repository->increment( $experiment['id'], $variants[0]['id'], 'desktop', array( 'views' => 1 ) );
		$wpdb->update( Database::experiment_daily_table(), array( 'stat_date' => '2020-01-01' ), array( 'experiment_id' => $experiment['id'] ), array( '%s' ), array( '%d' ) );
		$method = new \ReflectionMethod( Cleanup::class, 'delete_cro_batch' );
		$method->setAccessible( true );
		$this->assertSame( 0, $method->invoke( new Cleanup(), '2025-01-01' ) );
		$this->assertTrue( $repository->set_status( $experiment['id'], ExperimentStatus::REJECTED ) );
		$this->assertSame( 1, $method->invoke( new Cleanup(), '2025-01-01' ) );
	}

	private function autopilot_html( ExperimentRepository $repository, $form_id ) {
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$forms  = new FormRepository();
		$admin  = new \Formhawk\Admin\Admin( $forms, new \Formhawk\Integrations\IntegrationRegistry( array() ), $repository );
		$method = new \ReflectionMethod( $admin, 'render_autopilot' );
		$method->setAccessible( true );
		ob_start();
		try {
			$method->invoke( $admin, $forms->find( $form_id ), array(), array() );
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function running_experiment( $provider = 'cf7' ) {
		global $wpdb;
		$forms    = new FormRepository();
		$identity = $forms->resolve(
			array(
				'provider'         => $provider,
				'provider_form_id' => '99',
				'title'            => 'Quote request',
				'page_path'        => '/quote',
			)
		);
		$form_id  = absint( $identity['form_id'] );
		$wpdb->insert(
			Database::daily_table(),
			array(
				'form_id'         => $form_id,
				'stat_date'       => current_time( 'Y-m-d' ),
				'views'           => 500,
				'starts'          => 300,
				'submit_attempts' => 100,
			),
			array( '%d', '%s', '%d', '%d', '%d' )
		);
		$repository = new ExperimentRepository();
		$repository->enable( $form_id, array( 'mode' => 'full' ) );
		$manager = new AutopilotManager( $repository, $forms );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$experiment = $repository->active_for_form( $form_id );
		return array( $manager, $repository, $experiment, $repository->variants( $experiment['id'] ) );
	}
}
