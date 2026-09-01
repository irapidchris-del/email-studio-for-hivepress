<?php
/**
 * Design component.
 *
 * Applies the chosen design template to every outgoing HivePress email by swapping the body part
 * of the "email" template for this plugin's wrapper. Core's own template is a single line -
 * templates/email/email-content.php echoes the body with no header, footer or styling around it -
 * so the wrapper is additive: the admin-authored body renders inside it unchanged.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Styles outgoing HivePress emails.
 */
final class Hpes_Design extends Component {

	/**
	 * The header bar colour Panel uses when nobody has chosen one.
	 *
	 * Named rather than written twice because the settings field describes this colour to the owner
	 * and the design has to actually be it.
	 *
	 * @var string
	 */
	const DARK_HEADER = '#1f2430';


	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Swap the email body part for the design wrapper. Priority 100 so the swap runs after
		// other extensions have added their blocks and the wrapper is what actually renders.
		add_filter( 'hivepress/v1/templates/email/blocks', [ $this, 'alter_email_blocks' ], 100, 2 );

		parent::__construct( $args );
	}

	/**
	 * Gets the design templates and what each one does.
	 *
	 * One definition, read by both the settings list and the wrapper that draws them, so a template
	 * cannot be offered on screen and then not exist when an email is rendered - or gain a feature in
	 * the renderer that its description never mentions.
	 *
	 * The flags are deliberately coarse. Each template is a taste decision made once, rather than a
	 * pile of switches an owner has to combine correctly: the plugin ships opinions, not a builder.
	 *
	 * - `header`   how the top of the email is drawn: accent bar, dark bar, plain or none.
	 * - `heading`  show the email's subject as a large heading.
	 * - `button`   turn an automatically linked URL in the body into a button.
	 * - `card`     how the body sits on the background.
	 *
	 * @return array
	 */
	public function get_templates() {
		return [
			'clean'   => [
				'label'   => esc_html__( 'Clean', 'email-studio-for-hivepress' ),
				'header'  => 'plain',
				'align'   => 'left',
				'heading' => false,
				'button'  => false,
				'card'    => 'accent-top',
			],

			'boxed'   => [
				'label'   => esc_html__( 'Boxed', 'email-studio-for-hivepress' ),
				'header'  => 'rule',
				'align'   => 'center',
				'heading' => false,
				'button'  => false,
				'card'    => 'rounded',
			],

			'bold'    => [
				'label'   => esc_html__( 'Bold', 'email-studio-for-hivepress' ),
				'header'  => 'accent',
				'align'   => 'center',
				'heading' => false,
				'button'  => true,
				'card'    => 'plain',
			],

			'banner'  => [
				'label'   => esc_html__( 'Banner', 'email-studio-for-hivepress' ),
				'header'  => 'accent',
				'align'   => 'center',
				'heading' => 'banner',
				'button'  => true,
				'card'    => 'plain',
			],

			'panel'   => [
				'label'   => esc_html__( 'Panel', 'email-studio-for-hivepress' ),
				'header'  => 'dark',
				'align'   => 'left',
				'heading' => 'card',
				'button'  => true,
				'card'    => 'accent-side',
			],

			'minimal' => [
				'label'   => esc_html__( 'Minimal', 'email-studio-for-hivepress' ),
				'header'  => 'plain',
				'align'   => 'center',
				'heading' => false,
				'button'  => false,
				'card'    => 'none',
			],
		];
	}

	/**
	 * Checks whether the design wrapper is enabled.
	 *
	 * The checkbox is declared with a ticked default, and HivePress only seeds defaults into the
	 * database on its own activation and updates - so the option is absent on a fresh install
	 * while the settings screen renders it ticked. Reading absent as "on" keeps the behaviour
	 * matching the screen; a stored empty string is the admin deliberately unticking it.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$stored = get_option( 'hp_email_studio_design', null );

		if ( is_null( $stored ) ) {
			return true;
		}

		return (bool) $stored;
	}

	/**
	 * Gets the resolved design settings.
	 *
	 * Every option falls back in code because defaults are not reliably in the database (see the
	 * settings config), and because colour fields deliberately declare no default at all - an
	 * empty colour means "use the plugin's neutral", never a stored value.
	 *
	 * @return array
	 */
	public function get_settings() {

		// Template choice. Validated against the definitions rather than a second hard-coded list,
		// so a template can never be stored, offered, or drawn without the other two knowing.
		$templates = $this->get_templates();

		$template = (string) get_option( 'hp_email_studio_template' );

		if ( ! isset( $templates[ $template ] ) ) {
			$template = 'clean';
		}

		// Colours. sanitize_hex_color() returns null for anything invalid or empty.
		$accent = sanitize_hex_color( (string) get_option( 'hp_email_studio_accent_color' ) );

		if ( ! $accent ) {
			$accent = '#2b6cb0';
		}

		/*
		 * The header bar colour.
		 *
		 * The template decides whether there IS a bar; this decides what colour it is. Left empty it
		 * follows the template's own choice - the accent for Bold and Banner, a dark slate for Panel
		 * - so an owner who never opens this field gets exactly what the template preview showed
		 * them. Panel's slate was hard-coded with nothing to change it until 1.3.1.
		 */
		$header_color = sanitize_hex_color( (string) get_option( 'hp_email_studio_header_color' ) );

		$background = sanitize_hex_color( (string) get_option( 'hp_email_studio_background_color' ) );

		if ( ! $background ) {
			$background = '#f2f4f6';
		}

		$text = sanitize_hex_color( (string) get_option( 'hp_email_studio_text_color' ) );

		if ( ! $text ) {
			$text = '#26303b';
		}

		if ( ! $header_color ) {
			$header_color = 'dark' === $templates[ $template ]['header'] ? self::DARK_HEADER : $accent;
		}

		// Logo. Empty means "show the site name as text" and the wrapper branches on that.
		$logo = (string) get_option( 'hp_email_studio_logo' );

		// Logo width. A cleared number field stores '' and (int) '' is 0, which would collapse
		// the logo - so anything non-numeric falls back while an explicit number is respected.
		$logo_width = get_option( 'hp_email_studio_logo_width' );

		if ( ! is_numeric( $logo_width ) || (int) $logo_width < 1 ) {
			$logo_width = 180;
		}

		// Logo position. A closed set of three, so anything else - including the empty string a
		// pre-1.2.0 site stored while this was a select with a "match the template" placeholder -
		// falls back to the default rather than leaving the header with no alignment at all.
		$logo_align = (string) get_option( 'hp_email_studio_logo_align' );

		if ( ! in_array( $logo_align, [ 'left', 'center', 'right' ], true ) ) {
			$logo_align = 'left';
		}

		/*
		 * Footer. An absent option means the default has never been written, so the default wording
		 * applies - which is also what the settings field renders, so the box an owner opens agrees
		 * with what their emails say. A stored empty string is different: that is somebody having
		 * cleared the box on purpose, and it means no footer at all.
		 */
		$footer = get_option( 'hp_email_studio_footer_text', null );

		if ( is_null( $footer ) ) {
			$footer = hivepress()->hpes_settings->get_default_footer();
		}

		return array_merge(
			$templates[ $template ],
			[
				'template'   => $template,
				'accent'     => $accent,
				'header_bg'  => $header_color,
				'background' => $background,
				'text'       => $text,
				'logo'       => $logo,
				'logo_width' => (int) $logo_width,
				'logo_align' => $logo_align,
				'footer'     => (string) $footer,
			]
		);
	}

	/**
	 * Swaps the email body part for the design wrapper.
	 *
	 * @param array  $blocks Template blocks.
	 * @param object $template Template object.
	 * @return array
	 */
	public function alter_email_blocks( $blocks, $template ) {
		if ( ! $this->is_enabled() ) {
			return $blocks;
		}

		$blocks['email_content'] = [
			'type'   => 'part',
			'path'   => 'hpes-email/wrapper',
			'_order' => 10,
		];

		return $blocks;
	}
}
