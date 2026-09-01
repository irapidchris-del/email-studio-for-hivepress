<?php
/**
 * WooCommerce component.
 *
 * Brings WooCommerce's own transactional emails into the Email Studio list, so a marketplace owner
 * has one screen showing everything the site sends rather than two.
 *
 * **The design wrapper is deliberately NOT applied to them.** WooCommerce has a complete email
 * template system of its own, with its own header, footer, base colour and background settings under
 * WooCommerce > Settings > Emails, and many sites add a WooCommerce email designer on top of that.
 * Wrapping a WooCommerce email in this plugin's template would put two headers and two colour
 * schemes in one message - which is exactly the fault this plugin exists to remove. So Email Studio
 * lists, previews, tests and switches WooCommerce emails, and leaves their appearance to
 * WooCommerce.
 *
 * Editing follows the same principle: the Edit button opens WooCommerce's own settings screen for
 * that email rather than a second place to change the same subject and heading.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lists and previews WooCommerce emails.
 */
final class Hpes_Woo extends Component {

	/**
	 * Prefix that marks a row in the Studio as a WooCommerce email.
	 *
	 * HivePress email names and WooCommerce email ids are separate namespaces that could collide, so
	 * every WooCommerce row is addressed as `wc:{id}` throughout this plugin.
	 */
	const PREFIX = 'wc:';

	/**
	 * Cached email list.
	 *
	 * @var array|null
	 */
	protected $emails = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Late, so WooCommerce and this plugin's own settings are both loaded before anything asks
		// whether to take them over.
		add_action( 'init', [ $this, 'apply_design' ], 20 );

