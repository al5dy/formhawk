<?php

namespace Formhawk\CRO;

final class HypothesisEngine {
	public function create( array $opportunity ) {
		$type  = isset( $opportunity['type'] ) ? $opportunity['type'] : '';
		$field = isset( $opportunity['field_label'] ) ? $opportunity['field_label'] : '';
		switch ( $type ) {
			case 'remove_field':
				if ( isset( $opportunity['business_optimized'] ) && ! $opportunity['business_optimized'] ) {
					/* translators: %s: structural field label. */
					return sprintf( __( '%s creates measurable friction. Removing it may improve provider-confirmed conversion.', 'formhawk' ), $field );
				}
				/* translators: %s: structural field label. */
				return sprintf( __( '%s creates measurable friction without proven business-value improvement. Removing it may increase business value per visitor.', 'formhawk' ), $field );
			case 'make_optional':
				/* translators: %s: structural field label. */
				return sprintf( __( 'Making %s optional may reduce friction while preserving the field for visitors who choose to provide it.', 'formhawk' ), $field );
			case 'make_required':
				/* translators: %s: structural field label. */
				return sprintf( __( 'Requiring %s may improve lead quality enough to offset its conversion cost.', 'formhawk' ), $field );
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
