<?php

namespace Formhawk\Outcomes;

final class OutcomeManager {
	private $normalizer;
	private $repository;

	public function __construct( OutcomeNormalizer $normalizer = null, OutcomeRepository $repository = null ) {
		$this->normalizer = $normalizer ? $normalizer : new OutcomeNormalizer();
		$this->repository = $repository ? $repository : new OutcomeRepository();
	}

	public function record( array $input, $source = 'manual' ) {
		$normalized = $this->normalizer->normalize( $input, $source );
		if ( is_wp_error( $normalized ) ) {
			return $normalized; }
		$result = $this->repository->record( $normalized );
		if ( ! is_wp_error( $result ) && ! $this->repository->was_duplicate() ) {
			do_action( 'formhawk_outcome_recorded', $result['public_id'], $normalized['status'], $normalized['value_minor'], $normalized['currency'], $normalized['source'] );
		}
		return is_wp_error( $result ) ? $result : array(
			'ok'            => true,
			'duplicate'     => $this->repository->was_duplicate(),
			'submission_id' => $result['public_id'],
			'status'        => $result['status'],
		);
	}

	public static function generate_submission_id() {
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of cryptographically random opaque bytes, not executable code.
			return 'fh_' . rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
		} catch ( \Exception $exception ) {
			// No weak identifier fallback: provider submission must continue without attribution.
			return '';
		}
	}
}
