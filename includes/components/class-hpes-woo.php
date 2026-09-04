<?php
/**
 * WooCommerce component.
 *
 * Brings WooCommerce's own transactional emails into the Email Studio list, so a marketplace owner
 * has one screen showing everything the site sends rather than two.
 *
 * **By default the design wrapper is NOT applied to them.** WooCommerce has a complete email
 * template system of its own, with its own header, footer, base colour and background settings under
 * WooCommerce > Settings > Emails, and many sites add a WooCommerce email designer on top of that.
 * Wrapping a whole WooCommerce email in this plugin's template would put two headers and two colour
 * schemes in one message - which is exactly the fault this plugin exists to remove. So out of the
 * box Email Studio lists, previews, tests and switches WooCommerce emails, and leaves their
 * appearance to WooCommerce.
 *
 * **Since 1.5.0 the owner can choose the wrapper instead** (the "WooCommerce email layout" setting).
 * That path never nests one frame inside another: WooCommerce still builds its complete email and
 * inlines its stylesheet, then wrap_content() lifts the message out of WooCommerce's frame - the
 * `#body_content_inner` element its own header and footer templates draw around every message - and
 * renders that, with its inlined styling intact, inside this plugin's wrapper. The order table keeps
 * WooCommerce's styling because that styling is already on its elements by then; only the header,
 * footer and background are this plugin's.
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
	 * Option holding the chosen WooCommerce email layout.
	 */
	const LAYOUT_OPTION = 'hp_email_studio_woo_layout';

	/**
	 * Cached email list.
	 *
	 * @var array|null
	 */
	protected $emails = null;

	/**
	 * The heading and email object of the WooCommerce email being built right now.
	 *
	 * WooCommerce fires `woocommerce_email_header` with both while it renders a message
	 * (templates/emails/email-header.php, via WC_Emails::email_header()), and only the finished HTML
	 * reaches `woocommerce_mail_content` afterwards. Remembering them here is what lets the wrapper
	 * carry the email's own heading and subject.
	 *
	 * @var array|null
	 */
	protected $current = null;

	/**
	 * A layout to preview instead of the saved one, or null to follow the setting.
	 *
	 * @var string|null
	 */
	protected $layout_override = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Late, so WooCommerce and this plugin's own settings are both loaded before anything asks
		// whether to take them over.
		add_action( 'init', [ $this, 'apply_design' ], 20 );

		// The wrapper. Priority 1 on the header so the heading is known before any other listener;
		// priority 20 on the content so the message is wrapped after WooCommerce has inlined its
		// styles and after anything else that edits the finished HTML at the default priority.
		add_action( 'woocommerce_email_header', [ $this, 'remember_email' ], 1, 2 );
		add_filter( 'woocommerce_mail_content', [ $this, 'wrap_content' ], 20 );

		parent::__construct( $args );
	}

	/**
	 * Gets the layouts a WooCommerce email can be sent in, keyed by the stored value.
	 *
	 * @return array
	 */
	public function get_layouts() {
		return [
			'woocommerce'  => esc_html__( 'WooCommerce layout', 'email-studio-for-hivepress' ),
			'wrapper'      => esc_html__( 'Email Studio wrapper', 'email-studio-for-hivepress' ),
			'wrapper_body' => esc_html__( 'Email Studio wrapper, message body only', 'email-studio-for-hivepress' ),
		];
	}

	/**
	 * Gets the saved layout, validated against the list the settings screen offers.
	 *
	 * @return string
	 */
	public function get_layout() {
		$layout = (string) get_option( self::LAYOUT_OPTION );

		return isset( $this->get_layouts()[ $layout ] ) ? $layout : 'woocommerce';
	}

	/**
	 * Remembers the email WooCommerce is rendering, for the wrapper.
	 *
	 * @param string $heading Email heading.
	 * @param object $email WooCommerce email object, when WooCommerce passes one.
	 */
	public function remember_email( $heading, $email = null ) {
		$this->current = [
			'heading' => (string) $heading,
			'email'   => $email instanceof \WC_Email ? $email : null,
		];
	}

	/**
	 * Puts the design wrapper around a finished WooCommerce email, when the owner has asked for it.
	 *
	 * Runs for real sends (WC_Email::send(), includes/emails/class-wc-email.php:1233, WooCommerce
	 * 11.0.1) and for previews (EmailPreview::render_preview_email(), :372), which both pass the
	 * inlined HTML through `woocommerce_mail_content`. One filter therefore keeps what the owner
	 * previews and what the customer receives the same.
	 *
	 * The message body is taken from `#body_content_inner`. If a site's overridden templates have
	 * no such element there is nothing safe to lift, and WooCommerce's own email is sent unchanged
	 * rather than a broken one.
	 *
	 * @param string $content Finished email HTML.
	 * @return string
	 */
	public function wrap_content( $content ) {
		$layout = $this->layout_override ? $this->layout_override : $this->get_layout();

		if ( 'woocommerce' === $layout || ! hivepress()->hpes_design->is_enabled() ) {
			return $content;
		}

		$body = $this->extract_body( (string) $content );

		if ( '' === $body ) {
			return $content;
		}

		$heading = $this->current ? $this->current['heading'] : '';
		$subject = '';

		if ( $this->current && $this->current['email'] ) {
			try {
				$subject = (string) $this->current['email']->get_subject();
			} catch ( \Throwable $exception ) {
				$subject = '';
			}
		}

		$wrapped = $this->render_wrapper( $body, $heading, $subject, 'wrapper' === $layout );

		return '' === $wrapped ? $content : $wrapped;
	}

	/**
	 * Lifts the message out of a finished WooCommerce email.
	 *
	 * Parsed rather than matched with a pattern: the message holds nested tables and divs of its
	 * own (the order details template wraps its table in a div), and a pattern that stops at the
	 * first closing tag returns a fragment. libxml is told the document is UTF-8 through the XML
	 * declaration trick, because DOMDocument otherwise assumes Latin-1 and mangles every accented
	 * character in a customer's name or address.
	 *
	 * @param string $content Finished email HTML.
	 * @return string The inner HTML of the message element, or an empty string when there is none.
	 */
	protected function extract_body( $content ) {
		if ( '' === $content || false === strpos( $content, 'body_content_inner' ) || ! class_exists( '\DOMDocument' ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );

		try {
			$dom = new \DOMDocument();

			$dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_NOWARNING | LIBXML_NOERROR );

			$xpath = new \DOMXPath( $dom );
			$nodes = $xpath->query( '//*[@id="body_content_inner"]' );

			if ( ! $nodes || ! $nodes->length ) {
				return '';
			}

			$inner = '';

			foreach ( $nodes->item( 0 )->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
				$inner .= $dom->saveHTML( $child );
			}

			return trim( $inner );
		} catch ( \Throwable $exception ) {
			return '';
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Renders a WooCommerce message inside the design wrapper.
	 *
	 * The same `Blocks\Template( 'email' )` render every HivePress email goes through, with a
	 * stand-in email carrying WooCommerce's message as its body, so the wrapper and every filter on
	 * it behave exactly as they do for a HivePress email.
	 *
	 * The heading: WooCommerce prints one in its own header ("Thank you for your order"), and
	 * dropping that frame would drop the sentence with it. Templates that draw a heading (Banner,
	 * Panel) are handed it in place of the subject; the others get it as a heading at the top of
	 * the message. The body-only layout leaves it out altogether.
	 *
	 * @param string $body Message HTML, styles already inlined by WooCommerce.
	 * @param string $heading WooCommerce's heading for the email.
	 * @param string $subject The email's subject line.
	 * @param bool   $with_heading Whether to carry the heading into the wrapper.
	 * @return string
	 */
	protected function render_wrapper( $body, $heading, $subject, $with_heading ) {
		$design = hivepress()->hpes_design->get_settings();

		$template_heading = '';

		if ( $with_heading && '' !== $heading ) {
			if ( $design['heading'] ) {
				$template_heading = $heading;
			} else {
				$body = '<h2 style="margin:0 0 16px;font-family:Helvetica,Arial,sans-serif;font-size:20px;line-height:1.3;font-weight:bold;color:' . esc_attr( $design['accent'] ) . ';">' . esc_html( $heading ) . '</h2>' . $body;
			}
		}

		/*
		 * The footer, with the tokens this message cannot fill already dropped. The wrapper resolves
		 * a HivePress email's own tokens into the footer; a WooCommerce message has none, so a
		 * footer written for members ("as %user_name%") is cleaned the same way it is when
		 * WooCommerce prints it. Handed over by filtering the option for this one render only.
		 */
		$footer = $this->get_footer_text( $design );

		$supply_footer = function () use ( $footer ) {
			return $footer;
		};

		add_filter( 'option_hp_email_studio_footer_text', $supply_footer );
		add_filter( 'default_option_hp_email_studio_footer_text', $supply_footer );

		try {
			$email = new \HivePress\Emails\Hpes_Woo_Message(
				[
					'subject' => $subject,
					'body'    => $body,
				]
			);

			$output = ( new \HivePress\Blocks\Template(
				[
					'template' => 'email',

					'context'  => [
						'email'        => $email,
						'hpes_heading' => $template_heading,
					],
				]
			) )->render();
		} catch ( \Throwable $exception ) {
			$output = '';
		} finally {
			remove_filter( 'option_hp_email_studio_footer_text', $supply_footer );
			remove_filter( 'default_option_hp_email_studio_footer_text', $supply_footer );
		}

		return (string) $output;
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
		if ( ! $this->is_available() ) {
			return;
		}

		// The wrapper layouts imply the colours: WooCommerce still styles the order table from its
		// own base and text colours, and those have to match the frame now drawn around it.
		if ( ! get_option( 'hp_email_studio_woo_design' ) && 'woocommerce' === $this->get_layout() ) {
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
	 * The preview goes through `woocommerce_mail_content` like a real send, so it wears whatever
	 * layout the setting says. A layout passed in previews that layout instead, for the panel's
	 * compare switch, and changes nothing that is saved.
	 *
	 * @param string $name Email name.
	 * @param string $layout Layout to preview, or an empty string for the saved one.
	 * @return string
	 */
	public function render_preview( $name, $layout = '' ) {
		$email = $this->get_email( $name );

		if ( ! $email ) {
			return '';
		}

		$preview_class = '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview';

		if ( ! class_exists( $preview_class ) ) {
			return $this->render_unavailable();
		}

		$this->layout_override = isset( $this->get_layouts()[ $layout ] ) ? $layout : null;

		try {
			$preview = wc_get_container()->get( $preview_class );

			$preview->set_email_type( $email['wc_class'] );

			$output = $preview->render();
		} catch ( \Throwable $exception ) {
			return $this->render_unavailable();
		} finally {
			$this->layout_override = null;
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
