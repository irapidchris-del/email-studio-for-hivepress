<?php
/**
 * Tokens component.
 *
 * Works out the full list of %tokens% an email can use, including the custom attributes HivePress
 * itself leaves out of the list it shows.
 *
 * **The gap this closes, measured on hivepress-dev 2026-08-31 against core 1.7.31.** Core builds the
 * token list on the email edit screen by looping a model's fields and skipping any field that
 * carries a `_model` argument (`hivepress/includes/components/class-email.php:209-226`). Every
 * taxonomy-backed attribute carries one, so the attributes a site owner is most likely to have
 * created - the dropdowns, "Condition", "Type", "Region" - are absent from the list, alongside every
 * checkbox attribute.
 *
 * They are absent from the *list* only. `hp\replace_tokens()` looks a field up in `_get_fields()`
 * and calls `get_display_value()` with no such exclusion (`hivepress/includes/helpers.php:355-372`),
 * so the tokens work perfectly and nothing tells the owner they exist. Measured against a real
 * published listing: all fifteen hidden tokens resolved, `%listing.condition%` to "New",
 * `%listing.region%` to "New York" and `%listing.categories%` to "For Sale".
 *
 * So this does not add a capability to HivePress. It advertises one HivePress already has.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the available token list for an email.
 */
final class Hpes_Tokens extends Component {

	/**
	 * Field types that make no sense inside an email.
	 *
	 * This is core's own exclusion list (`components/class-email.php:211-223`) minus `checkbox`,
	 * which is excluded there but resolves to a plain "Yes" or "No" and is genuinely useful in a
	 * sentence such as "Featured listing: %listing.featured%". What stays excluded either renders a
	 * raw database ID (`id`) or renders nothing at all (the attachment and repeater types), and an
	 * advertised token that renders "40" is worse than no token.
	 */
	const EXCLUDED_FIELD_TYPES = [
		'id',
		'hidden',
		'file',
		'password',
		'repeater',
		'attachment_select',
		'attachment_upload',
	];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( is_admin() ) {

			// Priority 20, after core has built its own block at 10, so the fuller list replaces it
			// rather than sitting underneath as a second list of nearly the same thing.
			add_filter( 'hivepress/v1/meta_boxes/email_details', [ $this, 'alter_email_details' ], 20 );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}

