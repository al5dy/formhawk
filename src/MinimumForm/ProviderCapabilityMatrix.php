<?php

namespace Formhawk\MinimumForm;

use Formhawk\Domain\ProviderCatalog;
use Formhawk\Integrations\IntegrationRegistry;

final class ProviderCapabilityMatrix {
	const REMOVE_FIELD           = 'remove_field';
	const MAKE_OPTIONAL          = 'make_optional';
	const MAKE_REQUIRED          = 'make_required';
	const FIELD_ORDER            = 'field_order';
	const PROGRESSIVE_DISCLOSURE = 'progressive_disclosure';
	const MULTI_STEP             = 'multi_step';
	const CTA                    = 'cta';
	const CONFIRMED_SUCCESS      = 'confirmed_success';
	const OUTCOME_ATTRIBUTION    = 'outcome_attribution';
	const DEPENDENCY_GRAPH       = 'dependency_graph';

	private $registry;

	public function __construct( ?IntegrationRegistry $registry = null ) {
		$this->registry = $registry;
	}

	public function for_provider( $provider ) {
		$map    = array(
			self::REMOVE_FIELD           => ProviderCatalog::CAP_REMOVE_FIELD,
			self::MAKE_OPTIONAL          => ProviderCatalog::CAP_MAKE_OPTIONAL,
			self::MAKE_REQUIRED          => ProviderCatalog::CAP_MAKE_REQUIRED,
			self::FIELD_ORDER            => ProviderCatalog::CAP_FIELD_ORDER,
			self::PROGRESSIVE_DISCLOSURE => ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE,
			self::MULTI_STEP             => ProviderCatalog::CAP_MULTI_STEP,
			self::CTA                    => ProviderCatalog::CAP_FRONTEND_TRACKING,
			self::CONFIRMED_SUCCESS      => ProviderCatalog::CAP_SERVER_SUCCESS,
			self::OUTCOME_ATTRIBUTION    => ProviderCatalog::CAP_OUTCOME_ATTRIBUTION,
			self::DEPENDENCY_GRAPH       => ProviderCatalog::CAP_DEPENDENCY_GRAPH,
		);
		$output = array();
		foreach ( $map as $name => $capability ) {
			$output[ $name ] = $this->supports( $provider, $capability );
		}
		/**
		 * @param array  $output   Provider capability facts.
		 * @param string $provider Provider identifier.
		 */
		$filtered = apply_filters( 'formhawk_minimum_form_provider_capabilities', $output, $provider );
		return is_array( $filtered ) ? array_merge( $output, array_intersect_key( $filtered, $output ) ) : $output;
	}

	public function supports_mutation( $provider, $mutation ) {
		$capabilities = $this->for_provider( $provider );
		return ! empty( $capabilities[ $mutation ] );
	}

	private function supports( $provider, $capability ) {
		if ( $this->registry ) {
			return $this->registry->has_capability( $provider, $capability );
		}
		$fallback = array(
			ProviderCatalog::CF7       => array( ProviderCatalog::CAP_REMOVE_FIELD, ProviderCatalog::CAP_FIELD_ORDER, ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE, ProviderCatalog::CAP_DEPENDENCY_GRAPH, ProviderCatalog::CAP_SERVER_SUCCESS, ProviderCatalog::CAP_OUTCOME_ATTRIBUTION ),
			ProviderCatalog::WPFORMS   => array( ProviderCatalog::CAP_REMOVE_FIELD, ProviderCatalog::CAP_FIELD_ORDER, ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE, ProviderCatalog::CAP_DEPENDENCY_GRAPH, ProviderCatalog::CAP_SERVER_SUCCESS, ProviderCatalog::CAP_OUTCOME_ATTRIBUTION, ProviderCatalog::CAP_MULTI_STEP ),
			ProviderCatalog::ELEMENTOR => array( ProviderCatalog::CAP_REMOVE_FIELD, ProviderCatalog::CAP_FIELD_ORDER, ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE, ProviderCatalog::CAP_SERVER_SUCCESS, ProviderCatalog::CAP_OUTCOME_ATTRIBUTION, ProviderCatalog::CAP_MULTI_STEP ),
			ProviderCatalog::GENERIC   => array( ProviderCatalog::CAP_REMOVE_FIELD, ProviderCatalog::CAP_FIELD_ORDER, ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE ),
		);
		return isset( $fallback[ $provider ] ) && in_array( $capability, $fallback[ $provider ], true );
	}
}
