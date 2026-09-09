<?php

namespace Formhawk\CRO\Attribution;

/** Bounded, advisory browser evidence. Sequence numbers identify attempts, not visitors. */
final class ClientEventLifecycle {
	const MAX_ATTEMPTS = 50;
	const ONCE         = array(
		'view'              => array( 'viewed', 'views' ),
		'start'             => array( 'started', 'starts' ),
		'client_validation' => array( 'client_validation', 'client_validation_failures' ),
		'abandon'           => array( 'abandoned', 'abandonments' ),
		'js_error'          => array( 'js_error', 'js_errors' ),
	);

	public static function transition( array $state, $type, $attempt, $latency, $successful ) {
		$changes    = array();
		$increments = array();
		if ( isset( self::ONCE[ $type ] ) ) {
			list( $flag, $counter ) = self::ONCE[ $type ];
			if ( ! empty( $state[ $flag ] ) ) {
				return array( 'result' => in_array( $type, array( 'view', 'start', 'js_error' ), true ) ? 'duplicate_' . $type : 'replayed_context_event' );
			}
			if ( in_array( $type, array( 'abandon', 'client_validation' ), true ) && empty( $state['started'] ) ) {
				return array( 'result' => 'invalid_context_lifecycle' );
			}
			if ( 'abandon' === $type && ( $state['attempts'] !== $state['terminal_attempt'] || ! empty( $state['last_success'] ) ) ) {
				return array( 'result' => 'invalid_context_lifecycle' );
			}
			$changes[ $flag ]       = 1;
			$increments[ $counter ] = 1;
		} elseif ( 'attempt' === $type ) {
			if ( $attempt <= $state['attempts'] ) {
				return array( 'result' => 'replayed_context_event' );
			}
			if ( empty( $state['started'] ) || $attempt !== $state['attempts'] + 1 || $attempt > self::MAX_ATTEMPTS || $state['terminal_attempt'] !== $state['attempts'] ) {
				return array( 'result' => 'invalid_context_lifecycle' );
			}
			$changes    = array(
				'attempts'     => $attempt,
				'last_success' => 0,
			);
			$increments = array( 'attempts' => 1 );
		} elseif ( in_array( $type, array( 'observed_submit', 'latency', 'resume' ), true ) ) {
			if ( $attempt < 1 || $attempt > $state['attempts'] ) {
				return array( 'result' => 'event_without_attempt' );
			}
			$flag = 'latency' === $type ? 'latency_attempt' : ( 'resume' === $type ? 'resumed_attempt' : 'observed_attempt' );
			if ( $attempt !== $state['attempts'] || $state[ $flag ] >= $attempt ) {
				return array( 'result' => 'replayed_context_event' );
			}
			$changes[ $flag ] = $attempt;
			if ( 'resume' === $type ) {
				if ( $state['terminal_attempt'] !== $attempt || empty( $state['last_success'] ) ) {
					return array( 'result' => 'invalid_context_lifecycle' );
				}
				$changes['last_success'] = 0;
			} elseif ( 'latency' === $type ) {
				$changes['terminal_attempt'] = $attempt;
				$changes['last_success']     = $successful ? 1 : 0;
				$increments                  = array(
					'latency_samples'  => 1,
					'latency_total_ms' => $latency,
				);
			} else {
				$changes['terminal_attempt'] = $attempt;
				$changes['last_success']     = 1;
				$increments                  = array( 'observed_submits' => 1 );
			}
		} else {
			return array( 'result' => 'invalid_context_lifecycle' );
		}
		return array(
			'result'     => 'accepted',
			'changes'    => $changes,
			'increments' => $increments,
		);
	}
}
