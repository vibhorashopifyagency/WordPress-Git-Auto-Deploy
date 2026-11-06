<?php
/**
 * Unpacks a theme zip, swaps it in, health-checks the site and rolls back.
 *
 * Working folders live in wp-content/git-auto-deploy/ (blocked from the web by .htaccess):
 *   incoming/  zip being unpacked (emptied at the start of every deploy)
 *   backups/   earlier good versions, newest first; rollback moves one back into place
 *   failed/    versions that were rolled back, kept for a look
 * Each slot is a folder holding meta.json and the theme folder itself.
 */

defined( 'ABSPATH' ) || exit;

class Git_Auto_Deploy_Error extends RuntimeException {
	public int $status;

	public function __construct( string $message, int $status = 500 ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

final class Git_Auto_Deploy {

	const OPT_SECRET  = 'git_auto_deploy_secret';
	const OPT_CURRENT = 'git_auto_deploy_current';
	const OPT_LOG     = 'git_auto_deploy_log';
	const OPT_LOCK    = 'git_auto_deploy_lock';

	const MAX_FILES    = 10000;
	const MAX_UNZIPPED = 209715200; // 200 MB.
	const KEEP_FAILED  = 3;
	const LOG_SIZE     = 30;
	const LOCK_STALE   = 900; // A lock older than 15 minutes is from a crashed request.

	/* ------------------------------------------------------------------ paths */

	public static function base_dir(): string {
		return WP_CONTENT_DIR . '/git-auto-deploy';
	}

	/**
	 * Folder name of the theme that deploys replace: GIT_AUTO_DEPLOY_THEME from wp-config.php,
	 * or else the active theme (the child theme, when one is active). Empty when the name is
	 * not a plain folder name, so a bad value can never point the swap at another folder.
	 */
	public static function theme(): string {
		$theme = defined( 'GIT_AUTO_DEPLOY_THEME' ) ? GIT_AUTO_DEPLOY_THEME : get_option( 'stylesheet' );
		$theme = is_string( $theme ) ? $theme : '';
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $theme ) ? $theme : '';
	}

	public static function theme_from_config(): bool {
		return defined( 'GIT_AUTO_DEPLOY_THEME' );
	}

	public static function theme_dir(): string {
		$theme = self::theme();
		if ( '' === $theme ) {
			throw new Git_Auto_Deploy_Error( 'No valid theme folder name. Check GIT_AUTO_DEPLOY_THEME in wp-config.php.', 500 );
		}
		return get_theme_root( $theme ) . '/' . $theme;
	}

	/* ----------------------------------------------------------------- secret */

	public static function secret(): string {
		if ( defined( 'GIT_AUTO_DEPLOY_SECRET' ) && is_string( GIT_AUTO_DEPLOY_SECRET ) && strlen( GIT_AUTO_DEPLOY_SECRET ) >= 32 ) {
			return GIT_AUTO_DEPLOY_SECRET;
		}
		return (string) get_option( self::OPT_SECRET, '' );
	}

	public static function secret_from_config(): bool {
		return defined( 'GIT_AUTO_DEPLOY_SECRET' ) && is_string( GIT_AUTO_DEPLOY_SECRET ) && strlen( GIT_AUTO_DEPLOY_SECRET ) >= 32;
	}

	public static function regenerate_secret(): string {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( self::OPT_SECRET, $secret, false );
		return $secret;
	}

	public static function activate(): void {
		if ( '' === (string) get_option( self::OPT_SECRET, '' ) ) {
			self::regenerate_secret();
		}
		try {
			self::ensure_dirs();
		} catch ( Git_Auto_Deploy_Error $e ) {
			// The admin page reports folders it cannot create.
		}
	}

	/* ------------------------------------------------------------------- lock */

	/**
	 * Takes the deploy lock with INSERT IGNORE so two requests can never both win.
	 * (add_option() uses ON DUPLICATE KEY UPDATE, which lets both through.)
	 */
	private static function lock(): bool {
		global $wpdb;

		$take = static function () use ( $wpdb ): bool {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					self::OPT_LOCK,
					(string) time()
				)
			);
			return 1 === (int) $inserted;
		};

