<?php

namespace Formhawk\Outcomes;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\Infrastructure\Database;
use Formhawk\MinimumForm\MinimumFormRepository;

final class OutcomeAttribution {
	private $submissions;
	private $cro_context;
	private $forms;
	private $repository;

	public function __construct( SubmissionContext $submissions, RequestContext $cro_context, ?FormRepository $forms = null, ?OutcomeRepository $repository = null ) {
		$this->submissions = $submissions;
		$this->cro_context = $cro_context;
		$this->forms       = $forms ? $forms : new FormRepository();
		$this->repository  = $repository ? $repository : new OutcomeRepository();
	}

	public function attribute( $provider, $provider_form_id, $title, $page_path, array $context = array() ) {
		$public_id = $this->submissions->get();
		if ( ! $public_id ) {
			$public_id = OutcomeManager::generate_submission_id(); }
		if ( ! $public_id ) {
			return new \WP_Error( 'formhawk_secure_random_unavailable', __( 'Submission attribution is unavailable.', 'formhawk' ) );
		}
		$identity    = $this->forms->resolve(
			array(
				'provider'         => $provider,
				'provider_form_id' => $provider_form_id,
				'title'            => $title,
				'page_path'        => $page_path,
			)
		);
		$cro         = $this->cro_context->get( $provider, $provider_form_id );
		$baseline_id = Database::minimum_form_schema_is_current() ? ( new MinimumFormRepository() )->attributable_baseline_id( $identity['form_id'], $cro ? $cro : array() ) : 0;
		$submission  = $this->repository->create_submission(
			array(
				'public_id'                => $public_id,
				'form_id'                  => $identity['form_id'],
				'placement_id'             => $identity['placement_id'],
				'provider'                 => $provider,
				'provider_form_id'         => $provider_form_id,
				'provider_entry_id'        => isset( $context['provider_entry_id'] ) ? $context['provider_entry_id'] : '',
				'experiment_id'            => $cro ? $cro['experiment_id'] : 0,
				'variant_id'               => $cro ? $cro['variant_id'] : 0,
				'minimum_form_baseline_id' => $baseline_id,
				'device_class'             => $cro && isset( $cro['segment'] ) ? $cro['segment'] : 'unknown',
			),
			isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array()
		);
		if ( ! is_wp_error( $submission ) ) {
			do_action( 'formhawk_submission_attributed', $submission['public_id'], absint( $submission['form_id'] ), $provider );
		}
		return $submission;
	}
}
