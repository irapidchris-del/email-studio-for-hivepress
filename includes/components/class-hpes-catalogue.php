<?php
/**
 * Catalogue component.
 *
 * Discovers every email the site can send and renders previews of them.
 *
 * Nothing here keeps a hard-coded list of email types. HivePress finds email classes by globbing
 * `includes/emails/*.php` across every registered extension path and instantiating
 * `\HivePress\Emails\{Filename}` (`class-core.php:443-455`, verified against the live 1.7.31
 * install), so `get_classes( 'emails' )` already answers "what can this site send?" - including
 * emails added by an extension installed tomorrow, and by extensions we have never heard of. A
 * hard-coded list would go stale the first time someone activates Bookings.
 *
 * The gate for "can the site owner edit this one?" is a truthy `label` class meta, which is the
 * same test core's own Emails screen uses (`components/class-email.php:60`, `class-form.php:473`).
 * Emails without one are internal and are deliberately not listed.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Blocks;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Discovers and previews HivePress emails.
 */
final class Hpes_Catalogue extends Component {

	/**
	 * Emails that must keep working for the site to function.
	 *
	 * Switching one of these off does not merely stop a courtesy message: it removes the only way a
	 * member can finish an action they have already started. Without the password reset email there
	 * is no way back into a locked account, and without the verification email a new registration
	 * can never be completed. The Studio still allows it, because a site owner may have a genuine
	 * reason and we do not gate features behind our own opinion, but it asks twice and says what
	 * breaks (`Hpes_Studio::render_page()`).
	 *
	 * Names are email class names, which is what `get_classes( 'emails' )` keys on.
	 */
	const CRITICAL_EMAILS = [
		'user_password_request',
		'user_email_verify',
	];

	/**
	 * Cached email list, keyed by name.
	 *
	 * @var array|null
	 */
	protected $emails = null;

	/**
	 * Cached customisation posts, keyed by email name.
	 *
	 * @var array|null
	 */
	protected $custom_posts = null;

	/**
	 * Cached extension display names, keyed by directory path.
	 *
	 * @var array
	 */
	protected $sources = [];

	/**
	 * Gets every email the site can send, keyed by name.
	 *
	 * @return array
	 */
	public function get_emails() {
		if ( ! is_null( $this->emails ) ) {
			return $this->emails;
		}

		/**
		 * Every row has the same shape, but it is built from class meta that static analysis can
		 * only see as mixed, so the element type is stated rather than inferred.
		 *
		 * @var array<string, array<string, mixed>> $emails
		 */
		$emails = [];

		$custom_posts = $this->get_custom_posts();
		$disabled     = hivepress()->hpes_delivery->get_disabled();

		foreach ( hivepress()->get_classes( 'emails' ) as $name => $email_class ) {

			// Only emails the site owner is meant to edit carry a label, and that is the same
			// test core's own Emails screen applies before offering one for editing.
			$label = $email_class::get_meta( 'label' );

			if ( ! $label ) {
				continue;
			}

			$custom_post = hp\get_array_value( $custom_posts, $name );

			/*
			 * HivePress's own advice for stopping an email is to clear its wording, because
			 * `Email::send()` returns before `wp_mail()` when the body is empty
			 * (`emails/class-email.php:185-188`). An owner who has done that has switched the email
			 * off, and a list that showed it as active would be describing a message the site will
			 * never send. It is a different state from this plugin's own switch, so it is recorded
			 * as one: the Studio cannot turn it back on, because only the missing wording can.
			 */
			$empty_body = $custom_post && '' === trim( wp_strip_all_tags( (string) $custom_post->post_content ) );

			$switched_off = in_array( $name, $disabled, true );

			$emails[ $name ] = [
				'name'        => $name,
				'label'       => $label,
				'description' => $this->get_description( $name, $email_class ),
				'recipient'   => (string) $email_class::get_meta( 'recipient' ),
				'tokens'      => (array) $email_class::get_meta( 'tokens' ),
				'source'      => $this->get_source( $email_class ),
				'subject'     => $this->get_subject( $name, $email_class, $custom_post ),
				'customised'  => (bool) $custom_post,
				'edit_url'    => $custom_post ? (string) get_edit_post_link( $custom_post->ID, 'raw' ) : '',
				'disabled'    => $switched_off || $empty_body,
				'disabled_by' => $switched_off ? 'studio' : ( $empty_body ? 'empty' : '' ),
				'critical'    => in_array( $name, self::CRITICAL_EMAILS, true ),
				'woocommerce' => false,
			];
		}

		// WooCommerce's own emails, where WooCommerce is running. They are listed for the same
		// reason HivePress's are - so one screen answers "what does this site send?" - but their
		// appearance stays WooCommerce's business (see Hpes_Woo).
		foreach ( hivepress()->hpes_woo->get_emails() as $name => $email ) {
			$emails[ $name ] = $email;
		}

		/*
		 * Group by source, then alphabetically within it, so an owner scanning for "the Bookings
		 * ones" finds them together. Core's own screen sorts on the label alone, which interleaves
		 * every extension.
		 *
		 * Sorting an index of plain strings rather than the rows themselves: the null byte cannot
		 * occur in either value, so it separates the two parts of the key without any comparison
		 * function, and natural order puts "Extension 2" before "Extension 10" the way a reader
		 * expects.
		 */
		$order = [];

		foreach ( $emails as $name => $email ) {
			$order[ $name ] = $email['source'] . "\0" . $email['label'];
		}

		natcasesort( $order );

		$this->emails = [];

		foreach ( array_keys( $order ) as $name ) {
			$this->emails[ $name ] = $emails[ $name ];
		}

		return $this->emails;
	}

