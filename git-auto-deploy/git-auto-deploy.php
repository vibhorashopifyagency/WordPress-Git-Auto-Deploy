<?php
/**
 * Plugin Name:       Git Auto Deploy
 * Plugin URI:        https://github.com/vibhorashopifyagency/wordpressgitautodeploy
 * Description:       Deploys your theme from GitHub on every push. A GitHub Actions workflow sends a signed theme zip; the plugin keeps a backup, swaps the theme in, checks the home page and rolls back on its own if the new code breaks the site.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Vibhora Inc.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       git-auto-deploy
 * Update URI:        https://github.com/vibhorashopifyagency/wordpressgitautodeploy
 */

defined( 'ABSPATH' ) || exit;

define( 'GIT_AUTO_DEPLOY_VERSION', '1.0.0' );

/*
 * Which theme folder (under wp-content/themes/) deploys replace: the active theme, or the
 * active child theme. To deploy a different one, add this to wp-config.php:
 *     define( 'GIT_AUTO_DEPLOY_THEME', 'your-theme-folder' );
 */

// How many earlier theme versions to keep for rollback.
if ( ! defined( 'GIT_AUTO_DEPLOY_KEEP' ) ) {
	define( 'GIT_AUTO_DEPLOY_KEEP', 5 );
}

require __DIR__ . '/includes/class-git-auto-deploy.php';
require __DIR__ . '/includes/rest.php';

if ( is_admin() ) {
	require __DIR__ . '/includes/admin.php';
}

register_activation_hook( __FILE__, array( 'Git_Auto_Deploy', 'activate' ) );

/*
 * While the theme folder is being swapped it is missing for a moment. WordPress
 * switches to a default theme if wp-admin/themes.php or the Customizer loads in
 * that moment and finds no theme, so skip that check while a deploy holds the lock.
 */
add_filter(
	'validate_current_theme',
	static function ( $validate ) {
		return Git_Auto_Deploy::is_locked() ? false : $validate;
	}
);
