<?php
/**
 * GitHub release updater.
 *
 * The plugin is distributed via GitHub releases rather than wp.org, so update checks go through the
 * native `update_plugins_{$hostname}` API introduced in WordPress 5.8, keyed off the Update URI
 * header in the main plugin file. The update package is the release asset named `*.zip`, which must
 * contain a single `email-studio-for-hivepress` directory. No third-party library is involved.
 *
 * @package HivePress\EmailStudio
 */

namespace HivePress\EmailStudio\Updater;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

const UPDATE_REPO = 'irapidchris-del/email-studio-for-hivepress';

const UPDATE_SLUG = 'email-studio-for-hivepress';

const UPDATE_CACHE_KEY = 'hp_email_studio_github_release';

/**
 * Why the last release check came back empty, so the notice can say which.
 */
const UPDATE_REASON_KEY = 'hp_email_studio_github_release_reason';

/**
 * When GitHub's hourly allowance for this server is expected back. While this is set the
 * API is not called at all, so a site that has run out does not spend the rest of the
 * window making requests that can only fail.
 */
const UPDATE_RATE_LIMIT_KEY = 'hp_email_studio_github_release_rate_limit';

/**
 * Gets the installed plugin version.
 *
 * @return string
 */
function get_version() {
	static $version = null;

	if ( null === $version ) {
		$data = get_file_data( HP_EMAIL_STUDIO_FILE, [ 'Version' => 'Version' ] );

		$version = $data['Version'];
	}

	return $version;
}

/**
 * Gets the latest GitHub release details, cached for 6 hours.
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null
 */
function get_latest_release( $force = false ) {
	$cached = get_site_transient( UPDATE_CACHE_KEY );

	if ( ! $force && is_array( $cached ) ) {
		/*
		 * A cached answer is served at once, but one older than an hour is refreshed behind the
		 * scenes when someone is on the Plugins screen. Two releases inside the six-hour cache
		 * life otherwise meant a site updated to the middle one first and only saw the newer one
		 * after the cache turned over.
		 */
		if ( $cached && isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], [ 'plugins.php', 'update-core.php' ], true ) && time() - (int) ( isset( $cached['fetched_at'] ) ? $cached['fetched_at'] : 0 ) > HOUR_IN_SECONDS ) {
			schedule_release_refresh();
		}

		return $cached ? $cached : null;
	}

	/*
	 * A cold cache must not be filled from somebody's page load. WordPress asks every plugin for its
	 * update details while rendering an admin request, so with several of these installed one such
	 * request made one blocking call to GitHub after another, in series: a site with nine of them
	 * measured 18.6 seconds on a settings screen, once, and then behaved perfectly for six hours
	 * because the answers were cached again. That is the same shape as the listing-save incident,
	 * on the admin side rather than the public one.
	 *
	 * So the fetch moves to a background job and this answers with what is already known, which on
	 * the very first check is "nothing yet" for a few seconds. Nothing is skipped: the job runs
	 * moments later and fills the cache, and the manual Check for updates link still fetches
	 * immediately, because there a person is waiting for the answer on purpose.
	 */
	if ( ! $force ) {
		schedule_release_refresh();

		return null;
	}

	$release = fetch_latest_release();

	// A failed check must not erase what the last good one found. Overwriting the cache with an
	// empty result took a genuinely pending update off the Plugins screen for an hour with nothing
	// to say why, which is worse than showing a result that is at most a few hours old. The short
	// lifetime means the next check still tries again promptly.
	if ( ! $release && $cached ) {
		set_site_transient( UPDATE_CACHE_KEY, $cached, HOUR_IN_SECONDS );

		return $cached;
	}

	// Failures are cached briefly so the lookup is not repeated on every admin page load.
	if ( is_array( $release ) && $release ) {
		$release['fetched_at'] = time();
	}

	set_site_transient( UPDATE_CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

	return $release ? $release : null;
}

