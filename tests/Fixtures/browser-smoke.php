<?php
/** Local browser fixture: serve only with an explicit disposable WordPress root. */

$formhawk_smoke_root = getenv( 'FORMHAWK_WP_ROOT' );
if ( ! $formhawk_smoke_root ) {
	http_response_code( 404 );
	exit;
}
// The underlying form handler deliberately ignores the synthetic submitted fields.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only navigation marker, no privileged action or visitor data processing.
if ( isset( $_GET['done'] ) ) {
	echo '<!doctype html><title>Smoke complete</title><h1>Form handler reached</h1>';
	exit;
}
require_once rtrim( $formhawk_smoke_root, '/' ) . '/wp-load.php';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Formhawk browser smoke</title></head><body>
<h1>Native validation smoke</h1>
<form id="hardening-browser" method="post" action="?done=1">
<label for="email">Email</label><input id="email" name="email" type="email" required>
<label for="message">Message</label><textarea id="message" name="message" required></textarea>
<button type="submit">Send</button>
</form>
<script>
window.FormhawkConfig = 
<?php
echo wp_json_encode(
	array(
		'endpoint' => rest_url( 'formhawk/v1/events' ),
		'token'    => \Formhawk\Http\EventsController::public_token(),
		'path'     => '/formhawk-browser-smoke',
	)
);
?>
;
</script>
<?php
wp_enqueue_script( 'formhawk-browser-smoke', plugins_url( 'assets/js/tracker.js', FORMHAWK_FILE ), array(), FORMHAWK_VERSION, true );
wp_print_footer_scripts();
?>
</body></html>