	/**
	 * Gets a single email's details.
	 *
	 * @param string $name Email name.
	 * @return array|null
	 */
	public function get_email( $name ) {
		return hp\get_array_value( $this->get_emails(), $name );
	}

	/**
	 * Gets the distinct sources emails come from.
	 *
	 * @return array
	 */
	public function get_sources() {
		$sources = [];

		foreach ( $this->get_emails() as $email ) {
			$sources[ $email['source'] ] = $email['source'];
		}

		asort( $sources );

		return $sources;
	}

	/**
	 * Gets the counts shown in the Studio header.
	 *
	 * @return array
	 */
	public function get_summary() {
		$summary = [
			'total'      => 0,
			'customised' => 0,
			'disabled'   => 0,
		];

		foreach ( $this->get_emails() as $email ) {
			++$summary['total'];

			if ( $email['customised'] ) {
				++$summary['customised'];
			}

			if ( $email['disabled'] ) {
				++$summary['disabled'];
			}
		}

		return $summary;
	}

	/**
	 * Gets the customisation posts, keyed by email name.
	 *
	 * HivePress stores an owner's edits as an `hp_email` post whose `post_name` is the email name,
	 * and reads it back one email at a time (`components/class-email.php:63-73`). The Studio lists
	 * every email on one screen, so asking per email would mean one query per row; this fetches the
	 * lot in a single query instead. `posts_per_page => -1` is safe here because the number of rows
	 * is bounded by the number of email types a site has, not by anything a user can grow.
	 *
	 * @return array
	 */
	protected function get_custom_posts() {
		if ( ! is_null( $this->custom_posts ) ) {
			return $this->custom_posts;
		}

		$this->custom_posts = [];

		$posts = get_posts(
			[
				'post_type'        => 'hp_email',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => ! hivepress()->translator->is_multilingual(),
			]
		);

		foreach ( $posts as $post ) {
			if ( $post->post_name ) {
				$this->custom_posts[ $post->post_name ] = $post;
			}
		}

		return $this->custom_posts;
	}

