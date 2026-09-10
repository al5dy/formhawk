<?php

namespace Formhawk\MinimumForm;

use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\Database;

/** Read-only operational facts for the Minimum Form admin. */
final class MinimumFormDiagnostics {
	private $experiments;
	private $schemas;
	private $capabilities;

	public function __construct( ?ExperimentRepository $experiments = null, ?ProviderSchemaInspector $schemas = null, ?ProviderCapabilityMatrix $capabilities = null ) {
		$this->experiments  = $experiments ? $experiments : new ExperimentRepository();
		$this->schemas      = $schemas ? $schemas : new ProviderSchemaInspector();
		$this->capabilities = $capabilities ? $capabilities : new ProviderCapabilityMatrix();
	}

	public function for_form( array $form, array $run ) {
		$schema       = $this->schemas->inspect( $form );
		$experiment   = ! empty( $run['active_experiment_id'] ) ? $this->experiments->find( $run['active_experiment_id'] ) : null;
		$capabilities = $this->capabilities->for_provider( $form['provider'] );
		$schema_valid = $schema
			&& hash_equals( (string) $run['schema_fingerprint'], (string) $schema['schema_fingerprint'] )
			&& hash_equals( (string) $run['dependency_hash'], (string) $schema['dependency_hash'] );
		return array(
			'engine'               => Database::minimum_form_schema_is_current( true ) ? 'healthy' : 'unavailable',
			'provider'             => sanitize_key( $form['provider'] ),
			'capabilities'         => $capabilities,
			'current_baseline'     => absint( $run['current_baseline_id'] ),
			'active_experiment'    => $experiment ? absint( $experiment['id'] ) : 0,
			'last_evaluation'      => (string) ( $run['last_evaluated_at_utc'] ?? '' ),
			'outcome_coverage'     => $this->outcome_coverage( $form['id'] ),
			'experiment_integrity' => ! $experiment || empty( $experiment['integrity_warning'] ) ? 'valid' : 'review',
			'schema'               => $schema_valid ? 'valid' : 'changed',
			'background_job'       => wp_next_scheduled( MinimumFormManager::CRON_HOOK ) ? 'healthy' : 'not_scheduled',
		);
	}

	private function outcome_coverage( $form_id ) {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		$currency = is_array( $settings ) && isset( $settings['currency'] ) ? strtoupper( sanitize_text_field( $settings['currency'] ) ) : 'USD';
		$result   = ( new BusinessObjectiveResolver() )->resolve( $form_id, 'auto', $currency );
		return (float) $result['coverage'];
	}
}
