<?php
/**
 * Delivery component.
 *
 * Owns two things an email manager needs and HivePress does not provide: switching an individual
 * email off, and a record of what was actually sent.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Controls and records outgoing HivePress emails.
 */
final class Hpes_Delivery extends Component {

	/**
	 * Most entries the log will ever hold.
	 *
	 * The log lives in a single option, so its size is its cost on every read. 500 entries at
	 * roughly 200 bytes is about 100KB, which is a sensible ceiling for something stored this way;
	 * the option is written with autoload off so an ordinary page load never pays for it.
	 */
	const LOG_LIMIT_MAX = 500;

	/**
	 * Default number of entries kept.
	 */
	const LOG_LIMIT_DEFAULT = 100;

	/**
	 * Whether a preview is being rendered.
	 *
	 * @var bool
	 */
	protected $previewing = false;

	/**
	 * Whether a test send is in progress.
	 *
	 * @var bool
	 */
	protected $testing = false;

	/**
	 * Emails already being watched for sends, keyed by name.
	 *
	 * @var array
	 */
	protected $watched = [];

	/**
	 * The log entry written for the send currently in progress.
	 *
	 * @var array|null
	 */
	protected $pending = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		/*
		 * The catch-all for every email is the constructor filter on the `email` ancestor: the
		 * property filter loops a class's ancestors and applies one filter per ancestor
		 * (`emails/class-email.php:108-119`), so this fires once for every email of every
		 * extension. There is no catch-all *send* action - `.../send` only ever fires under a
		 * concrete email name (`:183`) - which is why the send listener is attached from here, on
		 * first sight of each email, rather than from a list of names built up front. Attaching it
		 * during construction is always in time, because `send()` cannot run before the object it
		 * belongs to exists.
		 *
		 * Priority 100 so core's own customisation lookup, on the same filter at priority 10
		 * (`components/class-email.php:28`), has already put the owner's body in place before this
		 * decides whether to empty it.
		 */
		add_filter( 'hivepress/v1/emails/email', [ $this, 'watch_email' ], 100, 2 );

		// Failures arrive as a WP_Error from inside wp_mail, so this fires between the send action
		// and Email::send() returning.
		add_action( 'wp_mail_failed', [ $this, 'record_failure' ] );

		// Keeps test sends out of the notifications feed. See skip_test_notification().
		add_filter( 'hpnf_notification_process_email', [ $this, 'skip_test_notification' ] );

