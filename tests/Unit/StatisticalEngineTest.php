<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\OptimizationPolicy;
use Formhawk\CRO\StatisticalEngine;
use PHPUnit\Framework\TestCase;

final class StatisticalEngineTest extends TestCase {
	private function policy() {
		return ( new OptimizationPolicy() )->for_form( array( 'aggressiveness' => 'balanced' ) );
	}

	public function test_near_tie_with_tiny_sample_never_selects_winner() {
		$result = ( new StatisticalEngine() )->evaluate(
			array(
				'views'       => 100,
				'conversions' => 50,
			),
			array(
				'views'       => 100,
				'conversions' => 51,
			),
			$this->policy(),
			30
		);
		$this->assertSame( 'collecting', $result['decision'] );
		$this->assertSame( 'minimum_sample', $result['reason'] );
	}

	public function test_large_material_lift_is_a_legitimate_winner() {
		$result = ( new StatisticalEngine() )->evaluate(
			array(
				'views'       => 10000,
				'conversions' => 5000,
			),
			array(
				'views'       => 10000,
				'conversions' => 5500,
			),
			$this->policy(),
			14
		);
		$this->assertSame( 'winner', $result['decision'] );
		$this->assertGreaterThan( 0.999, $result['probability_to_be_best'] );
		$this->assertLessThan( 0.001, $result['expected_loss'] );
		$this->assertEqualsWithDelta( 0.10, $result['expected_lift'], 0.002 );
	}

	public function test_minimum_runtime_and_conversions_are_independent_gates() {
		$engine = new StatisticalEngine();
		$result = $engine->evaluate(
			array(
				'views'       => 5000,
				'conversions' => 250,
			),
			array(
				'views'       => 5000,
				'conversions' => 400,
			),
			$this->policy(),
			1
		);
		$this->assertSame( 'minimum_runtime', $result['reason'] );
		$result = $engine->evaluate(
			array(
				'views'       => 5000,
				'conversions' => 10,
			),
			array(
				'views'       => 5000,
				'conversions' => 15,
			),
			$this->policy(),
			14
		);
		$this->assertSame( 'minimum_conversions', $result['reason'] );
	}

	public function test_equal_variants_end_inconclusive_at_maximum_runtime() {
		$result = ( new StatisticalEngine() )->evaluate(
			array(
				'views'       => 5000,
				'conversions' => 500,
			),
			array(
				'views'       => 5000,
				'conversions' => 500,
			),
			$this->policy(),
			50
		);
		$this->assertSame( 'inconclusive', $result['decision'] );
		$this->assertEqualsWithDelta( 0.5, $result['probability_to_be_best'], 0.001 );
	}

	public function test_credible_intervals_are_ordered_and_bounded() {
		$result = ( new StatisticalEngine() )->evaluate(
			array(
				'views'       => 100,
				'conversions' => 25,
			),
			array(
				'views'       => 100,
				'conversions' => 35,
			),
			$this->policy(),
			10
		);
		foreach ( array( $result['control_interval'], $result['variant_interval'] ) as $interval ) {
			$this->assertGreaterThanOrEqual( 0, $interval[0] );
			$this->assertGreaterThan( $interval[0], $interval[1] );
			$this->assertLessThanOrEqual( 1, $interval[1] );
		}
	}
}
