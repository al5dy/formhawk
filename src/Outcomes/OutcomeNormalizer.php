<?php

namespace Formhawk\Outcomes;

use Formhawk\Support\Sanitizer;

final class OutcomeNormalizer {
	const MAX_ABS_VALUE_MINOR = 9000000000000000;

	public function normalize( array $input, $source ) {
		$submission_id = isset( $input['submission_id'] ) && is_scalar( $input['submission_id'] ) ? (string) $input['submission_id'] : '';
		$status        = isset( $input['status'] ) && is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		$currency      = isset( $input['currency'] ) && is_scalar( $input['currency'] ) ? strtoupper( sanitize_text_field( (string) $input['currency'] ) ) : '';
		$value         = array_key_exists( 'value_minor', $input ) ? $input['value_minor'] : null;

		$statuses = apply_filters( 'formhawk_outcome_statuses', OutcomeStatus::all() );
		$statuses = is_array( $statuses ) ? array_values(
			array_filter(
				array_map( 'sanitize_key', $statuses ),
				static function ( $candidate ) {
					return '' !== $candidate && strlen( $candidate ) <= 24;
				}
			)
		) : OutcomeStatus::all();
		if ( ! preg_match( '/^fh_[A-Za-z0-9_-]{22,43}$/', $submission_id ) || ! in_array( $status, $statuses, true ) ) {
			return new \WP_Error( 'formhawk_invalid_outcome', __( 'Invalid submission ID or outcome status.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( null !== $value && ! is_int( $value ) ) {
			return new \WP_Error( 'formhawk_invalid_value', __( 'Monetary value must use integer minor units.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( null !== $value && abs( $value ) > self::MAX_ABS_VALUE_MINOR ) {
			return new \WP_Error( 'formhawk_invalid_value', __( 'Monetary value is outside the supported range.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( null !== $value && ! self::valid_currency( $currency ) ) {
			return new \WP_Error( 'formhawk_invalid_currency', __( 'A valid ISO 4217 currency is required with monetary value.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( null === $value ) {
			$currency = '';
		}
		if ( OutcomeStatus::VALUE_ADJUSTMENT === $status && null === $value ) {
			return new \WP_Error( 'formhawk_invalid_adjustment', __( 'A value adjustment requires value_minor and currency.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( null !== $value && ! in_array( $status, array( OutcomeStatus::WON, OutcomeStatus::VALUE_ADJUSTMENT ), true ) ) {
			return new \WP_Error( 'formhawk_invalid_value_status', __( 'Monetary value is accepted only for won outcomes and value adjustments.', 'formhawk' ), array( 'status' => 400 ) );
		}

		$occurred     = isset( $input['occurred_at'] ) && is_scalar( $input['occurred_at'] ) ? sanitize_text_field( (string) $input['occurred_at'] ) : '';
		$occurred_utc = $this->utc_datetime( $occurred );
		if ( '' !== $occurred && ! $occurred_utc ) {
			return new \WP_Error( 'formhawk_invalid_time', __( 'occurred_at must be a valid ISO 8601 date-time.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( $occurred_utc && strtotime( $occurred_utc . ' UTC' ) > time() + 5 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'formhawk_invalid_time', __( 'occurred_at cannot be in the future.', 'formhawk' ), array( 'status' => 400 ) );
		}

		$external    = isset( $input['external_reference'] ) && is_scalar( $input['external_reference'] ) ? sanitize_text_field( (string) $input['external_reference'] ) : '';
		$idempotency = isset( $input['idempotency_key'] ) && is_scalar( $input['idempotency_key'] ) ? sanitize_text_field( (string) $input['idempotency_key'] ) : '';
		$source      = Sanitizer::identifier( $source, 'unknown' );
		if ( strlen( $external ) > 191 || strlen( $idempotency ) > 191 ) {
			return new \WP_Error( 'formhawk_invalid_outcome', __( 'Outcome reference is too long.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( '' === $idempotency ) {
			$idempotency = implode( '|', array( $submission_id, $status, null === $value ? 'null' : (string) $value, $currency, $occurred_utc, $external ) );
		}

		return array(
			'submission_id'           => $submission_id,
			'status'                  => $status,
			'value_minor'             => $value,
			'currency'                => $currency,
			'occurred_at_utc'         => $occurred_utc ? $occurred_utc : current_time( 'mysql', true ),
			'external_reference_hash' => '' === $external ? null : hash_hmac( 'sha256', $external, wp_salt( 'auth' ) ),
			'idempotency_hash'        => hash( 'sha256', $idempotency ),
			'source'                  => $source,
		);
	}

	private function utc_datetime( $value ) {
		if ( '' === $value ) {
			return ''; }
		try {
			$date = new \DateTimeImmutable( $value );
			return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	public static function valid_currency( $currency ) {
		$codes = array( 'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BOV', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHE', 'CHF', 'CHW', 'CLF', 'CLP', 'CNY', 'COP', 'COU', 'CRC', 'CUC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MXV', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'USN', 'UYI', 'UYU', 'UYW', 'UZS', 'VED', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XAG', 'XAU', 'XBA', 'XBB', 'XBC', 'XBD', 'XCD', 'XDR', 'XOF', 'XPD', 'XPF', 'XPT', 'XSU', 'XTS', 'XUA', 'XXX', 'YER', 'ZAR', 'ZMW', 'ZWL' );
		return is_string( $currency ) && in_array( strtoupper( $currency ), $codes, true );
	}
}