		parent::__construct( $args );
	}

	/**
	 * Gets the names of emails the owner has switched off.
	 *
	 * @return array
	 */
	public function get_disabled() {
		$disabled = get_option( 'hp_email_studio_disabled' );

		if ( ! is_array( $disabled ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( 'strval', $disabled ),
				function( $name ) {
					return '' !== $name;
				}
			)
		);
	}

	/**
	 * Checks whether an email is switched off.
	 *
	 * @param string $name Email name.
	 * @return bool
	 */
	public function is_disabled( $name ) {
		return in_array( $name, $this->get_disabled(), true );
	}

	/**
	 * Switches an email on or off.
	 *
	 * @param string $name Email name.
	 * @param bool   $disabled Switch it off?
	 */
	public function set_disabled( $name, $disabled ) {
		$names = $this->get_disabled();

		if ( $disabled ) {
			$names[] = $name;
		} else {
			$names = array_diff( $names, [ $name ] );
		}

		update_option( 'hp_email_studio_disabled', array_values( array_unique( $names ) ), false );
	}

	/**
	 * Sets whether a preview is being rendered.
	 *
	 * A switched-off email still has to be previewable, or an owner cannot see what they have
	 * turned off before turning it back on.
	 *
	 * @param bool $previewing Rendering a preview?
	 */
	public function set_previewing( $previewing ) {
		$this->previewing = (bool) $previewing;
	}

	/**
	 * Sets whether a test send is in progress.
	 *
	 * A test bypasses the switched-off check on purpose: an owner asking to send this email, right
	 * now, to their own address has already answered the question the switch answers. It is still
	 * recorded, marked as a test, so the log never implies a member received it.
	 *
	 * @param bool $testing Sending a test?
	 */
	public function set_testing( $testing ) {
		$this->testing = (bool) $testing;
	}

	/**
	 * Whether a test send is in progress.
	 *
	 * @return bool
	 */
	public function is_testing() {
		return (bool) $this->testing;
	}

	/**
	 * Keeps a test send out of a member's notifications feed.
	 *
	 * Previews and test sends deliberately go through HivePress's real send path, so that what an
	 * owner checks is exactly what a member would receive. The cost is that anything listening for
	 * a send treats a test as a real event: Notifications for HivePress hooks
	 * `hivepress/v1/emails/{type}/send` for every enabled type, so Chris's own test sends turned up
	 * in his notifications feed on 2026-09-02.
	 *
	 * Answered from here rather than from that plugin, because this is the plugin doing the unusual
	 * thing and so the one that should declare it. Costs nothing when Notifications is absent: the
	 * filter simply never runs.
	 *
	 * @param bool $process Whether to turn this email into a notification.
	 * @return bool
	 */
	public function skip_test_notification( $process ) {
		return $this->testing ? false : $process;
	}

	/**
	 * Watches an email being built, and empties it if it is switched off.
	 *
	 * Emptying the body is what stops the send: `Email::send()` returns false without calling
	 * `wp_mail()` when the body is empty (`emails/class-email.php:185-188`). It is HivePress's own
	 * mechanism rather than a workaround - an owner who clears the body in the email editor stops
	 * that email the same way.
	 *
	 * @param array  $args Email arguments.
	 * @param object $email Email object.
	 * @return array
	 */
	public function watch_email( $args, $email ) {
		$name = $email::get_meta( 'name' );

		if ( ! $name ) {
			return $args;
		}

		if ( ! isset( $this->watched[ $name ] ) ) {
			$this->watched[ $name ] = true;

			add_action( 'hivepress/v1/emails/' . $name . '/send', [ $this, 'record_send' ] );
		}

		if ( ! $this->previewing && ! $this->testing && $this->is_disabled( $name ) ) {
			$args['body'] = '';
		}

		return $args;
	}

	/**
	 * Records an email on its way out.
	 *
	 * The send action fires before `Email::send()` checks the body (`emails/class-email.php:176-188`),
	 * so this sees both the emails that go and the ones stopped for being switched off, and can say
	 * which is which. That distinction is the whole value of the log: "it never arrived" and "we
	 * stopped it on purpose" look identical from the outside.
	 *
	 * @param object $email Email object.
	 */
	public function record_send( $email ) {
		if ( $this->previewing || ! $this->is_log_enabled() ) {
			return;
		}

		$name = $email::get_meta( 'name' );

		$blocked = ! $this->testing && $this->is_disabled( $name );

		// An empty body that we did not empty ourselves is the owner having cleared it in the email
		// editor. HivePress will not send it, and it is their own deliberate setting rather than
		// anything this plugin did, so it is not logged as an event.
		if ( ! $blocked && ! $email->get_body() ) {
			return;
		}

		$entry = [
			'time'    => time(),
			'name'    => (string) $name,
			'label'   => (string) $email::get_meta( 'label' ),
			'to'      => $this->get_recipient( $email ),
			'subject' => (string) $email->get_subject(),
			'status'  => $blocked ? 'blocked' : 'sent',
			'error'   => '',
			'test'    => $this->testing,
		];

		$this->pending = $entry;

		$this->append_log( $entry );
	}

	/**
	 * Marks the send in progress as failed.
	 *
	 * @param object $error Mail error.
	 */
	public function record_failure( $error ) {
		if ( ! $this->pending || ! $this->is_log_enabled() ) {
			return;
		}

		$message = '';

		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
		}

		$log = $this->get_log();

		// The entry just written is the last one. Matching by position rather than by content is
		// safe because wp_mail_failed fires inside the same wp_mail() call that Email::send() makes
		// immediately after the send action, with nothing able to run between them.
		$index = array_key_last( $log );

		if ( ! is_null( $index ) ) {
			$log[ $index ]['status'] = 'failed';
			$log[ $index ]['error']  = $message;

			$this->save_log( $log );
		}

		$this->pending = null;
	}

	/**
	 * Reads an email's recipient.
	 *
	 * `Email::recipient` is protected and has no getter of any kind, so the only way to read it
	 * without re-deriving the address ourselves - and getting it wrong for the emails that go to an
	 * administrator rather than to whoever triggered them - is to bind a closure into the class's
	 * own scope.
	 *
	 * @param object $email Email object.
	 * @return string
	 */
	protected function get_recipient( $email ) {
		try {
			$reader = \Closure::bind(
				function() {
					return $this->recipient;
				},
				$email,
				'\HivePress\Emails\Email'
			);

			$recipient = $reader();
		} catch ( \Throwable $exception ) {
			$recipient = '';
		}

		if ( is_array( $recipient ) ) {
			$recipient = implode( ', ', $recipient );
		}

		return (string) $recipient;
	}

	/**
	 * Checks whether the log is switched on.
	 *
	 * @return bool
	 */
	public function is_log_enabled() {
		$stored = get_option( 'hp_email_studio_log', null );

		// Absent means the default has never been written to the database, which HivePress only
		// does on its own activation and updates - so the code default has to agree with the
		// ticked box the settings screen renders.
		if ( is_null( $stored ) ) {
			return true;
		}

		return (bool) $stored;
	}

	/**
	 * Gets how many entries the log keeps.
	 *
	 * @return int
	 */
	public function get_log_limit() {
		$limit = get_option( 'hp_email_studio_log_limit' );

		// A cleared number field stores an empty string, and (int) '' is 0 - which would mean a log
		// that silently keeps nothing at all.
		if ( ! is_numeric( $limit ) || (int) $limit < 1 ) {
			return self::LOG_LIMIT_DEFAULT;
		}

		return min( (int) $limit, self::LOG_LIMIT_MAX );
	}

	/**
	 * Gets the log, oldest entry first.
	 *
	 * @return array
	 */
	public function get_log() {
		$log = get_option( 'hp_email_studio_log_entries' );

		if ( ! is_array( $log ) ) {
			return [];
		}

		return array_values( array_filter( $log, 'is_array' ) );
	}

	/**
	 * Empties the log.
	 */
	public function clear_log() {
		delete_option( 'hp_email_studio_log_entries' );
	}

	/**
	 * Adds an entry to the log.
	 *
	 * @param array $entry Log entry.
	 */
	protected function append_log( $entry ) {
		$log = $this->get_log();

		$log[] = $entry;

		$this->save_log( $log );
	}

	/**
	 * Saves the log, trimmed to the configured limit.
	 *
	 * @param array $log Log entries.
	 */
	protected function save_log( $log ) {
		$limit = $this->get_log_limit();

		if ( count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		// Autoload off: this is read on one admin screen and must never ride along on every request
		// of the site.
		update_option( 'hp_email_studio_log_entries', array_values( $log ), false );
	}
}
