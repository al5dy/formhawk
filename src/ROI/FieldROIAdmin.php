<?php

namespace Formhawk\ROI;

use Formhawk\Outcomes\ApiKeyRepository;
use Formhawk\Outcomes\Currency;
use Formhawk\Outcomes\OutcomeManager;
use Formhawk\Outcomes\OutcomeNormalizer;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Outcomes\OutcomeStatus;

final class FieldROIAdmin {
	private $roi;
	private $outcomes;
	private $keys;

	public function __construct( FieldROIRepository $roi = null, OutcomeRepository $outcomes = null, ApiKeyRepository $keys = null ) {
		$this->roi      = $roi ? $roi : new FieldROIRepository();
		$this->outcomes = $outcomes ? $outcomes : new OutcomeRepository();
		$this->keys     = $keys ? $keys : new ApiKeyRepository();
	}

	public function register() {
		// Parent Formhawk menu is registered at the default priority.
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_formhawk_field_roi_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_formhawk_record_manual_outcome', array( $this, 'record_manual' ) );
		add_action( 'admin_post_formhawk_create_outcome_key', array( $this, 'create_key' ) );
		add_action( 'admin_post_formhawk_revoke_outcome_key', array( $this, 'revoke_key' ) );
	}

	public function menu() {
		add_submenu_page( 'formhawk', __( 'Field ROI', 'formhawk' ), __( 'Field ROI', 'formhawk' ), 'manage_options', 'formhawk-field-roi', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( 'formhawk_page_formhawk-field-roi' === $hook ) {
			wp_enqueue_style( 'formhawk-field-roi', FORMHAWK_URL . 'assets/css/field-roi.css', array(), FORMHAWK_VERSION );
		}
	}

	public function render() {
		$this->authorize();
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$view     = $this->query_key( 'view', 'dashboard' );
		$field_id = absint( $this->query_key( 'field_id', 0 ) );
		$this->notice();
		?>
		<div class="wrap fh-roi-wrap">
			<div class="fh-roi-hero">
				<div><span class="fh-roi-kicker"><?php esc_html_e( 'BUSINESS VALUE INTELLIGENCE', 'formhawk' ); ?></span><h1><?php esc_html_e( 'Field ROI', 'formhawk' ); ?></h1><p><?php esc_html_e( 'Optimize business value per visitor — not vanity conversion alone.', 'formhawk' ); ?></p></div>
				<div class="fh-roi-freshness"><span><?php esc_html_e( 'Last evaluated', 'formhawk' ); ?></span><strong><?php echo esc_html( $this->last_evaluated() ); ?></strong></div>
			</div>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Field ROI sections', 'formhawk' ); ?>">
				<?php $this->tab( 'dashboard', __( 'Value map', 'formhawk' ), $view ); ?>
				<?php $this->tab( 'outcomes', __( 'Outcomes', 'formhawk' ), $view ); ?>
				<?php $this->tab( 'integrations', __( 'Outcome API', 'formhawk' ), $view ); ?>
				<?php $this->tab( 'settings', __( 'Business value', 'formhawk' ), $view ); ?>
			</nav>
			<?php
			if ( empty( $settings['enabled'] ) ) {
				$this->onboarding( $settings );
			} elseif ( $field_id ) {
				$this->detail( $field_id );
			} elseif ( 'outcomes' === $view ) {
				$this->outcomes();
			} elseif ( 'integrations' === $view ) {
				$this->integrations();
			} elseif ( 'settings' === $view ) {
				$this->settings( $settings );
			} else {
				$this->dashboard();
			}
			?>
		</div>
		<?php
	}

	public function save_settings() {
		$this->authorize();
		check_admin_referer( 'formhawk_field_roi_settings' );
		$objective       = $this->post_key( 'objective', 'business_value' );
		$outcome_source  = $this->post_key( 'outcome_source', 'manual' );
		$currency        = strtoupper( $this->post_key( 'currency', 'USD' ) );
		$window          = absint( $this->post_key( 'attribution_window', 90 ) );
		$maturity        = absint( $this->post_key( 'maturity_days', 14 ) );
		$qualified_value = max( 0, (int) $this->post_key( 'qualified_value_minor', 0 ) );
		$won_value       = max( 0, (int) $this->post_key( 'won_value_minor', 0 ) );
		if ( ! in_array( $objective, array( 'qualified', 'won', 'business_value' ), true ) ) {
			$objective = 'business_value';
		}
		if ( ! in_array( $outcome_source, array( 'manual', 'outcome_api', 'developer_api' ), true ) ) {
			$outcome_source = 'manual';
		}
		if ( ! OutcomeNormalizer::valid_currency( $currency ) ) {
			$currency = 'USD';
		}
		if ( ! in_array( $window, array( 30, 60, 90, 180 ), true ) ) {
			$window = 90;
		}
		update_option(
			'formhawk_field_roi_settings',
			array(
				'enabled'               => 1,
				'objective'             => $objective,
				'outcome_source'        => $outcome_source,
				'currency'              => $currency,
				'attribution_window'    => $window,
				'maturity_days'         => max( 1, min( 90, $maturity ) ),
				'qualified_value_minor' => min( OutcomeNormalizer::MAX_ABS_VALUE_MINOR, $qualified_value ),
				'won_value_minor'       => min( OutcomeNormalizer::MAX_ABS_VALUE_MINOR, $won_value ),
			),
			false
		);
		update_option( 'formhawk_field_roi_dirty', current_time( 'mysql', true ), false );
		$this->redirect_notice( 'success', __( 'Business value settings saved. Field ROI is collecting outcomes.', 'formhawk' ), 'settings' );
	}

	public function record_manual() {
		$this->authorize();
		check_admin_referer( 'formhawk_record_manual_outcome' );
		$value_raw = $this->post_key( 'value_minor', '' );
		$value     = '' === $value_raw ? null : filter_var( $value_raw, FILTER_VALIDATE_INT );
		if ( false === $value ) {
			$this->redirect_notice( 'error', __( 'Value must be entered in integer minor units.', 'formhawk' ), 'outcomes' );
		}
		$result = ( new OutcomeManager() )->record(
			array(
				'submission_id'   => $this->post_key( 'submission_id', '' ),
				'status'          => $this->post_key( 'status', '' ),
				'value_minor'     => $value,
				'currency'        => strtoupper( $this->post_key( 'currency', '' ) ),
				'idempotency_key' => 'manual|' . get_current_user_id() . '|' . wp_generate_uuid4(),
			),
			'manual'
		);
		$this->redirect_notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Outcome recorded without storing lead values.', 'formhawk' ), 'outcomes' );
	}

	public function create_key() {
		$this->authorize();
		check_admin_referer( 'formhawk_create_outcome_key' );
		$result = $this->keys->create( $this->post_key( 'name', __( 'CRM integration', 'formhawk' ) ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( 'error', $result->get_error_message(), 'integrations' );
		}
		$this->render_new_key( $result );
	}

	public function revoke_key() {
		$this->authorize();
		check_admin_referer( 'formhawk_revoke_outcome_key' );
		$revoked = $this->keys->revoke( absint( $this->post_key( 'key_id', 0 ) ) );
		$this->redirect_notice( $revoked ? 'success' : 'error', $revoked ? __( 'API key revoked.', 'formhawk' ) : __( 'API key could not be revoked.', 'formhawk' ), 'integrations' );
	}

	private function dashboard() {
		$rows = $this->roi->results();
		if ( ! $rows ) {
			$this->collecting();
			return;
		}
		$protected  = 0;
		$lost       = 0;
		$killers    = 0;
		$qualifiers = 0;
		$has_money  = false;
		foreach ( $rows as $row ) {
			$value = isset( $row['metrics']['expected_value_contribution_minor'] ) ? $row['metrics']['expected_value_contribution_minor'] : null;
			if ( null !== $value && ! empty( $row['metrics']['revenue']['revenue_samples'] ) ) {
				$has_money  = true;
				$protected += max( 0, $value );
				$lost      += abs( min( 0, $value ) );
			}
			$killers    += 'conversion_killer' === $row['verdict'] ? 1 : 0;
			$qualifiers += in_array( $row['verdict'], array( 'money_maker', 'qualifier' ), true ) ? 1 : 0;
		}
		?>
		<div class="fh-roi-summary">
			<?php $this->summary_card( __( 'Estimated value protected', 'formhawk' ), $has_money ? $this->money( $protected, $rows[0]['currency'] ) : __( 'Needs revenue data', 'formhawk' ), 'protected' ); ?>
			<?php $this->summary_card( __( 'Potential value lost', 'formhawk' ), $has_money ? $this->money( $lost, $rows[0]['currency'] ) : __( 'Needs revenue data', 'formhawk' ), 'lost' ); ?>
			<?php $this->summary_card( __( 'Conversion killers', 'formhawk' ), $killers, 'killer' ); ?>
			<?php $this->summary_card( __( 'High-value qualifiers', 'formhawk' ), $qualifiers, 'qualifier' ); ?>
		</div>
		<?php $this->value_map( $rows ); ?>
		<div class="fh-roi-card">
			<div class="fh-roi-card-head"><div><h2><?php esc_html_e( 'Field opportunities', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Ranked by potential absolute EVPV impact, then deterministic business-value score. Experimental and observational evidence are never mixed.', 'formhawk' ); ?></p></div></div>
			<table class="widefat striped fh-roi-table">
				<thead><tr><th><?php esc_html_e( 'Field', 'formhawk' ); ?></th><th><?php esc_html_e( 'Form', 'formhawk' ); ?></th><th><?php esc_html_e( 'Friction', 'formhawk' ); ?></th><th><?php esc_html_e( 'Submission impact', 'formhawk' ); ?></th><th><?php esc_html_e( 'Qualified impact', 'formhawk' ); ?></th><th><?php esc_html_e( 'Revenue / visitor', 'formhawk' ); ?></th><th><?php esc_html_e( 'Evidence', 'formhawk' ); ?></th><th><?php esc_html_e( 'Verdict', 'formhawk' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $row ) :
					$metrics = $row['metrics'];
					?>
					<tr>
						<td><a class="fh-roi-field" href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'     => 'formhawk-field-roi',
									'field_id' => $row['field_definition_id'],
								),
								admin_url( 'admin.php' )
							)
						);
						?>
															"><?php echo esc_html( $row['label'] ? $row['label'] : $row['normalized_key'] ); ?></a><small><?php echo esc_html( strtoupper( $row['field_type'] ) ); ?></small></td>
						<td><?php echo esc_html( $row['form_title'] ); ?></td>
						<td><?php echo esc_html( $this->percent( isset( $metrics['friction_score'] ) ? $metrics['friction_score'] : null, false ) ); ?></td>
						<td><?php echo esc_html( $this->percent( isset( $metrics['submission_impact'] ) ? $metrics['submission_impact'] : null ) ); ?></td>
						<td><?php echo esc_html( $this->percent( isset( $metrics['qualified_impact'] ) ? $metrics['qualified_impact'] : null ) ); ?></td>
						<td><?php echo esc_html( isset( $metrics['revenue']['impact_rpv_minor'] ) && $metrics['revenue']['revenue_samples'] ? $this->money( $metrics['revenue']['impact_rpv_minor'], $row['currency'] ) : '—' ); ?></td>
						<td><?php $this->evidence_badge( $row['evidence_level'], $row['confidence'] ); ?></td>
						<td><?php $this->verdict_badge( $row['verdict'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function value_map( array $rows ) {
		?>
		<section class="fh-roi-card fh-value-map" aria-labelledby="fh-value-map-title">
			<div class="fh-roi-card-head"><div><h2 id="fh-value-map-title"><?php esc_html_e( 'Field Value Map', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Horizontal: measured friction. Vertical: business-value impact. Unknown fields remain centered until valid outcome evidence exists.', 'formhawk' ); ?></p></div></div>
			<div class="fh-map-plot" role="img" aria-label="<?php esc_attr_e( 'Map of field friction and business value', 'formhawk' ); ?>">
				<span class="fh-map-y high"><?php esc_html_e( 'HIGH BUSINESS VALUE', 'formhawk' ); ?></span><span class="fh-map-y low"><?php esc_html_e( 'LOW BUSINESS VALUE', 'formhawk' ); ?></span>
				<span class="fh-map-x low"><?php esc_html_e( 'LOW FRICTION', 'formhawk' ); ?></span><span class="fh-map-x high"><?php esc_html_e( 'HIGH FRICTION', 'formhawk' ); ?></span>
				<span class="fh-quadrant q1"><?php esc_html_e( 'QUALIFIERS', 'formhawk' ); ?></span><span class="fh-quadrant q2"><?php esc_html_e( 'MONEY MAKERS', 'formhawk' ); ?></span><span class="fh-quadrant q3"><?php esc_html_e( 'NEUTRAL', 'formhawk' ); ?></span><span class="fh-quadrant q4"><?php esc_html_e( 'CONVERSION KILLERS', 'formhawk' ); ?></span>
				<?php
				foreach ( $rows as $row ) :
					$metrics  = $row['metrics'];
					$x        = max( 5, min( 95, 5 + 90 * (float) $metrics['friction_score'] ) );
					$business = isset( $metrics['revenue_impact'] ) && null !== $metrics['revenue_impact'] ? $metrics['revenue_impact'] : ( isset( $metrics['qualified_impact'] ) ? $metrics['qualified_impact'] : 0 );
					$y        = max( 8, min( 92, 50 - 42 * max( -1, min( 1, (float) $business ) ) ) );
					?>
					<a class="fh-map-point is-<?php echo esc_attr( $row['verdict'] ); ?>" style="left:<?php echo esc_attr( (string) $x ); ?>%;top:<?php echo esc_attr( (string) $y ); ?>%" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'     => 'formhawk-field-roi',
								'field_id' => $row['field_definition_id'],
							),
							admin_url( 'admin.php' )
						)
					);
					?>
												" aria-label="<?php echo esc_attr( ( $row['label'] ? $row['label'] : $row['normalized_key'] ) . ': ' . $this->label( $row['verdict'] ) ); ?>"><span><?php echo esc_html( $row['label'] ? $row['label'] : $row['normalized_key'] ); ?></span></a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function detail( $field_id ) {
		$row = $this->roi->result( $field_id );
		if ( ! $row ) {
			$this->collecting();
			return;
		}
		$metrics = $row['metrics'];
		$history = $this->roi->history( $field_id );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=formhawk-field-roi' ) ); ?>">← <?php esc_html_e( 'Back to Field Value Map', 'formhawk' ); ?></a></p>
		<div class="fh-roi-detail-head"><div><span class="fh-roi-kicker"><?php echo esc_html( $row['form_title'] ); ?></span><h2><?php echo esc_html( $row['label'] ? $row['label'] : $row['normalized_key'] ); ?></h2><?php $this->verdict_badge( $row['verdict'] ); ?></div><div class="fh-score"><strong><?php echo esc_html( $row['score'] ); ?></strong><span><?php esc_html_e( '/ 100 Business Value Score', 'formhawk' ); ?></span></div></div>
		<div class="fh-roi-detail-grid">
			<section class="fh-roi-card"><h3><?php esc_html_e( 'Funnel impact', 'formhawk' ); ?></h3><?php $this->detail_metric( __( 'Interacted', 'formhawk' ), number_format_i18n( $metrics['interactions'] ) ); ?><?php $this->detail_metric( __( 'Interaction / start rate', 'formhawk' ), $this->percent( $metrics['field_interaction_rate'], false ) ); ?><?php $this->detail_metric( __( 'Field reach rate', 'formhawk' ), $this->percent( $metrics['field_reach_rate'], false ) ); ?><?php $this->detail_metric( __( 'Completion rate', 'formhawk' ), $this->percent( $metrics['field_completion_rate'], false ) ); ?><?php $this->detail_metric( __( 'Correction rate', 'formhawk' ), $this->percent( $metrics['correction_rate'], false ) ); ?><?php $this->detail_metric( __( 'Validation failure rate', 'formhawk' ), $this->percent( $metrics['validation_failure_rate'], false ) ); ?><?php $this->detail_metric( __( 'Associated abandonment', 'formhawk' ), $this->percent( $metrics['abandonment_association'], false ) ); ?></section>
			<section class="fh-roi-card"><h3><?php esc_html_e( 'Business impact', 'formhawk' ); ?></h3><?php $this->detail_metric( __( 'Submission impact', 'formhawk' ), $this->percent( $metrics['submission_impact'] ) ); ?><?php $this->detail_metric( __( 'Qualified leads / visitor', 'formhawk' ), $this->percent( $metrics['qualified_impact'] ) ); ?><?php $this->detail_metric( __( 'Won leads / visitor', 'formhawk' ), $this->percent( $metrics['won_impact'] ) ); ?><?php $this->detail_metric( __( 'Revenue / visitor', 'formhawk' ), isset( $metrics['revenue']['impact_rpv_minor'] ) && $metrics['revenue']['revenue_samples'] ? $this->money( $metrics['revenue']['impact_rpv_minor'], $row['currency'] ) : __( 'Unavailable', 'formhawk' ) ); ?></section>
			<section class="fh-roi-card"><h3><?php esc_html_e( 'Evidence', 'formhawk' ); ?></h3><?php $this->evidence_badge( $row['evidence_level'], $row['confidence'] ); ?><p><?php echo esc_html( $this->evidence_copy( $row['evidence_level'], ! empty( $metrics['causal_roi_unavailable'] ) ) ); ?></p><?php $this->detail_metric( __( 'Eligible form views', 'formhawk' ), number_format_i18n( $metrics['sample_size'] ) ); ?><?php $this->detail_metric( __( 'Outcome coverage', 'formhawk' ), $this->percent( $metrics['outcome_coverage'], false ) ); ?><?php $this->detail_metric( __( 'Maturing submissions', 'formhawk' ), number_format_i18n( isset( $metrics['maturing_submissions'] ) ? $metrics['maturing_submissions'] : 0 ) ); ?><?php $this->detail_metric( __( 'Outcome data through', 'formhawk' ), $row['data_through_utc'] . ' UTC' ); ?></section>
		</div>
		<section class="fh-roi-card fh-recommendation"><span class="fh-roi-kicker"><?php esc_html_e( 'RECOMMENDATION', 'formhawk' ); ?></span><h3><?php echo esc_html( $this->label( $row['recommendation'] ) . ' ' . ( $row['label'] ? $row['label'] : $row['normalized_key'] ) ); ?></h3><p><?php echo esc_html( $this->recommendation_copy( $row['verdict'], $row['evidence_level'] ) ); ?></p></section>
		<section class="fh-roi-card"><h3><?php esc_html_e( 'Field ROI timeline', 'formhawk' ); ?></h3><p><?php esc_html_e( 'Immutable decision snapshots keep the original model version, evidence, confidence, and before/after cohorts.', 'formhawk' ); ?></p>
			<?php
			if ( ! $history ) :
				?>
				<p><?php esc_html_e( 'No completed evaluation snapshots yet.', 'formhawk' ); ?></p><?php else : ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Evaluated UTC', 'formhawk' ); ?></th><th><?php esc_html_e( 'Decision', 'formhawk' ); ?></th><th><?php esc_html_e( 'Evidence', 'formhawk' ); ?></th><th><?php esc_html_e( 'Confidence', 'formhawk' ); ?></th><th><?php esc_html_e( 'Currency', 'formhawk' ); ?></th><th><?php esc_html_e( 'Model', 'formhawk' ); ?></th><th><?php esc_html_e( 'Sample', 'formhawk' ); ?></th></tr></thead><tbody>
						<?php
						foreach ( $history as $snapshot ) :
							$after = json_decode( $snapshot['after_metrics_json'], true );
							?>
	<tr><td><?php echo esc_html( $snapshot['created_at_utc'] ); ?></td><td><?php echo esc_html( $this->label( $snapshot['decision'] ) ); ?></td><td><?php echo esc_html( $this->label( $snapshot['evidence_level'] ) ); ?></td><td><?php echo esc_html( $this->label( $snapshot['confidence'] ) ); ?></td><td><?php echo esc_html( $snapshot['currency'] ); ?></td><td><code><?php echo esc_html( $snapshot['model_version'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( isset( $after['visitors'] ) ? absint( $after['visitors'] ) : 0 ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
							<?php endif; ?>
		</section>
		<?php
	}

	private function outcomes() {
		$rows = $this->outcomes->recent_submissions();
		?>
		<div class="fh-roi-columns">
			<section class="fh-roi-card"><h2><?php esc_html_e( 'Record manual outcome', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Use the opaque Formhawk ID only. Do not paste names, email addresses, phone numbers, messages, or CRM payloads.', 'formhawk' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'formhawk_record_manual_outcome' ); ?><input type="hidden" name="action" value="formhawk_record_manual_outcome">
					<label><?php esc_html_e( 'Submission ID', 'formhawk' ); ?><input class="regular-text" required name="submission_id" pattern="fh_[A-Za-z0-9_-]{22,43}"></label>
					<label><?php esc_html_e( 'Outcome', 'formhawk' ); ?><select name="status">
					<?php
					foreach ( array_diff( OutcomeStatus::all(), array( OutcomeStatus::SUBMITTED, OutcomeStatus::UNKNOWN ) ) as $status ) :
						?>
						<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $this->label( $status ) ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Value in minor units (optional)', 'formhawk' ); ?><input type="number" name="value_minor" step="1"><small><?php esc_html_e( '$123.45 is 12345 USD minor units. Negative values are adjustments/refunds.', 'formhawk' ); ?></small></label>
					<label><?php esc_html_e( 'Currency', 'formhawk' ); ?><input name="currency" maxlength="3" value="<?php echo esc_attr( $this->currency() ); ?>"></label>
					<?php submit_button( __( 'Record outcome', 'formhawk' ) ); ?>
				</form>
			</section>
			<section class="fh-roi-card"><h2><?php esc_html_e( 'Recent attributed submissions', 'formhawk' ); ?></h2>
				<?php
				if ( ! $rows ) :
					?>
					<p><?php esc_html_e( 'No provider-confirmed submissions have been attributed yet.', 'formhawk' ); ?></p>
					<?php
				else :
					?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Submission ID', 'formhawk' ); ?></th><th><?php esc_html_e( 'Form', 'formhawk' ); ?></th><th><?php esc_html_e( 'Status', 'formhawk' ); ?></th><th><?php esc_html_e( 'Submitted UTC', 'formhawk' ); ?></th></tr></thead><tbody>
					<?php
					foreach ( $rows as $row ) :
						?>
					<tr><td><code><?php echo esc_html( $row['public_id'] ); ?></code></td><td><?php echo esc_html( $row['title'] ? $row['title'] : $row['provider'] ); ?></td><td><?php echo esc_html( $this->label( $row['status'] ) ); ?></td><td><?php echo esc_html( $row['submitted_at_utc'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function integrations() {
		?>
		<section class="fh-roi-card"><h2><?php esc_html_e( 'Outcome API', 'formhawk' ); ?></h2><p><?php esc_html_e( 'POST strict outcome records from your CRM. Authenticate with a WordPress Application Password or a dedicated key with record_outcomes capability.', 'formhawk' ); ?></p>
			<pre>POST <?php echo esc_html( rest_url( 'formhawk/v1/outcomes' ) ); ?>
Authorization: Bearer fhk_…
Idempotency-Key: crm-event-123
Content-Type: application/json

{"submission_id":"fh_…","status":"won","value_minor":12345,"currency":"USD","occurred_at":"2026-09-07T10:00:00Z"}</pre>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'formhawk_create_outcome_key' ); ?><input type="hidden" name="action" value="formhawk_create_outcome_key"><label><?php esc_html_e( 'Key name', 'formhawk' ); ?><input required name="name" maxlength="100" value="CRM"></label><?php submit_button( __( 'Create API key', 'formhawk' ) ); ?></form>
		</section>
		<section class="fh-roi-card"><h2><?php esc_html_e( 'API keys', 'formhawk' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'formhawk' ); ?></th><th><?php esc_html_e( 'Key ID', 'formhawk' ); ?></th><th><?php esc_html_e( 'Created', 'formhawk' ); ?></th><th><?php esc_html_e( 'Last used', 'formhawk' ); ?></th><th></th></tr></thead><tbody>
		<?php
		foreach ( $this->keys->all() as $key ) :
			?>
			<tr><td><?php echo esc_html( $key['name'] ); ?></td><td><code><?php echo esc_html( $key['key_id'] ); ?></code></td><td><?php echo esc_html( $key['created_at_utc'] ); ?></td><td><?php echo esc_html( $key['last_used_at_utc'] ? $key['last_used_at_utc'] : '—' ); ?></td><td>
			<?php
			if ( ! $key['revoked_at_utc'] ) :
				?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'formhawk_revoke_outcome_key' ); ?><input type="hidden" name="action" value="formhawk_revoke_outcome_key"><input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>"><?php submit_button( __( 'Revoke', 'formhawk' ), 'delete small', 'submit', false ); ?></form>
				<?php
else :
	esc_html_e( 'Revoked', 'formhawk' );
endif;
?>
</td></tr><?php endforeach; ?>
		</tbody></table></section>
		<?php
	}

	private function render_new_key( $secret ) {
		$link     = admin_url( 'admin.php?page=formhawk-field-roi&view=integrations' );
		$message  = '<p><strong>' . esc_html__( 'Copy this secret now. It cannot be recovered:', 'formhawk' ) . '</strong></p>';
		$message .= '<p><code class="fh-api-secret">' . esc_html( $secret ) . '</code></p>';
		$message .= '<p>' . esc_html__( 'Only its password hash has been stored. This response is the only plaintext copy Formhawk creates.', 'formhawk' ) . '</p>';
		$message .= '<p><a class="button button-primary" href="' . esc_url( $link ) . '">' . esc_html__( 'I have copied the key', 'formhawk' ) . '</a></p>';
		wp_die( $message, esc_html__( 'Outcome API key created', 'formhawk' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic fragment is escaped above.
	}

	private function onboarding( array $settings ) {
		?>
		<section class="fh-roi-card fh-onboarding"><span class="fh-roi-kicker"><?php esc_html_e( 'GET STARTED', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Connect form submissions to business outcomes', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Field ROI needs business outcomes to determine whether fields generate or destroy value. No demo data or lead values will be added.', 'formhawk' ); ?></p><?php $this->settings_form( $settings, __( 'Start collecting business outcomes', 'formhawk' ) ); ?></section>
		<?php
	}

	private function settings( array $settings ) {
		?>
		<section class="fh-roi-card"><h2><?php esc_html_e( 'Business value settings', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Recent unknown outcomes remain pending and are excluded from mature win/loss inference.', 'formhawk' ); ?></p><?php $this->settings_form( $settings, __( 'Save settings', 'formhawk' ) ); ?></section>
		<?php
	}

	private function settings_form( array $settings, $button ) {
		$objective       = isset( $settings['objective'] ) ? $settings['objective'] : 'business_value';
		$outcome_source  = isset( $settings['outcome_source'] ) ? $settings['outcome_source'] : 'manual';
		$currency        = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';
		$window          = isset( $settings['attribution_window'] ) ? absint( $settings['attribution_window'] ) : 90;
		$maturity        = isset( $settings['maturity_days'] ) ? absint( $settings['maturity_days'] ) : 14;
		$qualified_value = isset( $settings['qualified_value_minor'] ) ? absint( $settings['qualified_value_minor'] ) : 0;
		$won_value       = isset( $settings['won_value_minor'] ) ? absint( $settings['won_value_minor'] ) : 0;
		?>
		<form class="fh-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'formhawk_field_roi_settings' ); ?><input type="hidden" name="action" value="formhawk_field_roi_settings">
			<label><span>1. <?php esc_html_e( 'Business objective', 'formhawk' ); ?></span><select name="objective"><option value="business_value" <?php selected( $objective, 'business_value' ); ?>><?php esc_html_e( 'Revenue / business value', 'formhawk' ); ?></option><option value="qualified" <?php selected( $objective, 'qualified' ); ?>><?php esc_html_e( 'Qualified leads', 'formhawk' ); ?></option><option value="won" <?php selected( $objective, 'won' ); ?>><?php esc_html_e( 'Won leads', 'formhawk' ); ?></option></select></label>
			<label><span>2. <?php esc_html_e( 'Outcome source', 'formhawk' ); ?></span><select name="outcome_source"><option value="manual" <?php selected( $outcome_source, 'manual' ); ?>><?php esc_html_e( 'Manual testing', 'formhawk' ); ?></option><option value="outcome_api" <?php selected( $outcome_source, 'outcome_api' ); ?>><?php esc_html_e( 'Webhook / Outcome API', 'formhawk' ); ?></option><option value="developer_api" <?php selected( $outcome_source, 'developer_api' ); ?>><?php esc_html_e( 'Developer PHP API', 'formhawk' ); ?></option></select></label>
			<label><span>3. <?php esc_html_e( 'Reporting currency', 'formhawk' ); ?></span><input name="currency" maxlength="3" pattern="[A-Za-z]{3}" value="<?php echo esc_attr( $currency ); ?>"><small><?php esc_html_e( 'Currencies are calculated separately. Formhawk performs no automatic FX conversion.', 'formhawk' ); ?></small></label>
			<label><span>4. <?php esc_html_e( 'Attribution window', 'formhawk' ); ?></span><select name="attribution_window">
			<?php
			foreach ( array( 30, 60, 90, 180 ) as $days ) :
				?>
				<?php /* translators: %d: number of attribution days. */ ?>
				<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $window, $days ); ?>><?php echo esc_html( sprintf( __( '%d days', 'formhawk' ), $days ) ); ?></option><?php endforeach; ?></select></label>
			<label><span>5. <?php esc_html_e( 'Outcome maturity window', 'formhawk' ); ?></span><input type="number" min="1" max="90" name="maturity_days" value="<?php echo esc_attr( (string) $maturity ); ?>"><small><?php esc_html_e( 'Newer cohorts remain MATURING; UNKNOWN never means LOST.', 'formhawk' ); ?></small></label>
			<label><span>6. <?php esc_html_e( 'Configured Qualified value (minor units)', 'formhawk' ); ?></span><input type="number" min="0" step="1" name="qualified_value_minor" value="<?php echo esc_attr( (string) $qualified_value ); ?>"><small><?php esc_html_e( 'Used only when actual revenue is unavailable. Zero disables this fallback.', 'formhawk' ); ?></small></label>
			<label><span>7. <?php esc_html_e( 'Configured Won value (minor units)', 'formhawk' ); ?></span><input type="number" min="0" step="1" name="won_value_minor" value="<?php echo esc_attr( (string) $won_value ); ?>"><small><?php esc_html_e( 'Actual imported revenue takes precedence.', 'formhawk' ); ?></small></label>
			<?php submit_button( $button ); ?>
		</form>
		<?php
	}

	private function collecting() {
		?>
		<div class="fh-roi-card fh-empty"><span class="dashicons dashicons-chart-line"></span><h2><?php esc_html_e( 'Collecting business outcomes', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Field ROI will appear after provider-confirmed submissions receive enough mature outcomes. Until a controlled experiment exists, required fields remain observational and no causal claim is shown.', 'formhawk' ); ?></p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=formhawk-field-roi&view=outcomes' ) ); ?>"><?php esc_html_e( 'Record or connect outcomes', 'formhawk' ); ?></a></div>
		<?php
	}

	private function summary_card( $label, $value, $class_name ) {

		?>
	<div class="fh-summary-card is-<?php echo esc_attr( $class_name ); ?>"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div>
		<?php
	}
	private function detail_metric( $label, $value ) {

		?>
	<div class="fh-detail-metric"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div>
		<?php
	}
	private function evidence_badge( $evidence, $confidence ) {

		?>
	<span class="fh-evidence is-<?php echo esc_attr( $evidence ); ?>"><?php echo esc_html( $this->label( $evidence ) ); ?></span><small class="fh-confidence"><?php /* translators: %s: statistical confidence label. */ echo esc_html( sprintf( __( 'Confidence: %s', 'formhawk' ), $this->label( $confidence ) ) ); ?></small>
		<?php
	}
	private function verdict_badge( $verdict ) {

		?>
	<span class="fh-verdict is-<?php echo esc_attr( $verdict ); ?>"><?php echo esc_html( $this->label( $verdict ) ); ?></span>
		<?php
	}
	private function percent( $value, $signed = true ) {
		if ( null === $value ) {
			return '—';
		} return ( $signed && $value > 0 ? '+' : '' ) . number_format_i18n( 100 * $value, 1 ) . '%'; }
	private function money( $minor, $currency ) {
		$sign     = $minor > 0 ? '+' : ( $minor < 0 ? '−' : '' );
		$exponent = Currency::exponent( $currency );
		return $sign . $currency . ' ' . number_format_i18n( abs( Currency::major( $minor, $currency ) ), $exponent ); }
	private function currency() {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		return is_array( $settings ) && isset( $settings['currency'] ) ? $settings['currency'] : 'USD'; }

	private function label( $key ) {
		$labels = array(
			'money_maker'              => __( 'MONEY MAKER', 'formhawk' ),
			'conversion_killer'        => __( 'CONVERSION KILLER', 'formhawk' ),
			'qualifier'                => __( 'QUALIFIER', 'formhawk' ),
			'free_value'               => __( 'FREE VALUE', 'formhawk' ),
			'neutral'                  => __( 'NEUTRAL', 'formhawk' ),
			'unknown'                  => __( 'UNKNOWN', 'formhawk' ),
			'keep'                     => __( 'KEEP', 'formhawk' ),
			'test'                     => __( 'TEST', 'formhawk' ),
			'make_optional'            => __( 'MAKE OPTIONAL', 'formhawk' ),
			'make_required'            => __( 'MAKE REQUIRED', 'formhawk' ),
			'remove'                   => __( 'REMOVE', 'formhawk' ),
			'move_later'               => __( 'MOVE LATER', 'formhawk' ),
			'move_earlier'             => __( 'MOVE EARLIER', 'formhawk' ),
			'high_value_high_friction' => __( 'HIGH VALUE / HIGH FRICTION', 'formhawk' ),
			'low_value_high_friction'  => __( 'LOW VALUE / HIGH FRICTION', 'formhawk' ),
			'insufficient_data'        => __( 'INSUFFICIENT DATA', 'formhawk' ),
			'observational'            => __( 'OBSERVATIONAL', 'formhawk' ),
			'quasi_experimental'       => __( 'QUASI-EXPERIMENTAL', 'formhawk' ),
			'experimental'             => __( 'EXPERIMENTAL', 'formhawk' ),
			'strong_experimental'      => __( 'STRONG EXPERIMENTAL', 'formhawk' ),
			'insufficient'             => __( 'INSUFFICIENT', 'formhawk' ),
			'low'                      => __( 'LOW', 'formhawk' ),
			'medium'                   => __( 'MEDIUM', 'formhawk' ),
			'high'                     => __( 'HIGH', 'formhawk' ),
			'very_high'                => __( 'VERY HIGH', 'formhawk' ),
			'qualified'                => __( 'QUALIFIED', 'formhawk' ),
			'unqualified'              => __( 'UNQUALIFIED', 'formhawk' ),
			'won'                      => __( 'WON', 'formhawk' ),
			'lost'                     => __( 'LOST', 'formhawk' ),
			'spam'                     => __( 'SPAM', 'formhawk' ),
			'duplicate'                => __( 'DUPLICATE', 'formhawk' ),
			'value_adjustment'         => __( 'VALUE ADJUSTMENT', 'formhawk' ),
			'submitted'                => __( 'SUBMITTED', 'formhawk' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : strtoupper( str_replace( '_', ' ', $key ) );
	}

	private function evidence_copy( $level, $unavailable ) {
		if ( $unavailable ) {
			return __( 'Historical friction is associated with this field. Causal ROI is unavailable without a valid controlled experiment.', 'formhawk' ); }
		if ( CausalEvidence::STRONG_EXPERIMENTAL === $level ) {
			return __( 'A large controlled experiment passed the strong evidence and outcome-coverage thresholds.', 'formhawk' ); }
		return __( 'Measured causal lift from a controlled Formhawk experiment. Revenue inference uses robust resampling; displayed totals remain actual totals.', 'formhawk' );
	}

	private function recommendation_copy( $verdict, $evidence ) {
		if ( 'money_maker' === $verdict ) {
			return CausalEvidence::is_causal( $evidence ) ? __( 'This configuration costs some form completions, but leads passing through it generate substantially more business value. Keep it.', 'formhawk' ) : __( 'The field is associated with higher value and friction. Test it before changing the live form.', 'formhawk' ); }
		if ( 'conversion_killer' === $verdict ) {
			return __( 'The field creates measurable friction without corresponding lead-quality or revenue improvement. Test making it optional or removing it.', 'formhawk' ); }
		if ( 'qualifier' === $verdict ) {
			return __( 'The field filters volume while improving qualified or won leads per visitor. Keep it unless a higher-value experiment proves otherwise.', 'formhawk' ); }
		return __( 'Evidence is not yet sufficient for an automatic business decision. Continue collecting mature outcomes or run a controlled experiment.', 'formhawk' );
	}

	private function last_evaluated() {
		$value = get_option( 'formhawk_field_roi_last_evaluated', '' );
		return $value ? $value . ' UTC' : __( 'Not evaluated yet', 'formhawk' ); }
	private function tab( $view, $label, $current ) {
		$url = add_query_arg(
			array(
				'page' => 'formhawk-field-roi',
				'view' => $view,
			),
			admin_url( 'admin.php' )
		);
		?>
	<a class="nav-tab <?php echo $view === $current ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php
	}
	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Field ROI.', 'formhawk' ) ); } }

	private function query_key( $key, $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation input is normalized here.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $fallback;
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : $fallback;
	}

	private function post_key( $key, $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verifies the action nonce; scalar input is sanitized here.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
	}

	private function redirect_notice( $type, $message, $view ) {
		set_transient( 'formhawk_roi_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=formhawk-field-roi&view=' . $view ) );
		exit;
	}

	private function notice() {
		$notice = get_transient( 'formhawk_roi_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'formhawk_roi_notice_' . get_current_user_id() );
			?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php
		}
	}
}
