<?php

namespace Formhawk\MinimumForm;

use Formhawk\Analytics\AnalyticsRepository;
use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\ExperimentRepository;

final class MinimumFormAdmin {
	private $repository;
	private $manager;
	private $forms;
	private $analytics;
	private $experiments;
	private $schemas;
	private $capabilities;
	private $diagnostics;

	public function __construct( ?MinimumFormRepository $repository = null, ?MinimumFormManager $manager = null, ?FormRepository $forms = null, ?ExperimentRepository $experiments = null, ?ProviderCapabilityMatrix $capabilities = null ) {
		$this->repository   = $repository ? $repository : new MinimumFormRepository();
		$this->manager      = $manager ? $manager : new MinimumFormManager( $this->repository, $experiments, $forms );
		$this->forms        = $forms ? $forms : new FormRepository();
		$this->analytics    = new AnalyticsRepository();
		$this->experiments  = $experiments ? $experiments : new ExperimentRepository();
		$this->schemas      = new ProviderSchemaInspector();
		$this->capabilities = $capabilities ? $capabilities : new ProviderCapabilityMatrix();
		$this->diagnostics  = new MinimumFormDiagnostics( $this->experiments, $this->schemas, $this->capabilities );
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_formhawk_minimum_form', array( $this, 'action' ) );
	}

