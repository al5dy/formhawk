<?php

namespace Formhawk\MinimumForm;

/** Immutable value-free dependency projection used by candidate safety checks. */
final class FieldDependencyGraph {
	private $nodes = array();

	public function __construct( array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['normalized_key'] ?? $field['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$this->nodes[ $key ] = array(
				'depends_on'             => $this->keys( $field['depends_on'] ?? array() ),
				'controls_visibility_of' => $this->keys( $field['controls_visibility_of'] ?? array() ),
				'unknown'                => ! empty( $field['dependency_unknown'] ),
			);
		}
	}

	public function can_remove( $field_key ) {
		$field_key = sanitize_key( (string) $field_key );
		if ( ! isset( $this->nodes[ $field_key ] ) ) {
			return array(
				'safe'   => false,
				'reason' => 'field_not_in_schema',
			);
		}
		if ( $this->nodes[ $field_key ]['unknown'] ) {
			return array(
				'safe'   => false,
				'reason' => 'dependency_unknown',
			);
		}
		if ( $this->nodes[ $field_key ]['depends_on'] || $this->nodes[ $field_key ]['controls_visibility_of'] ) {
			return array(
				'safe'   => false,
				'reason' => 'direct_dependency',
			);
		}
		foreach ( $this->nodes as $key => $node ) {
			if ( $node['unknown'] || in_array( $field_key, $node['depends_on'], true ) || in_array( $field_key, $node['controls_visibility_of'], true ) ) {
				return array(
					'safe'   => false,
					'reason' => $node['unknown'] ? 'dependency_unknown' : 'referenced_by_' . $key,
				);
			}
		}
		return array(
			'safe'   => true,
			'reason' => 'independent',
		);
	}

	public function hash() {
		$nodes = $this->nodes;
		ksort( $nodes );
		return hash( 'sha256', (string) wp_json_encode( $nodes ) );
	}

	public function nodes() {
		return $this->nodes;
	}

	private function keys( $values ) {
		$output = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$key = sanitize_key( (string) $value );
			if ( '' !== $key && ! in_array( $key, $output, true ) ) {
				$output[] = $key;
			}
		}
		sort( $output );
		return $output;
	}
}
