<?php

namespace Formhawk\Integrations;

use Formhawk\Contracts\FormIntegrationInterface;

final class IntegrationRegistry {
	private $integrations = array();

	public function __construct( array $integrations ) {
		foreach ( $integrations as $integration ) {
			if ( $integration instanceof FormIntegrationInterface ) {
				$this->integrations[ $integration->id() ] = $integration;
			}
		}
	}

	public function register() {
		foreach ( $this->integrations as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}
	}

	public function get( $provider ) {
		return isset( $this->integrations[ $provider ] ) ? $this->integrations[ $provider ] : null;
	}

	public function all() {
		return $this->integrations;
	}

	public function label( $provider ) {
		$integration = $this->get( $provider );
		return $integration ? $integration->label() : (string) $provider;
	}

	public function has_capability( $provider, $capability ) {
		$integration = $this->get( $provider );
		return $integration && in_array( $capability, $integration->capabilities(), true );
	}
}