/**
 * Queues a background refresh of the release cache.
 *
 * Prefers HivePress's scheduler, which is Action Scheduler and already refuses a duplicate of a job
 * with the same hook and arguments, so repeated admin requests coalesce into one fetch. WP-Cron is
 * the fallback for the same reason it exists: it also runs the work outside this request.
 *
 * Neither is blocking, so on a site where cron itself is starved the cache simply stays cold and no
 * update is offered until somebody presses Check for updates, which always fetches immediately. That
 * is the same position such a site is already in for every other scheduled thing on it.
 *
 * @return void
 */
function schedule_release_refresh() {
	$hook = UPDATE_CACHE_KEY . '_refresh';

	// Assigned and then tested: Core defines no __isset(), so isset( hivepress()->x ) is always
	// false even for a component that is present and working.
	$scheduler = function_exists( 'hivepress' ) ? hivepress()->scheduler : null;

	if ( $scheduler ) {
		$scheduler->add_action( $hook );

		return;
	}

	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_single_event( time(), $hook );

		return;
	}
}

/**
 * Fills the release cache. Runs from the scheduler, never from a page render.
 *
 * @return void
 */
function refresh_release() {
	get_latest_release( true );
}

add_action( UPDATE_CACHE_KEY . '_refresh', __NAMESPACE__ . '\refresh_release' );

/**
 * Fetches the latest release details from the GitHub API.
 *
 * Draft and pre-release entries are excluded by the endpoint itself, so publishing a pre-release
 * never triggers an update notice.
 *
 * @return array<string, string>
 */
function fetch_latest_release() {
	$data = fetch_release_data();

	if ( ! is_array( $data ) ) {
		return [];
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return [];
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return [];
	}

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	];
}

/**
 * Gets the latest release, from github.com in preference to the GitHub API.
 *
 * WHY THIS DOES NOT SIMPLY CALL THE API
 *
 * Without a token `api.github.com` allows **60 requests an hour per IP address**, and that
 * allowance is shared by every plugin on the site, by every other site on the same server, and by
 * anything else calling the API from that address. A site running several of these extensions,
 * plus a few clicks of "Check for updates" - which deliberately bypasses the cache - spends it
 * easily; on shared hosting a neighbouring site can spend it alone. GitHub then answers 403, and
 * reporting that as "could not reach GitHub" sends the owner hunting a network fault that does not
 * exist. That is the same family of bug as reporting a 404 as unreachable: a refusal is an answer,
 * not a failure to get one.
 *
 * Everything this lookup needs is also published on github.com itself, which carries no such
 * allowance:
 *
 *   - `/releases/latest` answers 302, and the Location header names the release GitHub considers
 *     latest, with drafts and pre-releases excluded exactly as the API excludes them;
 *   - `/releases/expanded_assets/{tag}` is the fragment the release page uses to list its own
 *     downloads, so it names the asset;
 *   - `/releases.atom` carries the release notes.
 *
 * Measured against GitHub's own rate-limit counter on 2026-08-19, thirteen full update checks
 * through this route moved it by zero. The API is kept as a fallback so that a change at github.com
 * cannot leave the plugin with no way to check at all.
 *
 * @return array<string, mixed>|null Release data in the API's own shape, or null.
 */
function fetch_release_data() {
	$site = fetch_release_from_site();

	if ( isset( $site['release'] ) ) {
		delete_site_transient( UPDATE_REASON_KEY );

		return $site['release'];
	}

	// github.com has given a definite answer that nothing is published. Asking the API would only
	// repeat it, at the cost of one of the sixty.
	if ( isset( $site['reason'] ) && 'no_release' === $site['reason'] ) {
		set_site_transient( UPDATE_REASON_KEY, 'no_release', HOUR_IN_SECONDS );

		return null;
	}

	return fetch_release_from_api();
}

/**
 * Reads the latest release from github.com, without touching the API allowance.
 *
 * @return array<string, mixed> Either a `release` in the API's shape, a `reason`, or empty to fall
 *                              back to the API.
 */