		if ( $take() ) {
			self::forget_lock_cache();
			return true;
		}

		$since = self::lock_time();
		if ( $since && time() - $since > self::LOCK_STALE ) {
			// Delete only the exact stale row, so a fresh lock taken meanwhile survives.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::OPT_LOCK,
					(string) $since
				)
			);
			if ( $take() ) {
				self::forget_lock_cache();
				return true;
			}
		}
		return false;
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPT_LOCK ) );
		self::forget_lock_cache();
	}

	private static function lock_time(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_LOCK )
		);
	}

	public static function is_locked(): bool {
		$since = self::lock_time();
		return $since > 0 && time() - $since <= self::LOCK_STALE;
	}

	private static function forget_lock_cache(): void {
		wp_cache_delete( self::OPT_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/* ---------------------------------------------------------------- actions */

	/**
	 * Deploys a theme zip: unpack, validate, back up the live theme, swap, health-check.
	 *
	 * @param string $zip  Path to the zip file.
	 * @param array  $meta commit, subject and source of this version.
	 */
	public static function deploy( string $zip, array $meta ): array {
		return self::run(
			'deploy',
			$meta,
			static function () use ( $zip, $meta ): array {
				self::ensure_dirs();
				self::empty_dir( self::base_dir() . '/incoming' );

				$work = self::base_dir() . '/incoming/' . self::stamp( $meta['commit'] ?? '' );
				try {
					self::extract( $zip, $work );
					$new = $work . '/' . self::theme();
					self::check_theme( $new );
					$backup = self::swap_in( $new );
				} finally {
					self::rrmdir( $work );
				}

				$meta['time']  = time();
				$meta['theme'] = self::theme();
				update_option( self::OPT_CURRENT, $meta, false );

				try {
					self::flush_caches();
					$health = self::health_check();
				} catch ( Throwable $e ) {
					// Unknown state after the swap: put the previous version back to be safe.
					if ( $backup ) {
						self::restore( $backup, 'failed', 'Deploy error: ' . $e->getMessage() );
						self::flush_caches();
					}
					self::prune();
					throw new Git_Auto_Deploy_Error(
						'The deploy hit an error after the swap (' . $e->getMessage() . ')' . ( $backup ? ', so the previous version was put back.' : '.' ),
						500
					);
				}

				if ( 'failed' === $health['status'] && $backup ) {
					self::restore( $backup, 'failed', 'Health check failed: ' . $health['summary'] );
					self::flush_caches();
					self::prune();
					return array(
						'ok'          => false,
						'status'      => 500,
						'rolled_back' => true,
						'message'     => 'The new theme broke the site, so the previous version was put back. ' . $health['summary'],
						'health'      => $health,
					);
				}

				self::prune();

				$message = 'failed' === $health['status']
					? 'Deployed, but the health check failed and there was no earlier version to put back. ' . $health['summary']
					: 'Deployed. ' . $health['summary'];

				return array(
					'ok'      => 'failed' !== $health['status'],
					'status'  => 'failed' === $health['status'] ? 500 : 200,
					'message' => $message,
					'health'  => $health,
					'backup'  => $backup ? basename( $backup ) : null,
				);
			}
		);
	}

	/**
	 * Puts a backup back in place.
	 *
	 * @param string|null $slot_name Backup folder name, or null for the newest one.
	 * @param string      $aside     Where the replaced version goes: 'backups' (manual undo, can be
	 *                               toggled back) or 'failed' (the CI found it broken).
	 * @param array       $meta      source and reason of this rollback.
	 */
	public static function rollback( ?string $slot_name, string $aside, array $meta ): array {
		return self::run(
			'rollback',
			$meta,
			static function () use ( $slot_name, $aside, $meta ): array {
				self::ensure_dirs();

				$slot = null;
				foreach ( self::list_slots( 'backups' ) as $candidate ) {
					if ( ! $candidate['restorable'] ) {
						continue;
					}
					if ( null === $slot_name || $candidate['name'] === $slot_name ) {
						$slot = $candidate;
						break;
					}
				}
				if ( ! $slot ) {
					throw new Git_Auto_Deploy_Error( null === $slot_name ? 'There is no backup to roll back to.' : 'That backup no longer exists.', 404 );
				}

				$restored = self::restore( $slot['path'], 'failed' === $aside ? 'failed' : 'backups', $meta['reason'] ?? 'Rolled back' );
				self::flush_caches();
				$health = self::health_check();
				self::prune();

				return array(
					'ok'       => true,
					'status'   => 200,
					'message'  => sprintf( 'Restored %s. %s', self::label( $restored ), $health['summary'] ),
					'restored' => $restored,
					'health'   => $health,
					'log'      => array(
						'commit'  => $restored['commit'] ?? '',
						'subject' => $restored['subject'] ?? '',
					),
				);
			}
		);
	}

	/**
	 * Runs an action under the lock and logs the outcome.
	 */
	private static function run( string $action, array $meta, callable $work ): array {
		$started = microtime( true );

		if ( ! self::lock() ) {
			$result = array(
				'ok'      => false,
				'status'  => 409,
				'message' => 'Another deploy or rollback is running. Try again in a minute.',
			);
		} else {
			try {
				self::theme_dir(); // Refuses to start without a valid theme folder name.
				self::prepare_runtime();
				$result = $work();
			} catch ( Git_Auto_Deploy_Error $e ) {
				$result = array(
					'ok'      => false,
					'status'  => $e->status,
					'message' => $e->getMessage(),
				);
			} catch ( Throwable $e ) {
				$result = array(
					'ok'      => false,
					'status'  => 500,
					'message' => 'Unexpected error: ' . $e->getMessage(),
				);
			} finally {
				self::unlock();
			}
		}

		$result['seconds'] = round( microtime( true ) - $started, 1 );

		self::log(
			array_merge(
				array(
					'action'  => $action,
					'source'  => $meta['source'] ?? '',
					'commit'  => $meta['commit'] ?? '',
					'subject' => $meta['subject'] ?? '',
				),
				$result['log'] ?? array(),
				array(
					'ok'          => (bool) $result['ok'],
					'rolled_back' => ! empty( $result['rolled_back'] ),
					'message'     => $result['message'] ?? '',
					'seconds'     => $result['seconds'],
				)
			)
		);
		unset( $result['log'] );

		return $result;
	}

	private static function prepare_runtime(): void {
		// Finish the swap even if GitHub drops the connection halfway.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );
	}

	/* --------------------------------------------------------------- the zip */

	private static function extract( string $zip_file, string $dest ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Git_Auto_Deploy_Error( 'This server has no PHP zip extension. Ask the host to enable it.', 500 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file ) ) {
			throw new Git_Auto_Deploy_Error( 'The upload is not a valid zip file.', 400 );
		}

		try {
			$count = $zip->numFiles;
			if ( $count < 1 || $count > self::MAX_FILES ) {
				throw new Git_Auto_Deploy_Error( sprintf( 'The zip holds %d entries; expected 1 to %d.', $count, self::MAX_FILES ), 422 );
			}

			$root    = self::theme() . '/';
			$entries = array();
			$total   = 0;
			$styles  = false;

			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				$name = (string) ( $stat['name'] ?? '' );

				// Leftovers from the macOS Finder's "Compress" are skipped, not extracted.
				if ( str_starts_with( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name ) ) {
					continue;
				}

				if ( '' === $name
					|| str_contains( $name, "\0" )
					|| str_contains( $name, '\\' )
					|| str_starts_with( $name, '/' )
					|| preg_match( '#^[A-Za-z]:#', $name )
					|| preg_match( '#(^|/)\.\.?(/|$)#', $name )
				) {
					throw new Git_Auto_Deploy_Error( 'Unsafe path in zip: ' . $name, 422 );
				}

				if ( ! str_starts_with( $name, $root ) ) {
					throw new Git_Auto_Deploy_Error( sprintf( 'Everything in the zip must sit inside a "%s" folder; found "%s".', self::theme(), $name ), 422 );
				}

				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr )
					&& ZipArchive::OPSYS_UNIX === $opsys
					&& 0120000 === ( ( $attr >> 16 ) & 0170000 )
				) {
					throw new Git_Auto_Deploy_Error( 'Symlinks are not allowed in the zip: ' . $name, 422 );
				}

				$total += (int) ( $stat['size'] ?? 0 );
				if ( $total > self::MAX_UNZIPPED ) {
					throw new Git_Auto_Deploy_Error( 'The zip unpacks to more than 200 MB.', 422 );
				}

				if ( $root . 'style.css' === $name ) {
					$styles = true;
				}
				$entries[] = $name;
			}

			if ( ! $styles ) {
				throw new Git_Auto_Deploy_Error( sprintf( 'The zip has no %sstyle.css, so it is not this theme.', $root ), 422 );
			}

			if ( ! wp_mkdir_p( $dest ) || ! $zip->extractTo( $dest, $entries ) ) {
				throw new Git_Auto_Deploy_Error( 'Could not unpack the zip on the server (disk full or no write access?).', 500 );
			}
		} finally {
			$zip->close();
		}
	}

	private static function check_theme( string $dir ): void {
		$style = $dir . '/style.css';
		if ( ! is_file( $style ) ) {
			throw new Git_Auto_Deploy_Error( 'Unpacked theme has no style.css.', 422 );
		}
		$data = get_file_data( $style, array( 'name' => 'Theme Name' ) );
		if ( '' === trim( (string) $data['name'] ) ) {
			throw new Git_Auto_Deploy_Error( 'style.css has no "Theme Name:" header.', 422 );
		}
		if ( ! is_file( $dir . '/index.php' ) && ! is_file( $dir . '/templates/index.html' ) ) {
			throw new Git_Auto_Deploy_Error( 'Unpacked theme has no index.php.', 422 );
		}
	}

	/* ------------------------------------------------------------ the swap */

	/**
	 * Moves the live theme into a new backup slot and the new folder into its place.
	 *
	 * @return string|null Backup slot path, or null if there was no live theme yet.
	 */
	private static function swap_in( string $new ): ?string {
		$live = self::theme_dir();

		if ( ! is_dir( $live ) ) {
			if ( ! @rename( $new, $live ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Git_Auto_Deploy_Error( 'Could not move the new theme into wp-content/themes/.', 500 );
			}
			return null;
		}

		$current = self::current();
		if ( ! $current ) {
			$current = array(
				'commit'  => '',
				'subject' => 'Theme as it was before the first Git deploy',
				'source'  => 'before Git deploy',
				'time'    => (int) @filemtime( $live . '/style.css' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				'theme'   => self::theme(),
			);
		}

		$slot = self::new_slot( 'backups', $current['commit'] ?? '' );
		self::write_meta( $slot, $current );

		if ( ! @rename( $live, $slot . '/' . self::theme() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::rrmdir( $slot );
			throw new Git_Auto_Deploy_Error( 'Could not move the live theme aside. Check that wp-content/themes/ is writable.', 500 );
		}

		if ( ! @rename( $new, $live ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rename( $slot . '/' . self::theme(), $live ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::rrmdir( $slot );
			throw new Git_Auto_Deploy_Error( 'Could not move the new theme into place; the live theme was left as it was.', 500 );
		}

		return $slot;
	}

	/**
	 * Moves a backup slot's theme back into place. The version it replaces goes to $aside.
	 *
	 * @return array Meta of the restored version.
	 */
	private static function restore( string $slot, string $aside, string $reason ): array {
		$from = $slot . '/' . self::theme();
		if ( ! is_dir( $from ) ) {
			throw new Git_Auto_Deploy_Error( 'That backup has no theme folder in it.', 404 );
		}

		$live    = self::theme_dir();
		$current = self::current() ?? array(
			'commit' => '',
			'theme'  => self::theme(),
		);

		$moved = null;
		if ( is_dir( $live ) ) {
			$moved = self::new_slot( $aside, $current['commit'] ?? '' );
			self::write_meta( $moved, $current + array( 'replaced_because' => $reason ) );
			if ( ! @rename( $live, $moved . '/' . self::theme() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::rrmdir( $moved );
				throw new Git_Auto_Deploy_Error( 'Could not move the live theme aside for the rollback.', 500 );
			}
		}

		if ( ! @rename( $from, $live ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $moved ) {
				@rename( $moved . '/' . self::theme(), $live ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::rrmdir( $moved );
			}
			throw new Git_Auto_Deploy_Error( 'Could not move the backup into place; the live theme was left as it was.', 500 );
		}

		$restored = self::read_meta( $slot );
		unset( $restored['replaced_because'] );
		$restored['restored_at'] = time();
		$restored['theme']       = self::theme();
		update_option( self::OPT_CURRENT, $restored, false );
		self::rrmdir( $slot );

		return $restored;
	}

	/* ----------------------------------------------------------- health check */

	/**
	 * Loads the home page (and any URLs added with the git_auto_deploy_health_urls filter).
	 *
	 * status: ok | failed | unverified (the server could not reach itself, so nothing is known).
	 */
	public static function health_check(): array {
		$urls   = (array) apply_filters( 'git_auto_deploy_health_urls', array( home_url( '/' ) ) );
		$checks = array();

		foreach ( $urls as $url ) {
			// A fresh query string skips page caches such as LiteSpeed's.
			$probe    = add_query_arg( 'git_auto_deploy_check', wp_generate_password( 10, false ), $url );
			$response = wp_remote_get(
				$probe,
				array(
					'timeout'     => 25,
					'redirection' => 3,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'user-agent'  => 'Git-Auto-Deploy/' . GIT_AUTO_DEPLOY_VERSION . ' health check',
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$checks[] = array(
					'url'    => $url,
					'status' => 'unverified',
					'detail' => 'not reachable from the server: ' . $response->get_error_message(),
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			$broken = $code >= 500
				|| str_contains( $body, 'There has been a critical error' )
				|| preg_match( '#<b>(Fatal|Parse) error</b>:#', $body );

			if ( $broken ) {
				$status = 'failed';
				$detail = 'HTTP ' . $code . ( $code < 500 ? ' with a PHP error on the page' : '' );
			} elseif ( $code >= 200 && $code < 400 && str_contains( $body, '</html>' ) ) {
				$status = 'ok';
				$detail = 'HTTP ' . $code;
			} elseif ( $code >= 200 && $code < 400 ) {
				$status = 'failed';
				$detail = 'HTTP ' . $code . ' but the page stopped before </html>';
			} else {
				$status = 'unverified';
				$detail = 'HTTP ' . $code . ' (blocked or protected, so the check could not tell)';
			}

			$checks[] = array(
				'url'    => $url,
				'status' => $status,
				'detail' => $detail,
			);
		}

		$statuses = wp_list_pluck( $checks, 'status' );
		if ( in_array( 'failed', $statuses, true ) ) {
			$overall = 'failed';
		} elseif ( in_array( 'unverified', $statuses, true ) || ! $statuses ) {
			$overall = 'unverified';
		} else {
			$overall = 'ok';
		}

		$parts = array();
		foreach ( $checks as $check ) {
			$parts[] = wp_make_link_relative( $check['url'] ) . ' ' . $check['detail'];
		}
		$summary = array(
			'ok'         => 'Health check passed (' . implode( '; ', $parts ) . ').',
			'failed'     => 'Health check failed (' . implode( '; ', $parts ) . ').',
			'unverified' => 'Health check skipped (' . implode( '; ', $parts ) . ').',
		)[ $overall ];

		return array(
			'status'  => $overall,
			'summary' => $summary,
			'checks'  => $checks,
		);
	}

	private static function flush_caches(): void {
		wp_clean_themes_cache();

		// Make PHP drop cached copies of the theme's old code.
		if ( function_exists( 'opcache_invalidate' ) && is_dir( self::theme_dir() ) ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( self::theme_dir(), FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $files as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					@opcache_invalidate( $file->getPathname(), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}

		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache plugin, when it is active.
		do_action( 'git_auto_deploy_after_swap' );
	}

	/* ---------------------------------------------------------- slots & files */

	/**
	 * @return array[] name, path, meta, restorable — newest first.
	 */
	public static function list_slots( string $kind ): array {
		$dir = self::base_dir() . '/' . $kind;
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$names = array_filter(
			(array) scandir( $dir ),
			static function ( $name ) use ( $dir ) {
				return preg_match( '/^\d{8}-\d{6}-\d{6}-[A-Za-z0-9-]+$/', (string) $name ) && is_dir( $dir . '/' . $name );
			}
		);
		rsort( $names );

		$slots = array();
		foreach ( $names as $name ) {
			$path    = $dir . '/' . $name;
			$slots[] = array(
				'name'       => $name,
				'path'       => $path,
				'meta'       => self::read_meta( $path ),
				'restorable' => is_dir( $path . '/' . self::theme() ),
			);
		}
		return $slots;
	}

	private static function prune(): void {
		foreach ( array( 'backups' => max( 1, (int) GIT_AUTO_DEPLOY_KEEP ), 'failed' => self::KEEP_FAILED ) as $kind => $keep ) {
			foreach ( array_slice( self::list_slots( $kind ), $keep ) as $old ) {
				self::rrmdir( $old['path'] );
			}
		}
	}

	private static function new_slot( string $kind, string $commit ): string {
		$base = self::base_dir() . '/' . $kind . '/' . self::stamp( $commit );
		$slot = $base;
		$n    = 2;
		while ( file_exists( $slot ) ) {
			$slot = $base . '-' . $n++;
		}
		if ( ! wp_mkdir_p( $slot ) ) {
			throw new Git_Auto_Deploy_Error( 'Could not create ' . $slot, 500 );
		}
		return $slot;
	}

	/**
	 * Folder name that sorts by time down to the microsecond, then names the commit:
	 * 20261106-093012-482113-1a2b3c4. Slots are ordered by name, so two deploys in the
	 * same second must still sort in the order they happened.
	 */
	private static function stamp( string $commit ): string {
		$short = preg_match( '/^[0-9a-f]{7,40}$/', $commit ) ? substr( $commit, 0, 7 ) : 'manual';
		$now   = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
		return ( $now ? $now->format( 'Ymd-His-u' ) : gmdate( 'Ymd-His-000000' ) ) . '-' . $short;
	}

	private static function write_meta( string $slot, array $meta ): void {
		file_put_contents( $slot . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function read_meta( string $slot ): array {
		$file = $slot . '/meta.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $data ) ? $data : array();
	}

	public static function ensure_dirs(): void {
		$base = self::base_dir();
		foreach ( array( $base, "$base/incoming", "$base/backups", "$base/failed" ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new Git_Auto_Deploy_Error( 'Could not create ' . $dir . '. Check that wp-content/ is writable.', 500 );
			}
		}
		if ( ! is_file( "$base/.htaccess" ) ) {
			file_put_contents(
				"$base/.htaccess",
				"# Git Auto Deploy backups: never served to visitors.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			);
		}
		if ( ! is_file( "$base/index.php" ) ) {
			file_put_contents( "$base/index.php", "<?php\n// Silence is golden.\n" );
		}
	}

	private static function empty_dir( string $dir ): void {
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( '.' !== $name && '..' !== $name && '' !== (string) $name ) {
				self::rrmdir( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Deletes a folder tree. Refuses anything outside wp-content/git-auto-deploy/, so a bug
	 * can never delete the live theme.
	 */
	public static function rrmdir( string $path ): void {
		$inside = wp_normalize_path( self::base_dir() ) . '/';
		if ( ! str_starts_with( wp_normalize_path( $path ), $inside ) ) {
			throw new Git_Auto_Deploy_Error( 'Refusing to delete outside wp-content/git-auto-deploy/: ' . $path, 500 );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/* ------------------------------------------------------------ status & log */

	private static function log( array $entry ): void {
		$log = get_option( self::OPT_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, array( 'time' => time() ) + $entry );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	public static function label( array $meta ): string {
		$commit = (string) ( $meta['commit'] ?? '' );
		$text   = $commit ? substr( $commit, 0, 7 ) : 'manual upload';
		if ( ! empty( $meta['subject'] ) ) {
			$text .= ' “' . $meta['subject'] . '”';
		}
		return $text;
	}

	/**
	 * @return array[] label, ok (true|false|null for info), detail.
	 */
	public static function server_checks(): array {
		$theme  = self::theme();
		$themes = get_theme_root( $theme );
		$base   = self::base_dir();
		$post   = (string) ini_get( 'post_max_size' );
		$where  = self::theme_from_config() ? 'set in wp-config.php' : 'the active theme';

		if ( '' === $theme ) {
			$theme_check = array(
				'label'  => 'Theme folder',
				'ok'     => false,
				'detail' => 'GIT_AUTO_DEPLOY_THEME in wp-config.php is not a plain folder name',
			);
		} else {
			$exists      = is_dir( self::theme_dir() );
			$theme_check = array(
				'label'  => 'Theme folder',
				'ok'     => $exists,
				'detail' => wp_make_link_relative( content_url( 'themes/' . $theme ) ) . ' (' . $where . ')' . ( $exists ? '' : ', not there yet: the first deploy creates it' ),
			);
		}

		return array(
			array(
				'label'  => 'Deploy secret',
				'ok'     => '' !== self::secret(),
				'detail' => self::secret_from_config() ? 'set in wp-config.php' : ( '' !== self::secret() ? 'set' : 'missing: generate one below' ),
			),
			array(
				'label'  => 'PHP zip extension',
				'ok'     => class_exists( 'ZipArchive' ),
				'detail' => class_exists( 'ZipArchive' ) ? 'available' : 'missing: ask the host to enable it',
			),
			$theme_check,
			array(
				'label'  => 'Themes folder writable',
				'ok'     => wp_is_writable( $themes ),
				'detail' => wp_is_writable( $themes ) ? 'yes' : 'no: deploys cannot swap the theme',
			),
			array(
				'label'  => 'Backup folder writable',
				'ok'     => is_dir( $base ) ? wp_is_writable( $base ) : wp_is_writable( WP_CONTENT_DIR ),
				'detail' => 'wp-content/git-auto-deploy/',
			),
			array(
				'label'  => 'Largest deploy from GitHub',
				'ok'     => null,
				'detail' => 'post_max_size ' . $post . ' (the theme zip must be smaller)',
			),
			array(
				'label'  => 'Largest zip upload here',
				'ok'     => null,
				'detail' => size_format( wp_max_upload_size() ),
			),
		);
	}

	/**
	 * The record of the live version (commit, subject, source, time), or null when nothing was
	 * deployed yet or the record belongs to another theme (the active theme was switched).
	 */
	public static function current(): ?array {
		$current = get_option( self::OPT_CURRENT );
		if ( ! is_array( $current ) ) {
			return null;
		}
		return ( $current['theme'] ?? self::theme() ) === self::theme() ? $current : null;
	}

	public static function status(): array {
		return array(
			'ok'      => true,
			'site'    => home_url( '/' ),
			'plugin'  => GIT_AUTO_DEPLOY_VERSION,
			'theme'   => self::theme(),
			'current' => self::current(),
			'backups' => count( self::list_slots( 'backups' ) ),
			'checks'  => self::server_checks(),
		);
	}
}
