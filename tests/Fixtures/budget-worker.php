<?php
/** Concurrent worker. Must only be invoked by the isolated-storage test. */

$formhawk_test_root = getenv( 'FORMHAWK_WP_ROOT' );
if ( ! $formhawk_test_root || count( $argv ) < 3 ) {
	exit( 2 );
}
require_once rtrim( $formhawk_test_root, '/' ) . '/wp-load.php';
global $wpdb;
$formhawk_test_prefix = $argv[1];
if ( ! preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'fh_case_[a-z0-9]{8}_$/D', $formhawk_test_prefix ) ) {
	exit( 3 );
}
$wpdb->prefix      = $formhawk_test_prefix;
$formhawk_start_at = (float) $argv[2];
while ( microtime( true ) < $formhawk_start_at ) {
	usleep( 1000 );
}
$formhawk_budget = new \Formhawk\Infrastructure\AtomicBudgetStore();
$formhawk_guard  = new \Formhawk\Analytics\CardinalityGuard();
add_filter(
	'formhawk_ingestion_limits',
	static function ( $limits ) {
		$limits['forms_total'] = 7;
		return $limits;
	}
);
$formhawk_accepted = 0;
for ( $formhawk_index = 0; $formhawk_index < 40; ++$formhawk_index ) {
	$formhawk_admitted = isset( $argv[3] ) && 'dimensions' === $argv[3]
		? 'accepted' === $formhawk_guard->admit(
			array(
				'provider'         => 'html',
				'provider_form_id' => 'worker-' . (int) $argv[4] . '-' . $formhawk_index,
				'page_path'        => '/concurrent',
			)
		)
		: $formhawk_budget->reserve( 'concurrent-test', 1, 37, 0 );
	if ( $formhawk_admitted ) {
		++$formhawk_accepted;
	}
}
echo (int) $formhawk_accepted;