function fetch_release_from_site() {
	$base = 'https://github.com/' . UPDATE_REPO;

	$response = request(
		$base . '/releases/latest',
		[
			// Do not follow it. The redirect target is the answer.
			'redirection' => 0,
		]
	);

	if ( ! $response ) {
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	// A repository with nothing published answers 404 here, which is the normal state of a new
	// repository rather than a fault.
	if ( 404 === $code ) {
		return [ 'reason' => 'no_release' ];
	}

	if ( 301 !== $code && 302 !== $code ) {
		return [];
	}

	$location = wp_remote_retrieve_header( $response, 'location' );

	// WordPress hands back an array when a header repeats.
	if ( is_array( $location ) ) {
		$location = end( $location );
	}

	if ( ! preg_match( '#/releases/tag/(.+)$#', (string) $location, $matches ) ) {
		return [];
	}

	$tag = rawurldecode( trim( $matches[1] ) );

	$asset = fetch_release_asset( $base, $tag );

	// No downloadable asset means there is nothing the updater could install, so let the API have
	// its say rather than reporting a release that cannot be applied.
	if ( ! $asset ) {
		return [];
	}

	$notes = fetch_release_notes( $base, $tag );

	// Shaped exactly like the API's own answer, so everything downstream is identical either way.
	return [
		'release' => [
			'tag_name'     => $tag,
			'html_url'     => $base . '/releases/tag/' . rawurlencode( $tag ),
			'body'         => $notes['body'],
			'published_at' => $notes['published'],
			'assets'       => [
				[
					'name'                 => $asset['name'],
					'browser_download_url' => $asset['url'],
				],
			],
		],
	];
}

/**
 * Reads a release's asset from the fragment the release page uses to list its own downloads.
 *
 * @param string $base Repository URL.
 * @param string $tag Release tag.
 * @return array<string, string>|null
 */
function fetch_release_asset( $base, $tag ) {
	$response = request( $base . '/releases/expanded_assets/' . rawurlencode( $tag ) );

	if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	if ( ! preg_match_all( '#href="(/[^"]*/releases/download/[^"]+\.zip)"#i', wp_remote_retrieve_body( $response ), $matches ) ) {
		return null;
	}

	// Take the first zip, matching what the API branch does with the assets list.
	$path = html_entity_decode( $matches[1][0], ENT_QUOTES, 'UTF-8' );

	return [
		'name' => rawurldecode( basename( $path ) ),
		'url'  => 'https://github.com' . $path,
	];
}

/**
 * Reads a release's notes and publication date from the releases feed.
 *
 * Only the changelog in the plugin details popup depends on this, so a failure here is not fatal.
 *
 * @param string $base Repository URL.
 * @param string $tag Release tag.
 * @return array<string, string>
 */
function fetch_release_notes( $base, $tag ) {
	$empty = [
		'body'      => '',
		'published' => '',
	];

	$response = request( $base . '/releases.atom' );

	if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $empty;
	}

	if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', wp_remote_retrieve_body( $response ), $entries ) ) {
		return $empty;
	}

	foreach ( $entries[1] as $entry ) {

		// Match the tag rather than taking the newest entry: the feed also carries pre-releases,
		// which the latest-release redirect deliberately skips.
		if ( false === strpos( $entry, '/releases/tag/' . $tag ) ) {
			continue;
		}

		$notes = '';

		if ( preg_match( '#<content[^>]*>(.*?)</content>#s', $entry, $content ) ) {
			$notes = release_notes_to_text( $content[1] );
		}

		$published = '';

		if ( preg_match( '#<updated>(.*?)</updated>#s', $entry, $updated ) ) {
			$published = trim( $updated[1] );
		}

		return [
			'body'      => $notes,
			'published' => $published,
		];
	}

	return $empty;
}

/**
 * Turns the rendered notes in the feed back into the plain text the API would have returned.
 *
 * The API hands back the release body as it was written, in Markdown, and the details popup prints
 * that as text. The feed carries the rendered HTML instead, so headings, bold runs and list items
 * are put back into their Markdown spelling to keep the popup reading the same either way.
 *
 * @param string $html Rendered notes.
 * @return string
 */
