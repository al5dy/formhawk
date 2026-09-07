<?php

namespace Formhawk\CRO;

/** Deterministic Beta-Binomial analysis for two-arm conversion experiments. */
final class StatisticalEngine {
	const ALGORITHM_VERSION = 'beta-binomial-1.0';

	public function evaluate( array $control, array $variant, array $policy, $runtime_days ) {
		$c_views = max( 0, (int) ( $control['views'] ?? 0 ) );
		$v_views = max( 0, (int) ( $variant['views'] ?? 0 ) );
		$c_conv  = min( $c_views, max( 0, (int) ( $control['conversions'] ?? 0 ) ) );
		$v_conv  = min( $v_views, max( 0, (int) ( $variant['conversions'] ?? 0 ) ) );
		$alpha   = (float) $policy['prior_alpha'];
		$beta    = (float) $policy['prior_beta'];
		$c_a     = $c_conv + $alpha;
		$c_b     = $c_views - $c_conv + $beta;
		$v_a     = $v_conv + $alpha;
		$v_b     = $v_views - $v_conv + $beta;
		$c_mean  = $c_a / ( $c_a + $c_b );
		$v_mean  = $v_a / ( $v_a + $v_b );
		$prob    = $this->probability_greater( $v_a, $v_b, $c_a, $c_b );
		$loss    = $this->expected_loss( $c_mean, $this->beta_variance( $c_a, $c_b ), $v_mean, $this->beta_variance( $v_a, $v_b ) );
		$lift    = $c_mean > 0 ? ( $v_mean - $c_mean ) / $c_mean : null;
		$result  = array(
			'algorithm_version'      => self::ALGORITHM_VERSION,
			'control_rate'           => $c_mean,
			'variant_rate'           => $v_mean,
			'probability_to_be_best' => $prob,
			'expected_lift'          => $lift,
			'absolute_lift'          => $v_mean - $c_mean,
			'expected_loss'          => $loss,
			'control_interval'       => $this->credible_interval( $c_a, $c_b ),
			'variant_interval'       => $this->credible_interval( $v_a, $v_b ),
			'decision'               => 'collecting',
			'reason'                 => 'minimum_sample',
		);

		if ( $c_views < $policy['minimum_views_per_variant'] || $v_views < $policy['minimum_views_per_variant'] ) {
			return $result;
		}
		if ( ( $c_conv + $v_conv ) < $policy['minimum_conversions'] ) {
			$result['reason'] = 'minimum_conversions';
			return $result;
		}
		if ( (int) $runtime_days < $policy['minimum_runtime_days'] ) {
			$result['reason'] = 'minimum_runtime';
			return $result;
		}
		if ( $prob >= $policy['probability_to_be_best']
			&& $loss <= $policy['maximum_expected_loss']
			&& ( $v_mean - $c_mean ) >= $policy['minimum_absolute_effect'] ) {
			$result['decision'] = 'winner';
			$result['reason']   = 'credible_improvement';
			return $result;
		}
		if ( ( 1 - $prob ) >= $policy['probability_to_be_best'] && ( $c_mean - $v_mean ) >= $policy['minimum_absolute_effect'] ) {
			$result['decision'] = 'reject';
			$result['reason']   = 'credible_harm';
			return $result;
		}
		if ( (int) $runtime_days >= $policy['maximum_runtime_days'] ) {
			$result['decision'] = 'inconclusive';
			$result['reason']   = 'maximum_runtime';
		}

		return $result;
	}

	private function beta_variance( $a, $b ) {
		$sum = $a + $b;
		return ( $a * $b ) / ( $sum * $sum * ( $sum + 1 ) );
	}

	private function probability_greater( $a1, $b1, $a2, $b2 ) {
		$total = (float) $a1 + (float) $b1 + (float) $a2 + (float) $b2;
		if ( $total >= 400 ) {
			$mean = $a1 / ( $a1 + $b1 ) - $a2 / ( $a2 + $b2 );
			$sd   = sqrt( max( 1.0E-16, $this->beta_variance( $a1, $b1 ) + $this->beta_variance( $a2, $b2 ) ) );
			return $this->normal_cdf( $mean / $sd );
		}

		// Integrate f_variant(x) * CDF_control(x). Midpoints avoid the
		// Jeffreys-prior endpoint singularities. 4096 panels are deterministic.
		$panels = 4096;
		$sum    = 0.0;
		$log_b  = $this->log_beta( $a1, $b1 );
		for ( $i = 0; $i < $panels; ++$i ) {
			$x      = ( $i + 0.5 ) / $panels;
			$logpdf = ( $a1 - 1 ) * log( $x ) + ( $b1 - 1 ) * log( 1 - $x ) - $log_b;
			$sum   += exp( min( 700, $logpdf ) ) * $this->regularized_beta( $x, $a2, $b2 );
		}
		return max( 0.0, min( 1.0, $sum / $panels ) );
	}