	/**
	 * Gets an email's description, filling in the ones that ship without.
	 *
	 * Eight of the emails installed on a full HivePress site declare no `description` meta at all -
	 * every one of them from HivePress Bookings (measured across all installed extensions on
	 * 2026-09-01; the other 44 have one). On the list that left a row with a name and nothing saying
	 * when it fires, which is the one thing an owner deciding whether to edit it needs.
	 *
	 * The wording below is derived from each class's own label, recipient and default body rather
	 * than guessed, and it only ever fills a gap: an email that declares a description keeps it, so
	 * if Bookings adds its own these disappear on their own.
	 *
	 * @param string $name Email name.
	 * @param string $email_class Email class.
	 * @return string
	 */
	protected function get_description( $name, $email_class ) {
		$description = (string) $email_class::get_meta( 'description' );

		if ( $description ) {
			return $description;
		}

		$fallbacks = [
			'booking_request'        => esc_html__( 'This email is sent to vendors when someone requests a booking.', 'email-studio-for-hivepress' ),
			'booking_accept'         => esc_html__( 'This email is sent to users when their booking request is accepted.', 'email-studio-for-hivepress' ),
			'booking_decline'        => esc_html__( 'This email is sent to users when their booking request is declined.', 'email-studio-for-hivepress' ),
			'booking_remind'         => esc_html__( 'This email is sent to users shortly before a booking they have made.', 'email-studio-for-hivepress' ),
			'booking_confirm_user'   => esc_html__( 'This email is sent to users when their booking is confirmed.', 'email-studio-for-hivepress' ),
			'booking_confirm_vendor' => esc_html__( 'This email is sent to vendors when a booking is confirmed.', 'email-studio-for-hivepress' ),
			'booking_cancel_user'    => esc_html__( 'This email is sent to users when their booking is cancelled.', 'email-studio-for-hivepress' ),
			'booking_cancel_vendor'  => esc_html__( 'This email is sent to vendors when a booking is cancelled.', 'email-studio-for-hivepress' ),
		];

		return (string) hp\get_array_value( $fallbacks, $name, '' );
	}

	/**
	 * Gets the subject line an email currently sends with.
	 *
	 * Core only treats a customisation post's title as the subject when it is non-empty
	 * (`components/class-email.php:79-81`), so an owner who cleared the title still gets the
	 * shipped subject. This mirrors that rule rather than assuming the post always wins.
	 *
	 * @param string $name Email name.
	 * @param string $email_class Email class.
	 * @param object $custom_post Customisation post.
	 * @return string
	 */
	protected function get_subject( $name, $email_class, $custom_post ) {
		if ( $custom_post && $custom_post->post_title ) {
			return $custom_post->post_title;
		}

		// Built with "default" so core's own customisation lookup is skipped - the stored post has
		// already been read above, and this avoids one extra query per email on a screen that shows
		// every email at once.
		$email = $this->create_email( $name, [ 'default' => true ] );

		return $email ? (string) $email->get_subject() : '';
	}

	/**
	 * Gets the display name of the extension an email came from.
	 *
	 * Core keys its extension registry on the folder name, lowercased with `hivepress-` stripped and
	 * hyphens turned into underscores, and calls HivePress itself "core"
	 * (`class-core.php:257-296`). Reversing that from the class file's own path is what lets the
	 * Studio group emails by the plugin that provides them without any extension having to
	 * cooperate.
	 *
	 * @param string $email_class Email class.
	 * @return string
	 */
	protected function get_source( $email_class ) {
		try {
			$reflection = new \ReflectionClass( $email_class );

			$filepath = $reflection->getFileName();
		} catch ( \ReflectionException $exception ) {
			$filepath = '';
		}

		if ( ! $filepath ) {
			return esc_html__( 'Unknown', 'email-studio-for-hivepress' );
		}

		// {plugin}/includes/emails/class-name.php, so the plugin folder is three levels up.
		$dirpath = dirname( $filepath, 3 );

		if ( isset( $this->sources[ $dirpath ] ) ) {
			return $this->sources[ $dirpath ];
		}

		$dirname = basename( $dirpath );

		// Core's own key derivation, so the name lands in core's registry where it exists.
		$key = 'hivepress' === $dirname ? 'core' : str_replace( '-', '_', preg_replace( '/^hivepress-/', '', $dirname ) );

		$name = hivepress()->get_name( $key );

		if ( ! $name ) {

			// A renamed folder, or an extension registered under a key of its own choosing, will
			// not answer to the derived key. Its plugin header still names it.
			$name = $this->get_plugin_name( $dirpath, $dirname );
		}

		if ( ! $name ) {
			$name = ucwords( str_replace( [ '-', '_' ], ' ', $dirname ) );
		}

		$this->sources[ $dirpath ] = $name;

		return $name;
	}

