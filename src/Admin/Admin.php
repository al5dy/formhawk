<?php

namespace Formhawk\Admin;

use Formhawk\Analytics\AnalyticsRepository;
use Formhawk\Analytics\FormRepository;
use Formhawk\Analytics\HealthEvaluator;
use Formhawk\Analytics\EvidenceMetrics;
use Formhawk\CRO\AutopilotManager;
use Formhawk\CRO\CacheCoordinator;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\CRO\FormOptimizationScore;
use Formhawk\CRO\ProviderCROCapabilities;
use Formhawk\CRO\WinnerSelector;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Infrastructure\IngestionDiagnostics;
use Formhawk\Infrastructure\Activator;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\ModuleGate;
use Formhawk\Integrations\IntegrationRegistry;
use Formhawk\MinimumForm\MinimumFormManager;
use Formhawk\Outcomes\Currency;
use Formhawk\ROI\FieldROIRepository;

final class Admin {
	private $forms;
	private $analytics;
	private $integrations;
	private $experiments;

	public function __construct( FormRepository $forms, ?IntegrationRegistry $integrations = null, ?ExperimentRepository $experiments = null ) {
		$this->forms        = $forms;
		$this->analytics    = new AnalyticsRepository();
		$this->integrations = $integrations;
		$this->experiments  = $experiments ? $experiments : new ExperimentRepository();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_formhawk_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_formhawk_test_mail', array( $this, 'test_mail' ) );
		add_action( 'admin_post_formhawk_autopilot', array( $this, 'autopilot_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FORMHAWK_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Formhawk', 'formhawk' ),
			__( 'Formhawk', 'formhawk' ),
			'manage_options',
			'formhawk',
			array( $this, 'render' ),
			'dashicons-chart-area',
			58
		);
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_formhawk' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'formhawk-admin', FORMHAWK_URL . 'assets/css/admin.css', array(), FORMHAWK_VERSION );
		wp_enqueue_style( 'formhawk-admin-cro', FORMHAWK_URL . 'assets/css/admin-cro.css', array( 'formhawk-admin' ), FORMHAWK_VERSION );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=formhawk' ) ) . '">' . esc_html__( 'Dashboard', 'formhawk' ) . '</a>' );
		return $links;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access Formhawk.', 'formhawk' ) );
		}

