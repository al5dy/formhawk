<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\Attribution\ClientEventLifecycle;
use PHPUnit\Framework\TestCase;

final class ClientEventLifecycleTest extends TestCase {
	private function state() {
		return array(
			'viewed'           => 0,
			'started'          => 0,
			'attempts'         => 0,
			'terminal_attempt' => 0,
			'observed_attempt' => 0,
			'latency_attempt'  => 0,
			'resumed_attempt'  => 0,
			'last_success'     => 0,
			'abandoned'        => 0,
		);
	}

	public function test_observations_and_latency_are_bounded_by_fifty_sequenced_attempts() {
		$state = $this->state();
		$this->assertSame( 'event_without_attempt', ClientEventLifecycle::transition( $state, 'observed_submit', 1, null, false )['result'] );
		$state['started'] = 1;
		$totals           = array(
			'attempts'         => 0,
			'observed_submits' => 0,
			'latency_samples'  => 0,
			'latency_total_ms' => 0,
		);
		for ( $attempt = 1; $attempt <= 50; ++$attempt ) {
			foreach ( array( 'attempt', 'observed_submit', 'latency' ) as $type ) {
				$result = ClientEventLifecycle::transition( $state, $type, $attempt, 100, true );
				$this->assertSame( 'accepted', $result['result'] );
				$state = array_merge( $state, $result['changes'] );
				foreach ( $result['increments'] as $counter => $delta ) {
					$totals[ $counter ] += $delta;
				}
				$this->assertSame( 'replayed_context_event', ClientEventLifecycle::transition( $state, $type, $attempt, 99999, true )['result'] );
			}
			$this->assertLessThanOrEqual( $totals['attempts'], $totals['observed_submits'] );
			$this->assertLessThanOrEqual( $totals['attempts'], $totals['latency_samples'] );
		}
		$this->assertSame(
			array(
				'attempts'         => 50,
				'observed_submits' => 50,
				'latency_samples'  => 50,
				'latency_total_ms' => 5000,
			),
			$totals
		);
		$this->assertSame( 'invalid_context_lifecycle', ClientEventLifecycle::transition( $state, 'attempt', 51, null, false )['result'] );
	}

	public function test_editing_rearms_abandonment_but_focus_after_success_does_not() {
		$state = array_merge(
			$this->state(),
			array(
				'started'          => 1,
				'attempts'         => 1,
				'terminal_attempt' => 1,
				'last_success'     => 1,
			)
		);
		$this->assertSame( 'invalid_context_lifecycle', ClientEventLifecycle::transition( $state, 'abandon', 0, null, false )['result'] );
		$resume = ClientEventLifecycle::transition( $state, 'resume', 1, null, false );
		$this->assertSame( 'accepted', $resume['result'] );
		$this->assertSame( array(), $resume['increments'] );
		$state = array_merge( $state, $resume['changes'] );
		$this->assertSame( 'replayed_context_event', ClientEventLifecycle::transition( $state, 'resume', 1, null, false )['result'] );
		$this->assertSame( 'accepted', ClientEventLifecycle::transition( $state, 'abandon', 0, null, false )['result'] );
	}
}