	/**
	 * Reads a plugin's name out of its own header.
	 *
	 * A plugin's main file is usually named after its folder, and trying that first keeps this to a
	 * single file read. It is not a rule, though: Vendor Analytics Pro for HivePress lives in
	 * `vendor-analytics-pro-for-hivepress` with its main file called `hivepress-vendor-analytics.php`,
	 * and a folder renamed by a GitHub source zip breaks the assumption for any plugin. Falling back
	 * to whichever top-level file carries a Plugin Name header is what stops the Studio labelling a
	 * real extension with a guess made from its folder name - it read "Vendor Analytics Pro For
	 * Hivepress" until this existed.
	 *
	 * @param string $dirpath Plugin directory path.
	 * @param string $dirname Plugin directory name.
	 * @return string
	 */
	protected function get_plugin_name( $dirpath, $dirname ) {
		/*
		 * `get_file_data()` opens the path without checking it exists, so calling it on a guess
		 * raises "failed to open stream" on every page load. Measured: 1,050 bytes of warnings in
		 * debug.log from a handful of Studio requests, one per request, all naming
		 * vendor-analytics-pro-for-hivepress - the very plugin whose folder and main file disagree
		 * and therefore the reason this fallback exists at all.
		 */
		$filepath = $dirpath . '/' . $dirname . '.php';

		if ( file_exists( $filepath ) ) {
			$filedata = get_file_data( $filepath, [ 'name' => 'Plugin Name' ] );

			if ( $filedata['name'] ) {
				return $filedata['name'];
			}
		}

		foreach ( (array) glob( $dirpath . '/*.php' ) as $filepath ) {
			$filedata = get_file_data( $filepath, [ 'name' => 'Plugin Name' ] );

			if ( $filedata['name'] ) {
				return $filedata['name'];
			}
		}

		return '';
	}

	/**
	 * Creates an email object.
	 *
	 * @param string $name Email name.
	 * @param array  $args Email arguments.
	 * @return object|null
	 */
	public function create_email( $name, $args = [] ) {
		return hp\create_class_instance( '\HivePress\Emails\\' . $name, [ $args ] );
	}

