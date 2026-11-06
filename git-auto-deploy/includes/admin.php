<?php
/**
 * Tools → Git Auto Deploy: setup steps, server checks, backups with rollback,
 * manual zip upload and the activity log.
 */

defined( 'ABSPATH' ) || exit;

const GIT_AUTO_DEPLOY_PAGE = 'git-auto-deploy';
const GIT_AUTO_DEPLOY_DOCS = 'https://github.com/vibhorashopifyagency/wordpressgitautodeploy#readme';

add_action(
	'admin_menu',
	static function () {
		add_management_page( 'Git Auto Deploy', 'Git Auto Deploy', 'manage_options', GIT_AUTO_DEPLOY_PAGE, 'git_auto_deploy_admin_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/git-auto-deploy.php' ),
	static function ( array $links ) {
		array_unshift( $links, '<a href="' . esc_url( git_auto_deploy_page_url() ) . '">Settings</a>' );
		return $links;
	}
);

function git_auto_deploy_page_url(): string {
	return admin_url( 'tools.php?page=' . GIT_AUTO_DEPLOY_PAGE );
}

/* ------------------------------------------------------------------ actions */

function git_auto_deploy_admin_guard( string $action ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to do that.', 403 );
	}
	check_admin_referer( $action );
}

function git_auto_deploy_flash( string $type, string $text ): void {
	set_transient( 'git_auto_deploy_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
}

function git_auto_deploy_flash_result( array $result ): void {
	$type = $result['ok'] ? ( 'unverified' === ( $result['health']['status'] ?? '' ) ? 'warning' : 'success' ) : 'error';
	git_auto_deploy_flash( $type, (string) $result['message'] );
}

function git_auto_deploy_back(): void {
	wp_safe_redirect( git_auto_deploy_page_url() );
	exit;
}

add_action(
	'admin_post_git_auto_deploy_secret',
	static function () {
		git_auto_deploy_admin_guard( 'git_auto_deploy_secret' );
		Git_Auto_Deploy::regenerate_secret();
		git_auto_deploy_flash( 'warning', 'New secret created. Paste it into the GIT_AUTO_DEPLOY_SECRET secret on GitHub; deploys signed with the old one now fail.' );
		git_auto_deploy_back();
	}
);

add_action(
	'admin_post_git_auto_deploy_rollback',
	static function () {
		git_auto_deploy_admin_guard( 'git_auto_deploy_rollback' );
		$slot = sanitize_file_name( wp_unslash( (string) ( $_POST['slot'] ?? '' ) ) );
		$user = wp_get_current_user();
		git_auto_deploy_flash_result(
			Git_Auto_Deploy::rollback(
				$slot,
				'backups',
				array(
					'source' => 'wp-admin (' . $user->user_login . ')',
					'reason' => 'Rolled back by ' . $user->user_login,
				)
			)
		);
		git_auto_deploy_back();
	}
);

add_action(
	'admin_post_git_auto_deploy_upload',
	static function () {
		git_auto_deploy_admin_guard( 'git_auto_deploy_upload' );
		$file = $_FILES['git_auto_deploy_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$code = is_array( $file ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
			$why  = in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
				? 'The zip is bigger than this server accepts (' . size_format( wp_max_upload_size() ) . ').'
				: ( UPLOAD_ERR_NO_FILE === $code ? 'Choose a zip file first.' : 'The upload failed (PHP error ' . $code . ').' );
			git_auto_deploy_flash( 'error', $why );
			git_auto_deploy_back();
		}

		$user = wp_get_current_user();
		git_auto_deploy_flash_result(
			Git_Auto_Deploy::deploy(
				$file['tmp_name'],
				array(
					'commit'  => '',
					'subject' => 'Uploaded ' . sanitize_file_name( (string) $file['name'] ),
					'source'  => 'wp-admin (' . $user->user_login . ')',
				)
			)
		);
		git_auto_deploy_back();
	}
);

add_action(
	'admin_post_git_auto_deploy_health',
	static function () {
		git_auto_deploy_admin_guard( 'git_auto_deploy_health' );
		$health = Git_Auto_Deploy::health_check();
		git_auto_deploy_flash( array( 'ok' => 'success', 'failed' => 'error', 'unverified' => 'warning' )[ $health['status'] ], $health['summary'] );
		git_auto_deploy_back();
	}
);

/* --------------------------------------------------------------------- page */

function git_auto_deploy_when( $time ): string {
	$time = (int) $time;
	if ( ! $time ) {
		return '—';
	}
	return sprintf(
		'<time datetime="%s" title="%s">%s ago</time>',
		esc_attr( gmdate( 'c', $time ) ),
		esc_attr( wp_date( 'j M Y, H:i', $time ) ),
		esc_html( human_time_diff( $time ) )
	);
}

function git_auto_deploy_icon( array $entry ): string {
	if ( ! empty( $entry['ok'] ) ) {
		return '✅';
	}
	return ! empty( $entry['rolled_back'] ) ? '↩️' : '❌';
}

function git_auto_deploy_version_html( array $meta ): string {
	$commit = (string) ( $meta['commit'] ?? '' );
	$html   = $commit ? '<code>' . esc_html( substr( $commit, 0, 7 ) ) . '</code>' : '<em>no commit</em>';
	if ( ! empty( $meta['subject'] ) ) {
		$html .= ' ' . esc_html( $meta['subject'] );
	}
	return $html;
}

function git_auto_deploy_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice  = get_transient( 'git_auto_deploy_notice_' . get_current_user_id() );
	delete_transient( 'git_auto_deploy_notice_' . get_current_user_id() );
	$current = Git_Auto_Deploy::current();
	$log     = get_option( Git_Auto_Deploy::OPT_LOG, array() );
	$log     = is_array( $log ) ? $log : array();
	$backups = Git_Auto_Deploy::list_slots( 'backups' );
	$failed  = Git_Auto_Deploy::list_slots( 'failed' );
	$secret  = Git_Auto_Deploy::secret();
	$theme   = Git_Auto_Deploy::theme();
	$post    = esc_url( admin_url( 'admin-post.php' ) );
	?>
	<div class="wrap git-auto-deploy">
		<h1>Git Auto Deploy</h1>
		<p>Every push that changes the theme runs the <strong>Deploy theme</strong> workflow on GitHub. It lints the PHP,
			sends the theme here, and this plugin swaps it in as <code>wp-content/themes/<?php echo esc_html( $theme ); ?>/</code>,
			checks the home page, and puts the previous version back if anything breaks. Posts, pages, settings and everything
			else in the database are never touched.
			<a href="<?php echo esc_url( GIT_AUTO_DEPLOY_DOCS ); ?>" target="_blank" rel="noopener">Setup guide</a></p>

		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
		<?php endif; ?>

		<h2>Live theme</h2>
		<table class="widefat striped git-auto-deploy-table">
			<tbody>
				<tr>
					<th scope="row">Version</th>
					<td>
						<?php
						if ( is_array( $current ) ) {
							echo git_auto_deploy_version_html( $current ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
							echo '<br><span class="description">' . esc_html( $current['source'] ?? '' ) . ', ' . git_auto_deploy_when( $current['restored_at'] ?? ( $current['time'] ?? 0 ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							echo 'Not deployed from Git yet. The first deploy backs up the theme that is here now.';
						}
						?>
					</td>
				</tr>
				<?php if ( $log ) : ?>
					<tr>
						<th scope="row">Last activity</th>
						<td><?php echo git_auto_deploy_icon( $log[0] ); ?> <?php echo esc_html( $log[0]['message'] ); ?> <span class="description"><?php echo git_auto_deploy_when( $log[0]['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<h2>Connect GitHub</h2>
		<ol>
			<li>On GitHub open the repository, then <strong>Settings → Secrets and variables → Actions → New repository secret</strong>.</li>
			<li>Add <code>GIT_AUTO_DEPLOY_URL</code> with the value <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code></li>
			<li>Add <code>GIT_AUTO_DEPLOY_SECRET</code> with this value:
				<?php if ( '' !== $secret ) : ?>
					<details class="git-auto-deploy-secret"><summary>Show secret</summary>
						<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $secret ); ?>" onfocus="this.select()">
					</details>
				<?php else : ?>
					<em>no secret yet, create one below.</em>
				<?php endif; ?>
			</li>
			<li>Add the workflow and script from the <a href="<?php echo esc_url( GIT_AUTO_DEPLOY_DOCS ); ?>" target="_blank" rel="noopener">setup guide</a>
				to the repository, with <code>THEME_SLUG: <?php echo esc_html( $theme ); ?></code></li>
			<li>Push a theme change, or run <strong>Deploy theme</strong> by hand from the repository's <strong>Actions</strong> tab.</li>
		</ol>
		<?php if ( Git_Auto_Deploy::secret_from_config() ) : ?>
			<p class="description">The secret comes from <code>GIT_AUTO_DEPLOY_SECRET</code> in wp-config.php. Change it there.</p>
		<?php else : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" onsubmit="return confirm('Create a new secret? Deploys fail until you paste it into GitHub.');">
				<input type="hidden" name="action" value="git_auto_deploy_secret">
				<?php wp_nonce_field( 'git_auto_deploy_secret' ); ?>
				<?php submit_button( '' !== $secret ? 'Create a new secret' : 'Create secret', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h2>Server checks</h2>
		<table class="widefat striped git-auto-deploy-table">
			<tbody>
				<?php foreach ( Git_Auto_Deploy::server_checks() as $check ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
						<td><?php echo null === $check['ok'] ? '' : ( $check['ok'] ? '✅ ' : '❌ ' ); ?><?php echo esc_html( $check['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="git-auto-deploy-inline">
			<input type="hidden" name="action" value="git_auto_deploy_health">
			<?php wp_nonce_field( 'git_auto_deploy_health' ); ?>
			<?php submit_button( 'Run the health check now', 'secondary', 'submit', false ); ?>
			<span class="description">Loads the home page the same way a deploy does.</span>
		</form>

		<h2>Earlier versions</h2>
		<?php if ( ! $backups ) : ?>
			<p>No backups yet. Each deploy keeps the version it replaced (the last <?php echo (int) GIT_AUTO_DEPLOY_KEEP; ?>).</p>
		<?php else : ?>
			<table class="widefat striped git-auto-deploy-table">
				<thead><tr><th>Version</th><th>Live until</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $backups as $slot ) : ?>
						<tr>
							<td><?php echo git_auto_deploy_version_html( $slot['meta'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo git_auto_deploy_when( (int) filemtime( $slot['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td class="git-auto-deploy-actions">
								<?php if ( $slot['restorable'] ) : ?>
									<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" onsubmit="return confirm('Put this version back on the live site?');">
										<input type="hidden" name="action" value="git_auto_deploy_rollback">
										<input type="hidden" name="slot" value="<?php echo esc_attr( $slot['name'] ); ?>">
										<?php wp_nonce_field( 'git_auto_deploy_rollback' ); ?>
										<?php submit_button( 'Roll back to this', 'secondary small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Rolling back keeps the version it replaces in this list, so you can switch back.</p>
		<?php endif; ?>
		<?php if ( $failed ) : ?>
			<p class="description">Rolled back after failing a check: <?php echo esc_html( implode( ', ', array_map( static fn( $s ) => Git_Auto_Deploy::label( $s['meta'] ), $failed ) ) ); ?>. Their files stay in wp-content/git-auto-deploy/failed/ for a look.</p>
		<?php endif; ?>

		<h2>Upload a theme zip</h2>
		<p>For a deploy without GitHub. The zip must hold one folder named <code><?php echo esc_html( Git_Auto_Deploy::theme() ); ?></code>.
			It gets the same backup, health check and automatic rollback.</p>
		<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" enctype="multipart/form-data" class="git-auto-deploy-inline">
			<input type="hidden" name="action" value="git_auto_deploy_upload">
			<?php wp_nonce_field( 'git_auto_deploy_upload' ); ?>
			<input type="file" name="git_auto_deploy_zip" accept=".zip,application/zip" required>
			<?php submit_button( 'Upload and deploy', 'primary', 'submit', false ); ?>
		</form>

		<h2>Activity</h2>
		<?php if ( ! $log ) : ?>
			<p>Nothing yet.</p>
		<?php else : ?>
			<table class="widefat striped git-auto-deploy-table">
				<thead><tr><th>When</th><th>What</th><th>Version</th><th>Result</th></tr></thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo git_auto_deploy_when( $entry['time'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( ucfirst( (string) ( $entry['action'] ?? '' ) ) ); ?><br><span class="description"><?php echo esc_html( (string) ( $entry['source'] ?? '' ) ); ?></span></td>
							<td><?php echo git_auto_deploy_version_html( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo git_auto_deploy_icon( $entry ); ?> <?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?>
								<span class="description">(<?php echo esc_html( (string) ( $entry['seconds'] ?? 0 ) ); ?> s)</span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<style>
		.git-auto-deploy .git-auto-deploy-table { max-width: 960px; margin-top: 8px; }
		.git-auto-deploy .git-auto-deploy-table th[scope="row"] { width: 220px; font-weight: 600; }
		.git-auto-deploy .git-auto-deploy-actions { text-align: right; }
		.git-auto-deploy .git-auto-deploy-actions form { margin: 0; }
		.git-auto-deploy .git-auto-deploy-inline { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
		.git-auto-deploy .git-auto-deploy-secret { display: inline-block; vertical-align: top; min-width: min(560px, 100%); }
		.git-auto-deploy .git-auto-deploy-secret summary { cursor: pointer; color: #2271b1; }
		.git-auto-deploy .git-auto-deploy-secret input { margin-top: 6px; }
		.git-auto-deploy h2 { margin-top: 2em; }
		.git-auto-deploy code { word-break: break-all; }
	</style>
	<?php
}