	private function expected_loss( $control_mean, $control_variance, $variant_mean, $variant_variance ) {
		// The posterior difference is extremely close to normal once decisions
		// are eligible. This closed form is stable and conservative at low n.
		$mean = $control_mean - $variant_mean;
		$sd   = sqrt( max( 1.0E-16, $control_variance + $variant_variance ) );
		$z    = $mean / $sd;
		return max( 0.0, $sd * exp( -0.5 * $z * $z ) / sqrt( 2 * M_PI ) + $mean * $this->normal_cdf( $z ) );
	}

	private function credible_interval( $a, $b ) {
		return array(
			$this->beta_quantile( 0.025, $a, $b ),
			$this->beta_quantile( 0.975, $a, $b ),
		);
	}

	private function beta_quantile( $probability, $a, $b ) {
		$low  = 0.0;
		$high = 1.0;
		for ( $i = 0; $i < 70; ++$i ) {
			$mid = ( $low + $high ) / 2;
			if ( $this->regularized_beta( $mid, $a, $b ) < $probability ) {
				$low = $mid;
			} else {
				$high = $mid;
			}
		}
		return ( $low + $high ) / 2;
	}

	private function regularized_beta( $x, $a, $b ) {
		if ( $x <= 0 ) {
			return 0.0;
		}
		if ( $x >= 1 ) {
			return 1.0;
		}
		$front = exp( $a * log( $x ) + $b * log( 1 - $x ) - $this->log_beta( $a, $b ) );
		if ( $x < ( $a + 1 ) / ( $a + $b + 2 ) ) {
			return max( 0.0, min( 1.0, $front * $this->beta_fraction( $x, $a, $b ) / $a ) );
		}
		return max( 0.0, min( 1.0, 1 - $front * $this->beta_fraction( 1 - $x, $b, $a ) / $b ) );
	}

	private function beta_fraction( $x, $a, $b ) {
		$fpmin = 1.0E-30;
		$qab   = $a + $b;
		$qap   = $a + 1;
		$qam   = $a - 1;
		$c     = 1.0;
		$d     = 1 - $qab * $x / $qap;
		$d     = abs( $d ) < $fpmin ? $fpmin : $d;
		$d     = 1 / $d;
		$h     = $d;
		for ( $m = 1; $m <= 200; ++$m ) {
			$m2    = 2 * $m;
			$aa    = $m * ( $b - $m ) * $x / ( ( $qam + $m2 ) * ( $a + $m2 ) );
			$d     = 1 + $aa * $d;
			$d     = abs( $d ) < $fpmin ? $fpmin : $d;
			$c     = 1 + $aa / $c;
			$c     = abs( $c ) < $fpmin ? $fpmin : $c;
			$d     = 1 / $d;
			$h    *= $d * $c;
			$aa    = -( $a + $m ) * ( $qab + $m ) * $x / ( ( $a + $m2 ) * ( $qap + $m2 ) );
			$d     = 1 + $aa * $d;
			$d     = abs( $d ) < $fpmin ? $fpmin : $d;
			$c     = 1 + $aa / $c;
			$c     = abs( $c ) < $fpmin ? $fpmin : $c;
			$d     = 1 / $d;
			$delta = $d * $c;
			$h    *= $delta;
			if ( abs( $delta - 1 ) < 3.0E-14 ) {
				break;
			}
		}
		return $h;
	}

	private function log_beta( $a, $b ) {
		return $this->log_gamma( $a ) + $this->log_gamma( $b ) - $this->log_gamma( $a + $b );
	}

	private function log_gamma( $z ) {
		$coefficients = array( 676.5203681218851, -1259.1392167224028, 771.3234287776531, -176.6150291621406, 12.507343278686905, -0.13857109526572012, 9.984369578019572E-6, 1.5056327351493116E-7 );
		if ( $z < 0.5 ) {
			return log( M_PI ) - log( sin( M_PI * $z ) ) - $this->log_gamma( 1 - $z );
		}
		--$z;
		$x = 0.9999999999998099;
		foreach ( $coefficients as $index => $coefficient ) {
			$x += $coefficient / ( $z + $index + 1 );
		}
		$t = $z + count( $coefficients ) - 0.5;
		return 0.5 * log( 2 * M_PI ) + ( $z + 0.5 ) * log( $t ) - $t + log( $x );
	}

	private function normal_cdf( $x ) {
		// Abramowitz-Stegun 7.1.26; maximum absolute error < 7.5e-8.
		$sign = $x < 0 ? -1 : 1;
		$x    = abs( $x ) / sqrt( 2 );
		$t    = 1 / ( 1 + 0.3275911 * $x );
		$erf  = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );
		return 0.5 * ( 1 + $sign * $erf );
	}
}