	/**
	 * Renders a full preview of an email.
	 *
	 * The preview is built through the same path a real send uses: the same email class, the same
	 * filters, and the same `Blocks\Template( 'email' )` render that `Email::send()` passes to
	 * `wp_mail()` (`emails/class-email.php:199-211`). That is deliberate. A preview that composes
	 * its own approximation of the message eventually disagrees with what the site actually sends,
	 * and the disagreement is invisible until a member reports it.
	 *
	 * @param string $name Email name.
	 * @param bool   $show_default Preview the shipped default rather than the owner's version.
	 * @param string $layout For a WooCommerce email, the layout to preview; empty for the saved one.
	 * @return string
	 */
	public function render_preview( $name, $show_default = false, $layout = '' ) {

		// A WooCommerce email is rendered by WooCommerce, through its own preview class, and then
		// wrapped or not according to the layout setting (or the panel's compare switch).
		if ( hivepress()->hpes_woo->is_woo_email( $name ) ) {
			return hivepress()->hpes_woo->render_preview( $name, $layout );
		}

		$email_class = hp\get_array_value( hivepress()->get_classes( 'emails' ), $name );

		if ( ! $email_class || ! $email_class::get_meta( 'label' ) ) {
			return '';
		}

		$args = [ 'tokens' => $this->get_sample_tokens( $email_class ) ];

		if ( $show_default ) {
			$args['default'] = true;
		}

		// An email switched off in the Studio has its body emptied on the way out, which is what
		// stops the send. Previewing it must still show what it would look like, so the suppression
		// stands down for the duration of this render.
		hivepress()->hpes_delivery->set_previewing( true );

		try {
			$email = $this->create_email( $name, $args );

			if ( ! $email ) {
				return '';
			}

			$output = ( new Blocks\Template(
				[
					'template' => 'email',

					'context'  => [
						'email' => $email,
					],
				]
			) )->render();
		} finally {
			hivepress()->hpes_delivery->set_previewing( false );
		}

		return $output;
	}

	/**
	 * Gets sample token values for a preview or test send.
	 *
	 * Real objects are used wherever the site has one, because a preview showing this site's own
	 * listing title is worth far more than one showing invented text. Where a model has no rows yet
	 * - a new site with no bookings - an empty model instance stands in, and every token resolves to
	 * its fallback or to nothing, exactly as it would in a real send against missing data.
	 *
	 * @param string $email_class Email class.
	 * @return array
	 */
	public function get_sample_tokens( $email_class ) {
		$tokens = [];

		foreach ( (array) $email_class::get_meta( 'tokens' ) as $token ) {
			$model = $this->get_sample_model( $token );

			$tokens[ $token ] = is_null( $model ) ? $this->get_sample_value( $token ) : $model;
		}

		return $tokens;
	}

	/**
	 * Gets a sample model object for a token, if the token names a model at all.
	 *
	 * @param string $token Token name.
	 * @return object|null
	 */
	protected function get_sample_model( $token ) {
		$email_class = '\HivePress\Models\\' . $token;

		if ( ! class_exists( $email_class ) ) {
			return null;
		}

		try {

			// The signed-in administrator is the most useful stand-in for a user token: their name
			// and address are real, and they are the person looking at the preview.
			if ( is_subclass_of( $email_class, '\HivePress\Models\User' ) || '\HivePress\Models\User' === $email_class ) {
				$user = $email_class::query()->get_by_id( get_current_user_id() );

				if ( $user ) {
					return $user;
				}
			}

			$object = $email_class::query()->limit( 1 )->get_first();

			if ( $object ) {
				return $object;
			}

			// No rows of this type yet. An empty instance still resolves every field token to its
			// fallback text instead of leaving "%booking.number%" sitting in the preview.
			return hp\create_class_instance( $email_class );
		} catch ( \Throwable $exception ) {

			// A model belonging to an extension we cannot query is not worth failing a preview
			// over; the token simply falls through to a sample string.
			return null;
		}
	}

