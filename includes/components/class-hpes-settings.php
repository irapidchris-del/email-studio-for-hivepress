<?php
/**
 * Settings component.
 *
 * Defines the design settings and renders them on the Email Studio screen itself, rather than as a
 * tab under HivePress > Settings.
 *
 * **Why not a HivePress settings tab (decided with Chris, 2026-09-01).** The plugin is one idea -
 * look at your emails, change how they look, send yourself one - and splitting it across two screens
 * meant the controls that change the design were never on the same page as the preview that shows
 * it. Everything now lives on one screen with the preview a section away.
 *
 * **It is still WordPress's own Settings API**, registered against this plugin's own page and option
 * group and posting to `options.php` exactly as a HivePress tab does, with each field rendered and
 * validated by the same `\HivePress\Fields\*` object HivePress would have used
 * (`hivepress/includes/components/class-admin.php:287-325`, `:490-511`, `:520-567`). So the controls
 * look native, the tooltips and the `_parent` show/hide behave the same, and nothing here hand-rolls
 * saving or sanitising.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the design settings.
 */
final class Hpes_Settings extends Component {

	/**
	 * The option group and the settings page name, both this plugin's own.
	 */
	const GROUP = 'hp_email_studio_settings';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( is_admin() ) {

			// Priority 20 so HivePress core has finished its own admin_init work first.
			add_action( 'admin_init', [ $this, 'upgrade' ], 5 );
			add_action( 'admin_init', [ $this, 'register_settings' ], 20 );
		}