function release_notes_to_text( $html ) {
	$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

	$text = preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', "\n**$1**\n", $text );
	$text = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $text );
	$text = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $text );
	$text = preg_replace( '#<li[^>]*>#i', "\n- ", $text );
	$text = preg_replace( '#</(p|div|ul|ol|li|pre|blockquote)>#i', "\n", $text );
	$text = preg_replace( '#<br\s*/?>#i', "\n", $text );

	$text = wp_strip_all_tags( (string) $text );

	// Collapse the blank lines the substitutions leave behind.
	$text = preg_replace( '#\n{3,}#', "\n\n", (string) $text );

	return trim( (string) $text );
}

/**
 * Reads the latest release from the GitHub API.
 *
 * Kept as a fallback only. See `fetch_release_data()` for why it is not the first choice.
 *
 * @return array<string, mixed>|null
 */
function fetch_release_from_api() {

	// GitHub has already said the allowance is spent, so sit the window out rather than spending it
	// on requests that can only be refused.
	if ( get_site_transient( UPDATE_RATE_LIMIT_KEY ) ) {
		set_site_transient( UPDATE_REASON_KEY, 'rate_limited', HOUR_IN_SECONDS );

		return null;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,
			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

			// Our own User-Agent, because WordPress's default is "WordPress/{version}; {site url}"
			// (wp-includes/class-wp-http.php:211) and that puts the site's address and its exact
			// WordPress version into every release check. GitHub only requires that the header
			// identifies something, so this satisfies it while telling them nothing about the site.
			'user-agent' => 'email-studio-for-hivepress/' . HP_EMAIL_STUDIO_VERSION,
		]
	);

	if ( is_wp_error( $response ) ) {
		set_site_transient( UPDATE_REASON_KEY, 'unreachable', HOUR_IN_SECONDS );

		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		$reason = 404 === $code ? 'no_release' : 'unreachable';

		// A 403 or 429 with nothing left on the counter means this server's hourly allowance is
		// spent. Nothing is wrong with the site, the plugin or the repository, so it must not be
		// reported as though something were.
		if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			$reason = 'rate_limited';
			$reset  = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			$wait   = $reset > time() ? min( $reset - time(), HOUR_IN_SECONDS ) : 5 * MINUTE_IN_SECONDS;

			set_site_transient( UPDATE_RATE_LIMIT_KEY, $reset ? $reset : time() + $wait, $wait );
		}

		set_site_transient( UPDATE_REASON_KEY, $reason, HOUR_IN_SECONDS );

		return null;
	}

	delete_site_transient( UPDATE_RATE_LIMIT_KEY );
	delete_site_transient( UPDATE_REASON_KEY );

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Makes a request to github.com.
 *
 * The User-Agent is set for the same reason as in the API branch: WordPress's default would put the
 * site's address and its exact WordPress version into every check.
 *
 * @param string               $url Request URL.
 * @param array<string, mixed> $args Extra request arguments.
 * @return array<string, mixed>|null
 */
function request( $url, $args = [] ) {
	$response = wp_remote_get(
		$url,
		array_merge(
			[
				'timeout'    => 10,
				'headers'    => [ 'Accept' => 'text/html, application/xml;q=0.9, */*;q=0.8' ],
				'user-agent' => 'email-studio-for-hivepress/' . HP_EMAIL_STUDIO_VERSION,
			],
			$args
		)
	);

	return is_wp_error( $response ) ? null : $response;
}

/**
 * Provides the update details to the WordPress update system.
 *
 * WordPress matches the plugin to this filter via the Update URI header hostname and compares the
 * versions itself, filing the result under either the available updates or the up-to-date list.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function check_for_update( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( HP_EMAIL_STUDIO_FILE ) !== $plugin_file ) {
		return $update;
	}

	$release = get_latest_release();

	$details = [
		'id'     => 'https://github.com/' . UPDATE_REPO,
		'slug'   => UPDATE_SLUG,
		'plugin' => $plugin_file,
	];

	/*
	 * Answer even when there is nothing to update to. WordPress skips this plugin outright on a falsy
	 * return (wp-includes/update.php:557), and only files an answer under `no_update` when it gets one
	 * (:589-595) -- and that entry is what carries the `slug` the plugins list needs before it will
	 * print "View details" (wp-admin/includes/class-wp-plugins-list-table.php:1204, verified).
	 * Returning false left the row with no slug, so View details, the details popup and the donate link
	 * inside it were all unreachable from the Plugins screen whenever this plugin was up to date, which
	 * is almost always, or whenever the release check failed.
	 */

	if ( ! $release ) {
		$details['version'] = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0';

		return $details;
	}

	return array_merge(
		$details,
		[
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		]
	);
}

