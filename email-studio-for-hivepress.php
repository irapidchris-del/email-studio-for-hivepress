<?php
/**
 * Plugin Name: Email Studio for HivePress
 * Plugin URI: https://github.com/irapidchris-del/email-studio-for-hivepress
 * Description: Gives HivePress emails a design makeover: a studio screen listing every email your site can send, live previews, test sends, and a styled template applied to all outgoing HivePress emails.
 * Version: 1.3.1
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: email-studio-for-hivepress
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/email-studio-for-hivepress
 *
 * @package HivePress\EmailStudio
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HP_EMAIL_STUDIO_VERSION', '1.3.1' );
define( 'HP_EMAIL_STUDIO_FILE', __FILE__ );

/**
 * Registers the extension with HivePress.
 *
 * HivePress collects extension paths via this filter, then autoloads classes and merges
 * configs from the "includes" directory of every registered path. Two registration forms
 * exist and each has a catch, so the right one is chosen at runtime:
 *
 * - A bare directory STRING is what every official extension passes, but core then derives
 *   the main file as `{dirname}/{dirname}.php` (`class-core.php:267`) and silently registers
 *   nothing when the folder name and the file name disagree. A zip that unpacks to a suffixed
 *   folder, which is exactly what a GitHub "Download ZIP" produces, would leave the plugin
 *   active but completely inert with no error anywhere.
 * - An ARRAY is used as-is (`:262`) and so is immune to the folder name, but core's updater
 *   probe just above it concatenates EVERY entry into `file_exists()` (`:249-250`), which
 *   raises "Array to string conversion" for an array entry.
 *
 * So: use the string form whenever it actually resolves, which is the normal install and
 * behaves exactly like an official extension. Fall back to the array form only when the
 * folder has been renamed, and in that fallback run core's own probe first so the `updates`
 * key is already set and the loop that emits the warning never runs. When nothing bundles
 * the package, a never-existing path stands in, which core's string branch silently skips
 * via its own `file_exists()` guard (`:277`) - the same outcome as the probe finding
 * nothing, minus the warning.
 *
 * Priority 100, so the string-form extensions are all in the array before that probe runs.
 * The filter must be added at file scope; core reads it before plugins_loaded callbacks run.
 *
 * @param array $extensions Extension paths.
 * @return array
 */
add_filter(
	'hivepress/v1/extensions',
	function( $extensions ) {
		$dirname = basename( __DIR__ );

		if ( file_exists( __DIR__ . '/' . $dirname . '.php' ) ) {
			$extensions[] = __DIR__;

			return $extensions;
		}

		if ( ! isset( $extensions['updates'] ) ) {
			$package = '/vendor/hivepress/hivepress-updates';
			$updates = __DIR__ . '/updates-not-bundled';

			foreach ( $extensions as $dir ) {
				if ( is_string( $dir ) && file_exists( $dir . $package . '/hivepress-updates.php' ) ) {
					$updates = $dir . $package;

					break;
				}
			}

			$extensions['updates'] = $updates;
		}

		$extensions['email_studio_for_hivepress'] = [
			'name'    => 'Email Studio for HivePress',
			'version' => HP_EMAIL_STUDIO_VERSION,
			'path'    => __DIR__,
			'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
		];

		return $extensions;
	},
	100
);

/*
 * Translations are deliberately not loaded here.
 *
 * WordPress has loaded plugin translations automatically since 4.6 using the Text Domain and Domain
 * Path headers above, and it reads them from wp-content/languages/plugins/ - which is where both
 * translate.wordpress.org and Loco Translate put them, and the only place that survives a plugin
 * update. Calling load_plugin_textdomain() would point at this plugin's own /languages/ folder
 * instead, so a user's translation would be wiped by the next release. The folder ships the POT
 * alone, for translators to work from.
 */

/**
 * Adds the quick links to the plugin row on the Plugins screen.
 *
 * The links are only useful once HivePress has loaded the extension, so they only appear while
 * HivePress is active; the Check for updates link is added separately by the updater either way.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function( $links ) {
		if ( function_exists( 'hivepress' ) && current_user_can( 'manage_options' ) ) {

			// One link, because the plugin is one screen: the emails, the design that wraps them,
			// the composer and the delivery log all live on the Email Studio page.
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'admin.php?page=hp_email_studio' ) ) . '">' . esc_html__( 'Settings', 'email-studio-for-hivepress' ) . '</a>'
			);
		}

		return $links;
	}
);

/**
 * Adds the Donate link to the plugin row on the Plugins screen.
 *
 * The house spec in releasing.md is copied exactly, changing only the text domain: every plugin's
 * row must look identical, and sessions that composed their own wording or icon have made the rows
 * drift before. The icon is a Dashicon because this renders in wp-admin, where HivePress's Font
 * Awesome is not guaranteed to be enqueued.
 */
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="https://ko-fi.com/chrisbathivepresscommunity" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'email-studio-for-hivepress' )
			. '</a>';

		return $links;
	},
	10,
	2
);

/**
 * Says so when HivePress is missing.
 *
 * Everything this plugin does is loaded by HivePress from the path registered above, so without
 * HivePress there is no settings tab, no Email Studio screen and no email design, and nothing
 * would say why. A plain notice beats silence.
 */
add_action(
	'admin_notices',
	function() {
		if ( function_exists( 'hivepress' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Email Studio for HivePress requires the HivePress plugin to be installed and active. Until then, its settings tab, Email Studio screen and email design are unavailable.', 'email-studio-for-hivepress' ) . '</p></div>';
	}
);

/**
 * Loads the GitHub release updater.
 *
 * The plugin is distributed through GitHub releases rather than wp.org, so updates go through the
 * native "update_plugins_{$hostname}" API keyed off the Update URI header above. The updater is a
 * handful of plain functions with no third-party library behind them.
 */
require_once __DIR__ . '/updater.php';
