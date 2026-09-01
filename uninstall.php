<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's data by default.** Someone who deletes a plugin by
 * accident, or removes it to install a clean copy, gets their design settings back when they
 * reinstall. Destruction is opt-in, through the "Delete All Data" checkbox in the Removing the
 * Plugin section of the settings page, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own page. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all, whatever the file actually does,
 * so the setting's own description has to tell the owner that the core warning does not apply to
 * them unless they ticked the box.
 *
 * **The emails themselves are never touched, either way.** An owner's customised wording is stored
 * by HivePress as `hp_email` posts, and HivePress reads them with or without this plugin installed.
 * Deleting them here would throw away work this plugin did not create and HivePress still needs.
 * The same goes for the listing attributes behind the token list: they belong to HivePress.
 *
 * @package HivePress\EmailStudio
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$hpes_delete_all = (bool) get_option( 'hp_email_studio_delete_data' );

/*
 * ---------------------------------------------------------------------------------------------
 * Always cleaned, whichever way the setting is set.
 * ---------------------------------------------------------------------------------------------
 */

// The updater's cached release lookup and its two companions. Site transients live under their own
// prefix, so neither the option sweep below nor a plain delete_option() would ever reach them.
delete_site_transient( 'hp_email_studio_github_release' );
delete_site_transient( 'hp_email_studio_github_release_reason' );
delete_site_transient( 'hp_email_studio_github_release_rate_limit' );

/*
 * The updater's background release refresh.
 *
 * It is a queued job whose callback stops existing the moment the plugin does, so it is worse than
 * debris: cron keeps firing a hook nothing answers. Unscheduled from both places it can live,
 * because the refresh is queued through HivePress's scheduler (Action Scheduler) when HivePress is
 * present and through WP-Cron when it is not.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hp_email_studio_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'hp_email_studio_github_release_refresh' );
}

wp_clear_scheduled_hook( 'hp_email_studio_github_release_refresh' );

/*
 * Any broadcast still working its way through its batches.
 *
 * Each batch queues the next one with its own position in the arguments, so there is no single
 * pending action to remove by name - as_unschedule_all_actions() without arguments is what clears
 * the chain whatever position it has reached. Left behind, the queue would keep calling a hook whose
 * callback no longer exists.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hivepress/v1/email_studio/broadcast' );
}

wp_unschedule_hook( 'hivepress/v1/email_studio/broadcast' );

// Any other transient the plugin has ever set. Nothing writes one today, but a transient is stored
// as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the prefix sweep used
// for options further down cannot match them - it anchors on "hp_email_studio" at the start of the
// name. Leaving a timeout row behind with no value row is the classic orphan.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hpes_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'hp_email_studio' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'hp_email_studio' ) . '%'
	)
);

foreach ( (array) $hpes_transients as $hpes_transient_name ) {
	delete_option( $hpes_transient_name );
}

/*
 * The delivery log goes whatever the setting says.
 *
 * It holds the email address of every member the site has written to, which is personal data this
 * plugin collected rather than anything the owner authored. "Retain by default" protects an owner's
 * own work; it is not a reason to leave other people's addresses in the database of a site that has
 * just removed the only screen able to display or clear them.
 */
delete_option( 'hp_email_studio_log_entries' );

/*
 * The record of broadcasts goes for the same reason: each one stores the user IDs it was sent to,
 * which is a list of people this plugin built rather than anything the owner authored.
 */
delete_option( 'hp_email_studio_campaigns' );

/*
 * ---------------------------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * ---------------------------------------------------------------------------------------------
 */

if ( $hpes_delete_all ) {
	/*
	 * Delete the options. The names are matched on the plugin's prefix, which is also what covers
	 * options added in later versions: a new one only needs a mention here if its name ever stops
	 * starting with "hp_email_studio". This runs once, while the plugin is being deleted, so there
	 * is nothing worth caching.
	 *
	 * The "delete all data" option itself is excluded here and removed at the very end. If this run
	 * fails part-way through, the flag is still set, so a second attempt finishes the job. Sweeping
	 * it away first would silently flip the site back to "retain" with half the data gone.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$hpes_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_email_studio' ) . '%',
			'hp_email_studio_delete_data'
		)
	);

	foreach ( (array) $hpes_option_names as $hpes_option_name ) {
		delete_option( $hpes_option_name );
	}

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_email_studio_delete_data' );
}