add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\check_for_update', 10, 3 );

/**
 * Provides the plugin details for the update information popup.
 *
 * Without this the "View version x.x.x details" link on the Plugins screen would open an empty
 * modal, since the plugin is not on wp.org.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args API arguments.
 * @return object|array|false
 */
function get_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	$plugin_data = get_file_data(
		HP_EMAIL_STUDIO_FILE,
		[
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		]
	);

	return (object) [
		'name'          => $plugin_data['Name'],
		'slug'          => UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
		'homepage'      => 'https://github.com/' . UPDATE_REPO,

		// The "View details" popup is the second of the exactly two Donate placements the house
		// spec allows (releasing.md); WordPress renders this as its own "Donate to this plugin"
		// link, so the wording is core's, not ours.
		'donate_link'   => 'https://ko-fi.com/chrisbathivepresscommunity',
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],
		'sections'      => [
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'email-studio-for-hivepress' ) . '</p>',
		],
	];
}

add_filter( 'plugins_api', __NAMESPACE__ . '\\get_plugin_information', 10, 3 );

/**
 * Adds the manual update check link to the plugin row.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function add_update_check_link( $links ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hp_email_studio_check_updates=1' ), 'hp_email_studio_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'email-studio-for-hivepress' ) . '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( HP_EMAIL_STUDIO_FILE ), __NAMESPACE__ . '\\add_update_check_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( HP_EMAIL_STUDIO_FILE ), __NAMESPACE__ . '\\add_update_check_link' );

/**
 * Handles the manual update check.
 *
 * Refreshes the cached release, re-runs the update check and redirects back to the Plugins screen
 * with the result.
 *
 * @return void
 */
