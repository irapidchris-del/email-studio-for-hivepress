<?php
/**
 * Compose component.
 *
 * Sends a one-off email, written by the site owner, to a chosen audience, with the plugin's design
 * applied.
 *
 * **Nothing is sent from the request that presses Send.** A broadcast to every member is the exact
 * shape of the incident recorded in `security-standards.md` (5): work whose duration scales with
 * site size, holding a PHP worker while it runs. Pressing Send queues a job and returns; the job
 * sends one batch, then queues the next. On shared hosting that is the difference between a
 * campaign and a site-wide 504.
 *
 * **Each batch carries its own position in the action arguments**, because
 * `Scheduler::add_action()` refuses a hook that already has an action with identical arguments and
 * `as_has_scheduled_action()` counts the RUNNING one - so a job queueing its successor with the
 * same arguments matches itself, mid-run, and the chain stops after one batch with nothing in any
 * log (`hivepress-framework.md`, *A chained background job must vary its arguments*).
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Composes and sends broadcasts.
 */
final class Hpes_Compose extends Component {

	/**
	 * The scheduled hook each batch runs on.
	 */
	const HOOK = 'hivepress/v1/email_studio/broadcast';

	/**
	 * Recipients per batch.
	 *
	 * Small enough that one batch is comfortably inside any host's PHP time limit even when the
	 * mail transport is slow, and large enough that a few thousand members do not need thousands of
	 * scheduled actions.
	 */
	const BATCH_SIZE = 25;

	/**
	 * How many finished campaigns are remembered.
	 */
	const HISTORY_LIMIT = 20;

	/**
	 * Gets how many recipients one batch covers.
	 *
	 * Filterable for two reasons: a host with a strict mail rate or a slow transport may need a
	 * smaller batch than the default, and a chain that only ever runs one batch on a small site is a
	 * chain nobody has tested. Forcing this to 1 or 2 is how the successor-queueing is exercised
	 * without inventing thousands of users (`hivepress-framework.md`, *A chained background job must
	 * vary its arguments*).
	 *
	 * @return int
	 */
	public function get_batch_size() {
		/**
		 * Filters how many recipients one broadcast batch sends to.
		 *
		 * @hook hivepress/v1/email_studio/batch_size
		 * @param {int} $size Recipients per batch.
		 * @return {int} Recipients per batch.
		 */
		$size = (int) apply_filters( 'hivepress/v1/email_studio/batch_size', self::BATCH_SIZE );

		return max( 1, $size );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		add_action( self::HOOK, [ $this, 'run_batch' ], 10, 2 );

		parent::__construct( $args );
	}

	/**
	 * Gets the audiences an owner can send to.
	 *
	 * @return array
	 */
	public function get_audiences() {
		$audiences = [
			'all'       => esc_html__( 'Everyone with an account', 'email-studio-for-hivepress' ),
			'vendors'   => esc_html__( 'Vendors only', 'email-studio-for-hivepress' ),
			'customers' => esc_html__( 'Everyone except vendors', 'email-studio-for-hivepress' ),
			'users'     => esc_html__( 'Specific people', 'email-studio-for-hivepress' ),
		];

		return $audiences;
	}

	/**
	 * Gets the user IDs an audience resolves to.
	 *
	 * Vendors are resolved through HivePress's own vendor model rather than by guessing at a role,
	 * because being a vendor is a `hp_vendor` post owned by the user and not a WordPress role at
	 * all - a site can have vendors whose role is plain Subscriber.
	 *
	 * @param string $audience Audience name.
	 * @param array  $user_ids Chosen user IDs, for the "specific people" audience.
	 * @return array
	 */
	public function get_recipient_ids( $audience, $user_ids = [] ) {
		if ( 'users' === $audience ) {
			return array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
		}

		$vendor_ids = $this->get_vendor_user_ids();

		if ( 'vendors' === $audience ) {
			return $vendor_ids;
		}

		$all = array_map( 'absint', get_users( [ 'fields' => 'ID' ] ) );

		if ( 'customers' === $audience ) {
			return array_values( array_diff( $all, $vendor_ids ) );
		}

		return array_values( $all );
	}