	public function menu() {
		add_submenu_page( 'formhawk', __( 'Minimum Viable Form', 'formhawk' ), __( 'Minimum Form', 'formhawk' ), 'manage_options', 'formhawk-minimum-form', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( 'formhawk_page_formhawk-minimum-form' === $hook ) {
			wp_enqueue_style( 'formhawk-minimum-form', FORMHAWK_URL . 'assets/css/minimum-form.css', array(), FORMHAWK_VERSION );
		}
	}

	public function action() {
		$this->authorize();
		check_admin_referer( 'formhawk_minimum_form' );
		$form_id   = absint( $this->post( 'form_id', 0 ) );
		$operation = sanitize_key( $this->post( 'operation', '' ) );
		if ( ! $form_id || ! $this->forms->find( $form_id ) ) {
			wp_die( esc_html__( 'Form not found.', 'formhawk' ) );
		}
		$result  = false;
		$message = __( 'Minimum Form action could not be completed. The original form remains available.', 'formhawk' );
		if ( 'start' === $operation ) {
			$result  = (bool) $this->manager->start(
				$form_id,
				array(
					'mode'           => $this->choice( $this->post( 'mode', 'approve' ), array( 'observe', 'approve', 'full' ), 'approve' ),
					'aggressiveness' => $this->choice( $this->post( 'aggressiveness', 'balanced' ), array( 'conservative', 'balanced', 'aggressive' ), 'balanced' ),
					'objective'      => $this->choice( $this->post( 'objective', 'auto' ), array( 'auto', 'confirmed_conversion', 'qualified_leads', 'won_leads', 'revenue_per_visitor' ), 'auto' ),
				)
			);
			$message = $result ? __( 'Minimum Form started. Formhawk is building its first safe baseline.', 'formhawk' ) : __( 'Minimum Form needs a verified provider schema before it can start.', 'formhawk' );
		} elseif ( 'start_experiment' === $operation ) {
			$result  = $this->manager->start_experiment( $form_id );
			$message = $result ? __( 'The proposed field experiment is now collecting evidence.', 'formhawk' ) : $message;
		} elseif ( 'evaluate' === $operation ) {
			$result  = $this->manager->evaluate_form( $form_id );
			$message = $result ? __( 'Minimum Form evaluation completed.', 'formhawk' ) : $message;
		} elseif ( 'pause' === $operation ) {
			$result  = $this->manager->pause( $form_id );
			$message = $result ? __( 'Optimization paused. The current safe baseline remains active.', 'formhawk' ) : $message;
		} elseif ( 'resume' === $operation ) {
			$result  = $this->manager->resume( $form_id );
			$message = $result ? __( 'Optimization resumed.', 'formhawk' ) : $message;
		} elseif ( 'restore_previous' === $operation ) {
			$result  = $this->manager->restore_previous( $form_id );
			$message = $result ? __( 'The previous validated baseline was restored.', 'formhawk' ) : __( 'No previous validated baseline is available.', 'formhawk' );
		} elseif ( 'revalidate' === $operation ) {
			$result  = (bool) $this->manager->revalidate( $form_id );
			$message = $result ? __( 'A new original baseline was created from the current provider form. Previous history remains available.', 'formhawk' ) : __( 'The provider schema could not be revalidated. The original form remains active.', 'formhawk' );
		} elseif ( 'disable' === $operation ) {
			$result  = $this->manager->disable( $form_id );
			$message = $result ? __( 'Minimum Form disabled. The runtime that was active before Minimum Form has been restored.', 'formhawk' ) : $message;
		}
		set_transient( 'formhawk_minimum_notice_' . get_current_user_id(), array( $result ? 'success' : 'error', $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=formhawk-minimum-form&form_id=' . $form_id ) );
		exit;
	}

	public function render() {
		$this->authorize();
		$this->notice();
		$form_id = absint( $this->query( 'form_id', 0 ) );
		if ( ! $form_id ) {
			$this->form_picker();
			return;
		}
		$form = $this->forms->find( $form_id );
		if ( ! is_array( $form ) ) {
			wp_die( esc_html__( 'Form not found.', 'formhawk' ) );
		}
		$profile = $this->repository->profile( $form_id );
		if ( 'off' === $profile->status() ) {
			$this->onboarding( $form );
			return;
		}
		$this->dashboard( $form, $profile->data() );
	}

	private function form_picker() {
		$forms = $this->analytics->overview( 30, 1, 100 );
		?>
		<div class="wrap fh-min-wrap">
			<div class="fh-min-hero fh-min-hero-picker"><div><span class="fh-min-kicker"><?php esc_html_e( 'AUTOPILOT · BUSINESS VALUE', 'formhawk' ); ?></span><h1><?php esc_html_e( 'Minimum Viable Form', 'formhawk' ); ?></h1><p><?php esc_html_e( 'Find the least friction your form needs without sacrificing qualified leads or revenue.', 'formhawk' ); ?></p></div></div>
			<div class="fh-min-card"><h2><?php esc_html_e( 'Choose a form', 'formhawk' ); ?></h2>
			<?php
			if ( ! $forms ) :
				?>
				<div class="fh-min-empty"><h3><?php esc_html_e( 'No forms observed yet', 'formhawk' ); ?></h3><p><?php esc_html_e( 'Formhawk needs real form traffic before it can build a structural baseline.', 'formhawk' ); ?></p></div>
				<?php
			else :
				?>
				<div class="fh-min-form-grid"><?php foreach ( $forms as $form ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=formhawk-minimum-form&form_id=' . absint( $form['id'] ) ) ); ?>"><strong><?php echo esc_html( $form['title'] ? $form['title'] : $form['provider_form_id'] ); ?></strong><span><?php echo esc_html( strtoupper( $form['provider'] ) . ' · ' . number_format_i18n( $form['views'] ) . ' ' . __( 'views', 'formhawk' ) ); ?></span></a>
			<?php endforeach; ?></div><?php endif; ?></div>
		</div>
		<?php
	}

	private function onboarding( array $form ) {
		$schema       = $this->schemas->inspect( $form );
		$capabilities = $this->capabilities->for_provider( $form['provider'] );
		?>
		<div class="wrap fh-min-wrap">
			<?php $this->header( $form, 'off' ); ?>
			<div class="fh-min-onboarding">
				<div class="fh-min-card fh-min-onboarding-copy"><span class="fh-min-kicker"><?php esc_html_e( 'ONE SAFE EXPERIMENT AT A TIME', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Find My Minimum Viable Form', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Formhawk uses Field ROI to select a field, tests one reversible semantic change, waits for provider-confirmed submissions and mature business outcomes, then promotes or rejects the change.', 'formhawk' ); ?></p>
					<ul><li><?php esc_html_e( 'The original provider form is never edited.', 'formhawk' ); ?></li><li><?php esc_html_e( 'Security, legal and dependency fields are never removed automatically.', 'formhawk' ); ?></li><li><?php esc_html_e( 'A higher conversion rate cannot overrule a proven revenue or lead-quality loss.', 'formhawk' ); ?></li></ul>
				</div>
				<div class="fh-min-card"><h2><?php esc_html_e( 'Optimization controls', 'formhawk' ); ?></h2>
				<?php
				if ( ! $schema ) :
					?>
					<div class="fh-min-callout is-amber"><strong><?php esc_html_e( 'STRUCTURAL BASELINE NEEDED', 'formhawk' ); ?></strong><p><?php esc_html_e( 'A provider-confirmed submission must be observed before Formhawk can verify this form schema.', 'formhawk' ); ?></p></div><?php endif; ?>
				<?php
				if ( empty( $capabilities[ ProviderCapabilityMatrix::CONFIRMED_SUCCESS ] ) || empty( $capabilities[ ProviderCapabilityMatrix::DEPENDENCY_GRAPH ] ) ) :
					?>
					<div class="fh-min-callout is-amber"><strong><?php esc_html_e( 'OBSERVE ONLY', 'formhawk' ); ?></strong><p><?php esc_html_e( 'This provider cannot yet prove both server success and field dependencies. Automatic semantic experiments remain disabled.', 'formhawk' ); ?></p></div><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="formhawk_minimum_form"><input type="hidden" name="operation" value="start"><input type="hidden" name="form_id" value="<?php echo esc_attr( $form['id'] ); ?>"><?php wp_nonce_field( 'formhawk_minimum_form' ); ?>
					<label><?php esc_html_e( 'Mode', 'formhawk' ); ?><select name="mode"><option value="approve" selected><?php esc_html_e( 'Approve (recommended)', 'formhawk' ); ?></option><option value="observe"><?php esc_html_e( 'Observe', 'formhawk' ); ?></option><option value="full"><?php esc_html_e( 'Full Autopilot', 'formhawk' ); ?></option></select><small><?php esc_html_e( 'Approve prepares one safe experiment and waits for you before traffic changes.', 'formhawk' ); ?></small></label>
					<label><?php esc_html_e( 'Aggressiveness', 'formhawk' ); ?><select name="aggressiveness"><option value="conservative"><?php esc_html_e( 'Conservative', 'formhawk' ); ?></option><option value="balanced" selected><?php esc_html_e( 'Balanced', 'formhawk' ); ?></option><option value="aggressive"><?php esc_html_e( 'Aggressive', 'formhawk' ); ?></option></select><small><?php esc_html_e( 'Aggressive permits selected CAUTION fields, never PROTECTED or FORBIDDEN fields.', 'formhawk' ); ?></small></label>
					<label><?php esc_html_e( 'Business objective', 'formhawk' ); ?><select name="objective"><option value="auto" selected><?php esc_html_e( 'Best available outcome', 'formhawk' ); ?></option><option value="revenue_per_visitor"><?php esc_html_e( 'Revenue per visitor', 'formhawk' ); ?></option><option value="qualified_leads"><?php esc_html_e( 'Qualified leads per visitor', 'formhawk' ); ?></option><option value="won_leads"><?php esc_html_e( 'Won leads per visitor', 'formhawk' ); ?></option><option value="confirmed_conversion"><?php esc_html_e( 'Confirmed conversion', 'formhawk' ); ?></option></select></label>
					<button class="button button-primary button-hero" type="submit" <?php disabled( ! $schema ); ?>><?php esc_html_e( 'Find My Minimum Viable Form', 'formhawk' ); ?></button>
				</form></div>
			</div>
		</div>
		<?php
	}

	private function dashboard( array $form, array $profile ) {
		$decisions     = $profile['decisions'];
		$fields        = $this->repository->fields_for_form( $form['id'], $this->currency() );
		$schema        = $this->schemas->inspect( $form );
		$baseline      = $profile['current_baseline'];
		$baseline_data = $baseline ? $baseline->data() : array();
		$mutations     = $baseline ? $baseline->mutations() : array();
		$active        = ! empty( $profile['active_experiment_id'] ) ? $this->experiments->find( $profile['active_experiment_id'] ) : null;
		$progress      = array(
			'evaluated' => count( array_unique( array_column( $decisions, 'field_definition_id' ) ) ),
			'total'     => absint( $profile['original_field_count'] ),
		);
		$objective     = ( new BusinessObjectiveResolver() )->resolve( $form['id'], $profile['objective'], $this->currency() );
		$metric        = 'business_value' === $objective['metric'] ? 'revenue_per_visitor' : ( 'qualified_leads' === $objective['metric'] ? 'qualified_leads_per_visitor' : ( 'won_leads' === $objective['metric'] ? 'won_leads_per_visitor' : 'confirmed_conversion' ) );
		$lift          = $this->cumulative_lift( $decisions, $metric );
		$score         = ( new FormEfficiencyScore() )->calculate( $profile, $fields, $decisions );
		$monthly       = $this->estimated_monthly_value( $form['id'], $decisions );
		/* translators: 1: evaluated field count, 2: total field count. */
		$progress_text = sprintf( __( '%1$d of %2$d fields evaluated', 'formhawk' ), $progress['evaluated'], $progress['total'] );
		?>
		<div class="wrap fh-min-wrap">
			<?php $this->header( $form, $profile['status'] ); ?>
			<?php
			if ( in_array( $profile['status'], array( 'revalidation_required', 'integrity_failure' ), true ) ) :
				?>
				<div class="fh-min-critical" role="alert"><strong><?php echo esc_html( 'revalidation_required' === $profile['status'] ? __( 'BASELINE NEEDS REVALIDATION', 'formhawk' ) : __( 'EXPERIMENT INTEGRITY FAILED', 'formhawk' ) ); ?></strong><p><?php esc_html_e( 'Optimization is paused and traffic is routed to the original form. No promotion can occur until the evidence is valid again.', 'formhawk' ); ?></p></div><?php endif; ?>
			<section class="fh-min-hero-card">
				<div><span class="fh-min-kicker"><?php esc_html_e( 'MINIMUM VIABLE FORM', 'formhawk' ); ?></span><div class="fh-min-status-line"><h2><?php echo esc_html( $this->status_label( $profile['status'] ) ); ?></h2><span class="fh-min-badge is-<?php echo esc_attr( $profile['status'] ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $profile['status'] ) ) ); ?></span></div><p><?php echo esc_html( $progress_text ); ?></p><div class="fh-min-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) max( 1, $progress['total'] ) ); ?>" aria-valuenow="<?php echo esc_attr( (string) $progress['evaluated'] ); ?>"><span style="width:<?php echo esc_attr( (string) ( $progress['total'] ? min( 100, 100 * $progress['evaluated'] / $progress['total'] ) : 0 ) ); ?>%"></span></div></div>
				<div class="fh-min-kpis"><div><span><?php esc_html_e( 'Original', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( $profile['original_field_count'] ) ); ?></strong><small><?php esc_html_e( 'fields', 'formhawk' ); ?></small></div><div><span><?php esc_html_e( 'Current baseline', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( $profile['current_field_count'] ) ); ?></strong><small><?php echo esc_html( isset( $baseline_data['version'] ) ? 'V' . absint( $baseline_data['version'] ) : __( 'Original', 'formhawk' ) ); ?></small></div><div><span><?php echo esc_html( $objective['business_optimized'] ? __( 'Business value lift', 'formhawk' ) : __( 'Confirmed conversion lift', 'formhawk' ) ); ?></span><strong><?php echo null === $lift ? esc_html__( 'Learning', 'formhawk' ) : esc_html( $this->percent( $lift ) ); ?></strong><small><?php esc_html_e( 'same-metric promotions', 'formhawk' ); ?></small></div><div><span><?php esc_html_e( 'Form efficiency', 'formhawk' ); ?></span><strong><?php echo esc_html( $score ); ?></strong><small><?php esc_html_e( 'out of 100', 'formhawk' ); ?></small></div></div>
			</section>
			<?php if ( ! $objective['business_optimized'] ) : ?>
				<section class="fh-min-card fh-min-learning"><span class="fh-min-kicker"><?php esc_html_e( 'CONVERSION OPTIMIZATION MODE', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Business outcome data is not yet sufficient', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Formhawk can optimize provider-confirmed conversion, but cannot yet prove whether a field changes lead quality or revenue.', 'formhawk' ); ?></p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=formhawk-field-roi&view=outcomes' ) ); ?>"><?php esc_html_e( 'Connect Business Outcomes', 'formhawk' ); ?></a></section>
			<?php endif; ?>
			<?php
			if ( 'optimized' === $profile['status'] ) {
				$this->final_report( $profile, $decisions, $lift, $monthly, $objective['business_optimized'] ); }
			?>
			<?php $this->current_experiment( $form, $profile, $active ); ?>
			<div class="fh-min-grid">
				<?php $this->form_map( $schema, $fields, $mutations, $decisions, $active ); ?>
				<?php $this->safety_panel( $form, $profile, $schema ); ?>
			</div>
			<?php $this->before_after( $schema, $mutations ); ?>
			<?php $this->journey( $profile, $decisions ); ?>
			<?php $this->controls( $form, $profile, $active ); ?>
		</div>
		<?php
	}

	private function current_experiment( array $form, array $profile, $active ) {
		if ( ! $active ) {
			if ( 'collecting' === $profile['status'] ) {
				$stats = $this->analytics->form_stats( $form['id'], 90 );
				?>
				<section class="fh-min-card fh-min-learning"><span class="fh-min-kicker"><?php esc_html_e( 'MINIMUM FORM IS LEARNING', 'formhawk' ); ?></span><h2><?php esc_html_e( 'More real evidence is needed', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Formhawk will not fabricate a recommendation from friction alone. It is waiting for Field ROI confidence and mature business outcomes.', 'formhawk' ); ?></p><div class="fh-min-inline-kpis"><span><?php echo esc_html( number_format_i18n( $stats['views'] ) . ' ' . __( 'visitors', 'formhawk' ) ); ?></span><span><?php echo esc_html( number_format_i18n( $stats['confirmed_successes'] ) . ' ' . __( 'confirmed submissions', 'formhawk' ) ); ?></span></div></section>
				<?php
			}
			return;
		}
		$variants = $this->experiments->variants( $active['id'] );
		$totals   = $this->experiments->aggregate( $active['id'] );
		$control  = isset( $variants[0] ) ? $totals[ $variants[0]['id'] ] ?? array() : array();
		$variant  = isset( $variants[1] ) ? $totals[ $variants[1]['id'] ] ?? array() : array();
		$field    = $active['policy']['opportunity']['field_label'] ?? __( 'field', 'formhawk' );
		/* translators: 1: mutation type, 2: field label. */
		$experiment_title = sprintf( __( 'Testing %1$s: %2$s', 'formhawk' ), str_replace( '_', ' ', $active['type'] ), $field );
		?>
		<section class="fh-min-card fh-min-experiment"><div><span class="fh-min-kicker"><?php esc_html_e( 'CURRENT EXPERIMENT', 'formhawk' ); ?></span><h2><?php echo esc_html( $experiment_title ); ?></h2><p><?php echo esc_html( $active['hypothesis'] ); ?></p></div><div class="fh-min-experiment-metrics"><div><span><?php esc_html_e( 'Control · confirmed conversion', 'formhawk' ); ?></span><strong><?php echo esc_html( $this->rate( $control ) ); ?></strong></div><div><span><?php esc_html_e( 'Variant · confirmed conversion', 'formhawk' ); ?></span><strong><?php echo esc_html( $this->rate( $variant ) ); ?></strong></div><span class="fh-min-badge"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $active['status'] ) ) ); ?></span></div></section>
		<?php
	}