function handle_update_check() {
	if ( ! isset( $_GET['hp_email_studio_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	check_admin_referer( 'hp_email_studio_check_updates' );

	$release = get_latest_release( true );

	wp_clean_plugins_cache();
	wp_update_plugins();

	// Read why the lookup ended as it did rather than inferring it from the result. Since a failed
	// check now keeps the last good answer, the presence of a release no longer proves the check
	// itself succeeded, and reporting a stale answer as a fresh one would be a lie.
	$reason = get_site_transient( UPDATE_REASON_KEY );

	if ( 'no_release' === $reason ) {
		$status = 'empty';
	} elseif ( 'rate_limited' === $reason ) {
		$status = 'limited';
	} elseif ( 'unreachable' === $reason ) {
		$status = 'error';
	} elseif ( $release && version_compare( $release['version'], get_version(), '>' ) ) {
		$status = 'available';
	} else {
		$status = 'none';
	}

	wp_safe_redirect( add_query_arg( 'hp_email_studio_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}

add_action( 'admin_init', __NAMESPACE__ . '\\handle_update_check' );

/**
 * Shows the manual update check result.
 *
 * @return void
 */
function show_update_check_notice() {

	// This only decides which of three fixed sentences to print after the nonce-verified check
	// above redirected here. Nothing is processed and nothing changes state.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hp_email_studio_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = sanitize_key( wp_unslash( $_GET['hp_email_studio_checked'] ) );

	if ( 'available' === $status ) {
		$release = get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Email Studio for HivePress (%s) is available.', 'email-studio-for-hivepress' ), $release ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Email Studio for HivePress is up to date.', 'email-studio-for-hivepress' );
		$class   = 'notice-success';
	} elseif ( 'empty' === $status ) {
		$message = __( 'No releases have been published for Email Studio for HivePress yet, so there is nothing to update to. This is normal for a brand new copy and does not mean anything is wrong.', 'email-studio-for-hivepress' );
		$class   = 'notice-info';
	} elseif ( 'limited' === $status ) {
		$message = __( 'GitHub limits how many update checks one server may make each hour, and this server has reached that limit. Nothing is wrong with the plugin or your site, and checking will work again within the hour.', 'email-studio-for-hivepress' );
		$class   = 'notice-warning';
	} elseif ( 'error' === $status ) {
		$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'email-studio-for-hivepress' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

add_action( 'admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );

/**
 * Keeps updates installing into the current plugin directory.
 *
 * The extracted release folder is renamed to match the directory the plugin is installed in, so an
 * update can never end up in a differently named folder even if the release zip is packaged
 * unexpectedly.
 *
 * @param string               $source Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $hook_extra Extra hook arguments.
 * @return string|\WP_Error
 */
function fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	global $wp_filesystem;

	if ( plugin_basename( HP_EMAIL_STUDIO_FILE ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( HP_EMAIL_STUDIO_FILE ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new \WP_Error( 'hp_email_studio_rename_failed', __( 'Could not rename the update directory.', 'email-studio-for-hivepress' ) );
	}

	return $target;
}

add_filter( 'upgrader_source_selection', __NAMESPACE__ . '\\fix_update_directory', 10, 4 );

/**
 * Puts the cached release into WordPress's update list whenever the list lacks it.
 *
 * Core builds that list in wp_update_plugins(), which stamps last_checked BEFORE it calls
 * api.wordpress.org and returns early, without asking any Update URI plugin, when that call fails
 * or times out (wp-includes/update.php, the is_wp_error check after wp_remote_post). The stamp
 * then keeps the empty list for up to twelve hours. That is how the second of two updates
 * failed with "up to date" straight after the first succeeded: the first update wiped the list,
 * the rebuild on the next click lost the wordpress.org race, and only Check for updates, which
 * wipes the stamp, put the entry back. Reading the answer into the list here means the release
 * this plugin already knows about is offered whatever wordpress.org did.
 *
 * The same read drops an entry that has become stale, so a list built before an update does not
 * keep offering the version that is now installed.
 *
 * @param object|false $transient The update_plugins transient.
 * @return object|false
 */
function inject_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$basename = plugin_basename( HP_EMAIL_STUDIO_FILE );
	$release  = get_site_transient( UPDATE_CACHE_KEY );
	$version  = get_version();

	if ( ! $basename || ! is_array( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
		return $transient;
	}

	if ( version_compare( $release['version'], $version, '>' ) ) {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		if ( ! isset( $transient->response[ $basename ] ) ) {
			$transient->response[ $basename ] = (object) [
				'id'          => 'https://github.com/' . UPDATE_REPO,
				'slug'        => UPDATE_SLUG,
				'plugin'      => $basename,
				'new_version' => $release['version'],
				'url'         => isset( $release['url'] ) ? $release['url'] : '',
				'package'     => $release['package'],
			];
		}

		if ( isset( $transient->no_update[ $basename ] ) ) {
			unset( $transient->no_update[ $basename ] );
		}
	} elseif ( isset( $transient->response[ $basename ] ) ) {
		$offered = $transient->response[ $basename ];
		$offered = is_object( $offered ) && isset( $offered->new_version ) ? $offered->new_version : '';

		if ( ! $offered || version_compare( $offered, $version, '<=' ) ) {
			unset( $transient->response[ $basename ] );
		}
	}

	return $transient;
}

/**
 * Adds the bulk action to the Plugins screen. Registered by one copy of this updater only.
 *
 * @param array<string, string> $actions Bulk actions.
 * @return array<string, string>
 */
function add_bulk_check( $actions ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$actions['hpx_check_updates'] = __( 'Check for updates', 'email-studio-for-hivepress' );
	}

	return $actions;
}

/**
 * Answers the bulk action for this plugin: a fresh release lookup when it was selected.
 *
 * @param string   $redirect Redirect URL.
 * @param string   $action Bulk action name.
 * @param string[] $plugin_files Selected plugin basenames.
 * @return string
 */
function handle_bulk_check( $redirect, $action, $plugin_files ) {
	if ( 'hpx_check_updates' === $action && current_user_can( 'update_plugins' ) && in_array( plugin_basename( HP_EMAIL_STUDIO_FILE ), (array) $plugin_files, true ) ) {
		get_latest_release( true );
	}

	return $redirect;
}

/**
 * Rebuilds the update list once every copy has answered, and names the result in the redirect.
 *
 * Runs after every handle_bulk_check() (priority 20 against their 10), from the one copy that
 * registered the action.
 *
 * @param string   $redirect Redirect URL.
 * @param string   $action Bulk action name.
 * @param string[] $plugin_files Selected plugin basenames.
 * @return string
 */
function finish_bulk_check( $redirect, $action, $plugin_files ) {
	if ( 'hpx_check_updates' !== $action || ! current_user_can( 'update_plugins' ) ) {
		return $redirect;
	}

	wp_clean_plugins_cache();
	wp_update_plugins();

	$current   = get_site_transient( 'update_plugins' );
	$available = 0;

	foreach ( (array) $plugin_files as $file ) {
		if ( is_object( $current ) && isset( $current->response[ $file ] ) ) {
			++$available;
		}
	}

	return add_query_arg(
		[
			'hpx_checked'   => count( (array) $plugin_files ),
			'hpx_available' => $available,
		],
		$redirect
	);
}

/**
 * Shows the bulk check result.
 *
 * @return void
 */
function show_bulk_check_notice() {
	// Reads two counts the bulk handler put in its own redirect; the values only pick a sentence.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hpx_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$checked   = absint( wp_unslash( $_GET['hpx_checked'] ) );
	$available = isset( $_GET['hpx_available'] ) ? absint( wp_unslash( $_GET['hpx_available'] ) ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( $available ) {
		/* translators: 1: number of plugins checked, 2: number with an update available. */
		$message = sprintf( _n( 'Checked %1$s plugin for updates: %2$s can be updated.', 'Checked %1$s plugins for updates: %2$s can be updated.', $checked, 'email-studio-for-hivepress' ), number_format_i18n( $checked ), number_format_i18n( $available ) );
	} else {
		/* translators: %s: number of plugins checked. */
		$message = sprintf( _n( 'Checked %s plugin for updates: it is up to date.', 'Checked %s plugins for updates: all are up to date.', $checked, 'email-studio-for-hivepress' ), number_format_i18n( $checked ) );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Keeps an updating row full width on phones.
 *
 * Below 783px core lays every list-table row out as a wrapping flex row, and the single cell of
 * a plugin's update row then shrinks to the width of its "Updating..." text. Printed once, by the
 * copy of this updater that registered the bulk action.
 *
 * @return void
 */
function print_plugins_screen_styles() {
	echo '<style id="hpx-plugins-screen">@media screen and (max-width: 782px) { .wp-list-table.plugins .plugin-update-tr .plugin-update { flex: 1 1 100%; width: 100%; box-sizing: border-box; } }</style>';
}

add_filter( 'site_transient_update_plugins', __NAMESPACE__ . '\\inject_update' );
add_filter( 'handle_bulk_actions-plugins', __NAMESPACE__ . '\\handle_bulk_check', 10, 3 );

// The Plugins screen bulk action, its notice and the row style: one copy of this updater
// registers them (whichever loads first); every copy answers the action for its own plugin.
if ( empty( $GLOBALS['hpx_update_check_bulk'] ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared handshake between every copy of this updater; a plugin-specific prefix would defeat it.
	$GLOBALS['hpx_update_check_bulk'] = 'email-studio-for-hivepress'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared handshake between every copy of this updater; a plugin-specific prefix would defeat it.

	add_filter( 'bulk_actions-plugins', __NAMESPACE__ . '\\add_bulk_check' );
	add_filter( 'handle_bulk_actions-plugins', __NAMESPACE__ . '\\finish_bulk_check', 20, 3 );
	add_action( 'admin_notices', __NAMESPACE__ . '\\show_bulk_check_notice' );
	add_action( 'network_admin_notices', __NAMESPACE__ . '\\show_bulk_check_notice' );
	add_action( 'admin_print_styles-plugins.php', __NAMESPACE__ . '\\print_plugins_screen_styles' );
}
