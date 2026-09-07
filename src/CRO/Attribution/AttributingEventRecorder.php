<?php

namespace Formhawk\CRO\Attribution;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Outcomes\OutcomeAttribution;

final class AttributingEventRecorder implements EventRecorderInterface {
	private $inner;
	private $contexts;
	private $experiments;
	private $outcomes;

	public function __construct( EventRecorderInterface $inner, RequestContext $contexts, ExperimentRepository $experiments = null, OutcomeAttribution $outcomes = null ) {
		$this->inner       = $inner;
		$this->contexts    = $contexts;
		$this->experiments = $experiments ? $experiments : new ExperimentRepository();
		$this->outcomes    = $outcomes;
	}

	public function record_success( $provider, $provider_form_id, $title, $page_path, array $context = array() ) {
		$result = $this->inner->record_success( $provider, $provider_form_id, $title, $page_path, $context );
		if ( $result ) {
			$this->record( $provider, $provider_form_id, array( 'confirmed_successes' => 1 ) );
			if ( $this->outcomes ) {
				$this->outcomes->attribute( $provider, $provider_form_id, $title, $page_path, $context );
			}
		}
		return $result;
	}

	public function record_failure( $provider, $provider_form_id, $title, $page_path, $code ) {
		$result = $this->inner->record_failure( $provider, $provider_form_id, $title, $page_path, $code );
		if ( $result ) {
			$this->record( $provider, $provider_form_id, array( 'provider_failures' => 1 ) );
		}
		return $result;
	}

	public function record_validation_failure( $provider, $provider_form_id, $title, $page_path, array $fields = array() ) {
		$result = $this->inner->record_validation_failure( $provider, $provider_form_id, $title, $page_path, $fields );
		if ( $result ) {
			$this->record( $provider, $provider_form_id, array( 'provider_validation_failures' => 1 ) );
		}
		return $result;
	}

	public function record_mail_success( $provider, $provider_form_id, $title, $page_path ) {
		return $this->inner->record_mail_success( $provider, $provider_form_id, $title, $page_path );
	}

	public function record_mail_failure( $provider, $provider_form_id, $title, $page_path ) {
		$result = $this->inner->record_mail_failure( $provider, $provider_form_id, $title, $page_path );
		if ( $result ) {
			$this->record( $provider, $provider_form_id, array( 'mail_failures' => 1 ) );
		}
		return $result;
	}

	private function record( $provider, $provider_form_id, array $increments ) {
		$context = $this->contexts->get( $provider, $provider_form_id );
		if ( ! $context ) {
			return;
		}
		$experiment = $this->experiments->find( $context['experiment_id'] );
		if ( ! $experiment || absint( $experiment['form_id'] ) !== absint( $context['form_id'] ) || ! in_array( $experiment['status'], array( 'running', 'promoted_monitoring' ), true ) ) {
			return;
		}
		$valid_variant = false;
		foreach ( $this->experiments->variants( $context['experiment_id'] ) as $variant ) {
			if ( absint( $variant['id'] ) === absint( $context['variant_id'] ) && 'active' === $variant['status'] ) {
				$valid_variant = true;
				break;
			}
		}
		if ( ! $valid_variant ) {
			return;
		}
		$this->experiments->increment( $context['experiment_id'], $context['variant_id'], $context['segment'], $increments );
	}
}
