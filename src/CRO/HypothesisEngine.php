<?php

namespace Formhawk\CRO;

final class HypothesisEngine {
	public function create( array $opportunity ) {
		$type  = isset( $opportunity['type'] ) ? $opportunity['type'] : '';
		$field = isset( $opportunity['field_label'] ) ? $opportunity['field_label'] : '';
		switch ( $type ) {
			case 'field_order':
				/* translators: %s: structural field label. */
				return sprintf( __( 'Moving %s later may reduce early abandonment without changing submitted data.', 'formhawk' ), $field );
			case 'progressive_disclosure':
				/* translators: %s: structural field label. */
				return sprintf( __( 'Collapsing the optional %s field may make the form feel shorter.', 'formhawk' ), $field );
			case 'multi_step':
				return __( 'Grouping a long form into accessible steps may improve completion while preserving provider submission semantics.', 'formhawk' );
			case 'submit_button':
			default:
				return __( 'A clearer submit call to action may increase completed submissions without changing form behavior.', 'formhawk' );
		}
	}
}
