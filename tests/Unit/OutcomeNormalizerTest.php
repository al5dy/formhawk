<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Outcomes\Currency;
use Formhawk\Outcomes\OutcomeNormalizer;
use PHPUnit\Framework\TestCase;

final class OutcomeNormalizerTest extends TestCase {
	public function test_monetary_value_is_not_accepted_on_non_revenue_state() {
		$result = ( new OutcomeNormalizer() )->normalize(
			array(
				'submission_id' => 'fh_AAAAAAAAAAAAAAAAAAAAAA',
				'status'        => 'qualified',
				'value_minor'   => 1000,
				'currency'      => 'USD',
			),
			'api'
		);
		$this->assertInstanceOf( '\WP_Error', $result );
		$this->assertSame( 'formhawk_invalid_value_status', $result->get_error_code() );
	}
	public function test_accepts_integer_minor_units_and_normalizes_time_to_utc() {
		$result = ( new OutcomeNormalizer() )->normalize(
			array(
				'submission_id'   => 'fh_7Kx29Abcdefghijklmnopq',
				'status'          => 'WON',
				'value_minor'     => 12345,
				'currency'        => 'usd',
				'occurred_at'     => '2026-09-07T13:00:00+03:00',
				'idempotency_key' => 'deal-1',
			),
			'webhook'
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'won', $result['status'] );
		$this->assertSame( 12345, $result['value_minor'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame( '2026-09-07 10:00:00', $result['occurred_at_utc'] );
	}

	public function test_rejects_float_money_unknown_currency_and_pii_shaped_submission_id() {
		$normalizer = new OutcomeNormalizer();
		$this->assertInstanceOf(
			'\WP_Error',
			$normalizer->normalize(
				array(
					'submission_id' => 'fh_7Kx29Abcdefghijklmnopq',
					'status'        => 'won',
					'value_minor'   => 12.34,
					'currency'      => 'USD',
				),
				'api'
			)
		);
		$this->assertInstanceOf(
			'\WP_Error',
			$normalizer->normalize(
				array(
					'submission_id' => 'fh_7Kx29Abcdefghijklmnopq',
					'status'        => 'won',
					'value_minor'   => 1234,
					'currency'      => 'ZZZ',
				),
				'api'
			)
		);
		$this->assertInstanceOf(
			'\WP_Error',
			$normalizer->normalize(
				array(
					'submission_id' => 'anton@example.test',
					'status'        => 'won',
				),
				'api'
			)
		);
	}

	public function test_currency_exponents_do_not_assume_every_currency_has_cents() {
		$this->assertSame( 123.45, Currency::major( 12345, 'USD' ) );
		$this->assertSame( 12345, Currency::major( 12345, 'JPY' ) );
		$this->assertSame( 12.345, Currency::major( 12345, 'KWD' ) );
	}
}