	/**
	 * Gets the user IDs that own a vendor profile.
	 *
	 * @return array
	 */
	protected function get_vendor_user_ids() {
		$ids = [];

		try {
			$vendors = \HivePress\Models\Vendor::query()->filter( [ 'status' => 'publish' ] )->limit( 10000 )->get();

			foreach ( $vendors as $vendor ) {
				$user_id = (int) $vendor->get_user__id();

				if ( $user_id ) {
					$ids[] = $user_id;
				}
			}
		} catch ( \Throwable $exception ) {
			$ids = [];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Counts an audience without loading it twice.
	 *
	 * @param string $audience Audience name.
	 * @param array  $user_ids Chosen user IDs.
	 * @return int
	 */
	public function count_audience( $audience, $user_ids = [] ) {
		return count( $this->get_recipient_ids( $audience, $user_ids ) );
	}

	/**
	 * Queues a broadcast.
	 *
	 * @param array $args Campaign arguments.
	 * @return array|null
	 */
	public function queue( $args ) {
		$recipients = $this->get_recipient_ids( $args['audience'], hp\get_array_value( $args, 'user_ids', [] ) );

		if ( ! $recipients ) {
			return null;
		}

		$campaign = [
			'id'         => uniqid( 'hpes', false ),
			'subject'    => (string) $args['subject'],
			'body'       => (string) $args['body'],
			'audience'   => (string) $args['audience'],
			'recipients' => $recipients,
			'total'      => count( $recipients ),
			'sent'       => 0,
			'failed'     => 0,
			'status'     => 'sending',
			'created'    => time(),
			'author'     => get_current_user_id(),
		];

		$this->save_campaign( $campaign );

		// No time and no interval is an async action that runs as soon as the queue is next
		// processed, which is what "send it now, just not in this request" means
		// (`hivepress-framework.md`, Scheduling).
		hivepress()->scheduler->add_action( self::HOOK, [ $campaign['id'], 0 ] );

		return $campaign;
	}

	/**
	 * Sends one batch and queues the next.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param int    $offset Position in the recipient list.
	 */
	public function run_batch( $campaign_id, $offset = 0 ) {
		$campaign = $this->get_campaign( $campaign_id );

		if ( ! $campaign || 'sending' !== $campaign['status'] ) {
			return;
		}

		$offset = absint( $offset );

		$batch = array_slice( $campaign['recipients'], $offset, $this->get_batch_size() );

		if ( ! $batch ) {
			$campaign['status'] = 'sent';

			$this->save_campaign( $campaign );

			return;
		}

		foreach ( $batch as $user_id ) {
			if ( $this->send_to_user( (int) $user_id, $campaign ) ) {
				++$campaign['sent'];
			} else {
				++$campaign['failed'];
			}
		}

		$next = $offset + $this->get_batch_size();

		if ( $next >= $campaign['total'] ) {
			$campaign['status'] = 'sent';
		}

		$this->save_campaign( $campaign );

		if ( 'sending' === $campaign['status'] ) {

			// The offset is in the arguments so this call differs from the one currently running.
			// Without it the dedupe guard matches this very action and the chain stops here.
			hivepress()->scheduler->add_action( self::HOOK, [ $campaign_id, $next ] );
		}
	}

	/**
	 * Sends a broadcast to one person.
	 *
	 * @param int   $user_id User ID.
	 * @param array $campaign Campaign.
	 * @return bool
	 */
	protected function send_to_user( $user_id, $campaign ) {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$email = hp\create_class_instance(
			'\HivePress\Emails\Hpes_Broadcast',
			[
				[
					'recipient' => $user->user_email,
					'subject'   => $campaign['subject'],
					'body'      => $campaign['body'],
					'tokens'    => $this->get_tokens( $user ),
				],
			]
		);

		if ( ! $email ) {
			return false;
		}

		return (bool) $email->send();
	}

	/**
	 * Gets the tokens a broadcast can use.
	 *
	 * @param object $user User object.
	 * @return array
	 */
	public function get_tokens( $user ) {
		$tokens = [
			'user_name' => $user ? $user->display_name : '',
			'site_name' => get_bloginfo( 'name' ),
			'year'      => wp_date( 'Y' ),
		];

		// The user model as well, so every field an owner can see in the token list of any other
		// email - %user.first_name% and the rest - works here too.
		if ( $user ) {
			try {
				$model = \HivePress\Models\User::query()->get_by_id( $user->ID );

				if ( $model ) {
					$tokens['user'] = $model;
				}
			} catch ( \Throwable $exception ) {
				$tokens['user'] = null;

				unset( $tokens['user'] );
			}
		}

		return $tokens;
	}

	/**
	 * Renders a preview of a composed message.
	 *
	 * @param string $subject Subject.
	 * @param string $body Body.
	 * @return string
	 */
	public function render_preview( $subject, $body ) {
		$email = hp\create_class_instance(
			'\HivePress\Emails\Hpes_Broadcast',
			[
				[
					'subject' => $subject,
					'body'    => $body,
					'tokens'  => $this->get_tokens( wp_get_current_user() ),
				],
			]
		);

		if ( ! $email ) {
			return '';
		}

		return ( new \HivePress\Blocks\Template(
			[
				'template' => 'email',

				'context'  => [
					'email' => $email,
				],
			]
		) )->render();
	}

	/**
	 * Gets every remembered campaign, newest first.
	 *
	 * @return array
	 */
	public function get_campaigns() {
		$campaigns = get_option( 'hp_email_studio_campaigns' );

		if ( ! is_array( $campaigns ) ) {
			return [];
		}

		$campaigns = array_values( array_filter( $campaigns, 'is_array' ) );

		usort(
			$campaigns,
			function ( $a, $b ) {
				return (int) hp\get_array_value( $b, 'created' ) <=> (int) hp\get_array_value( $a, 'created' );
			}
		);

		return $campaigns;
	}

	/**
	 * Gets one campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|null
	 */
	public function get_campaign( $campaign_id ) {
		foreach ( $this->get_campaigns() as $campaign ) {
			if ( hp\get_array_value( $campaign, 'id' ) === $campaign_id ) {
				return $campaign;
			}
		}

		return null;
	}

	/**
	 * Saves a campaign.
	 *
	 * @param array $campaign Campaign.
	 */
	protected function save_campaign( $campaign ) {
		$campaigns = [];

		foreach ( $this->get_campaigns() as $existing ) {
			if ( hp\get_array_value( $existing, 'id' ) !== $campaign['id'] ) {
				$campaigns[] = $existing;
			}
		}

		array_unshift( $campaigns, $campaign );

		$campaigns = array_slice( $campaigns, 0, self::HISTORY_LIMIT );

		// Autoload off: this can hold thousands of recipient IDs and is read on one admin screen.
		update_option( 'hp_email_studio_campaigns', $campaigns, false );
	}
}