	private function form_map( $schema, array $fields, array $mutations, array $decisions, $active ) {
		$schema_fields = $schema && isset( $schema['fields'] ) ? $schema['fields'] : array();
		$removed       = $this->mutation_keys( $mutations, 'remove_field' );
		$optional      = $this->mutation_keys( $mutations, 'make_optional' );
		$testing       = $active ? (string) ( $active['policy']['opportunity']['field_key'] ?? '' ) : '';
		$decision_map  = array();
		$roi_map       = array();
		foreach ( $decisions as $decision ) {
			$key = (string) $decision['normalized_key'];
			if ( ! isset( $decision_map[ $key ] ) ) {
				$decision_map[ $key ] = $decision;
			}
		}
		foreach ( $fields as $field ) {
			$roi_map[ (string) $field['normalized_key'] ] = $field;
		}
		$classifier = new FieldSafetyClassifier();
		?>
		<section class="fh-min-card"><div class="fh-min-card-head"><div><span class="fh-min-kicker"><?php esc_html_e( 'VISUAL FORM MAP', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Every field must earn its place', 'formhawk' ); ?></h2></div></div><div class="fh-min-field-map">
		<?php
		foreach ( $schema_fields as $field ) :
			$key      = (string) ( $field['normalized_key'] ?? $field['key'] ?? '' );
			$safety   = $classifier->classify( $field );
			$state    = in_array( $key, $removed, true ) ? 'removed' : ( in_array( $key, $optional, true ) ? 'optional' : ( $key === $testing ? 'testing' : ( in_array( $safety['safety'], array( FieldSafety::PROTECTED, FieldSafety::FORBIDDEN ), true ) ? 'protected' : 'retained' ) ) );
			$decision = isset( $decision_map[ $key ] ) ? $decision_map[ $key ] : null;
			$roi      = isset( $roi_map[ $key ] ) ? $roi_map[ $key ] : array();
			$verdict  = ! empty( $roi['verdict'] ) && 'unknown' !== $roi['verdict'] ? $roi['verdict'] : $safety['safety'];
			?>
			<details class="fh-min-field is-<?php echo esc_attr( $state ); ?>"><summary><span class="fh-min-field-icon" aria-hidden="true"></span><span><strong><?php echo esc_html( $field['label'] ?? $key ); ?></strong><small><?php echo esc_html( strtoupper( $state ) . ' · ' . strtoupper( str_replace( '_', ' ', $decision ? $decision['decision'] : $verdict ) ) ); ?></small></span><span class="fh-min-chevron" aria-hidden="true"></span></summary><div class="fh-min-field-reason"><?php $this->field_reason( $state, $field, $decision, $safety, $roi ); ?></div></details>
		<?php endforeach; ?></div><div class="fh-min-legend"><span class="is-retained"><?php esc_html_e( 'Retained', 'formhawk' ); ?></span><span class="is-removed"><?php esc_html_e( 'Removed', 'formhawk' ); ?></span><span class="is-optional"><?php esc_html_e( 'Optional', 'formhawk' ); ?></span><span class="is-testing"><?php esc_html_e( 'Testing', 'formhawk' ); ?></span><span class="is-protected"><?php esc_html_e( 'Protected', 'formhawk' ); ?></span></div></section>
		<?php
	}

	private function field_reason( $state, array $field, $decision, array $safety, array $roi = array() ) {
		if ( $decision ) {
			if ( 'removed' === $state ) {
				/* translators: %s: field label. */
				$heading = sprintf( __( '%s was removed', 'formhawk' ), $field['label'] );
			} else {
				/* translators: %s: field label. */
				$heading = sprintf( __( '%s is staying', 'formhawk' ), $field['label'] );
			}
			?>
			<h3><?php echo esc_html( $heading ); ?></h3><dl><div><dt><?php esc_html_e( 'Decision', 'formhawk' ); ?></dt><dd><?php echo esc_html( strtoupper( $decision['decision'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Visitors', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( absint( $decision['control_visitors'] ) + absint( $decision['variant_visitors'] ) ) ); ?></dd></div><div><dt><?php esc_html_e( 'Confirmed conversion', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->decision_conversion( $decision ) ); ?></dd></div><div><dt><?php esc_html_e( 'Business lift', 'formhawk' ); ?></dt><dd><?php echo esc_html( null === $decision['business_lift'] ? __( 'Not conclusive', 'formhawk' ) : $this->percent( $decision['business_lift'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Probability better', 'formhawk' ); ?></dt><dd><?php echo esc_html( null === $decision['probability'] ? __( 'Not available', 'formhawk' ) : number_format_i18n( 100 * $decision['probability'], 1 ) . '%' ); ?></dd></div><div><dt><?php esc_html_e( 'Evidence', 'formhawk' ); ?></dt><dd><?php echo esc_html( strtoupper( $decision['confidence'] ) ); ?></dd></div></dl><p><?php echo esc_html( $this->reason_text( $decision['reason'] ) ); ?></p>
			<?php
			return;
		}
		if ( in_array( $state, array( 'protected' ), true ) ) {
			/* translators: %s: structural safety reason. */
			$explanation = sprintf( __( 'Formhawk will not remove this field automatically: %s.', 'formhawk' ), str_replace( '_', ' ', $safety['reason'] ) );
		} elseif ( in_array( $roi['verdict'] ?? '', array( 'money_maker', 'qualifier', 'free_value' ), true ) ) {
			$verdicts = array(
				'money_maker' => __( 'MONEY MAKER', 'formhawk' ),
				'qualifier'   => __( 'QUALIFIER', 'formhawk' ),
				'free_value'  => __( 'FREE VALUE', 'formhawk' ),
			);
			/* translators: 1: Field ROI verdict. */
			$explanation = sprintf( __( 'Field ROI classifies this field as %s. Formhawk keeps it because current evidence shows business value, lead-quality value, or value without measurable friction.', 'formhawk' ), $verdicts[ $roi['verdict'] ] );
		} else {
			$explanation = __( 'No causal field decision has been recorded yet.', 'formhawk' );
		}
		?>
		<p><?php echo esc_html( $explanation ); ?></p>
		<?php if ( $roi ) : ?>
			<?php $metrics = isset( $roi['metrics'] ) && is_array( $roi['metrics'] ) ? $roi['metrics'] : array(); ?>
			<dl><div><dt><?php esc_html_e( 'Field ROI verdict', 'formhawk' ); ?></dt><dd><?php echo esc_html( strtoupper( str_replace( '_', ' ', $roi['verdict'] ?? 'unknown' ) ) ); ?></dd></div><div><dt><?php esc_html_e( 'Evidence', 'formhawk' ); ?></dt><dd><?php echo esc_html( strtoupper( str_replace( '_', ' ', $roi['evidence_level'] ?? 'unknown' ) ) ); ?></dd></div><div><dt><?php esc_html_e( 'Confidence', 'formhawk' ); ?></dt><dd><?php echo esc_html( strtoupper( $roi['confidence'] ?? 'insufficient' ) ); ?></dd></div>
			<?php
			if ( isset( $metrics['friction_score'] ) ) :
				?>
				<div><dt><?php esc_html_e( 'Friction signal', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->percent( (float) $metrics['friction_score'] ) ); ?></dd></div><?php endif; ?>
			<?php
			if ( isset( $metrics['qualified_impact'] ) && null !== $metrics['qualified_impact'] ) :
				?>
				<div><dt><?php esc_html_e( 'Qualified leads / visitor', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->percent( (float) $metrics['qualified_impact'] ) ); ?></dd></div><?php endif; ?>
			<?php
			if ( isset( $metrics['revenue_impact'] ) && null !== $metrics['revenue_impact'] ) :
				?>
				<div><dt><?php esc_html_e( 'Revenue / visitor', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->percent( (float) $metrics['revenue_impact'] ) ); ?></dd></div><?php endif; ?></dl>
		<?php endif; ?>
		<?php
	}

	private function safety_panel( array $form, array $profile, $schema ) {
		$capabilities = $this->capabilities->for_provider( $form['provider'] );
		$diagnostics  = $this->diagnostics->for_form( $form, $profile );
		$protected    = 0;
		$classifier   = new FieldSafetyClassifier();
		foreach ( $schema && isset( $schema['fields'] ) ? $schema['fields'] : array() as $field ) {
			$classification = $classifier->classify( $field );
			$protected     += in_array( $classification['safety'], array( FieldSafety::PROTECTED, FieldSafety::FORBIDDEN ), true ) ? 1 : 0;
		}
		?>
		<aside class="fh-min-card fh-min-safety"><span class="fh-min-kicker"><?php esc_html_e( 'AUTOPILOT SAFETY', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Fail-safe by design', 'formhawk' ); ?></h2><dl><div><dt><?php esc_html_e( 'Original form', 'formhawk' ); ?></dt><dd><?php esc_html_e( 'Never modified', 'formhawk' ); ?></dd></div><div><dt><?php esc_html_e( 'Protected fields', 'formhawk' ); ?></dt><dd><?php echo esc_html( (string) $protected ); ?></dd></div><div><dt><?php esc_html_e( 'Automatic rollback', 'formhawk' ); ?></dt><dd><?php esc_html_e( 'Enabled', 'formhawk' ); ?></dd></div><div><dt><?php esc_html_e( 'Provider confirmation', 'formhawk' ); ?></dt><dd><?php echo esc_html( ! empty( $capabilities[ ProviderCapabilityMatrix::CONFIRMED_SUCCESS ] ) ? __( 'Active', 'formhawk' ) : __( 'Unavailable', 'formhawk' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Business-value objective', 'formhawk' ); ?></dt><dd><?php echo esc_html( 'confirmed_conversion' === $profile['objective'] ? __( 'Confirmed conversion', 'formhawk' ) : __( 'Best covered outcome', 'formhawk' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Outcome coverage', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( 100 * $diagnostics['outcome_coverage'], 0 ) . '%' ); ?></dd></div><div><dt><?php esc_html_e( 'Experiment integrity', 'formhawk' ); ?></dt><dd><?php echo esc_html( 'valid' === $diagnostics['experiment_integrity'] ? __( 'Valid', 'formhawk' ) : __( 'Review required', 'formhawk' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Schema', 'formhawk' ); ?></dt><dd><?php echo esc_html( 'valid' === $diagnostics['schema'] ? __( 'Valid', 'formhawk' ) : __( 'Changed', 'formhawk' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Background job', 'formhawk' ); ?></dt><dd><?php echo esc_html( 'healthy' === $diagnostics['background_job'] ? __( 'Healthy', 'formhawk' ) : __( 'Not scheduled', 'formhawk' ) ); ?></dd></div></dl><div class="fh-min-capabilities"><span class="<?php echo empty( $capabilities[ ProviderCapabilityMatrix::REMOVE_FIELD ] ) ? 'is-off' : ''; ?>"><?php esc_html_e( 'REMOVE', 'formhawk' ); ?></span><span class="<?php echo empty( $capabilities[ ProviderCapabilityMatrix::MAKE_OPTIONAL ] ) ? 'is-off' : ''; ?>"><?php esc_html_e( 'OPTIONAL', 'formhawk' ); ?></span><span class="<?php echo empty( $capabilities[ ProviderCapabilityMatrix::MAKE_REQUIRED ] ) ? 'is-off' : ''; ?>"><?php esc_html_e( 'REQUIRED', 'formhawk' ); ?></span></div></aside>
		<?php
	}

	private function before_after( $schema, array $mutations ) {
		$fields   = $schema && isset( $schema['fields'] ) ? $schema['fields'] : array();
		$removed  = $this->mutation_keys( $mutations, 'remove_field' );
		$optional = $this->mutation_keys( $mutations, 'make_optional' );
		?>
		<section class="fh-min-card"><span class="fh-min-kicker"><?php esc_html_e( 'BEFORE / AFTER', 'formhawk' ); ?></span><h2><?php esc_html_e( 'A structural preview of the validated baseline', 'formhawk' ); ?></h2><div class="fh-min-preview"><div><h3><?php esc_html_e( 'Original', 'formhawk' ); ?></h3><strong><?php echo esc_html( (string) count( $fields ) ); ?> <?php esc_html_e( 'fields', 'formhawk' ); ?></strong><ol>
		<?php
		foreach ( $fields as $field ) :
			?>
			<li><?php echo esc_html( $field['label'] ?? $field['key'] ); ?></li><?php endforeach; ?></ol></div><div class="is-optimized"><h3><?php esc_html_e( 'Optimized', 'formhawk' ); ?></h3><strong><?php echo esc_html( (string) ( count( $fields ) - count( $removed ) ) ); ?> <?php esc_html_e( 'fields', 'formhawk' ); ?></strong><ol>
			<?php
			foreach ( $fields as $field ) :
						$key = $field['normalized_key'] ?? $field['key'];
				if ( in_array( $key, $removed, true ) ) {
					continue; }
				?>
	<li><?php echo esc_html( $field['label'] ?? $key ); ?>
				<?php
				if ( in_array( $key, $optional, true ) ) :
					?>
		<small><?php esc_html_e( 'optional', 'formhawk' ); ?></small><?php endif; ?></li><?php endforeach; ?></ol></div></div></section>
		<?php
	}

	private function journey( array $profile, array $decisions ) {
		$decisions = array_reverse( $decisions );
		/* translators: %d: original form field count. */
		$original_text = sprintf( __( '%d fields', 'formhawk' ), $profile['original_field_count'] );
		/* translators: %d: fields remaining in the current baseline. */
		$current_text = sprintf( __( '%d fields remain', 'formhawk' ), $profile['current_field_count'] );
		?>
		<section class="fh-min-card"><span class="fh-min-kicker"><?php esc_html_e( 'OPTIMIZATION JOURNEY', 'formhawk' ); ?></span><h2><?php esc_html_e( 'How the form evolved', 'formhawk' ); ?></h2><ol class="fh-min-timeline"><li><span>V1</span><div><strong><?php esc_html_e( 'Original baseline', 'formhawk' ); ?></strong><p><?php echo esc_html( $original_text ); ?></p></div></li>
		<?php
		foreach ( $decisions as $decision ) :
			?>
			<li class="is-<?php echo esc_attr( $decision['decision'] ); ?>"><span><?php echo esc_html( strtoupper( substr( $decision['decision'], 0, 1 ) ) ); ?></span><div><strong><?php echo esc_html( $decision['label'] . ' · ' . str_replace( '_', ' ', $decision['mutation_type'] ) ); ?></strong><p><?php echo esc_html( strtoupper( $decision['decision'] ) . ( null !== $decision['business_lift'] ? ' · ' . $this->percent( $decision['business_lift'] ) : '' ) ); ?></p></div></li><?php endforeach; ?><li class="is-current"><span></span><div><strong><?php echo esc_html( 'optimized' === $profile['status'] ? __( 'MINIMUM VIABLE FORM', 'formhawk' ) : __( 'Current validated baseline', 'formhawk' ) ); ?></strong><p><?php echo esc_html( $current_text ); ?></p></div></li></ol></section>
		<?php
	}

	private function final_report( array $profile, array $decisions, $lift, $monthly, $business_optimized ) {
		$winners      = 0;
		$rejected     = 0;
		$inconclusive = 0;
		$high         = true;
		foreach ( $decisions as $decision ) {
			if ( 'promote' === $decision['decision'] ) {
				++$winners;
				$high = $high && 'high' === $decision['confidence'];
			} elseif ( in_array( $decision['decision'], array( 'reject', 'rollback' ), true ) ) {
				++$rejected;
			} elseif ( 'inconclusive' === $decision['decision'] ) {
				++$inconclusive;
			}
		}
		$removed = max( 0, absint( $profile['original_field_count'] ) - absint( $profile['current_field_count'] ) );
		?>
		<section class="fh-min-card fh-min-final">
			<div><span class="fh-min-kicker"><?php esc_html_e( 'MINIMUM VIABLE FORM FOUND', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Every remaining field has earned its place', 'formhawk' ); ?></h2><p><?php esc_html_e( 'The result is based on sequential provider-confirmed experiments. Decisions remain monitored and reversible.', 'formhawk' ); ?></p></div>
			<div class="fh-min-final-grid"><div><span><?php esc_html_e( 'Original', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( $profile['original_field_count'] ) ); ?></strong><small><?php esc_html_e( 'fields', 'formhawk' ); ?></small></div><div><span><?php esc_html_e( 'Optimized', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( $profile['current_field_count'] ) ); ?></strong><small><?php esc_html_e( 'fields', 'formhawk' ); ?></small></div><div><span><?php esc_html_e( 'Removed', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( $removed ) ); ?></strong><small><?php esc_html_e( 'fields', 'formhawk' ); ?></small></div><div><span><?php echo esc_html( $business_optimized ? __( 'Business value lift', 'formhawk' ) : __( 'Confirmed conversion lift', 'formhawk' ) ); ?></span><strong><?php echo esc_html( null === $lift ? __( 'Not measured', 'formhawk' ) : $this->percent( $lift ) ); ?></strong><small><?php esc_html_e( 'same-metric promotions', 'formhawk' ); ?></small></div></div>
			<div class="fh-min-final-evidence"><span><?php echo esc_html( number_format_i18n( count( $decisions ) ) . ' ' . __( 'experiments', 'formhawk' ) ); ?></span><span><?php echo esc_html( number_format_i18n( $winners ) . ' ' . __( 'winners', 'formhawk' ) ); ?></span><span><?php echo esc_html( number_format_i18n( $rejected ) . ' ' . __( 'rejected', 'formhawk' ) ); ?></span><span><?php echo esc_html( number_format_i18n( $inconclusive ) . ' ' . __( 'inconclusive', 'formhawk' ) ); ?></span><span><?php echo esc_html( $winners && $high ? __( 'HIGH CONFIDENCE', 'formhawk' ) : __( 'MEASURED EVIDENCE', 'formhawk' ) ); ?></span></div>
			<?php if ( $monthly ) : ?>
				<div class="fh-min-impact"><div><span><?php esc_html_e( 'ESTIMATED ADDITIONAL VALUE', 'formhawk' ); ?></span><strong><?php echo esc_html( $monthly['currency'] . ' ' . number_format_i18n( $monthly['amount'], 0 ) . ' / ' . __( 'month', 'formhawk' ) ); ?></strong></div><p><?php esc_html_e( 'Measured revenue-per-visitor lift multiplied by the last 30 days of observed traffic.', 'formhawk' ); ?></p></div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function estimated_monthly_value( $form_id, array $decisions ) {
		$delta_minor = 0.0;
		foreach ( $decisions as $decision ) {
			if ( 'promote' !== $decision['decision'] || 'revenue_per_visitor' !== $decision['primary_metric'] || null === $decision['control_value'] || null === $decision['variant_value'] ) {
				continue;
			}
			$delta_minor += (float) $decision['variant_value'] - (float) $decision['control_value'];
		}
		if ( $delta_minor <= 0 ) {
			return null;
		}
		$traffic = $this->analytics->form_stats( $form_id, 30 );
		$views   = absint( $traffic['views'] ?? 0 );
		if ( ! $views ) {
			return null;
		}
		return array(
			'amount'   => ( $delta_minor * $views ) / 100,
			'currency' => $this->currency(),
		);
	}

	private function decision_conversion( array $decision ) {
		$control_visitors  = absint( $decision['control_visitors'] ?? 0 );
		$variant_visitors  = absint( $decision['variant_visitors'] ?? 0 );
		$control_confirmed = absint( $decision['control_confirmed'] ?? 0 );
		$variant_confirmed = absint( $decision['variant_confirmed'] ?? 0 );
		if ( ! $control_visitors || ! $variant_visitors || $control_confirmed > $control_visitors || $variant_confirmed > $variant_visitors ) {
			return __( 'Not available', 'formhawk' );
		}
		return number_format_i18n( 100 * $control_confirmed / $control_visitors, 1 ) . '% → ' . number_format_i18n( 100 * $variant_confirmed / $variant_visitors, 1 ) . '%';
	}

	private function controls( array $form, array $profile, $active ) {
		?>
		<section class="fh-min-card fh-min-controls"><div><span class="fh-min-kicker"><?php esc_html_e( 'USER CONTROLS', 'formhawk' ); ?></span><h2><?php esc_html_e( 'You stay in control', 'formhawk' ); ?></h2></div><div class="fh-min-actions">
		<?php
		if ( $active && in_array( $active['status'], array( 'suggested', 'awaiting_approval', 'paused_manual' ), true ) ) {
			$this->button( $form['id'], 'start_experiment', __( 'Start proposed experiment', 'formhawk' ), true ); }
		?>
		<?php
		if ( 'revalidation_required' === $profile['status'] ) {
			$this->button( $form['id'], 'revalidate', __( 'Build a new verified baseline', 'formhawk' ), true ); }
		?>
		<?php
		if ( 'paused' === $profile['status'] ) {
			$this->button( $form['id'], 'resume', __( 'Resume optimization', 'formhawk' ), true );
		} elseif ( in_array( $profile['status'], array( 'collecting', 'optimizing', 'rollback' ), true ) ) {
			$this->button( $form['id'], 'pause', __( 'Pause optimization', 'formhawk' ) ); }
		?>
		<?php
		if ( in_array( $profile['status'], array( 'collecting', 'optimizing', 'rollback' ), true ) ) {
			$this->button( $form['id'], 'evaluate', __( 'Evaluate now', 'formhawk' ) ); }
		?>
		<?php
		if ( absint( $profile['current_baseline_id'] ) !== absint( $profile['original_baseline_id'] ) ) {
			$this->button( $form['id'], 'restore_previous', __( 'Restore previous baseline', 'formhawk' ) ); }
		?>
		<?php $this->button( $form['id'], 'disable', __( 'Disable Minimum Form', 'formhawk' ), false, true ); ?>
		</div></section>
		<?php
	}

	private function button( $form_id, $operation, $label, $primary = false, $danger = false ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="formhawk_minimum_form"><input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>"><input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>"><?php wp_nonce_field( 'formhawk_minimum_form' ); ?><button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?> <?php echo $danger ? 'fh-min-danger' : ''; ?>"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	private function header( array $form, $status ) {
		?>
		<div class="fh-min-page-head"><div><a href="<?php echo esc_url( admin_url( 'admin.php?page=formhawk-minimum-form' ) ); ?>"><?php esc_html_e( 'All forms', 'formhawk' ); ?></a><h1><?php echo esc_html( $form['title'] ? $form['title'] : $form['provider_form_id'] ); ?></h1><p><?php echo esc_html( strtoupper( $form['provider'] ) . ' · ' . $form['page_path'] ); ?></p></div><span class="fh-min-badge is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $status ) ) ); ?></span></div>
		<?php
	}

	private function mutation_keys( array $mutations, $type ) {
		$output = array();
		foreach ( $mutations as $mutation ) {
			if ( is_array( $mutation ) && ( $mutation['type'] ?? '' ) === $type && ! empty( $mutation['config']['field_key'] ) ) {
				$output[] = (string) $mutation['config']['field_key'];
			}
		}
		return array_values( array_unique( $output ) );
	}

	private function cumulative_lift( array $decisions, $metric ) {
		$factor = 1.0;
		$found  = false;
		foreach ( $decisions as $decision ) {
			if ( 'promote' === $decision['decision'] && $metric === $decision['primary_metric'] && null !== $decision['business_lift'] ) {
				$factor *= 1 + (float) $decision['business_lift'];
				$found   = true;
			}
		}
		return $found ? $factor - 1 : null;
	}

	private function rate( array $row ) {
		$assignments = absint( $row['assignments'] ?? 0 );
		$conversions = absint( $row['confirmed_successes'] ?? 0 );
		return $assignments && $conversions <= $assignments ? number_format_i18n( 100 * $conversions / $assignments, 1 ) . '%' : __( 'Collecting', 'formhawk' );
	}

	private function percent( $value ) {
		return ( $value >= 0 ? '+' : '' ) . number_format_i18n( 100 * $value, 1 ) . '%';
	}

	private function status_label( $status ) {
		$labels = array(
			'collecting'            => __( 'Minimum Form is learning', 'formhawk' ),
			'optimizing'            => __( 'Optimizing', 'formhawk' ),
			'paused'                => __( 'Optimization paused', 'formhawk' ),
			'optimized'             => __( 'Minimum Viable Form found', 'formhawk' ),
			'revalidation_required' => __( 'Baseline needs revalidation', 'formhawk' ),
			'integrity_failure'     => __( 'Integrity review required', 'formhawk' ),
			'rollback'              => __( 'Rolling back', 'formhawk' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Minimum Viable Form', 'formhawk' );
	}

	private function reason_text( $reason ) {
		$reasons = array(
			'credible_business_value_improvement' => __( 'Strong evidence shows this change improves business value per visitor.', 'formhawk' ),
			'credible_business_value_harm'        => __( 'The change increased risk to business value and was rejected.', 'formhawk' ),
			'qualified_lead_guardrail'            => __( 'Qualified leads per visitor fell significantly, so Formhawk protected the field.', 'formhawk' ),
			'credible_improvement'                => __( 'Provider-confirmed conversion improved beyond the practical significance threshold.', 'formhawk' ),
			'credible_harm'                       => __( 'The change caused credible harm and was rejected.', 'formhawk' ),
			'practical_significance'              => __( 'The measured difference is too small relative to uncertainty to justify removing a field.', 'formhawk' ),
			'maximum_runtime'                     => __( 'The experiment reached its maximum runtime without decisive evidence.', 'formhawk' ),
		);
		return isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : str_replace( '_', ' ', $reason );
	}

	private function currency() {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		return is_array( $settings ) && isset( $settings['currency'] ) ? strtoupper( $settings['currency'] ) : 'USD';
	}

	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Minimum Form.', 'formhawk' ) );
		}
	}

	private function choice( $value, array $allowed, $fallback ) {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private function post( $key, $fallback ) {
		// Nonce is checked by action() before this helper is called.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The scalar value is sanitized immediately below.
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
	}

	private function query( $key, $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation value, normalized immediately.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $fallback;
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
	}

	private function notice() {
		$key    = 'formhawk_minimum_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php
		}
	}
}
