<?php

namespace Formhawk\Outcomes;

final class Currency {
	public static function exponent( $currency ) {
		$currency = strtoupper( (string) $currency );
		if ( in_array( $currency, array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true ) ) {
			return 0; }
		if ( in_array( $currency, array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ), true ) ) {
			return 3; }
		if ( in_array( $currency, array( 'CLF', 'UYW' ), true ) ) {
			return 4; }
		return 2;
	}

	public static function major( $minor, $currency ) {
		return $minor / pow( 10, self::exponent( $currency ) );
	}
}