		$this->notice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only raw input is type-checked and normalized on the next line.
		$form_id = isset( $_GET['form_id'] ) ? wp_unslash( $_GET['form_id'] ) : 0;
		$form_id = is_scalar( $form_id ) ? absint( $form_id ) : 0;
		if ( $form_id ) {
			$this->render_form( $form_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only raw input is type-checked and sanitized on the next line.
		$tab = isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'overview';
		$tab = is_scalar( $tab ) ? sanitize_key( (string) $tab ) : 'overview';
		$this->header( $tab );

		switch ( $tab ) {
			case 'diagnostics':
				$this->render_diagnostics();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_overview();
				break;
		}
		echo '</div>';
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'formhawk' ) );
		}
		check_admin_referer( 'formhawk_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and normalized on the next line.
		$days = isset( $_POST['retention_days'] ) ? wp_unslash( $_POST['retention_days'] ) : 90;
		$days = is_scalar( $days ) ? absint( $days ) : 90;
		if ( ! in_array( $days, array( 30, 90, 180, 365 ), true ) ) {
			$days = 90;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and sanitized on the next line.
		$delete_on_uninstall = isset( $_POST['delete_on_uninstall'] ) ? wp_unslash( $_POST['delete_on_uninstall'] ) : '';
		$delete_on_uninstall = is_scalar( $delete_on_uninstall ) && '1' === sanitize_text_field( (string) $delete_on_uninstall ) ? 1 : 0;
		update_option(
			'formhawk_settings',
			array(
				'retention_days'      => $days,
				'delete_on_uninstall' => $delete_on_uninstall,
			),
			false
		);
		$this->set_notice( 'success', __( 'Settings saved.', 'formhawk' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=formhawk&tab=settings' ) );
		exit;
	}

	public function test_mail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'formhawk' ) );
		}
		check_admin_referer( 'formhawk_test_mail' );
		$user      = wp_get_current_user();
		$recipient = $user && is_email( $user->user_email ) ? $user->user_email : get_option( 'admin_email' );
		$result    = wp_mail(
			$recipient,
			__( 'Formhawk mail health check', 'formhawk' ),
			__( 'This message was generated by a manual Formhawk mail health check. Formhawk does not store the recipient or message content.', 'formhawk' )
		);
		$this->set_notice(
			$result ? 'success' : 'error',
			$result
				? __( 'WordPress accepted the test email for sending. This confirms wp_mail() completed without an immediate error, not inbox delivery.', 'formhawk' )
				: __( 'wp_mail() reported a failure. Check your SMTP/mail configuration and the Formhawk mail health status below.', 'formhawk' )
		);
		wp_safe_redirect( admin_url( 'admin.php?page=formhawk&tab=diagnostics' ) );
		exit;
	}

	public function autopilot_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'formhawk' ) );
		}
		check_admin_referer( 'formhawk_autopilot' );
		if ( ! Database::cro_schema_is_current() ) {
			wp_die( esc_html__( 'Autopilot storage is not ready. Check Formhawk Diagnostics and database permissions.', 'formhawk' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw admin action is type-checked and normalized below.
		$form_id = isset( $_POST['form_id'] ) ? wp_unslash( $_POST['form_id'] ) : 0;
		$form_id = is_scalar( $form_id ) ? absint( $form_id ) : 0;
		$form    = $this->forms->find( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formhawk' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw admin action is allowlisted below.
		$operation         = isset( $_POST['operation'] ) ? wp_unslash( $_POST['operation'] ) : '';
		$operation         = is_scalar( $operation ) ? sanitize_key( (string) $operation ) : '';
		$locked_operations = array( 'start', 'pause', 'stop', 'reject', 'promote', 'rollback', 'disable' );
		$has_lock          = false;
		if ( in_array( $operation, $locked_operations, true ) ) {
			$has_lock = $this->experiments->acquire_lock( $form_id );
			if ( ! $has_lock ) {
				$this->set_notice( 'error', __( 'Another Autopilot decision is in progress. No changes were made; try again shortly.', 'formhawk' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=formhawk&form_id=' . $form_id ) );
				exit;
			}
		}
		$active            = $this->experiments->active_for_form( $form_id );
		$message           = __( 'Autopilot settings updated.', 'formhawk' );
		$purge             = false;
		$success           = true;
		$editable_statuses = array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::RUNNING, ExperimentStatus::PAUSED_MANUAL );

		if ( in_array( $operation, array( 'enable', 'save' ), true ) ) {
			// The one-click enable action has no settings fields. Passing synthetic
			// zeroes would silently replace Balanced defaults with hard minimums.
			$input   = 'save' === $operation ? $this->autopilot_input() : array();
			$success = $this->experiments->enable( $form_id, $input );
			if ( $success ) {
				( new AutopilotManager( $this->experiments, $this->forms ) )->evaluate_form( $form_id );
				$message = 'enable' === $operation ? __( 'Autopilot enabled in Approve mode.', 'formhawk' ) : $message;
			} else {
				$message = __( 'Autopilot settings could not be saved. The original form remains unchanged.', 'formhawk' );
			}
		} elseif ( 'start' === $operation && $active && in_array( $active['status'], array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
			$success = $this->experiments->start( $active['id'] );
			$purge   = $success;
			$message = $success ? __( 'Experiment started.', 'formhawk' ) : __( 'Experiment could not be started; its safe baseline remains active.', 'formhawk' );
		} elseif ( 'pause' === $operation && $active && ExperimentStatus::RUNNING === $active['status'] ) {
			$success = $this->experiments->route_to_control( $active['id'] ) && $this->experiments->set_status( $active['id'], ExperimentStatus::PAUSED_MANUAL );
			$purge   = true;
			$message = $success ? __( 'Experiment paused; new views use the safe baseline.', 'formhawk' ) : __( 'Experiment pause could not be persisted. Check Autopilot Diagnostics.', 'formhawk' );
		} elseif ( 'stop' === $operation && $active && in_array( $active['status'], $editable_statuses, true ) ) {
			$success = $this->experiments->route_to_control( $active['id'] ) && $this->experiments->set_status( $active['id'], ExperimentStatus::MANUALLY_STOPPED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			if ( $success ) {
				$this->record_manual_decision( $form_id, $active, 'manually_stopped' );
			}
			$purge   = true;
			$message = $success ? __( 'Experiment stopped.', 'formhawk' ) : __( 'Experiment could not be stopped cleanly. Check Autopilot Diagnostics.', 'formhawk' );
		} elseif ( 'reject' === $operation && $active && in_array( $active['status'], $editable_statuses, true ) ) {
			$success = $this->experiments->route_to_control( $active['id'] ) && $this->experiments->set_status( $active['id'], ExperimentStatus::REJECTED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			if ( $success ) {
				$this->record_manual_decision( $form_id, $active, 'manual_reject' );
				do_action( 'formhawk_cro_variant_rejected', $active['id'], 0, 'manual' );
			}
			$purge   = true;
			$message = $success ? __( 'Variant rejected; baseline restored.', 'formhawk' ) : __( 'Variant rejection could not be persisted. Check Autopilot Diagnostics.', 'formhawk' );
		} elseif ( 'promote' === $operation && $active && in_array( $active['status'], $editable_statuses, true ) ) {
			$variants = $this->experiments->variants( $active['id'] );
			if ( isset( $variants[1]['config']['mutations'] ) && $this->experiments->promote_experiment( $form_id, $active['id'], $variants[1]['id'], $variants[1]['config']['mutations'] ) ) {
				do_action( 'formhawk_cro_winner_promoted', $active['id'], $variants[1]['id'] );
				$this->record_manual_decision( $form_id, $active, 'manual_promote', $variants[1]['config']['mutations'] );
				$purge   = true;
				$message = __( 'Variant promoted manually and entered regression monitoring.', 'formhawk' );
			} else {
				$success = false;
				$message = __( 'The variant could not be promoted safely. The current baseline was preserved.', 'formhawk' );
			}
		} elseif ( 'rollback' === $operation ) {
			$candidate = $active && ExperimentStatus::PROMOTED_MONITORING === $active['status'] ? $active : $this->experiments->latest_rollback_candidate( $form_id );
			if ( $candidate && $this->experiments->rollback_experiment( $form_id, $candidate['id'] ) ) {
				do_action( 'formhawk_cro_rollback', $candidate['id'], $candidate['winner_variant_id'] );
				$this->record_manual_decision( $form_id, $candidate, 'manual_rollback' );
				$purge   = true;
				$message = __( 'Previous validated baseline restored.', 'formhawk' );
			} else {
				$success = false;
				$message = __( 'No validated winner is available to roll back.', 'formhawk' );
			}
		} elseif ( 'disable' === $operation ) {
			$closed = true;
			if ( $active ) {
				$closed = $this->experiments->route_to_control( $active['id'] ) && $this->experiments->set_status( $active['id'], ExperimentStatus::MANUALLY_STOPPED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			$disabled = $this->experiments->disable( $form_id );
			$success  = $closed && $disabled;
			$purge    = true;
			if ( ! $disabled ) {
				$message = __( 'Autopilot could not be disabled. Check Autopilot Diagnostics.', 'formhawk' );
			} elseif ( ! $closed ) {
				$message = __( 'Autopilot was disabled and the original form is shown, but the experiment audit state could not be finalized.', 'formhawk' );
			} else {
				$message = __( 'Autopilot disabled. The original provider form is now shown.', 'formhawk' );
			}
		} else {
			$success = false;
			$message = __( 'This Autopilot action is not valid for the current experiment state.', 'formhawk' );
		}

		if ( $purge ) {
			( new CacheCoordinator() )->purge();
		}
		if ( $has_lock ) {
			$this->experiments->release_lock( $form_id );
		}
		$this->set_notice( $success ? 'success' : 'error', $message );
		wp_safe_redirect( admin_url( 'admin.php?page=formhawk&form_id=' . $form_id ) );
		exit;
	}

	private function autopilot_input() {
		$input = array();
		foreach ( array( 'mode', 'aggressiveness', 'currency', 'optimization_objective' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the action nonce; values are type-checked here and allowlisted in the repository.
			$value         = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$input[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		foreach ( array( 'max_experimental_traffic', 'min_duration_days', 'min_conversions' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the action nonce; numeric settings are bounded by policy.
			$value         = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : 0;
			$input[ $key ] = is_scalar( $value ) ? absint( $value ) : 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the action nonce; optional lead value is bounded in the repository.
		$value               = isset( $_POST['lead_value'] ) ? wp_unslash( $_POST['lead_value'] ) : '';
		$input['lead_value'] = is_scalar( $value ) ? (string) $value : '';
		return $input;
	}

	private function record_manual_decision( $form_id, array $experiment, $decision, ?array $resulting_baseline = null ) {
		$settings = $this->experiments->settings( $form_id );
		if ( ! $settings ) {
			return;
		}
		$previous  = in_array( $decision, array( 'manual_promote', 'manual_rollback' ), true ) ? $settings['previous_baseline'] : $settings['baseline'];
		$resulting = null === $resulting_baseline ? $settings['baseline'] : $resulting_baseline;
		$this->experiments->add_history(
			array(
				'form_id'            => $form_id,
				'experiment_id'      => $experiment['id'],
				'decision'           => $decision,
				'previous_baseline'  => $previous,
				'resulting_baseline' => $resulting,
				'algorithm_version'  => $experiment['algorithm_version'],
				'policy_version'     => $experiment['policy_version'],
				'reason'             => 'manual_admin_override',
			)
		);
	}

	private function render_overview() {
		$days     = $this->days();
		$page     = $this->page_number();
		$per_page = 50;
		$totals   = $this->analytics->overview_totals( $days );
		$page     = min( $page, max( 1, (int) ceil( $totals['forms'] / $per_page ) ) );
		$rows     = $this->analytics->overview( $days, $page, $per_page );
		$rate     = EvidenceMetrics::ratio( $totals['eligible_confirmed_successes'], $totals['eligible_starts'] );
		?>
		<div class="fh-toolbar">
			<div>
				<h2><?php esc_html_e( 'Forms', 'formhawk' ); ?></h2>
				<p><?php esc_html_e( 'Conversion and health at a glance. No field values are collected.', 'formhawk' ); ?></p>
			</div>
			<?php $this->range_picker( $days ); ?>
		</div>

		<div class="fh-metrics">
			<?php $this->metric( __( 'Forms tracked', 'formhawk' ), $totals['forms'], __( 'discovered automatically', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Views', 'formhawk' ), $totals['views'], __( 'entered the viewport', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Started', 'formhawk' ), $totals['starts'], __( 'first interaction', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Submit attempts', 'formhawk' ), $totals['submit_attempts'], __( 'browser-observed, all forms', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Observed HTML attempts', 'formhawk' ), $totals['observed_generic_attempts'], __( 'backend outcome unknown', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Confirmed successes', 'formhawk' ), $totals['eligible_confirmed_successes'], __( 'provider-confirmed', 'formhawk' ) ); ?>
			<?php $this->metric( __( 'Confirmed conversion', 'formhawk' ), $this->rate_label( $rate ), __( 'confirmed / starts, supported providers only', 'formhawk' ) ); ?>
		</div>

		<div class="fh-card fh-table-card">
			<?php if ( empty( $rows ) ) : ?>
				<div class="fh-empty">
					<span class="dashicons dashicons-chart-area"></span>
					<h3><?php esc_html_e( 'Waiting for form traffic', 'formhawk' ); ?></h3>
					<p><?php esc_html_e( 'Formhawk is active. Visit a frontend page containing a form; it will appear here automatically when the form enters the visitor viewport.', 'formhawk' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat fixed striped fh-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Form', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Health', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Views', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Started', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Attempts', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Confirmed successes', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Confirmed conversion', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Abandonment', 'formhawk' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last success', 'formhawk' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $rows as $row ) :
						$health = HealthEvaluator::evaluate( $row );
						$conv   = HealthEvaluator::conversion_rate( $row );
						$ab     = HealthEvaluator::abandonment_rate( $row );
						$url    = add_query_arg(
							array(
								'page'    => 'formhawk',
								'form_id' => absint( $row['id'] ),
								'days'    => $days,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<a class="fh-form-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $this->form_title( $row ) ); ?></a>
								<div class="fh-muted"><?php echo esc_html( $this->provider_label( $row['provider'] ) . ' · ' . $row['page_path'] ); ?></div>
							</td>
							<td><?php $this->status( $health ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['starts'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['submit_attempts'] ) ); ?></td>
							<td><?php echo esc_html( $this->confirmed_label( $row ) ); ?></td>
							<td><strong><?php echo esc_html( $this->rate_label( $conv ) ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $ab, 1 ) . '%' ); ?></td>
							<td><?php echo esc_html( $this->relative_time( $row['last_success_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php $this->overview_pagination( $page, $per_page, $totals['forms'], $days ); ?>
		<?php
	}

	private function render_form( $form_id ) {
		$form = $this->forms->find( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formhawk' ) );
		}
		$days       = $this->days();
		$current    = $this->analytics->form_stats( $form_id, $days );
		$previous   = $this->analytics->form_stats( $form_id, $days, true );
		$fields     = $this->analytics->field_stats( $form_id, $days );
		$placements = $this->analytics->placement_stats( $form_id, $days );
		$row        = array_merge( $form, $current );
		$health     = HealthEvaluator::evaluate( $row );
		$anomaly    = HealthEvaluator::anomaly( $row, array_merge( $form, $previous ) );
		$conv       = HealthEvaluator::conversion_rate( $row );
		$ab         = HealthEvaluator::abandonment_rate( $current );
		$avg_ms     = ! empty( $current['duration_samples'] ) ? (int) round( $current['duration_total_ms'] / $current['duration_samples'] ) : 0;
		?>
		<div class="wrap fh-wrap">
			<div class="fh-detail-head">
				<div>
					<a class="fh-back" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'formhawk',
								'days' => $days,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
												">← <?php esc_html_e( 'All forms', 'formhawk' ); ?></a>
					<h1><?php echo esc_html( $this->form_title( $form ) ); ?></h1>
					<p><?php echo esc_html( $this->provider_label( $form['provider'] ) . ' · ' . $form['page_path'] ); ?></p>
				</div>
				<div class="fh-detail-actions"><?php $this->status( $health, true ); ?><?php $this->range_picker( $days, $form_id ); ?></div>
			</div>

			<?php if ( $anomaly ) : ?>
				<?php
				/* translators: %s: percentage drop in form conversion. */
				$drop_message = sprintf( __( 'Confirmed conversion dropped %s%%.', 'formhawk' ), number_format_i18n( $anomaly['drop'], 0 ) );
				/* translators: 1: current conversion percentage, 2: previous comparable-period conversion percentage. */
				$period_message = sprintf( __( 'Current period: %1$s%%. Previous comparable period: %2$s%%.', 'formhawk' ), number_format_i18n( $anomaly['current_rate'], 1 ), number_format_i18n( $anomaly['previous_rate'], 1 ) );
				?>
				<div class="fh-alert fh-alert-warning"><strong><?php echo esc_html( $drop_message ); ?></strong> <?php echo esc_html( $period_message ); ?></div>
			<?php endif; ?>

			<div class="fh-metrics fh-metrics-detail">
				<?php $this->metric( __( 'Views', 'formhawk' ), $current['views'], __( 'form viewed', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Started', 'formhawk' ), $current['starts'], $this->percent_of( $current['starts'], $current['views'] ) . ' ' . __( 'of views', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Submit attempts', 'formhawk' ), $current['submit_attempts'], __( 'browser-observed', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Confirmed successes', 'formhawk' ), $this->confirmed_label( $row ), __( 'provider-confirmed', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Confirmed conversion', 'formhawk' ), $this->rate_label( $conv ), __( 'confirmed / starts', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Observed attempt rate', 'formhawk' ), $this->rate_label( EvidenceMetrics::observed_attempt_rate( $row ) ), __( 'browser attempts / starts', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Abandoned', 'formhawk' ), $current['abandons'], number_format_i18n( $ab, 1 ) . '% ' . __( 'of starts', 'formhawk' ) ); ?>
				<?php $this->metric( __( 'Avg. time', 'formhawk' ), $this->duration( $avg_ms ), __( 'started → submit/leave', 'formhawk' ) ); ?>
			</div>

			<p class="fh-note"><?php esc_html_e( 'Conversion is an aggregate ratio, not a linked visitor funnel. N/A means unavailable evidence, no denominator, or more outcomes than recorded starts. Browser validation friction is shown as reports, not a failure rate: native validation can block submission before a submit event exists.', 'formhawk' ); ?></p>
			<p class="fh-note"><?php esc_html_e( 'Validation rejection share = provider validation rejections / (provider validation rejections + accepted submissions), measured since the evidence upgrade. Other provider failures and unknown outcomes are excluded.', 'formhawk' ); ?></p>
			<?php $this->render_business_value_intelligence( $form ); ?>
			<?php $this->render_autopilot( $form, $current, $fields ); ?>
			<details><summary><?php esc_html_e( 'Historical counters (legacy evidence)', 'formhawk' ); ?></summary>
				<p><?php esc_html_e( 'These frozen counters predate evidence separation and are excluded from current conversion and validation calculations.', 'formhawk' ); ?></p>
				<dl class="fh-kv"><div><dt><?php esc_html_e( 'Legacy submissions (mixed evidence)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $current['submissions'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Legacy validation (source unknown)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $current['validation_failures'] ) ); ?></dd></div></dl>
			</details>
			<div class="fh-grid-2">
				<div class="fh-card">
					<div class="fh-card-head"><h2><?php esc_html_e( 'Conversion funnel', 'formhawk' ); ?></h2></div>
					<?php $this->funnel_row( __( 'Viewed', 'formhawk' ), $current['views'], $current['views'] ); ?>
					<?php $this->funnel_row( __( 'Started', 'formhawk' ), $current['starts'], $current['views'] ); ?>
					<?php $this->funnel_row( __( 'Browser submit attempts', 'formhawk' ), $current['submit_attempts'], $current['views'] ); ?>
				</div>
				<div class="fh-card">
					<div class="fh-card-head"><h2><?php esc_html_e( 'Health', 'formhawk' ); ?></h2></div>
					<div class="fh-health-large"><?php $this->status( $health, true ); ?><p><?php echo esc_html( $health['reason'] ); ?></p></div>
					<dl class="fh-kv">
						<div><dt><?php esc_html_e( 'Last confirmed success', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->relative_time( $form['last_success_at'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Last failure', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->relative_time( $form['last_failure_at'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Browser validation friction reports', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $current['client_validation_failures'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Provider validation rejections', 'formhawk' ); ?></dt><dd><?php echo esc_html( ProviderCatalog::has_server_success( $form['provider'] ) ? number_format_i18n( $current['provider_validation_failures'] ) : __( 'N/A', 'formhawk' ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Validation rejection share', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->rate_label( EvidenceMetrics::validation_rate( $row ) ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Provider failures', 'formhawk' ); ?></dt><dd><?php echo esc_html( ProviderCatalog::has_server_failure( $form['provider'] ) ? number_format_i18n( $current['failures'] ) : __( 'N/A', 'formhawk' ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Mail action successes', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $current['mail_successes'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Mail failures', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $current['mail_failures'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Confirmed successes', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->confirmed_label( $row ) ); ?></dd></div>
					</dl>
					<?php
					if ( ! ProviderCatalog::has_server_success( $form['provider'] ) ) :
						?>
						<p class="fh-note"><?php esc_html_e( 'Standard HTML forms expose browser submit attempts but have no universal server-side success signal. Supported form plugins use provider-confirmed lifecycle hooks.', 'formhawk' ); ?></p><?php endif; ?>
				</div>
			</div>

			<div class="fh-card fh-table-card">
				<div class="fh-card-head"><h2><?php esc_html_e( 'Placements', 'formhawk' ); ?></h2><span><?php esc_html_e( 'Performance by page path', 'formhawk' ); ?></span></div>
				<?php if ( empty( $placements ) ) : ?>
					<div class="fh-empty-mini"><?php esc_html_e( 'Placement analytics will appear after the next tracked event.', 'formhawk' ); ?></div>
				<?php else : ?>
					<table class="widefat striped fh-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Page path', 'formhawk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Views', 'formhawk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Started', 'formhawk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attempts', 'formhawk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Confirmed successes', 'formhawk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Confirmed conversion', 'formhawk' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $placements as $placement ) : ?>
							<tr>
								<td><code><?php echo esc_html( $placement['page_path'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $placement['views'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $placement['starts'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $placement['submit_attempts'] ) ); ?></td>
								<td><?php echo esc_html( $this->confirmed_label( array_merge( $placement, array( 'provider' => $form['provider'] ) ) ) ); ?></td>
								<td><?php echo esc_html( $this->rate_label( EvidenceMetrics::confirmed_conversion( array_merge( $placement, array( 'provider' => $form['provider'] ) ) ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fh-grid-2">
				<div class="fh-card">
					<div class="fh-card-head"><h2><?php esc_html_e( 'Where people abandon', 'formhawk' ); ?></h2><span><?php esc_html_e( 'Last field before leaving', 'formhawk' ); ?></span></div>
					<?php $this->field_table( $fields, 'abandonments' ); ?>
				</div>
				<div class="fh-card">
					<div class="fh-card-head"><h2><?php esc_html_e( 'Validation friction', 'formhawk' ); ?></h2><span><?php esc_html_e( 'Errors by field', 'formhawk' ); ?></span></div>
					<h3><?php esc_html_e( 'Browser friction', 'formhawk' ); ?></h3>
					<?php $this->field_table( $fields, 'client_validation_errors' ); ?>
					<h3><?php esc_html_e( 'Provider validation', 'formhawk' ); ?></h3>
					<?php
					if ( ProviderCatalog::has_server_success( $form['provider'] ) ) {
						$this->field_table( $fields, 'provider_validation_errors' );
					} else {
						echo '<p>' . esc_html__( 'N/A: this form has no provider validation evidence.', 'formhawk' ) . '</p>'; }
					?>
					<h3><?php esc_html_e( 'Legacy validation (source unknown)', 'formhawk' ); ?></h3>
					<?php $this->field_table( $fields, 'validation_errors' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_business_value_intelligence( array $form ) {
		if ( ! Database::field_roi_schema_is_current() || ! ( new ModuleGate() )->enabled( 'field_roi' ) ) {
			return;
		}
		$repository  = new FieldROIRepository();
		$settings    = get_option( 'formhawk_field_roi_settings', array() );
		$settings    = is_array( $settings ) ? $settings : array();
		$currency    = isset( $settings['currency'] ) ? strtoupper( $settings['currency'] ) : 'USD';
		$end         = current_time( 'Y-m-d' );
		$start       = wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS, wp_timezone() );
		$summary     = $repository->form_summary( $form['id'], $start, $end, $currency );
		$rows        = array_values(
			array_filter(
				$repository->results( 200 ),
				static function ( $row ) use ( $form ) {
					return absint( $row['form_id'] ) === absint( $form['id'] );
				}
			)
		);
		$money_maker = null;
		$killer      = null;
		foreach ( $rows as $row ) {
			if ( ! $money_maker && in_array( $row['verdict'], array( 'money_maker', 'qualifier', 'free_value' ), true ) ) {
				$money_maker = $row;
			}
			if ( ! $killer && 'conversion_killer' === $row['verdict'] ) {
				$killer = $row;
			}
		}
		$views              = absint( $summary['views'] );
		$actual_revenue     = absint( $summary['revenue_samples'] ) > 0;
		$value_per_visitor  = $actual_revenue && $views ? (int) round( (int) $summary['revenue_minor'] / $views ) : null;
		$raw_conversion     = $views ? 100 * absint( $summary['submissions'] ) / $views : null;
		$qualified_per_view = $views ? 100 * absint( $summary['qualified'] ) / $views : null;
		$link               = admin_url( 'admin.php?page=formhawk-field-roi' );
		$minimum_form_link  = admin_url( 'admin.php?page=formhawk-minimum-form&form_id=' . absint( $form['id'] ) );
		?>
		<section class="fh-card fh-business-value">
			<div class="fh-card-head"><div><span class="fh-business-kicker"><?php esc_html_e( 'BUSINESS VALUE INTELLIGENCE', 'formhawk' ); ?></span><h2><?php esc_html_e( 'Field ROI', 'formhawk' ); ?></h2></div><div class="fh-card-actions"><a class="button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open Field Value Map', 'formhawk' ); ?></a><a class="button button-primary" href="<?php echo esc_url( $minimum_form_link ); ?>"><?php esc_html_e( 'Find Minimum Form', 'formhawk' ); ?></a></div></div>
			<?php if ( empty( $settings['enabled'] ) ) : ?>
				<p><?php esc_html_e( 'Connect business outcomes to learn which fields protect value and which only create friction.', 'formhawk' ); ?></p>
			<?php else : ?>
				<div class="fh-business-metrics"><div><span><?php esc_html_e( 'Form value / visitor', 'formhawk' ); ?></span><strong><?php echo esc_html( null === $value_per_visitor ? '—' : $this->roi_money( $value_per_visitor, $currency ) ); ?></strong></div><div><span><?php esc_html_e( 'Raw conversion', 'formhawk' ); ?></span><strong><?php echo esc_html( null === $raw_conversion ? '—' : number_format_i18n( $raw_conversion, 1 ) . '%' ); ?></strong></div><div><span><?php esc_html_e( 'Qualified / visitor', 'formhawk' ); ?></span><strong><?php echo esc_html( null === $qualified_per_view ? '—' : number_format_i18n( $qualified_per_view, 1 ) . '%' ); ?></strong></div><div><span><?php esc_html_e( 'Actual revenue', 'formhawk' ); ?></span><strong><?php echo esc_html( $actual_revenue ? $this->roi_money( (int) $summary['revenue_minor'], $currency ) : '—' ); ?></strong></div></div>
				<div class="fh-business-findings"><div><span><?php esc_html_e( 'TOP VALUE FIELD', 'formhawk' ); ?></span><strong><?php echo esc_html( $money_maker ? ( $money_maker['label'] ? $money_maker['label'] : $money_maker['normalized_key'] ) : __( 'Collecting evidence', 'formhawk' ) ); ?></strong><small><?php echo esc_html( $money_maker ? strtoupper( str_replace( '_', ' ', $money_maker['verdict'] ) ) : __( 'No eligible verdict yet', 'formhawk' ) ); ?></small></div><div><span><?php esc_html_e( 'TOP CONVERSION KILLER', 'formhawk' ); ?></span><strong><?php echo esc_html( $killer ? ( $killer['label'] ? $killer['label'] : $killer['normalized_key'] ) : __( 'None detected', 'formhawk' ) ); ?></strong><small><?php echo esc_html( $killer ? __( 'Test making it optional', 'formhawk' ) : __( 'Controlled evidence required', 'formhawk' ) ); ?></small></div></div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function roi_money( $minor, $currency ) {
		return $currency . ' ' . number_format_i18n( Currency::major( $minor, $currency ), Currency::exponent( $currency ) );
	}

	private function render_diagnostics() {
		$mail   = get_option( 'formhawk_mail_health', array() );
		$cro    = $this->experiments->diagnostics();
		$checks = array(
			array( __( 'Analytics database', 'formhawk' ), Database::core_schema_is_current() && Database::ingestion_ready(), __( 'Core evidence schema and structural migration are complete.', 'formhawk' ) ),
			array( __( 'Autopilot database', 'formhawk' ), ! empty( $cro['schema_ready'] ), __( 'Autopilot aggregate schema is ready. A failed optional CRO migration does not stop core analytics or provider forms.', 'formhawk' ) ),
			array( __( 'Frontend tracker', 'formhawk' ), is_readable( FORMHAWK_DIR . 'assets/js/tracker.js' ), __( 'Tracker asset is readable.', 'formhawk' ) ),
			array( __( 'REST ingestion', 'formhawk' ), function_exists( 'register_rest_route' ), __( 'WordPress REST API support is available.', 'formhawk' ) ),
			array( __( 'Data cleanup', 'formhawk' ), (bool) wp_next_scheduled( Activator::CRON_HOOK ), __( 'Daily retention cleanup is scheduled.', 'formhawk' ) ),
			array( __( 'Autopilot runtime', 'formhawk' ), is_readable( FORMHAWK_DIR . 'assets/js/cro-autopilot.js' ) && is_readable( FORMHAWK_DIR . 'assets/css/cro.css' ), __( 'Runtime Variant Engine assets are readable.', 'formhawk' ) ),
			array( __( 'Autopilot evaluator', 'formhawk' ), (bool) wp_next_scheduled( AutopilotManager::CRON_HOOK ), __( 'Race-safe hourly statistical evaluation is scheduled.', 'formhawk' ) ),
			array( __( 'Minimum Form database', 'formhawk' ), Database::minimum_form_schema_is_current( true ), __( 'Immutable baseline, run and decision storage is ready.', 'formhawk' ) ),
			array( __( 'Minimum Form evaluator', 'formhawk' ), (bool) wp_next_scheduled( MinimumFormManager::CRON_HOOK ), __( 'Sequential field optimization and promotion monitoring are scheduled.', 'formhawk' ) ),
		);
		if ( $this->integrations ) {
			foreach ( $this->integrations->all() as $provider => $integration ) {
				if ( 'html' === $provider ) {
					continue;
				}
				$checks[] = array( $integration->label(), $integration->is_available(), __( 'Provider lifecycle integration is active.', 'formhawk' ), true );
			}
		}
		$this->render_diagnostics_output( $mail, $cro, $checks );
	}

	private function render_autopilot( array $form, array $stats, array $fields ) {
		if ( ! Database::cro_schema_is_current() ) {
			?>
			<section class="fh-card fh-autopilot"><div class="fh-cro-empty"><h2><?php esc_html_e( 'Autopilot CRO unavailable', 'formhawk' ); ?></h2><p><?php esc_html_e( 'The optional Autopilot database migration has not completed. The original form and core analytics continue normally; check database permissions and Diagnostics.', 'formhawk' ); ?></p></div></section>
			<?php
			return;
		}
		$settings           = $this->experiments->settings( $form['id'] );
		$experiment         = $settings ? $this->experiments->active_for_form( $form['id'] ) : null;
		$history            = $settings ? $this->experiments->history( $form['id'], 100 ) : array();
		$rollback_candidate = $settings ? $this->experiments->latest_rollback_candidate( $form['id'] ) : null;
		$score              = ( new FormOptimizationScore() )->calculate( $stats, $fields, $form['provider'] );
		$variants           = $experiment ? $this->experiments->variants( $experiment['id'] ) : array();
		$totals             = $experiment ? $this->experiments->aggregate( $experiment['id'] ) : array();
		$analysis           = null;
		if ( count( $variants ) === 2 ) {
			$control         = $totals[ $variants[0]['id'] ] ?? array();
			$variant         = $totals[ $variants[1]['id'] ] ?? array();
			$exposure_column = \Formhawk\CRO\DecisionEvidence::allows_autonomy( $experiment, $form['provider'] ) ? 'assignments' : 'views';
			$metric          = 'observed_submit_rate' === $experiment['primary_metric'] ? 'observed_submits' : 'confirmed_successes';
			$analysis        = in_array( $experiment['primary_metric'], array( 'business_value', 'qualified_leads', 'won_leads' ), true ) ? null : ( new WinnerSelector() )->select(
				array(
					'views'       => $control[ $exposure_column ] ?? 0,
					'conversions' => $control[ $metric ] ?? 0,
				),
				array(
					'views'       => $variant[ $exposure_column ] ?? 0,
					'conversions' => $variant[ $metric ] ?? 0,
				),
				$experiment['policy'],
				0
			);
		}
		$cumulative   = 1.0;
		$winners      = 0;
		$rejected     = 0;
		$inconclusive = 0;
		$decided      = array();
		$rolled_back  = array();
		foreach ( $history as $record ) {
			$decided[ absint( $record['experiment_id'] ) ] = true;
			if ( in_array( $record['decision'], array( 'rollback', 'manual_rollback' ), true ) ) {
				$rolled_back[ absint( $record['experiment_id'] ) ] = true;
			}
		}
		foreach ( $history as $record ) {
			if ( 'winner' === $record['decision'] && null !== $record['lift'] && empty( $rolled_back[ absint( $record['experiment_id'] ) ] ) ) {
				$cumulative *= 1 + (float) $record['lift'];
				++$winners;
			} elseif ( in_array( $record['decision'], array( 'reject', 'stopped_guardrail', 'manual_reject' ), true ) ) {
				++$rejected;
			} elseif ( 'inconclusive' === $record['decision'] ) {
				++$inconclusive;
			}
		}
		$cumulative_lift = max( -1, $cumulative - 1 );
		$outcomes        = ProviderCatalog::has_server_success( $form['provider'] ) ? absint( $stats['confirmed_successes'] ?? 0 ) : absint( $stats['submit_attempts'] ?? 0 );
		$additional      = $cumulative > 1 ? max( 0, round( $outcomes - $outcomes / $cumulative ) ) : 0;
		$estimated_value = ProviderCatalog::has_server_success( $form['provider'] ) && $settings && $settings['lead_value'] ? $additional * (float) $settings['lead_value'] : null;
		$display_state   = $experiment ? $experiment['status'] : ( $settings ? $settings['state'] : __( 'OFF', 'formhawk' ) );
		?>
		<section class="fh-card fh-autopilot" aria-labelledby="formhawk-autopilot-title">
			<div class="fh-autopilot-head">
				<div><span class="fh-eyebrow"><?php esc_html_e( 'AUTOPILOT CRO', 'formhawk' ); ?></span><h2 id="formhawk-autopilot-title"><?php esc_html_e( 'Self-Optimizing Forms', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Turn it on. Formhawk continuously improves your form with reversible runtime changes.', 'formhawk' ); ?></p></div>
				<span class="fh-autopilot-state"><?php echo esc_html( strtoupper( $display_state ) ); ?></span>
			</div>
			<div class="fh-cro-score">
				<div><strong><?php echo esc_html( $score['score'] ); ?></strong><span>/ 100</span><small><?php esc_html_e( 'Form Optimization Score', 'formhawk' ); ?></small></div>
				<dl><div><dt><?php echo esc_html( ProviderCatalog::has_server_success( $form['provider'] ) ? __( 'Confirmed conversion', 'formhawk' ) : __( 'Observed submit rate', 'formhawk' ) ); ?></dt><dd><?php echo esc_html( $score['conversion'] ); ?></dd></div><div><dt><?php esc_html_e( 'Field friction', 'formhawk' ); ?></dt><dd><?php echo esc_html( $score['field_friction'] ); ?></dd></div><div><dt><?php esc_html_e( 'Validation', 'formhawk' ); ?></dt><dd><?php echo esc_html( $score['validation'] ); ?></dd></div><div><dt><?php esc_html_e( 'Completion', 'formhawk' ); ?></dt><dd><?php echo esc_html( $score['completion'] ); ?></dd></div><div><dt><?php esc_html_e( 'Confidence', 'formhawk' ); ?></dt><dd><?php echo esc_html( ucfirst( $score['confidence'] ) ); ?></dd></div></dl>
			</div>
			<?php if ( ! ProviderCatalog::has_server_success( $form['provider'] ) ) : ?>
				<p class="notice notice-warning"><?php esc_html_e( 'Confirmed server-side submissions are unavailable for this generic HTML form. Observed submit attempts remain advisory; autonomous promotion is disabled. Use Observe or Approve mode.', 'formhawk' ); ?></p>
			<?php endif; ?>
			<?php if ( $experiment && (int) ( $experiment['integrity_version'] ?? 1 ) < 2 ) : ?>
				<p class="notice notice-warning"><?php esc_html_e( 'This experiment predates protected assignment accounting. Automatic decisions are disabled; review it manually or start a new experiment. Historical views have not been converted into assignments.', 'formhawk' ); ?></p>
			<?php elseif ( $experiment && 'client_telemetry_review' === $experiment['integrity_warning'] ) : ?>
				<p class="notice notice-warning"><?php esc_html_e( 'Browser telemetry needs review. Client-only errors, validation and latency do not authorize automatic rejection or rollback.', 'formhawk' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $settings ) : ?>
				<div class="fh-cro-empty"><h3><?php esc_html_e( 'Safe by default', 'formhawk' ); ?></h3><p><?php esc_html_e( 'Autopilot starts in Approve mode. It never edits the provider form and will wait for enough traffic before proposing an experiment.', 'formhawk' ); ?></p>
				<?php $this->autopilot_button( $form['id'], 'enable', __( 'Enable Autopilot', 'formhawk' ), 'button button-primary button-hero' ); ?></div>
			<?php else : ?>
				<?php if ( $experiment && in_array( $experiment['status'], array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::RUNNING ), true ) && ! empty( $experiment['policy']['opportunity'] ) ) : ?>
					<?php $opportunity = $experiment['policy']['opportunity']; ?>
					<div class="fh-cro-opportunity"><span class="fh-eyebrow"><?php esc_html_e( 'NEXT OPPORTUNITY', 'formhawk' ); ?></span><h3><?php echo esc_html( $experiment['hypothesis'] ); ?></h3><p><?php echo esc_html( sprintf( /* translators: 1: impact score, 2: confidence score, 3: risk score, 4: sample size. */ __( 'Impact %1$d/100 · Confidence %2$d/100 · Risk %3$d/100 · Evidence sample %4$d', 'formhawk' ), absint( $opportunity['impact_score'] ?? 0 ), absint( $opportunity['confidence_score'] ?? 0 ), absint( $opportunity['risk_score'] ?? 0 ), absint( $opportunity['sample_size'] ?? 0 ) ) ); ?></p><strong><?php echo esc_html( 'running' === $experiment['status'] ? __( 'Running automatically', 'formhawk' ) : ( 'suggested' === $experiment['status'] ? __( 'Observation only', 'formhawk' ) : __( 'Scheduled — awaiting approval', 'formhawk' ) ) ); ?></strong></div>
				<?php endif; ?>
				<div class="fh-cro-impact"><div><strong><?php echo esc_html( (string) number_format_i18n( 100 * $cumulative_lift, 1 ) . '%' ); ?></strong><span><?php esc_html_e( 'Cumulative measured improvement', 'formhawk' ); ?></span></div><div><strong><?php echo esc_html( (string) count( $decided ) ); ?></strong><span><?php esc_html_e( 'Experiments decided', 'formhawk' ); ?></span></div><div><strong><?php echo esc_html( (string) $winners ); ?></strong><span><?php esc_html_e( 'Winning optimizations', 'formhawk' ); ?></span></div><div><strong><?php echo esc_html( (string) $rejected ); ?></strong><span><?php esc_html_e( 'Rejected variants', 'formhawk' ); ?></span></div><div><strong><?php echo esc_html( (string) $inconclusive ); ?></strong><span><?php esc_html_e( 'Inconclusive', 'formhawk' ); ?></span></div><div><strong><?php echo esc_html( (string) $additional ); ?></strong><span><?php echo esc_html( ProviderCatalog::has_server_success( $form['provider'] ) ? __( 'Estimated additional conversions', 'formhawk' ) : __( 'Estimated additional observed submits', 'formhawk' ) ); ?></span></div>
				<?php
				if ( null !== $estimated_value ) :
					?>
					<div><strong><?php echo esc_html( $settings['currency'] . ' ' . number_format_i18n( $estimated_value, 2 ) ); ?></strong><span><?php esc_html_e( 'Estimated additional value', 'formhawk' ); ?></span></div><?php endif; ?></div>
				<?php if ( $experiment ) : ?>
					<div class="fh-cro-current"><span class="fh-eyebrow"><?php esc_html_e( 'CURRENT EXPERIMENT', 'formhawk' ); ?></span><h3><?php echo esc_html( $experiment['hypothesis'] ); ?></h3><p><?php echo esc_html( $this->primary_metric_label( $experiment['primary_metric'] ) ); ?></p>
					<?php if ( $analysis && count( $variants ) === 2 ) : ?>
						<?php if ( 'assignments' === $exposure_column ) : ?>
							<p class="fh-note"><?php echo esc_html( sprintf( /* translators: 1: control assignments, 2: variant assignments. */ __( 'Server-issued assignments: %1$d control / %2$d variant. Decision rates use assignments; browser views are separate observations.', 'formhawk' ), absint( $control['assignments'] ?? 0 ), absint( $variant['assignments'] ?? 0 ) ) ); ?></p>
						<?php endif; ?>
						<div class="fh-cro-compare"><div><span><?php esc_html_e( 'Control', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( 100 * $analysis['control_rate'], 1 ) . '%' ); ?></strong><small><?php echo esc_html( sprintf( /* translators: 1: views, 2: conversions, 3: traffic percentage. */ __( '%1$d views · %2$d conversions · %3$d%% traffic', 'formhawk' ), absint( $totals[ $variants[0]['id'] ]['views'] ?? 0 ), absint( $totals[ $variants[0]['id'] ][ $metric ] ?? 0 ), absint( $variants[0]['traffic_weight'] ) ) ); ?></small></div><div><span><?php esc_html_e( 'Variant', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( 100 * $analysis['variant_rate'], 1 ) . '%' ); ?></strong><small><?php echo esc_html( sprintf( /* translators: 1: views, 2: conversions, 3: traffic percentage. */ __( '%1$d views · %2$d conversions · %3$d%% traffic', 'formhawk' ), absint( $totals[ $variants[1]['id'] ]['views'] ?? 0 ), absint( $totals[ $variants[1]['id'] ][ $metric ] ?? 0 ), absint( $variants[1]['traffic_weight'] ) ) ); ?></small></div><div><span><?php esc_html_e( 'Probability better', 'formhawk' ); ?></span><strong><?php echo esc_html( number_format_i18n( 100 * $analysis['probability_to_be_best'], 1 ) . '%' ); ?></strong><small><?php echo esc_html( strtoupper( str_replace( '_', ' ', $analysis['decision'] ) ) . ' · ' . __( 'Beta-Binomial posterior', 'formhawk' ) ); ?></small></div></div>
					<?php endif; ?>
					<?php if ( in_array( $experiment['status'], array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::RUNNING, ExperimentStatus::PAUSED_MANUAL ), true ) ) : ?>
					<div class="fh-cro-actions">
						<?php
						if ( in_array( $experiment['status'], array( ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::SUGGESTED, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
							$this->autopilot_button( $form['id'], 'start', __( 'Start experiment', 'formhawk' ), 'button button-primary' ); }
						?>
						<?php
						if ( ExperimentStatus::RUNNING === $experiment['status'] ) {
							$this->autopilot_button( $form['id'], 'pause', __( 'Pause experiment', 'formhawk' ) ); }
						?>
						<?php $this->autopilot_button( $form['id'], 'reject', __( 'Reject variant', 'formhawk' ) ); ?>
						<?php $this->autopilot_button( $form['id'], 'promote', __( 'Promote variant', 'formhawk' ) ); ?>
						<?php $this->autopilot_button( $form['id'], 'stop', __( 'Stop experiment', 'formhawk' ) ); ?>
					</div>
					<?php endif; ?></div>
					<?php
				else :
					?>
					<div class="fh-cro-empty"><h3><?php esc_html_e( 'Collecting data', 'formhawk' ); ?></h3><p><?php esc_html_e( 'Formhawk needs more traffic before Autopilot can safely optimize this form.', 'formhawk' ); ?></p></div><?php endif; ?>
				<details class="fh-cro-settings"><summary><?php esc_html_e( 'Autopilot mode and safety budget', 'formhawk' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="formhawk_autopilot"><input type="hidden" name="operation" value="save"><input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form['id'] ); ?>"><?php wp_nonce_field( 'formhawk_autopilot' ); ?>
				<div class="fh-cro-settings-grid">
					<?php $effective_mode = ! ProviderCatalog::has_server_success( $form['provider'] ) && 'full' === $settings['mode'] ? 'approve' : $settings['mode']; ?>
					<label><?php esc_html_e( 'Mode', 'formhawk' ); ?><select name="mode"><option value="observe" <?php selected( $effective_mode, 'observe' ); ?>><?php esc_html_e( 'Observe', 'formhawk' ); ?></option><option value="approve" <?php selected( $effective_mode, 'approve' ); ?>><?php esc_html_e( 'Approve', 'formhawk' ); ?></option><option value="full" <?php selected( $effective_mode, 'full' ); ?> <?php disabled( ! ProviderCatalog::has_server_success( $form['provider'] ) ); ?>><?php esc_html_e( 'Full Autopilot', 'formhawk' ); ?></option></select></label>
					<label><?php esc_html_e( 'Optimization objective', 'formhawk' ); ?><select name="optimization_objective"><option value="auto" <?php selected( $settings['optimization_objective'], 'auto' ); ?>><?php esc_html_e( 'Best available business metric', 'formhawk' ); ?></option><option value="submissions" <?php selected( $settings['optimization_objective'], 'submissions' ); ?>><?php esc_html_e( 'Confirmed submissions', 'formhawk' ); ?></option><option value="qualified_leads" <?php selected( $settings['optimization_objective'], 'qualified_leads' ); ?>><?php esc_html_e( 'Qualified leads / visitor', 'formhawk' ); ?></option><option value="won_leads" <?php selected( $settings['optimization_objective'], 'won_leads' ); ?>><?php esc_html_e( 'Won leads / visitor', 'formhawk' ); ?></option><option value="business_value" <?php selected( $settings['optimization_objective'], 'business_value' ); ?>><?php esc_html_e( 'Revenue / visitor', 'formhawk' ); ?></option></select></label>
					<label><?php esc_html_e( 'Aggressiveness', 'formhawk' ); ?><select name="aggressiveness"><option value="conservative" <?php selected( $settings['aggressiveness'], 'conservative' ); ?>><?php esc_html_e( 'Conservative', 'formhawk' ); ?></option><option value="balanced" <?php selected( $settings['aggressiveness'], 'balanced' ); ?>><?php esc_html_e( 'Balanced', 'formhawk' ); ?></option><option value="aggressive" <?php selected( $settings['aggressiveness'], 'aggressive' ); ?>><?php esc_html_e( 'Aggressive', 'formhawk' ); ?></option></select></label>
					<label><?php esc_html_e( 'Maximum experimental traffic (%)', 'formhawk' ); ?><input type="number" min="10" max="50" name="max_experimental_traffic" value="<?php echo esc_attr( (string) $settings['max_experimental_traffic'] ); ?>"></label>
					<label><?php esc_html_e( 'Minimum duration (days)', 'formhawk' ); ?><input type="number" min="3" max="60" name="min_duration_days" value="<?php echo esc_attr( (string) $settings['min_duration_days'] ); ?>"></label>
					<label><?php esc_html_e( 'Minimum conversions / outcomes', 'formhawk' ); ?><input type="number" min="20" max="10000" name="min_conversions" value="<?php echo esc_attr( (string) $settings['min_conversions'] ); ?>"></label>
					<label><?php esc_html_e( 'Legacy average lead value', 'formhawk' ); ?><input type="number" min="0" step="0.01" name="lead_value" value="<?php echo esc_attr( null === $settings['lead_value'] ? '' : $settings['lead_value'] ); ?>"></label>
					<input type="hidden" name="currency" value="<?php echo esc_attr( $settings['currency'] ); ?>">
				</div><?php submit_button( __( 'Save Autopilot settings', 'formhawk' ), 'secondary', 'submit', false ); ?></form></details>
				<div class="fh-cro-actions">
				<?php
				if ( $rollback_candidate ) {
					$this->autopilot_button( $form['id'], 'rollback', __( 'Rollback winner', 'formhawk' ) ); }
				?>
				<?php $this->autopilot_button( $form['id'], 'disable', __( 'Disable Autopilot', 'formhawk' ), 'button button-link-delete' ); ?></div>
				<?php
				if ( $history ) :
					?>
					<h3><?php esc_html_e( 'Optimization History', 'formhawk' ); ?></h3><div class="fh-table-card"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Experiment', 'formhawk' ); ?></th><th><?php esc_html_e( 'Decision', 'formhawk' ); ?></th><th><?php esc_html_e( 'Lift', 'formhawk' ); ?></th><th><?php esc_html_e( 'Evidence', 'formhawk' ); ?></th><th><?php esc_html_e( 'Date', 'formhawk' ); ?></th></tr></thead><tbody>
					<?php
					foreach ( $history as $record ) :
						?>
					<tr><td>#<?php echo esc_html( $record['experiment_id'] ); ?></td><td><?php echo esc_html( strtoupper( str_replace( '_', ' ', $record['decision'] ) ) ); ?></td><td><?php echo esc_html( null === $record['lift'] ? '—' : number_format_i18n( 100 * (float) $record['lift'], 1 ) . '%' ); ?></td><td><?php echo esc_html( $record['algorithm_version'] . ' · ' . $record['policy_version'] ); ?></td><td><?php echo esc_html( $record['created_at_utc'] ); ?> UTC</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
			<?php endif; ?>
			<p class="fh-note"><?php esc_html_e( 'No form values, IP addresses, cookies, browser storage or persistent visitor identifiers are used. Assignment lasts only for the current page lifecycle. The original provider form remains the source of truth.', 'formhawk' ); ?></p>
		</section>
		<?php
	}

	private function autopilot_button( $form_id, $operation, $label, $button_class = 'button' ) {
		?>
		<form class="fh-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="formhawk_autopilot"><input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>"><input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>"><?php wp_nonce_field( 'formhawk_autopilot' ); ?><button type="submit" class="<?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	private function primary_metric_label( $metric ) {
		$labels = array(
			'confirmed_conversion' => __( 'Primary metric: provider-confirmed conversion.', 'formhawk' ),
			'observed_submit_rate' => __( 'Primary metric: observed submit rate; confirmed conversion is unavailable for generic HTML.', 'formhawk' ),
			'qualified_leads'      => __( 'Primary metric: qualified leads per visitor. Recent unknown outcomes remain maturing.', 'formhawk' ),
			'won_leads'            => __( 'Primary metric: won leads per visitor. Recent unknown outcomes remain maturing.', 'formhawk' ),
			'business_value'       => __( 'Primary metric: revenue per visitor. Winner selection uses robust revenue inference, not raw conversion.', 'formhawk' ),
		);
		return isset( $labels[ $metric ] ) ? $labels[ $metric ] : $labels['confirmed_conversion'];
	}

	private function render_diagnostics_output( array $mail, array $cro, array $checks ) {
		?>
		<div class="fh-toolbar"><div><h2><?php esc_html_e( 'Diagnostics', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Fast checks for the Formhawk runtime and WordPress mail layer.', 'formhawk' ); ?></p></div></div>
		<div class="fh-checks">
		<?php
		foreach ( $checks as $check ) :
			$optional = ! empty( $check[3] );
			?>
			<div class="fh-card fh-check"><span class="fh-check-icon <?php echo $check[1] ? 'is-ok' : ( $optional ? 'is-neutral' : 'is-bad' ); ?>"><?php echo $check[1] ? '✓' : ( $optional ? '–' : '!' ); ?></span><div><h3><?php echo esc_html( $check[0] ); ?></h3><p><?php echo esc_html( $check[1] ? $check[2] : ( $optional ? __( 'Not active. This is optional.', 'formhawk' ) : __( 'Check failed.', 'formhawk' ) ) ); ?></p></div></div>
		<?php endforeach; ?>
		</div>

		<div class="fh-card">
			<h2><?php esc_html_e( 'Autopilot Diagnostics', 'formhawk' ); ?></h2>
			<dl class="fh-kv"><div><dt><?php esc_html_e( 'Active experiments', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['active_experiments'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Last statistical evaluation', 'formhawk' ); ?></dt><dd><?php echo esc_html( $cro['last_evaluated_at_utc'] ? $cro['last_evaluated_at_utc'] . ' UTC' : __( 'Not run yet', 'formhawk' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Assigned views (7 days)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['assigned_views'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Variant application errors (7 days)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['application_errors'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Attempts without provider outcome (7 days)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['missing_confirmations'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Guardrail triggers', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['guardrail_triggers'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Orphan experiments', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['orphan_experiments'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Rejected CRO config requests (today)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['rejected_config_requests'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Rejected CRO event requests (today)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['rejected_event_requests'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Throttled CRO requests (today)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['throttled_config_requests'] + $cro['throttled_event_requests'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'CRO storage failures (today)', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $cro['storage_failures'] ) ); ?></dd></div></dl>
			<h3><?php esc_html_e( 'Provider CRO capability matrix', 'formhawk' ); ?></h3>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Provider', 'formhawk' ); ?></th><th><?php esc_html_e( 'CTA', 'formhawk' ); ?></th><th><?php esc_html_e( 'Order', 'formhawk' ); ?></th><th><?php esc_html_e( 'Progressive', 'formhawk' ); ?></th><th><?php esc_html_e( 'Multi-step', 'formhawk' ); ?></th><th><?php esc_html_e( 'Confirmed success', 'formhawk' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( ProviderCROCapabilities::matrix() as $provider => $capability ) :
				?>
				<tr><th scope="row"><?php echo esc_html( $this->provider_label( $provider ) ); ?></th><td>✓</td><td>✓*</td><td>✓*</td><td>✓*</td><td><?php echo ! empty( $capability['confirmed_success'] ) ? '✓' : esc_html__( 'N/A — observed only', 'formhawk' ); ?></td></tr><?php endforeach; ?>
			</tbody></table><p class="fh-note"><?php esc_html_e( '* Structural mutations run only after the runtime safety classifier confirms independent fields and no conditional, legal, security, payment, CAPTCHA or file controls.', 'formhawk' ); ?></p>
		</div>
		<div class="fh-card">
			<h2><?php esc_html_e( 'Ingestion diagnostics (today, UTC)', 'formhawk' ); ?></h2>
			<p><?php esc_html_e( 'Site-wide aggregate counters. No IP addresses, request payloads or visitor identifiers are retained. Event counts cover bounded, parseable batches, including throttled requests. Oversized or unparseable bodies are counted as requests only.', 'formhawk' ); ?></p>
			<dl class="fh-kv">
			<?php
			$diagnostic_labels = array(
				'rejected_events'             => __( 'Rejected events', 'formhawk' ),
				'rejected_requests'           => __( 'Rejected requests', 'formhawk' ),
				'throttled_events'            => __( 'Throttled events', 'formhawk' ),
				'throttled_requests'          => __( 'Throttled requests', 'formhawk' ),
				'cardinality_rejected_events' => __( 'Cardinality-rejected events', 'formhawk' ),
				'storage_rejected_events'     => __( 'Storage/admission unavailable events', 'formhawk' ),
			);
			foreach ( ( new IngestionDiagnostics() )->today() as $key => $value ) :
				?>
				<div><dt><?php echo esc_html( $diagnostic_labels[ $key ] ); ?></dt><dd><?php echo esc_html( number_format_i18n( $value ) ); ?></dd></div>
			<?php endforeach; ?>
			</dl>
		</div>
		<div class="fh-card fh-mail-card">
			<div><h2><?php esc_html_e( 'WordPress mail health', 'formhawk' ); ?></h2><p><?php esc_html_e( 'Formhawk listens to wp_mail() success/failure hooks globally but never stores recipients, subjects, message bodies or attachments.', 'formhawk' ); ?></p></div>
			<dl class="fh-kv">
				<div><dt><?php esc_html_e( 'Last wp_mail success', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->relative_time( isset( $mail['last_success'] ) ? $mail['last_success'] : null ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Last wp_mail failure', 'formhawk' ); ?></dt><dd><?php echo esc_html( $this->relative_time( isset( $mail['last_failure'] ) ? $mail['last_failure'] : null ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Observed successes', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( isset( $mail['successes'] ) ? $mail['successes'] : 0 ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Observed failures', 'formhawk' ); ?></dt><dd><?php echo esc_html( number_format_i18n( isset( $mail['failures'] ) ? $mail['failures'] : 0 ) ); ?></dd></div>
			</dl>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="formhawk_test_mail">
				<?php wp_nonce_field( 'formhawk_test_mail' ); ?>
				<?php submit_button( __( 'Send test email to me', 'formhawk' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="fh-note"><?php esc_html_e( 'A successful wp_mail() call means WordPress/PHPMailer accepted the message for sending. It does not prove final inbox delivery.', 'formhawk' ); ?></p>
		</div>
		<?php
	}

	private function rate_label( $rate ) {
		return null === $rate ? __( 'N/A', 'formhawk' ) : number_format_i18n( $rate, 1 ) . '%';
	}

	private function confirmed_label( array $row ) {
		return ProviderCatalog::has_server_success( $row['provider'] ) ? number_format_i18n( $row['confirmed_successes'] ) : __( 'N/A', 'formhawk' );
	}

	private function render_settings() {
		$settings            = get_option( 'formhawk_settings', array() );
		$days                = isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 90;
		$delete_on_uninstall = ! empty( $settings['delete_on_uninstall'] );
		?>
		<div class="fh-toolbar"><div><h2><?php esc_html_e( 'Settings', 'formhawk' ); ?></h2><p><?php esc_html_e( 'The defaults are intentionally small and privacy-first.', 'formhawk' ); ?></p></div></div>
		<div class="fh-grid-2">
			<div class="fh-card">
				<h2><?php esc_html_e( 'Data retention', 'formhawk' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="formhawk_save_settings">
					<?php wp_nonce_field( 'formhawk_save_settings' ); ?>
					<label class="fh-label" for="retention_days"><?php esc_html_e( 'Keep daily aggregates for', 'formhawk' ); ?></label>
					<select name="retention_days" id="retention_days">
						<?php foreach ( array( 30, 90, 180, 365 ) as $option ) : ?>
							<?php
							/* translators: %d: number of days analytics aggregates are retained. */
							$retention_label = sprintf( _n( '%d day', '%d days', $option, 'formhawk' ), $option );
							?>
						<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $days, $option ); ?>><?php echo esc_html( $retention_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="fh-checkbox"><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?>> <?php esc_html_e( 'Delete all Formhawk analytics data when the plugin is deleted.', 'formhawk' ); ?></label>
					<?php submit_button( __( 'Save settings', 'formhawk' ) ); ?>
				</form>
			</div>
			<div class="fh-card">
				<h2><?php esc_html_e( 'Privacy model', 'formhawk' ); ?></h2>
				<ul class="fh-privacy-list">
					<li>✓ <?php esc_html_e( 'No cookies.', 'formhawk' ); ?></li>
					<li>✓ <?php esc_html_e( 'No IP addresses stored.', 'formhawk' ); ?></li>
					<li>✓ <?php esc_html_e( 'No visitor user IDs or visitor identifiers stored in analytics.', 'formhawk' ); ?></li>
					<li>✓ <?php esc_html_e( 'No form field values stored.', 'formhawk' ); ?></li>
					<li>✓ <?php esc_html_e( 'No email subjects, recipients or bodies stored.', 'formhawk' ); ?></li>
					<li>✓ <?php esc_html_e( 'No external analytics service. Aggregates stay in this WordPress database.', 'formhawk' ); ?></li>
				</ul>
				<p class="fh-note"><?php esc_html_e( 'Field names/labels and page paths are stored so the dashboard can identify friction points. They describe the form structure, never the visitor response.', 'formhawk' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function header( $active ) {
		?>
		<div class="wrap fh-wrap">
			<div class="fh-brand"><div class="fh-logo">FH</div><div><h1>Formhawk</h1><p><?php esc_html_e( 'Know when forms stop working — and where visitors give up.', 'formhawk' ); ?></p></div></div>
			<nav class="nav-tab-wrapper fh-tabs">
				<?php
				foreach ( array(
					'overview'    => __( 'Overview', 'formhawk' ),
					'diagnostics' => __( 'Diagnostics', 'formhawk' ),
					'settings'    => __( 'Settings', 'formhawk' ),
				) as $tab => $label ) :
					?>
					<a class="nav-tab <?php echo $active === $tab ? 'nav-tab-active' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => 'formhawk',
								'tab'  => $tab,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
										"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php
	}

	private function range_picker( $days, $form_id = 0 ) {
		echo '<div class="fh-range">';
		foreach ( array( 7, 30, 90 ) as $option ) {
			$args = array(
				'page' => 'formhawk',
				'days' => $option,
			);
			if ( $form_id ) {
				$args['form_id'] = $form_id;
			}
			$class = $days === $option ? 'is-active' : '';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $option . 'd' ) . '</a>';
		}
		echo '</div>';
	}

	private function metric( $label, $value, $hint ) {
		echo '<div class="fh-card fh-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( is_numeric( $value ) ? number_format_i18n( $value ) : $value ) . '</strong><small>' . esc_html( $hint ) . '</small></div>';
	}

	private function status( array $health, $large = false ) {
		echo '<span class="fh-status fh-status-' . esc_attr( $health['status'] ) . ( $large ? ' is-large' : '' ) . '"><i></i>' . esc_html( $health['label'] ) . '</span>';
	}

	private function funnel_row( $label, $value, $base ) {
		$percent = $base > 0 ? min( 100, ( $value / $base ) * 100 ) : 0;
		echo '<div class="fh-funnel-row"><div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div><div class="fh-bar"><i style="width:' . esc_attr( number_format( $percent, 2, '.', '' ) ) . '%"></i></div><small>' . esc_html( number_format_i18n( $percent, 1 ) . '%' ) . '</small></div>';
	}

	private function field_table( array $fields, $metric ) {
		$filtered = array_values(
			array_filter(
				$fields,
				static function ( $row ) use ( $metric ) {
					return ! empty( $row[ $metric ] );
				}
			)
		);
		if ( empty( $filtered ) ) {
			echo '<div class="fh-empty-mini">' . esc_html__( 'No data yet.', 'formhawk' ) . '</div>';
			return;
		}
		echo '<div class="fh-field-list">';
		foreach ( array_slice( $filtered, 0, 8 ) as $field ) {
			$label = $field['field_label'] ? $field['field_label'] : $field['field_key'];
			$count = absint( $field[ $metric ] );
			$rate  = 'abandonments' === $metric && ! empty( $field['interactions'] ) ? ( $count / absint( $field['interactions'] ) ) * 100 : null;
			echo '<div class="fh-field-row"><div><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $field['field_key'] ) . '</span></div><div><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>' . ( null !== $rate ? '<span>' . esc_html( number_format_i18n( $rate, 1 ) . '%' ) . '</span>' : '' ) . '</div></div>';
		}
		echo '</div>';
	}

	private function days() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only raw input is type-checked and normalized on the next line.
		$days = isset( $_GET['days'] ) ? wp_unslash( $_GET['days'] ) : 30;
		$days = is_scalar( $days ) ? absint( $days ) : 30;
		return in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
	}

	private function page_number() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only raw input is type-checked and normalized on the next line.
		$page = isset( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1;
		return is_scalar( $page ) ? max( 1, absint( $page ) ) : 1;
	}

	private function overview_pagination( $page, $per_page, $total, $days ) {
		$total_pages = (int) ceil( absint( $total ) / max( 1, absint( $per_page ) ) );
		if ( $total_pages <= 1 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => str_replace(
					'999999999',
					'%#%',
					add_query_arg(
						array(
							'page'  => 'formhawk',
							'days'  => absint( $days ),
							'paged' => 999999999,
						),
						admin_url( 'admin.php' )
					)
				),
				'format'    => '',
				'current'   => min( max( 1, absint( $page ) ), $total_pages ),
				'total'     => $total_pages,
				'mid_size'  => 2,
				'prev_text' => __( 'Previous', 'formhawk' ),
				'next_text' => __( 'Next', 'formhawk' ),
				'type'      => 'list',
			)
		);
		if ( $links ) {
			echo '<nav class="fh-pagination" aria-label="' . esc_attr__( 'Forms pagination', 'formhawk' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	private function form_title( array $row ) {
		if ( ! empty( $row['title'] ) ) {
			return $row['title'];
		}
		if ( 'cf7' === $row['provider'] ) {
			/* translators: %s: Contact Form 7 form ID. */
			return sprintf( __( 'Contact Form 7 #%s', 'formhawk' ), $row['provider_form_id'] );
		}
		if ( 'wpforms' === $row['provider'] ) {
			/* translators: %s: WPForms form ID. */
			return sprintf( __( 'WPForms #%s', 'formhawk' ), $row['provider_form_id'] );
		}
		if ( 'elementor' === $row['provider'] ) {
			/* translators: %s: Elementor document and widget identity. */
			return sprintf( __( 'Elementor form %s', 'formhawk' ), $row['provider_form_id'] );
		}
		/* translators: %s: page path containing the HTML form. */
		return sprintf( __( 'HTML form · %s', 'formhawk' ), $row['page_path'] );
	}

	private function provider_label( $provider ) {
		return $this->integrations ? $this->integrations->label( $provider ) : strtoupper( (string) $provider );
	}

	private function percent_of( $value, $base ) {
		return number_format_i18n( $base > 0 ? ( $value / $base ) * 100 : 0, 1 ) . '%';
	}

	private function duration( $ms ) {
		if ( $ms <= 0 ) {
			return '—';
		}
		$seconds = (int) round( $ms / 1000 );
		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds spent interacting with a form. */
			return sprintf( _n( '%d sec', '%d sec', $seconds, 'formhawk' ), $seconds );
		}
		$minutes = (int) floor( $seconds / 60 );
		$remain  = $seconds % 60;
		return sprintf( '%dm %02ds', $minutes, $remain );
	}

	private function relative_time( $datetime ) {
		if ( empty( $datetime ) ) {
			return '—';
		}
		$site_datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $datetime, wp_timezone() );
		if ( ! $site_datetime ) {
			return '—';
		}
		/* translators: %s: human-readable time difference, for example "5 minutes". */
		return sprintf( __( '%s ago', 'formhawk' ), human_time_diff( $site_datetime->getTimestamp(), current_datetime()->getTimestamp() ) );
	}


	private function set_notice( $type, $message ) {
		set_transient(
			'formhawk_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function notice() {
		$key    = 'formhawk_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === $notice['type'] ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