		parent::__construct( $args );
	}

	/**
	 * Loads the token list's styles on the email edit screen.
	 *
	 * The same stylesheet the Studio screen uses; every rule in it is scoped to this plugin's own
	 * class names, so loading it beside HivePress's editor cannot restyle anything of core's.
	 *
	 * @param string $hook_suffix Current screen's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'hp_email' !== $screen->post_type ) {
			return;
		}

		$path = plugin_dir_path( HP_EMAIL_STUDIO_FILE );

		wp_enqueue_style(
			'hp-email-studio-admin',
			plugin_dir_url( HP_EMAIL_STUDIO_FILE ) . 'assets/css/admin.css',
			[],
			HP_EMAIL_STUDIO_VERSION . '.' . (int) filemtime( $path . 'assets/css/admin.css' )
		);
	}

	/**
	 * Replaces the token list on the email edit screen with the complete one.
	 *
	 * @param array $meta_box Meta box arguments.
	 * @return array
	 */
	public function alter_email_details( $meta_box ) {

		// Core only adds its block when the screen really is an email with a label, and it reads the
		// email from the current post. Anything else here means core did not build a list at all,
		// and there is nothing for this to improve on.
		if ( ! isset( $meta_box['blocks']['email_details']['content'] ) ) {
			return $meta_box;
		}

		$email_class = hp\get_array_value( hivepress()->get_classes( 'emails' ), get_post_field( 'post_name' ) );

		if ( ! $email_class || ! $email_class::get_meta( 'label' ) ) {
			return $meta_box;
		}

		$content = $this->render_token_list( $email_class );

		if ( ! $content ) {
			return $meta_box;
		}

		$meta_box['blocks']['email_details']['content'] = $content;

		return $meta_box;
	}

	/**
	 * Gets the token groups available to an email.
	 *
	 * @param string $email_class Email class.
	 * @return array
	 */
	public function get_groups( $email_class ) {
		$groups = [];

		$general = [];

		foreach ( (array) $email_class::get_meta( 'tokens' ) as $token ) {
			$model = $this->get_model( $token );

			if ( ! $model ) {
				$general[] = [
					'token' => $token,
					'label' => '',
				];

				continue;
			}

			foreach ( $this->get_model_groups( $token, $model ) as $group ) {
				$groups[] = $group;
			}
		}

		if ( $general ) {
			array_unshift(
				$groups,
				[
					'title'  => esc_html__( 'This email', 'email-studio-for-hivepress' ),
					'note'   => esc_html__( 'Values that belong to this message.', 'email-studio-for-hivepress' ),
					'tokens' => $general,
				]
			);
		}

		return $groups;
	}

	/**
	 * Gets a model instance for a token, if the token names one.
	 *
	 * @param string $token Token name.
	 * @return object|null
	 */
	protected function get_model( $token ) {

		// Class names are case-insensitive in PHP, which is why core addresses models by the bare
		// token name; matching that keeps multi-word models such as listing_category working.
		$model = hp\create_class_instance( '\HivePress\Models\\' . $token );

		if ( ! $model ) {
			return null;
		}

		// Core does this before reading the fields, with a "@todo remove temporary fix" note against
		// it (`components/class-email.php:201`). Matching core is the safe side of an unexplained
		// workaround: an instance it treats as unsaved is the state its own list is built from.
		$model->set_id( null );

		return $model;
	}

	/**
	 * Splits one model's fields into a details group and an attributes group.
	 *
	 * @param string $token Token name.
	 * @param object $model Model object.
	 * @return array
	 */
	protected function get_model_groups( $token, $model ) {
		$attributes = [];

		try {
			$attributes = hivepress()->attribute->get_attributes( $token );
		} catch ( \Throwable $exception ) {

			// Only listings and vendors have attributes; anything else simply has none.
			$attributes = [];
		}

		$details = [
			[
				'token' => $token . '.id',
				'label' => '',
			],
		];

		$attributed = [];

		foreach ( $model->_get_fields() as $field ) {
			if ( in_array( $field::get_meta( 'name' ), self::EXCLUDED_FIELD_TYPES, true ) ) {
				continue;
			}

			$name = $field->get_name();

			$entry = [
				'token' => $token . '.' . $name,
				'label' => '',
			];

			if ( isset( $attributes[ $name ] ) ) {
				/*
				 * The attribute's own label, but only where it says something the token does not.
				 * Most labels are just the slug with capitals and spaces - "Areas Covered" beside
				 * `%listing.areas_covered%` - and printing both put the same words on the screen
				 * twice for the majority of a 25-entry list, which made the genuinely useful ones
				 * ("Training & Experience" for `vendor_training`) harder to spot rather than easier.
				 */
				$label = (string) hp\get_array_value( $attributes[ $name ], 'label' );

				if ( $this->normalise( $label ) !== $this->normalise( $name ) ) {
					$entry['label'] = $label;
				}

				$attributed[] = $entry;
			} else {
				$details[] = $entry;
			}
		}

		$model_label = $this->get_model_label( $token );

		$groups = [
			[
				/* translators: %s: a model name such as Listing or Vendor. */
				'title'  => sprintf( esc_html__( '%s details', 'email-studio-for-hivepress' ), $model_label ),
				'note'   => '',
				'tokens' => $details,
			],
		];

		if ( $attributed ) {
			$groups[] = [
				/* translators: %s: a model name such as Listing or Vendor. */
				'title'  => sprintf( esc_html__( '%s attributes', 'email-studio-for-hivepress' ), $model_label ),
				'note'   => esc_html__( 'Your own attributes, plus any added by extensions. HivePress does not list these itself, but they work.', 'email-studio-for-hivepress' ),
				'tokens' => $attributed,
			];
		}

		return $groups;
	}

	/**
	 * Reduces a label or a slug to the letters and digits in it, lowercased.
	 *
	 * So "Areas Covered" and `areas_covered` compare equal, while "Training & Experience" and
	 * `vendor_training` do not.
	 *
	 * @param string $value Value to normalise.
	 * @return string
	 */
	protected function normalise( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $value ) );
	}

	/**
	 * Gets a readable name for a model.
	 *
	 * @param string $token Token name.
	 * @return string
	 */
	protected function get_model_label( $token ) {
		$label = hivepress()->translator->get_string( $token );

		if ( $label ) {
			return $label;
		}

		return ucwords( str_replace( '_', ' ', $token ) );
	}

	/**
	 * Renders the token list as HTML.
	 *
	 * Everything is escaped here rather than trusted downstream: a `content` meta box block is
	 * returned by `Blocks\Content::render()` exactly as given, with no escaping of its own, and the
	 * attribute labels in this list are text a site owner typed.
	 *
	 * @param string $email_class Email class.
	 * @return string
	 */
	public function render_token_list( $email_class ) {
		$groups = $this->get_groups( $email_class );

		if ( ! $groups ) {
			return '';
		}

		$output = '';

		$description = $email_class::get_meta( 'description' );

		if ( $description ) {
			$output .= '<p>' . esc_html( $description ) . '</p>';
		}

		$output .= '<div class="hpes-tokens">';

		foreach ( $groups as $group ) {
			$output .= '<div class="hpes-tokens__group">';
			$output .= '<h4 class="hpes-tokens__title">' . esc_html( $group['title'] ) . '</h4>';

			if ( $group['note'] ) {
				$output .= '<p class="hpes-tokens__note description">' . esc_html( $group['note'] ) . '</p>';
			}

			$output .= '<ul class="hpes-tokens__list">';

			foreach ( $group['tokens'] as $entry ) {
				$output .= '<li class="hpes-tokens__item">';

				// data-component="copy" is core's own click-to-copy handler, which is what its token
				// list uses on this same screen (`components/class-email.php:231`).
				$output .= '<code title="' . esc_attr( (string) hivepress()->translator->get_string( 'click_to_copy' ) ) . '" data-component="copy">%' . esc_html( $entry['token'] ) . '%</code>';

				if ( $entry['label'] ) {
					$output .= ' <span class="hpes-tokens__label">' . esc_html( $entry['label'] ) . '</span>';
				}

				$output .= '</li>';
			}

			$output .= '</ul></div>';
		}

		$output .= '</div>';

		$output .= '<p class="description">' . sprintf(
			/* translators: 1: the | character, 2: the percent character, 3: a complete example token. */
			esc_html__( 'A token with no value is replaced by nothing. To show something else instead, add %1$s and your wording before the closing %2$s, like %3$s.', 'email-studio-for-hivepress' ),
			'<code>|</code>',
			'<code>%</code>',
			'<code>%' . esc_html( $this->get_example_token( $groups ) ) . '|not stated%</code>'
		) . '</p>';

		return $output;
	}

	/**
	 * Picks a token from this email's own list to use in the fallback example.
	 *
	 * The example used to be a hard-coded `%listing.condition%`. Seen on staging on 2026-09-01,
	 * where no Condition attribute exists: the help text was telling a site owner to copy a token
	 * their site does not have, which for the audience this is written for - somebody building
	 * their first WordPress site - is worse than no example. Taking one from the list printed
	 * directly above means the example is always real, on every site.
	 *
	 * @param array $groups Token groups.
	 * @return string
	 */
	protected function get_example_token( $groups ) {

		// An attribute makes the better example, because a fallback matters most where a value is
		// optional and attributes usually are. Groups carrying labels are the attribute ones.
		foreach ( $groups as $group ) {
			foreach ( $group['tokens'] as $entry ) {
				if ( $entry['label'] ) {
					return $entry['token'];
				}
			}
		}

		foreach ( $groups as $group ) {
			foreach ( $group['tokens'] as $entry ) {
				if ( false === strpos( $entry['token'], '.id' ) ) {
					return $entry['token'];
				}
			}
		}

		return 'listing.title';
	}
}
