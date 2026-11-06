<?php
/**
 * Signed REST endpoints for GitHub Actions (see .github/scripts/git-auto-deploy.sh).
 *
 *   POST ?rest_route=/git-auto-deploy/v1/deploy    body: the theme zip
 *   POST ?rest_route=/git-auto-deploy/v1/rollback  put the previous version back
 *   POST ?rest_route=/git-auto-deploy/v1/ping      check the URL and secret work
 *
 * Every request carries these headers:
 *   X-GAD-Timestamp  Unix time; must be within 5 minutes of the server clock
 *   X-GAD-Commit     Git commit SHA (may be empty)
 *   X-GAD-Subject    base64 of the commit's first line (may be empty)
 *   X-GAD-Signature  hex HMAC-SHA256, keyed with the deploy secret, of
 *                   "<action>\n<timestamp>\n<commit>\n<subject>\n<sha256 of body>"
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		foreach ( array( 'deploy', 'rollback', 'ping' ) as $action ) {
			register_rest_route(
				'git-auto-deploy/v1',
				'/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => 'git_auto_deploy_rest_' . $action,
					'permission_callback' => static function ( WP_REST_Request $request ) use ( $action ) {
						return git_auto_deploy_verify_request( $request, $action );
					},
				)
			);
		}
	}
);

/**
 * @return true|WP_Error
 */
function git_auto_deploy_verify_request( WP_REST_Request $request, string $action ) {
	$secret = Git_Auto_Deploy::secret();
	if ( '' === $secret ) {
		return new WP_Error( 'git_auto_deploy_not_configured', 'No deploy secret is set on this site. Open Tools → Git Auto Deploy.', array( 'status' => 503 ) );
	}

	$timestamp = (string) $request->get_header( 'x_gad_timestamp' );
	$commit    = strtolower( trim( (string) $request->get_header( 'x_gad_commit' ) ) );
	$subject   = trim( (string) $request->get_header( 'x_gad_subject' ) );
	$signature = strtolower( trim( (string) $request->get_header( 'x_gad_signature' ) ) );

	if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
		return new WP_Error( 'git_auto_deploy_clock', 'The request timestamp is missing or more than 5 minutes from the server clock.', array( 'status' => 401 ) );
	}
	if ( '' !== $commit && ! preg_match( '/^[0-9a-f]{7,40}$/', $commit ) ) {
		return new WP_Error( 'git_auto_deploy_commit', 'X-GAD-Commit is not a commit SHA.', array( 'status' => 400 ) );
	}

	$payload  = implode( "\n", array( $action, $timestamp, $commit, $subject, hash( 'sha256', $request->get_body() ) ) );
	$expected = hash_hmac( 'sha256', $payload, $secret );

	if ( ! preg_match( '/^[0-9a-f]{64}$/', $signature ) || ! hash_equals( $expected, $signature ) ) {
		return new WP_Error( 'git_auto_deploy_signature', 'Bad signature. Check that GIT_AUTO_DEPLOY_SECRET in GitHub matches Tools → Git Auto Deploy.', array( 'status' => 401 ) );
	}

	// A signed request works once, so a captured one cannot be replayed.
	$seen = 'git_auto_deploy_sig_' . substr( $signature, 0, 40 );
	if ( get_transient( $seen ) ) {
		return new WP_Error( 'git_auto_deploy_replay', 'This signed request was already used.', array( 'status' => 409 ) );
	}
	set_transient( $seen, 1, 15 * MINUTE_IN_SECONDS );

	return true;
}

function git_auto_deploy_request_meta( WP_REST_Request $request ): array {
	$subject = base64_decode( trim( (string) $request->get_header( 'x_gad_subject' ) ), true );
	$subject = is_string( $subject ) ? sanitize_text_field( $subject ) : '';

	return array(
		'commit'  => strtolower( trim( (string) $request->get_header( 'x_gad_commit' ) ) ),
		'subject' => mb_substr( $subject, 0, 200 ),
		'source'  => 'GitHub Actions',
	);
}

function git_auto_deploy_rest_response( array $result ): WP_REST_Response {
	$status = (int) ( $result['status'] ?? 200 );
	unset( $result['status'] );
	return new WP_REST_Response( $result, $status );
}

function git_auto_deploy_rest_deploy( WP_REST_Request $request ): WP_REST_Response {
	$body = $request->get_body();
	if ( '' === $body ) {
		return git_auto_deploy_rest_response(
			array(
				'ok'      => false,
				'status'  => 400,
				'message' => sprintf( 'The upload arrived empty. Is the zip bigger than post_max_size (%s)?', ini_get( 'post_max_size' ) ),
			)
		);
	}

	// Saved inside wp-content/git-auto-deploy/ (web-blocked) rather than /tmp, which open_basedir often forbids.
	try {
		Git_Auto_Deploy::ensure_dirs();
	} catch ( Git_Auto_Deploy_Error $e ) {
		return git_auto_deploy_rest_response( array( 'ok' => false, 'status' => 500, 'message' => $e->getMessage() ) );
	}
	$zip = Git_Auto_Deploy::base_dir() . '/upload-' . wp_generate_password( 16, false ) . '.zip';
	if ( false === file_put_contents( $zip, $body ) ) {
		return git_auto_deploy_rest_response(
			array(
				'ok'      => false,
				'status'  => 500,
				'message' => 'Could not save the upload in wp-content/git-auto-deploy/ (disk full or no write access?).',
			)
		);
	}
	unset( $body );

	try {
		$result = Git_Auto_Deploy::deploy( $zip, git_auto_deploy_request_meta( $request ) );
	} finally {
		@unlink( $zip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	return git_auto_deploy_rest_response( $result );
}

function git_auto_deploy_rest_rollback( WP_REST_Request $request ): WP_REST_Response {
	$meta           = git_auto_deploy_request_meta( $request );
	$meta['reason'] = 'GitHub Actions smoke test failed after deploying ' . Git_Auto_Deploy::label( $meta );

	// The version being replaced was found broken, so it goes to failed/, not backups/.
	return git_auto_deploy_rest_response( Git_Auto_Deploy::rollback( null, 'failed', $meta ) );
}

function git_auto_deploy_rest_ping( WP_REST_Request $request ): WP_REST_Response {
	return git_auto_deploy_rest_response( Git_Auto_Deploy::status() );
}