		parent::__construct( $args );
	}

	/**
	 * Hands WooCommerce this plugin's design settings, when the owner has asked for that.
	 *
	 * **Their settings are filtered, not their templates replaced, and that is a deliberate
	 * choice.** Swapping `emails/email-header.php` and `emails/email-footer.php` would put our own
	 * wrapper around a WooCommerce email, and it would also throw away the container the rest of
	 * WooCommerce's stylesheet is written against: its CSS targets `#template_container`,
	 * `#body_content` and the classes inside them, so the order table - the part of the email that
	 * actually matters - would lose its styling while the frame around it looked right.
	 *
	 * WooCommerce already exposes every branding decision as an option, so setting those instead
	 * gives the same outcome with none of that risk: same accent, same background, same text colour,
	 * same logo, same footer, and WooCommerce's own layout underneath.
	 *
	 * Nothing is written to the database. `option_{name}` filters the value on the way out, so
	 * unticking the box restores whatever the owner had set in WooCommerce, untouched.
	 */
	public function apply_design() {
		if ( ! $this->is_available() || ! get_option( 'hp_email_studio_woo_design' ) ) {
			return;
		}

		/*
		 * Except on WooCommerce's own Emails settings screen.
		 *
		 * Those fields render from the same options, so filtering them there would show our values
		 * in WooCommerce's boxes - and saving that screen would then write our design into their
		 * settings for real, which is exactly the "leaves your WooCommerce settings untouched"
		 * promise broken.
		 */
		if ( $this->is_woo_email_settings_screen() ) {
			return;
		}

		$design = hivepress()->hpes_design->get_settings();

		$map = [
			'woocommerce_email_base_color'            => $design['accent'],
			'woocommerce_email_background_color'      => $design['background'],
			'woocommerce_email_body_background_color' => '#ffffff',
			'woocommerce_email_text_color'            => $design['text'],
		];

		if ( $design['logo'] ) {
			$map['woocommerce_email_header_image'] = $design['logo'];
		}

		$footer = $this->get_footer_text( $design );

		if ( '' !== $footer ) {
			$map['woocommerce_email_footer_text'] = $footer;
		}

		foreach ( $map as $option => $value ) {
			add_filter(
				'option_' . $option,
				function () use ( $value ) {
					return $value;
				}
			);
		}
	}

	/**
	 * Gets the footer wording to hand WooCommerce.
	 *
	 * WooCommerce has no per-recipient tokens of its own here, so only the two that do not need one
	 * are resolved; anything else an owner put in the footer is dropped rather than printed raw,
	 * because `%user_name%` sitting in a shop email reads as a fault.
	 *
	 * @param array $design Design settings.
	 * @return string
	 */
	protected function get_footer_text( $design ) {
		$footer = (string) $design['footer'];

		if ( '' === trim( $footer ) ) {
			return '';
		}

		$footer = hp\replace_tokens(
			[
				'year'      => wp_date( 'Y' ),
				'site_name' => get_bloginfo( 'name' ),
			],
			$footer
		);

		// Anything still wrapped in percent signs is a token this context cannot fill.
		$footer = preg_replace( '/%[a-z0-9_.]+(\s*\|[^%]*)?%/i', '', $footer );

		return trim( $footer );
	}

	/**
	 * Checks whether WooCommerce's own email settings screen is being rendered.
	 *
	 * @return bool
	 */
	protected function is_woo_email_settings_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen is being rendered, not acting on input.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen is being rendered, not acting on input.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return 'wc-settings' === $page && 'email' === $tab;
	}

	/**
	 * Checks whether WooCommerce is here to be asked.
	 *
	 * `hp\is_plugin_active()` is a class_exists test rather than a plugin-slug test
	 * (`helpers.php:512-514`), which is why the class name is what gets passed.
	 *
	 * @return bool
	 */
	public function is_available() {
		return hp\is_plugin_active( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Gets every WooCommerce email, keyed the way the Studio addresses them.
	 *
	 * @return array
	 */
	public function get_emails() {
		if ( ! is_null( $this->emails ) ) {
			return $this->emails;
		}

		$this->emails = [];

		if ( ! $this->is_available() ) {
			return $this->emails;
		}

		try {
			$mailer = WC()->mailer();

			if ( ! $mailer ) {
				return $this->emails;
			}

			$source = $mailer->get_emails();
		} catch ( \Throwable $exception ) {
			return $this->emails;
		}

		foreach ( (array) $source as $email ) {
			// A WC_Email always declares an id; anything else in this array is not one of theirs.
			if ( ! $email instanceof \WC_Email || ! $email->id ) {
				continue;
			}

			$name = self::PREFIX . $email->id;

			$this->emails[ $name ] = [
				'name'              => $name,
				'label'             => (string) $email->get_title(),
				'description'       => wp_strip_all_tags( (string) $email->get_description() ),
				'recipient'         => $this->get_recipient_label( $email ),
				'recipient_address' => $this->get_recipient_address( $email ),
				'tokens'            => [],
				'source'            => esc_html__( 'WooCommerce', 'email-studio-for-hivepress' ),
				'subject'           => $this->get_subject( $email ),
				'customised'        => false,
				'edit_url'          => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( get_class( $email ) ) ),
				'disabled'          => ! $email->is_enabled(),
				'disabled_by'       => $email->is_enabled() ? '' : 'woocommerce',
				'critical'          => in_array( $email->id, [ 'customer_reset_password', 'customer_new_account' ], true ),
				'woocommerce'       => true,
				'wc_id'             => (string) $email->id,
				'wc_class'          => get_class( $email ),
			];
		}

		return $this->emails;
	}

	/**
	 * Reads a WooCommerce email's subject without assuming it can be read.
	 *
	 * **Some WooCommerce emails cannot answer this while merely being listed.**
	 * `WC_Email_Customer_Invoice::get_subject()` picks between a paid and an unpaid subject by
	 * calling `$this->object->has_status()` (`includes/emails/class-wc-email-customer-invoice.php:90`,
	 * WooCommerce 11.0.1) - and `$this->object` is the order, which is null until the email is
	 * actually being sent about one. Asking every email for its subject to build a list is therefore
	 * a fatal on that one, which is exactly what it did the first time this ran: "Call to a member
	 * function has_status() on null", taking the whole screen down rather than one row.
	 *
	 * There is no way to know in advance which emails do this, so the answer is to treat the subject
	 * as optional. A row with no subject line still reads correctly - the list only prints one when
	 * it says something the email's name does not.
	 *
	 * @param object $email WooCommerce email.
	 * @return string
	 */
	protected function get_subject( $email ) {
		try {
			return (string) $email->get_subject();
		} catch ( \Throwable $exception ) {
			return '';
		}
	}

	/**
	 * Gets a readable description of who a WooCommerce email goes to.
	 *
	 * @param object $email WooCommerce email.
	 * @return string
	 */
	protected function get_recipient_label( $email ) {

		// WooCommerce splits its emails into ones addressed to the shopper and ones addressed to
		// whoever runs the shop; `customer_email` is the flag it uses itself.
		if ( ! empty( $email->customer_email ) ) {
			return esc_html__( 'Customer', 'email-studio-for-hivepress' );
		}

		/*
		 * The role, never the address.
		 *
		 * WooCommerce stores a literal address for its shop-side emails, and printing it put the
		 * site owner's own email address in a column whose other rows say "User" and "Vendor" - one
		 * row describing a person, the next quoting an inbox. The address is still worth having, so
		 * it goes in the cell's title where hovering reveals it, rather than on screen beside
		 * everything else.
		 */
		return esc_html__( 'Site admin', 'email-studio-for-hivepress' );
	}

	/**
	 * Gets the actual address a shop-side WooCommerce email is configured to reach.
	 *
	 * @param object $email WooCommerce email.
	 * @return string
	 */
	protected function get_recipient_address( $email ) {
		if ( ! empty( $email->customer_email ) ) {
			return '';
		}

		return isset( $email->recipient ) ? (string) $email->recipient : '';
	}

	/**
	 * Gets one WooCommerce email by the Studio's name for it.
	 *
	 * @param string $name Email name.
	 * @return array|null
	 */
	public function get_email( $name ) {
		return hp\get_array_value( $this->get_emails(), $name );
	}

	/**
	 * Checks whether a name addresses a WooCommerce email.
	 *
	 * @param string $name Email name.
	 * @return bool
	 */
	public function is_woo_email( $name ) {
		return 0 === strpos( (string) $name, self::PREFIX );
	}

	/**
	 * Renders a preview of a WooCommerce email.
	 *
	 * WooCommerce builds previews itself, against a dummy order, through
	 * `WooCommerce\Internal\Admin\EmailPreview\EmailPreview` - the same class its own settings screen
	 * uses. Borrowing it means the preview is the real template with the real styles rather than an
	 * approximation of them. It lives in WooCommerce's `Internal` namespace, which WooCommerce does
	 * not promise to keep, so every use of it is guarded and the preview simply reports that it is
	 * unavailable rather than failing the screen.
	 *
	 * @param string $name Email name.
	 * @return string
	 */
	public function render_preview( $name ) {
		$email = $this->get_email( $name );

		if ( ! $email ) {
			return '';
		}

		$preview_class = '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview';

		if ( ! class_exists( $preview_class ) ) {
			return $this->render_unavailable();
		}

		try {
			$preview = wc_get_container()->get( $preview_class );

			$preview->set_email_type( $email['wc_class'] );

			$output = $preview->render();
		} catch ( \Throwable $exception ) {
			return $this->render_unavailable();
		}

		return (string) $output;
	}

	/**
	 * Renders a stand-in when WooCommerce cannot produce a preview.
	 *
	 * @return string
	 */
	protected function render_unavailable() {
		$message = esc_html__( 'WooCommerce could not build a preview of this email on this site. You can still send yourself a test of it, and edit it under WooCommerce settings.', 'email-studio-for-hivepress' );

		return '<!DOCTYPE html><html><head><meta charset="utf-8" /></head>'
			. '<body style="margin:0;padding:32px;background:#f2f4f6;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#50575e;">'
			. '<p style="max-width:480px;margin:0 auto;">' . $message . '</p>'
			. '</body></html>';
	}

	/**
	 * Sends a test of a WooCommerce email.
	 *
	 * @param string $name Email name.
	 * @param string $address Address to send to.
	 * @return bool
	 */
	public function send_test( $name, $address ) {
		$email = $this->get_email( $name );

		if ( ! $email ) {
			return false;
		}

		$content = $this->render_preview( $name );

		if ( ! $content ) {
			return false;
		}

		/* translators: %s: the email's own subject line. */
		$subject = sprintf( esc_html__( '[Test] %s', 'email-studio-for-hivepress' ), $email['subject'] );

		return (bool) wp_mail(
			$address,
			$subject,
			$content,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	/**
	 * Switches a WooCommerce email on or off.
	 *
	 * This writes WooCommerce's own `enabled` setting, which is the same switch its settings screen
	 * offers - so the two screens always agree and nothing here is a second, competing mechanism.
	 *
	 * @param string $name Email name.
	 * @param bool   $disabled Switch it off?
	 * @return bool
	 */
	public function set_disabled( $name, $disabled ) {
		$email = $this->get_email( $name );

		if ( ! $email ) {
			return false;
		}

		$option_name = 'woocommerce_' . $email['wc_id'] . '_settings';

		$settings = get_option( $option_name );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$settings['enabled'] = $disabled ? 'no' : 'yes';

		update_option( $option_name, $settings );

		// The list was built before this change, so drop it rather than answer from it.
		$this->emails = null;

		return true;
	}
}