	/**
	 * Gets a sample value for a plain token.
	 *
	 * @param string $token Token name.
	 * @return string
	 */
	protected function get_sample_value( $token ) {
		$user = wp_get_current_user();

		// Named tokens whose sample is worth getting right, because they carry the shape of the
		// value rather than just its presence: an amount must look like money, dates like dates.
		$values = [
			'user_name'       => $user->exists() ? $user->display_name : esc_html__( 'Alex', 'email-studio-for-hivepress' ),
			'vendor_name'     => esc_html__( 'Riverside Studios', 'email-studio-for-hivepress' ),
			'match_name'      => esc_html__( 'Sam', 'email-studio-for-hivepress' ),
			'listing_title'   => $this->get_sample_listing_title(),
			'request_title'   => esc_html__( 'Photographer needed for a weekend event', 'email-studio-for-hivepress' ),
			'booking_dates'   => $this->get_sample_dates(),
			'booking_number'  => '1042',
			'order_number'    => '1042',
			'order_amount'    => $this->get_sample_amount(),
			'payout_amount'   => $this->get_sample_amount(),
			'payout_method'   => esc_html__( 'Bank transfer', 'email-studio-for-hivepress' ),
			'membership_plan' => esc_html__( 'Professional', 'email-studio-for-hivepress' ),
			'decline_reason'  => esc_html__( 'Those dates have just been taken.', 'email-studio-for-hivepress' ),
			'order_note'      => esc_html__( 'Sorry, this item is out of stock.', 'email-studio-for-hivepress' ),
			'message_text'    => esc_html__( 'Hello, is this still available next weekend?', 'email-studio-for-hivepress' ),
			'reply_text'      => esc_html__( 'Thank you for the kind words, see you again soon.', 'email-studio-for-hivepress' ),
			'sender'          => esc_html__( 'Sam', 'email-studio-for-hivepress' ),
			'recipient'       => $user->exists() ? $user->display_name : esc_html__( 'Alex', 'email-studio-for-hivepress' ),
			'period'          => esc_html__( 'last month', 'email-studio-for-hivepress' ),
			'listing_views'   => '248',
			'profile_views'   => '96',
			'messages'        => '12',
			'bookings'        => '4',
			'earnings'        => $this->get_sample_amount(),

			/*
			 * Never a real or real-looking password, and never a working reset link. A preview is
			 * rendered on demand by an administrator and can be left open on a shared screen, so the
			 * sample must be visibly a sample. The genuine values only ever exist inside a real send.
			 */
			'user_password'   => esc_html__( '[your new password]', 'email-studio-for-hivepress' ),
		];

		if ( isset( $values[ $token ] ) ) {
			return $values[ $token ];
		}

		// Anything ending in _url is a link somewhere into this site. Pointing them at the home page
		// keeps them clickable and harmless; a fabricated deep link would 404 when tested.
		if ( '_url' === substr( $token, -4 ) ) {
			return home_url( '/' );
		}

		/*
		 * An unrecognised token from an extension we have never seen. Rendering the token's own name
		 * in brackets is the honest answer: the owner sees exactly which value will appear there
		 * without the preview pretending to know what it holds.
		 */
		/* translators: %s: the name of an email token, with underscores replaced by spaces. */
		return sprintf( esc_html__( '[%s]', 'email-studio-for-hivepress' ), str_replace( '_', ' ', $token ) );
	}

	/**
	 * Gets a sample listing title, preferring one from this site.
	 *
	 * @return string
	 */
	protected function get_sample_listing_title() {
		try {
			$listing = \HivePress\Models\Listing::query()->filter( [ 'status' => 'publish' ] )->limit( 1 )->get_first();

			if ( $listing && $listing->get_title() ) {
				return $listing->get_title();
			}
		} catch ( \Throwable $exception ) {
			$listing = null;
		}

		return esc_html__( 'Sunny studio flat with a river view', 'email-studio-for-hivepress' );
	}

	/**
	 * Gets a sample date range in the site's own date format.
	 *
	 * @return string
	 */
	protected function get_sample_dates() {
		$format = (string) get_option( 'date_format' );

		if ( ! $format ) {
			$format = 'F j, Y';
		}

		// Relative to now, so the sample never reads as a date in the past.
		$start = strtotime( '+7 days' );
		$end   = strtotime( '+10 days' );

		return wp_date( $format, $start ) . ' - ' . wp_date( $format, $end );
	}

	/**
	 * Gets a sample money amount in the site's own currency where one is known.
	 *
	 * @return string
	 */
	protected function get_sample_amount() {
		$amount = '120.00';

		// WooCommerce owns the currency on a HivePress site that sells anything, and formatting
		// through it means the sample matches what the real emails will show.
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( (float) $amount ) );
		}

		return $amount;
	}
}