		parent::__construct( $args );
	}

	/**
	 * Carries settings forward when the plugin is updated.
	 *
	 * **The footer needed one, and the upgrade was tested on a site that had the old version.**
	 * Until 1.1.0 the Footer Text field had no default, so HivePress seeded the option as an empty
	 * string and an empty footer meant "write the copyright line automatically". 1.1.0 gives the
	 * field a real default and reads a stored empty string as what it looks like - a deliberate
	 * "no footer at all", so somebody can actually have none. Both decisions are right; together
	 * they would have taken the footer off every site that upgraded, silently, because the value
	 * that used to mean "the automatic one" now means "none".
	 *
	 * Writing the default into that empty option is what keeps an upgraded site looking the way it
	 * did, and it also fills the box on screen, which is the other half of the same complaint: an
	 * empty field that was quietly producing text.
	 *
	 * Only ever runs once per version, and only touches an option that is empty.
	 */
	public function upgrade() {
		$stored = get_option( 'hp_email_studio_version' );

		if ( HP_EMAIL_STUDIO_VERSION === $stored ) {
			return;
		}

		/*
		 * Versions before 1.1.0 never wrote this option, so an absent one means "older", not
		 * "fresh". The two are told apart by the settings rows themselves: a site that has never had
		 * this plugin has no `hp_email_studio_footer_text` row at all, and `get_option()` answers
		 * null rather than the empty string a pre-1.1.0 site stored. Only the second kind is
		 * migrated, so a fresh install still takes its default from the field.
		 */
		if ( ! $stored || version_compare( $stored, '1.1.0', '<' ) ) {
			$footer = get_option( 'hp_email_studio_footer_text', null );

			if ( ! is_null( $footer ) && '' === trim( (string) $footer ) ) {
				update_option( 'hp_email_studio_footer_text', $this->get_default_footer() );
			}
		}

		/*
		 * Settings that no longer exist leave their rows behind, and a row for a setting with no
		 * field is a value nothing will ever show, change or explain. Social links, the font choice
		 * and the email width were all dropped in 1.3.0; removing their rows keeps the options table
		 * honest about what this plugin actually has.
		 */
		if ( ! $stored || version_compare( $stored, '1.3.0', '<' ) ) {
			foreach ( [ 'hp_email_studio_social', 'hp_email_studio_font', 'hp_email_studio_width' ] as $retired ) {
				delete_option( $retired );
			}
		}

		update_option( 'hp_email_studio_version', HP_EMAIL_STUDIO_VERSION, false );
	}

	/**
	 * Gets the default footer wording.
	 *
	 * Written with tokens rather than baked-in values so the year keeps itself right and a site
	 * rename carries through, and so the box an owner opens shows them the tokens working rather
	 * than looking empty while something they cannot see writes the footer for them.
	 *
	 * @return string
	 */
	public function get_default_footer() {
		return sprintf(
			/* translators: 1: the year token, 2: the site name token. Both are filled in automatically and must be left exactly as they appear. */
			esc_html__( '© %1$s %2$s. All rights reserved.', 'email-studio-for-hivepress' ),
			'%year%',
			'%site_name%'
		);
	}

	/**
	 * Gets the settings sections and their fields.
	 *
	 * @return array
	 */
	public function get_sections() {
		return [
			'design'  => [
				'title'       => esc_html__( 'Email Design', 'email-studio-for-hivepress' ),
				'description' => esc_html__( 'These settings style every email HivePress and its extensions send, so the plain messages arrive wrapped in a branded template instead. Preview any email with your design applied in the list above.', 'email-studio-for-hivepress' ),

				'fields'      => [
					'email_studio_design'           => [
						'label'       => esc_html__( 'Email Design', 'email-studio-for-hivepress' ),
						'caption'     => esc_html__( 'Apply the design to outgoing emails', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Wraps every email HivePress sends in the template chosen below. Untick to send plain emails exactly as HivePress does on its own; previews and test sends keep working either way. Leave this off if you have already designed your emails by hand, or the two designs will sit on top of each other.', 'email-studio-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					'email_studio_template'         => [
						'label'       => esc_html__( 'Design Template', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'The overall look of the email. Clean and Minimal stay out of the way; Boxed puts the message on a rounded card; Bold, Banner and Panel are fully styled, turning a plain link in the message into a button, and Banner and Panel also show the subject as a heading. Preview any email above to see the difference.', 'email-studio-for-hivepress' ),
						'type'        => 'radio',
						'default'     => 'clean',
						'_parent'     => 'email_studio_design',
						'_order'      => 20,

						// Straight from the definitions, so the list on screen is exactly the list the
						// renderer can draw.
						'options'     => wp_list_pluck( hivepress()->hpes_design->get_templates(), 'label' ),
					],

					'email_studio_accent_color'     => [
						'label'       => esc_html__( 'Accent Colour', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Used for the card border, the subject heading, buttons and links inside the email - and for the header bar on Bold and Banner unless you set a header colour below. Paste a 6-digit hex code such as #2b6cb0, or pick one; empty uses a neutral blue.', 'email-studio-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'email_studio_design',
						'_order'      => 30,
					],

					'email_studio_header_color'     => [
						'label'       => esc_html__( 'Header Bar Colour', 'email-studio-for-hivepress' ),
						/* translators: %s: the default dark colour Panel uses, as a hex code. */
						'description' => sprintf( esc_html__( 'Only for templates that have a coloured bar across the top - Bold, Banner and Panel. Empty follows the template: the accent colour for Bold and Banner, %s for Panel. The site name and heading on the bar switch between white and dark text on their own, whichever reads better against the colour you pick.', 'email-studio-for-hivepress' ), Hpes_Design::DARK_HEADER ),
						'type'        => 'color',
						'_parent'     => 'email_studio_design',
						'_order'      => 35,
					],

					'email_studio_background_color' => [
						'label'       => esc_html__( 'Background Colour', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'The page colour behind the email card. Paste a 6-digit hex code, or pick one; empty uses a light grey.', 'email-studio-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'email_studio_design',
						'_order'      => 40,
					],

					'email_studio_text_color'       => [
						'label'       => esc_html__( 'Text Colour', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'The colour of the words in the body of the email. Empty uses a dark grey that stays readable on a white card.', 'email-studio-for-hivepress' ),
						'type'        => 'color',
						'_parent'     => 'email_studio_design',
						'_order'      => 50,
					],

					'email_studio_logo'             => [
						'label'       => esc_html__( 'Logo Image', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Shown at the top of every email. Use the Choose image button to pick one from your Media Library, or paste an image address. Empty shows your site name as text instead.', 'email-studio-for-hivepress' ),
						'type'        => 'url',
						'placeholder' => 'https://example.com/wp-content/uploads/logo.png',
						'_parent'     => 'email_studio_design',
						'_order'      => 80,
					],

					'email_studio_logo_width'       => [
						'label'       => esc_html__( 'Logo Width (pixels)', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'The displayed width of the logo image. The height scales to match. Empty goes back to 180.', 'email-studio-for-hivepress' ),
						'type'        => 'number',
						'default'     => 180,
						'min_value'   => 40,
						'max_value'   => 400,
						'_parent'     => 'email_studio_design',
						'_order'      => 90,
					],

					'email_studio_logo_align'       => [
						'label'       => esc_html__( 'Logo Position', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Where the logo or site name sits in the header, whichever template you have chosen.', 'email-studio-for-hivepress' ),

						/*
						 * A radio rather than a select, now that the list is a closed set of three
						 * with a default already chosen: a single-choice select always grows a bare
						 * dash option above its real ones, and here that dash would read as a fourth
						 * position (hivepress-settings.md, "A single-choice select always grows a
						 * bare dash option"). Left is the default because the default template puts
						 * it there, so a fresh install looks the same as it did before this setting
						 * existed.
						 */
						'type'        => 'radio',
						'default'     => 'left',
						'_parent'     => 'email_studio_design',
						'_order'      => 100,

						'options'     => [
							'left'   => esc_html__( 'Left', 'email-studio-for-hivepress' ),
							'center' => esc_html__( 'Centre', 'email-studio-for-hivepress' ),
							'right'  => esc_html__( 'Right', 'email-studio-for-hivepress' ),
						],
					],

					'email_studio_footer_text'      => [
						'label'       => esc_html__( 'Footer Text', 'email-studio-for-hivepress' ),
						'description' => sprintf(
							/* translators: 1: the year token, 2: the site name token, 3: the user name token. All three are filled in automatically and must be left exactly as they appear. */
							esc_html__( 'Small print at the bottom of every email. %1$s and %2$s fill themselves in, and any token the email itself offers works here too, so %3$s greets the person receiving it. Line breaks are kept. A link, bold and italic are allowed. Empty means no footer at all.', 'email-studio-for-hivepress' ),
							'%year%',
							'%site_name%',
							'%user_name%'
						),
						'type'        => 'textarea',
						'max_length'  => 1000,
						'default'     => $this->get_default_footer(),
						'_parent'     => 'email_studio_design',
						'_order'      => 110,

						// Routes sanitisation through wp_kses() instead of sanitize_textarea_field(),
						// which would otherwise destroy any %xx% sequence whose first two characters
						// are hex digits - tokens included (see hivepress-settings.md).
						'html'        => [
							'a'      => [
								'href'   => [],
								'target' => [],
							],
							'strong' => [],
							'em'     => [],
							'br'     => [],
						],
					],
				],
			],

			'woo'     => $this->get_woo_section(),

			'log'     => [
				'title'       => esc_html__( 'Delivery Log', 'email-studio-for-hivepress' ),
				'description' => esc_html__( 'Keeps a short record of the emails your site sends, shown at the bottom of this screen. It is what tells "the email never sent" apart from "it sent and did not arrive", which is the difference between a problem with your site and a problem with the inbox it was going to. The record includes the address each email went to, so it is personal data: keep it short, and clear it when you no longer need it.', 'email-studio-for-hivepress' ),

				'fields'      => [
					'email_studio_log'       => [
						'label'       => esc_html__( 'Delivery Log', 'email-studio-for-hivepress' ),
						'caption'     => esc_html__( 'Record emails as they are sent', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Untick to record nothing at all. Anything already recorded stays until you clear it.', 'email-studio-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					'email_studio_log_limit' => [
						'label'       => esc_html__( 'Entries to Keep', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Once this many are recorded, the oldest drops off as each new one arrives. Empty goes back to 100.', 'email-studio-for-hivepress' ),
						'type'        => 'number',
						'default'     => 100,
						'min_value'   => 10,
						'max_value'   => 500,
						'_parent'     => 'email_studio_log',
						'_order'      => 20,
					],
				],
			],

			'removal' => [
				'title'       => esc_html__( 'Removing the Plugin', 'email-studio-for-hivepress' ),
				'description' => esc_html__( 'What happens if you ever delete this plugin. WordPress shows a generic warning that deleting a plugin also deletes its data; for this plugin that only applies if you tick the box below. Emails you have customised belong to HivePress itself and are never deleted either way.', 'email-studio-for-hivepress' ),

				'fields'      => [
					'email_studio_delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'email-studio-for-hivepress' ),
						'caption'     => esc_html__( 'Delete all data when this plugin is deleted', 'email-studio-for-hivepress' ),
						'description' => esc_html__( 'Removes every Email Studio setting when the plugin is deleted from the Plugins screen. This cannot be undone. Leave unticked to keep your settings for a future reinstall; deactivating never removes anything either way.', 'email-studio-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		];
	}

	/**
	 * Gets the WooCommerce section, which only exists where WooCommerce does.
	 *
	 * An empty `fields` array makes the section render nothing at all, which is the behaviour wanted
	 * on a site with no shop: no heading, no explanation of a setting that could not do anything.
	 *
	 * @return array
	 */
	protected function get_woo_section() {
		if ( ! hivepress()->hpes_woo->is_available() ) {
			return [
				'title'       => '',
				'description' => '',
				'fields'      => [],
			];
		}

		$description = esc_html__( 'WooCommerce styles its own emails, so by default this plugin leaves them alone and only lists them. Turning this on hands WooCommerce your colours, your logo and your footer wording instead of its own, so a customer gets the same look whether the email came from HivePress or from the shop.', 'email-studio-for-hivepress' );

		/*
		 * A second email designer would be fighting this one for the same emails, and the owner would
		 * see whichever won rather than an error. Naming the conflict where the choice is made beats
		 * a line in a readme nobody reading this screen will open.
		 */
		$rival = $this->get_rival_designer();

		if ( $rival ) {
			$description .= ' ' . sprintf(
				/* translators: %s: the name of another plugin that also designs WooCommerce emails. */
				esc_html__( 'You also have %s active, which designs WooCommerce emails as well. Use one or the other, not both.', 'email-studio-for-hivepress' ),
				$rival
			);
		}

		return [
			'title'       => esc_html__( 'WooCommerce Emails', 'email-studio-for-hivepress' ),
			'description' => $description,

			'fields'      => [
				'email_studio_woo_design' => [
					'label'       => esc_html__( 'WooCommerce Emails', 'email-studio-for-hivepress' ),
					'caption'     => esc_html__( 'Use my design for WooCommerce emails too', 'email-studio-for-hivepress' ),
					'description' => esc_html__( 'Applies your accent colour, background, text colour, logo and footer to WooCommerce\'s own email template. WooCommerce keeps its own layout, so an order table still looks like an order table; only the branding around it changes. Your WooCommerce email settings are left untouched, so unticking this puts everything back.', 'email-studio-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 10,
				],
			],
		];
	}

	/**
	 * Names another active plugin that also designs WooCommerce emails, if there is one.
	 *
	 * Detected by class or function rather than by plugin slug, because a slug tells you what a
	 * folder is called and a class tells you what actually loaded.
	 *
	 * @return string
	 */
	protected function get_rival_designer() {

		/**
		 * Filters the plugins treated as also designing WooCommerce emails.
		 *
		 * Passing the list through a filter is not only for extensibility: a literal array of class
		 * names none of which exist on the machine doing the analysis lets static analysis conclude
		 * the check can never be true and report the branch as dead, which it is not - it is a
		 * runtime question about somebody else's site.
		 *
		 * @hook hivepress/v1/email_studio/rival_designers
		 * @param {array} $designers Class name to plugin name.
		 * @return {array} Class name to plugin name.
		 */
		$known = (array) apply_filters(
			'hivepress/v1/email_studio/rival_designers',
			[
				'Kadence_Woomail_Designer' => 'Kadence WooCommerce Email Designer',
				'YayMail'                  => 'YayMail',
				'YayMailAddon'             => 'YayMail',
				'WCEmailEditor'            => 'WooCommerce Email Customizer',
			]
		);

		foreach ( $known as $symbol => $name ) {
			if ( class_exists( $symbol ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Registers the settings with WordPress.
	 *
	 * Registration has to happen on the Studio screen (so the fields render) and on `options.php`
	 * (so the save is allowed at all - the Settings API refuses to write an option that is not
	 * registered for the posted group, and does it by silently changing nothing).
	 */
	public function register_settings() {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen is being rendered, not acting on input.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'options.php' !== $pagenow && 'hp_email_studio' !== $page ) {
			return;
		}

		foreach ( $this->get_sections() as $section_name => $section ) {
			add_settings_section(
				$section_name,
				esc_html( $section['title'] ),
				function() use ( $section ) {
					// hp\sanitize_html() IS the escaping here, and it is what HivePress itself uses
					// for a settings section description (`components/class-admin.php:481`), so a
					// link or an icon in one behaves the same on this screen as on a HivePress tab.
					echo '<p>' . hp\sanitize_html( $section['description'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				self::GROUP
			);

			foreach ( hp\sort_array( $section['fields'] ) as $field_name => $field_args ) {
				$option_name = hp\prefix( $field_name );

				$field = $this->create_field( $field_args, $option_name );

				if ( ! $field ) {
					continue;
				}

				add_settings_field(
					$option_name,
					$this->render_label( $field ),
					[ $this, 'render_field' ],
					self::GROUP,
					$section_name,
					$field->get_args()
				);

				register_setting(
					self::GROUP,
					$option_name,
					[
						'sanitize_callback' => function( $value ) use ( $field_args, $option_name ) {
							return $this->validate_field( $field_args, $option_name, $value );
						},
					]
				);
			}
		}
	}

	/**
	 * Creates a HivePress field object for a setting.
	 *
	 * @param array  $field_args Field arguments.
	 * @param string $option_name Option name.
	 * @return object|null
	 */
	protected function create_field( $field_args, $option_name ) {
		return hp\create_class_instance(
			'\HivePress\Fields\\' . $field_args['type'],
			[
				array_merge(
					$field_args,
					[
						'name'    => $option_name,
						'default' => get_option( $option_name, hp\get_array_value( $field_args, 'default' ) ),
					]
				),
			]
		);
	}

	/**
	 * Renders a field's label and tooltip.
	 *
	 * Matches HivePress's own markup exactly, so core's stylesheet lays these rows out for us and a
	 * tooltip here behaves like a tooltip on any other HivePress screen.
	 *
	 * @param object $field Field object.
	 * @return string
	 */
	protected function render_label( $field ) {
		$output = '<div><label class="hp-field__label"><span>' . esc_html( $field->get_label() ) . '</span>';

		if ( $field->get_statuses() ) {
			$output .= ' <small>(' . esc_html( implode( ', ', $field->get_statuses() ) ) . ')</small>';
		}

		$output .= '</label>';

		if ( $field->get_description() ) {
			$output .= '<div class="hp-tooltip"><span class="hp-tooltip__icon dashicons dashicons-editor-help"></span>';
			$output .= '<div class="hp-tooltip__text">' . wp_kses_post( $field->get_description() ) . '</div></div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders a field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_field( $args ) {
		$field = hp\create_class_instance( '\HivePress\Fields\\' . $args['type'], [ $args ] );

		if ( ! $field ) {
			return;
		}

		$attributes = [];

		// data-component="field" plus data-parent is what core's own common.js watches to show and
		// hide a child row with its parent checkbox, so the conditional rows behave here exactly as
		// they do on a HivePress settings tab.
		if ( $field->get_arg( '_parent' ) ) {
			$attributes['data-component'] = 'field';
			$attributes['data-parent']    = hp\prefix( $field->get_arg( '_parent' ) );
		}

		echo '<div ' . hp\html_attributes( $attributes ) . '>' . $field->render() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes are escaped by hp\html_attributes(), and the field renders its own escaped control.
	}

	/**
	 * Validates a field on save.
	 *
	 * @param array  $field_args Field arguments.
	 * @param string $option_name Option name.
	 * @param mixed  $value Posted value.
	 * @return mixed
	 */
	protected function validate_field( $field_args, $option_name, $value ) {
		$field = hp\create_class_instance( '\HivePress\Fields\\' . $field_args['type'], [ $field_args ] );

		if ( ! $field ) {
			return $value;
		}

		$field->set_value( $value );

		if ( $field->validate() ) {
			return $field->get_value();
		}

		foreach ( $field->get_errors() as $error ) {
			add_settings_error( $option_name, $option_name, esc_html( $error ) );
		}

		// Keep what was there rather than writing a value the field rejected.
		return get_option( $option_name );
	}
}
