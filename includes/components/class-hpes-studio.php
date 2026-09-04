<?php
/**
 * Studio component.
 *
 * The whole plugin on one screen: every email the site can send, what each one looks like, the
 * design that wraps them, a composer for sending one of your own, and a record of what went out.
 *
 * @package HivePress\EmailStudio\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders the Email Studio screen.
 */
final class Hpes_Studio extends Component {

	/**
	 * Nonce action shared by every request this screen makes.
	 */
	const NONCE_ACTION = 'hpes_studio';

	/**
	 * The screen's own hook suffix, used to load assets on this page and nowhere else.
	 *
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_admin_page' ], 20 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			// A Preview button on the screen where the wording is actually edited, so checking a
			// change does not mean navigating away and back.
			add_action( 'post_submitbox_misc_actions', [ $this, 'render_edit_preview_button' ] );
			add_action( 'admin_footer', [ $this, 'render_edit_panel' ] );

			// The preview answers a GET because it is loaded as an iframe source, where the browser
			// makes the request rather than our script.
			add_action( 'wp_ajax_hpes_preview', [ $this, 'ajax_preview' ] );

			add_action( 'wp_ajax_hpes_tokens', [ $this, 'ajax_tokens' ] );
			add_action( 'wp_ajax_hpes_test', [ $this, 'ajax_test' ] );
			add_action( 'wp_ajax_hpes_toggle', [ $this, 'ajax_toggle' ] );
			add_action( 'wp_ajax_hpes_reset', [ $this, 'ajax_reset' ] );
			add_action( 'wp_ajax_hpes_customise', [ $this, 'ajax_customise' ] );
			add_action( 'wp_ajax_hpes_clear_log', [ $this, 'ajax_clear_log' ] );
			add_action( 'wp_ajax_hpes_compose_preview', [ $this, 'ajax_compose_preview' ] );
			add_action( 'wp_ajax_hpes_compose_test', [ $this, 'ajax_compose_test' ] );
			add_action( 'wp_ajax_hpes_compose_send', [ $this, 'ajax_compose_send' ] );
			add_action( 'wp_ajax_hpes_compose_count', [ $this, 'ajax_compose_count' ] );
		}

		parent::__construct( $args );
	}

	/**
	 * Adds the Email Studio page under the HivePress menu.
	 */
	public function add_admin_page() {
		$this->hook_suffix = (string) add_submenu_page(
			'hp_settings',
			esc_html__( 'Email Studio', 'email-studio-for-hivepress' ),
			esc_html__( 'Email Studio', 'email-studio-for-hivepress' ),
			'manage_options',
			'hp_email_studio',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Checks whether the screen being rendered is the email edit screen.
	 *
	 * @return bool
	 */
	protected function is_email_edit_screen() {
		$screen = get_current_screen();

		return $screen && 'hp_email' === $screen->post_type && in_array( $screen->base, [ 'post' ], true );
	}

	/**
	 * Loads the screen's assets, on the Studio screen and the email edit screen only.
	 *
	 * @param string $hook_suffix Current screen's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$is_studio = $this->hook_suffix && $hook_suffix === $this->hook_suffix;
		$is_edit   = in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) && $this->is_email_edit_screen();

		if ( ! $is_studio && ! $is_edit ) {
			return;
		}

		$url  = plugin_dir_url( HP_EMAIL_STUDIO_FILE );
		$path = plugin_dir_path( HP_EMAIL_STUDIO_FILE );

		// The file time rides along so browser and page caches refresh whenever the file changes,
		// not only on version bumps.
		wp_enqueue_style(
			'hp-email-studio-admin',
			$url . 'assets/css/admin.css',
			[],
			HP_EMAIL_STUDIO_VERSION . '.' . (int) filemtime( $path . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'hp-email-studio-admin',
			$url . 'assets/js/admin.js',
			[],
			HP_EMAIL_STUDIO_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin.js' ),
			true
		);

		wp_localize_script( 'hp-email-studio-admin', 'hpEmailStudio', $this->get_script_data() );

		if ( $is_studio ) {

			// The design controls live on this screen now, so their pickers come with it.
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );

			wp_enqueue_script(
				'hp-email-studio-settings',
				$url . 'assets/js/admin-settings.js',
				[ 'jquery', 'wp-color-picker' ],
				HP_EMAIL_STUDIO_VERSION . '.' . (int) filemtime( $path . 'assets/js/admin-settings.js' ),
				true
			);

			wp_localize_script(
				'hp-email-studio-settings',
				'hpEmailStudioSettings',
				[
					'chooseLogo' => esc_html__( 'Choose logo image', 'email-studio-for-hivepress' ),
					'useImage'   => esc_html__( 'Use this image', 'email-studio-for-hivepress' ),
					'chooseText' => esc_html__( 'Choose image', 'email-studio-for-hivepress' ),
				]
			);
		}
	}

	/**
	 * Gets the data the script needs.
	 *
	 * Every string here is passed through `__()` rather than `esc_html__()`. `wp_localize_script()`
	 * JSON-encodes what it is given, which is the correct escaping for a JavaScript context, and
	 * HTML-escaping first is not merely redundant - it is visible: the confirmation that names an
	 * email read `Switching off &quot;Email Verification&quot;` in the browser's own dialog, because
	 * `window.confirm()` prints its argument literally and has no HTML to decode. Anything these
	 * strings reach in the DOM is written with textContent.
	 *
	 * @return array
	 */
	protected function get_script_data() {
		return [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE_ACTION ),

			// The saved WooCommerce layout, so the panel's switch starts on it.
			'wooLayout' => hivepress()->hpes_woo->get_layout(),

			'strings'   => [
				'testSending'      => __( 'Sending...', 'email-studio-for-hivepress' ),
				'genericError'     => __( 'Something went wrong. Please try again.', 'email-studio-for-hivepress' ),
				'confirmReset'     => __( 'Reset this email to the wording it came with? Your edited version moves to the Trash, so you can restore it from there if you change your mind.', 'email-studio-for-hivepress' ),

				/* translators: %s: the name of the email being disabled. */
				'confirmCritical'  => __( 'Disabling "%s" stops people completing something they have already started, and there is no other way for them to finish it. Are you sure?', 'email-studio-for-hivepress' ),

				'confirmClearLog'  => __( 'Clear the whole delivery log? This cannot be undone.', 'email-studio-for-hivepress' ),

				/* translators: %s: the number of people the message will go to. */
				'confirmSend'      => __( 'Send this email to %s people? It goes out in the background and cannot be recalled once it starts.', 'email-studio-for-hivepress' ),

				'confirmSendOne'   => __( 'Send this email to 1 person? It goes out in the background and cannot be recalled once it starts.', 'email-studio-for-hivepress' ),

				'composeMissing'   => __( 'Add a subject and a message before sending.', 'email-studio-for-hivepress' ),
				'audienceEmpty'    => __( 'Nobody matches that audience, so there is nothing to send.', 'email-studio-for-hivepress' ),
				'counting'         => __( 'Counting...', 'email-studio-for-hivepress' ),

				/* translators: %s: the number of people in the chosen audience. */
				'audienceCount'    => __( 'This will go to %s people.', 'email-studio-for-hivepress' ),

				'audienceCountOne' => __( 'This will go to 1 person.', 'email-studio-for-hivepress' ),

				'sortAscending'    => __( 'Sorted A to Z. Click to reverse.', 'email-studio-for-hivepress' ),
				'sortDescending'   => __( 'Sorted Z to A. Click to reverse.', 'email-studio-for-hivepress' ),

				'jumpTo'           => __( 'Jump to a section:', 'email-studio-for-hivepress' ),
				'save'             => __( 'Save Changes', 'email-studio-for-hivepress' ),
				'backToTop'        => __( 'Back to top', 'email-studio-for-hivepress' ),
			],
		];
	}

	/**
	 * Renders the Email Studio screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$emails  = hivepress()->hpes_catalogue->get_emails();
		$summary = hivepress()->hpes_catalogue->get_summary();
		$sources = hivepress()->hpes_catalogue->get_sources();

		?>
		<div class="wrap hpes">
			<div class="hpes__header">
				<div>
					<h1 class="hpes__title"><?php esc_html_e( 'Email Studio', 'email-studio-for-hivepress' ); ?></h1>
					<p class="hpes__subtitle"><?php esc_html_e( 'Every email your site can send, with a preview of exactly what lands in the inbox. Emails added by extensions you install later appear here on their own.', 'email-studio-for-hivepress' ); ?></p>
				</div>
			</div>

			<?php settings_errors(); ?>

			<div class="hpes__stats">
				<?php
				$this->render_stat( 'email-alt', esc_html__( 'Emails available', 'email-studio-for-hivepress' ), (string) $summary['total'] );
				$this->render_stat( 'edit', esc_html__( 'Customised by you', 'email-studio-for-hivepress' ), (string) $summary['customised'] );
				$this->render_stat( 'hidden', esc_html__( 'Disabled', 'email-studio-for-hivepress' ), (string) $summary['disabled'] );
				$this->render_stat(
					'art',
					esc_html__( 'Design', 'email-studio-for-hivepress' ),
					hivepress()->hpes_design->is_enabled() ? esc_html__( 'On', 'email-studio-for-hivepress' ) : esc_html__( 'Off', 'email-studio-for-hivepress' )
				);
				?>
			</div>

			<?php
			$this->render_email_section( $emails, $sources );
			$this->render_compose_section();
			$this->render_design_section();
			$this->render_log_section();
			?>
		</div>

		<?php $this->render_preview_panel(); ?>
		<?php
	}

	/**
	 * Renders one figure in the header strip.
	 *
	 * @param string $icon Dashicon name, without its prefix.
	 * @param string $label Figure label.
	 * @param string $value Figure value.
	 */
	protected function render_stat( $icon, $label, $value ) {
		?>
		<div class="hpes__stat">
			<span class="hpes__stat-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="hpes__stat-body">
				<span class="hpes__stat-value"><?php echo esc_html( $value ); ?></span>
				<span class="hpes__stat-label"><?php echo esc_html( $label ); ?></span>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders the list of emails.
	 *
	 * @param array $emails Emails.
	 * @param array $sources Distinct sources.
	 */
	protected function render_email_section( $emails, $sources ) {
		?>
		<section class="hpes__section" id="hpes-section-emails">
			<h2><?php esc_html_e( 'Emails', 'email-studio-for-hivepress' ); ?></h2>

			<?php if ( ! $emails ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No editable emails were found. This usually means HivePress has only just been installed; add a listing type or activate an extension and they will appear here.', 'email-studio-for-hivepress' ); ?></p></div>
			<?php else : ?>

				<div class="hpes__toolbar">
					<label class="hpes__search">
						<span class="screen-reader-text"><?php esc_html_e( 'Search emails', 'email-studio-for-hivepress' ); ?></span>
						<input type="search" id="hpes-search" placeholder="<?php esc_attr_e( 'Search emails...', 'email-studio-for-hivepress' ); ?>" />
					</label>

					<label class="hpes__filter">
						<span class="screen-reader-text"><?php esc_html_e( 'Filter by plugin', 'email-studio-for-hivepress' ); ?></span>
						<select id="hpes-source">
							<option value=""><?php esc_html_e( 'All plugins', 'email-studio-for-hivepress' ); ?></option>
							<?php foreach ( $sources as $source ) : ?>
								<option value="<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="hpes__filter">
						<span class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'email-studio-for-hivepress' ); ?></span>
						<select id="hpes-status">
							<option value=""><?php esc_html_e( 'All statuses', 'email-studio-for-hivepress' ); ?></option>
							<option value="customised"><?php esc_html_e( 'Customised', 'email-studio-for-hivepress' ); ?></option>
							<option value="default"><?php esc_html_e( 'Original wording', 'email-studio-for-hivepress' ); ?></option>
							<option value="disabled"><?php esc_html_e( 'Disabled', 'email-studio-for-hivepress' ); ?></option>
						</select>
					</label>
				</div>

				<?php
				/*
				 * The table scrolls inside its own box rather than letting the page scroll
				 * sideways. Measured at 390px before this wrapper existed: the columns need more
				 * width than a phone has, so the whole admin page scrolled and the wp-admin chrome
				 * went with it.
				 */
				?>
				<?php
				/*
				 * The four data columns sort; Actions does not, because there is nothing to order it
				 * by. Sorting is done in the browser over rows that are all already on the page, so
				 * it costs no request and works alongside the search and the filters.
				 */
				?>
				<div class="hpes__table-wrap">
					<table class="hpes__table widefat striped">
						<thead>
							<tr>
								<th scope="col" class="hpes__sortable" data-sort="label" aria-sort="none"><button type="button" class="hpes__sort-button"><?php esc_html_e( 'Email', 'email-studio-for-hivepress' ); ?><span class="hpes__sort-arrow" aria-hidden="true"></span></button></th>
								<th scope="col" class="hpes__sortable" data-sort="source" aria-sort="none"><button type="button" class="hpes__sort-button"><?php esc_html_e( 'Plugin', 'email-studio-for-hivepress' ); ?><span class="hpes__sort-arrow" aria-hidden="true"></span></button></th>
								<th scope="col" class="hpes__sortable" data-sort="recipient" aria-sort="none"><button type="button" class="hpes__sort-button"><?php esc_html_e( 'Goes to', 'email-studio-for-hivepress' ); ?><span class="hpes__sort-arrow" aria-hidden="true"></span></button></th>
								<th scope="col" class="hpes__sortable" data-sort="status" aria-sort="none"><button type="button" class="hpes__sort-button"><?php esc_html_e( 'Status', 'email-studio-for-hivepress' ); ?><span class="hpes__sort-arrow" aria-hidden="true"></span></button></th>
								<th scope="col" class="hpes__actions-heading"><?php esc_html_e( 'Actions', 'email-studio-for-hivepress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $emails as $email ) : ?>
								<?php $this->render_row( $email ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="hpes__empty" id="hpes-empty" hidden><?php esc_html_e( 'No emails match what you typed.', 'email-studio-for-hivepress' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders one email's row.
	 *
	 * @param array $email Email details.
	 */
	protected function render_row( $email ) {
		$status = 'default';

		if ( $email['disabled'] ) {
			$status = 'disabled';
		} elseif ( $email['customised'] ) {
			$status = 'customised';
		}

		// Everything the search and the filters read is carried on the row itself, so filtering is
		// instant and needs no request. The searchable text is built here rather than in script, so
		// a translated label is searchable in the language it is displayed in.
		$haystack = strtolower( $email['label'] . ' ' . $email['description'] . ' ' . $email['subject'] . ' ' . $email['source'] . ' ' . $email['name'] );

		$is_woo = ! empty( $email['woocommerce'] );

		// An email switched off by having its wording cleared cannot be switched back on from here,
		// because only the missing wording can do that.
		$locked_off = 'empty' === hp\get_array_value( $email, 'disabled_by' );

		?>
		<?php
		/*
		 * Every value the table sorts on is carried on the row. The status sort key is prefixed with
		 * a digit so the three states come out in a deliberate order - disabled first, because
		 * "show me everything that is switched off" is the reason somebody sorts this column - and
		 * not in the alphabetical order of whatever those states happen to be called in the reader's
		 * language.
		 */
		$sort_status = [
			'disabled'   => '1',
			'customised' => '2',
			'default'    => '3',
		];
		?>
		<tr class="hpes__row<?php echo $email['disabled'] ? ' is-disabled' : ''; ?>"
			data-name="<?php echo esc_attr( $email['name'] ); ?>"
			data-woocommerce="<?php echo ! empty( $email['woocommerce'] ) ? '1' : '0'; ?>"
			data-source="<?php echo esc_attr( $email['source'] ); ?>"
			data-status="<?php echo esc_attr( $status ); ?>"
			data-sort-label="<?php echo esc_attr( $email['label'] ); ?>"
			data-sort-source="<?php echo esc_attr( $email['source'] ); ?>"
			data-sort-recipient="<?php echo esc_attr( $email['recipient'] ); ?>"
			data-sort-status="<?php echo esc_attr( hp\get_array_value( $sort_status, $status, '3' ) ); ?>"
			data-customised="<?php echo $email['customised'] ? '1' : '0'; ?>"
			data-critical="<?php echo $email['critical'] ? '1' : '0'; ?>"
			data-label="<?php echo esc_attr( $email['label'] ); ?>"
			data-search="<?php echo esc_attr( $haystack ); ?>">

			<td class="hpes__cell-email">
				<strong class="hpes__label"><?php echo esc_html( $email['label'] ); ?></strong>

				<?php
				/*
				 * Most emails ship a subject identical to their event name, so printing both put
				 * the same words on screen twice on most rows and read as a rendering fault. The
				 * subject earns its line only when it says something the label does not.
				 */
				?>
				<?php if ( $email['subject'] && $email['subject'] !== $email['label'] ) : ?>
					<span class="hpes__subject"><?php echo esc_html( $email['subject'] ); ?></span>
				<?php endif; ?>

				<?php if ( $email['description'] ) : ?>
					<span class="hpes__description"><?php echo esc_html( $email['description'] ); ?></span>
				<?php endif; ?>
			</td>

			<td class="hpes__cell-source"><?php echo esc_html( $email['source'] ); ?></td>

			<?php
			// The address a shop-side WooCommerce email is configured to reach, revealed on hover
			// rather than printed beside rows that name a role.
			$recipient_address = (string) hp\get_array_value( $email, 'recipient_address' );
			?>
			<td class="hpes__cell-recipient"<?php echo $recipient_address ? ' title="' . esc_attr( $recipient_address ) . '"' : ''; ?>>
				<?php echo esc_html( $email['recipient'] ? $email['recipient'] : esc_html__( 'Not stated', 'email-studio-for-hivepress' ) ); ?>
			</td>

			<td class="hpes__cell-status">
				<?php if ( $email['disabled'] ) : ?>
					<span class="hpes__badge hpes__badge--off"><?php esc_html_e( 'Disabled', 'email-studio-for-hivepress' ); ?></span>
				<?php elseif ( $email['customised'] ) : ?>
					<span class="hpes__badge hpes__badge--custom"><?php esc_html_e( 'Customised', 'email-studio-for-hivepress' ); ?></span>
				<?php else : ?>
					<span class="hpes__badge hpes__badge--default"><?php esc_html_e( 'Original wording', 'email-studio-for-hivepress' ); ?></span>
				<?php endif; ?>

				<?php if ( $email['critical'] ) : ?>
					<span class="hpes__badge hpes__badge--critical" title="<?php esc_attr_e( 'People cannot finish signing in or signing up without this one.', 'email-studio-for-hivepress' ); ?>"><?php esc_html_e( 'Essential', 'email-studio-for-hivepress' ); ?></span>
				<?php endif; ?>

				<?php if ( $locked_off ) : ?>
					<span class="hpes__error"><?php esc_html_e( 'Its wording is empty, so HivePress will not send it. Add wording back to enable it.', 'email-studio-for-hivepress' ); ?></span>
				<?php elseif ( $is_woo && $email['disabled'] ) : ?>
					<span class="hpes__error"><?php esc_html_e( 'Disabled in WooCommerce.', 'email-studio-for-hivepress' ); ?></span>
				<?php endif; ?>
			</td>

			<td class="hpes__cell-actions">
				<button type="button" class="button button-secondary hpes-preview" data-name="<?php echo esc_attr( $email['name'] ); ?>">
					<?php esc_html_e( 'Preview', 'email-studio-for-hivepress' ); ?>
				</button>

				<?php if ( $is_woo ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( $email['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'email-studio-for-hivepress' ); ?></a>
				<?php elseif ( $email['customised'] && $email['edit_url'] ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( $email['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'email-studio-for-hivepress' ); ?></a>
					<button type="button" class="button button-secondary hpes-reset" data-name="<?php echo esc_attr( $email['name'] ); ?>"><?php esc_html_e( 'Reset', 'email-studio-for-hivepress' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-secondary hpes-customise" data-name="<?php echo esc_attr( $email['name'] ); ?>"><?php esc_html_e( 'Edit', 'email-studio-for-hivepress' ); ?></button>
				<?php endif; ?>

				<?php if ( ! $locked_off ) : ?>
					<button type="button" class="button button-secondary hpes-toggle" data-name="<?php echo esc_attr( $email['name'] ); ?>" data-disabled="<?php echo $email['disabled'] ? '1' : '0'; ?>">
						<?php echo $email['disabled'] ? esc_html__( 'Enable', 'email-studio-for-hivepress' ) : esc_html__( 'Disable', 'email-studio-for-hivepress' ); ?>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the composer.
	 */
	protected function render_compose_section() {
		$compose = hivepress()->hpes_compose;

		?>
		<section class="hpes__section" id="hpes-section-compose">
			<h2><?php esc_html_e( 'Email Composer', 'email-studio-for-hivepress' ); ?></h2>

			<p class="hpes__section-note"><?php esc_html_e( 'Write a one-off email and send it to your members, wrapped in the same design as everything else. It goes out in the background a batch at a time, so a large list never holds up your site. It cannot be recalled once it starts, so preview it and send yourself a test first.', 'email-studio-for-hivepress' ); ?></p>

			<div class="hpes-compose">
				<p class="hpes-compose__row">
					<label class="hpes-compose__label" for="hpes-compose-audience"><?php esc_html_e( 'Send to', 'email-studio-for-hivepress' ); ?></label>
					<select id="hpes-compose-audience">
						<?php foreach ( $compose->get_audiences() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="hpes-compose__count" id="hpes-compose-count" role="status" aria-live="polite"></span>
				</p>

				<p class="hpes-compose__row hpes-compose__row--users" id="hpes-compose-users-row" hidden>
					<label class="hpes-compose__label" for="hpes-compose-users"><?php esc_html_e( 'People', 'email-studio-for-hivepress' ); ?></label>
					<?php $this->render_user_picker(); ?>
				</p>

				<p class="hpes-compose__row">
					<label class="hpes-compose__label" for="hpes-compose-subject"><?php esc_html_e( 'Subject', 'email-studio-for-hivepress' ); ?></label>
					<input type="text" id="hpes-compose-subject" class="regular-text" maxlength="200" />
				</p>

				<?php
				// No visible label: a full editor directly under the Subject box is unmistakably the
				// message, and the label only pushed the two apart. The editor still names itself for
				// a screen reader through wp_editor's own labelling.
				?>
				<div class="hpes-compose__editor">
					<?php
					/*
					 * The id has no dashes on purpose: wp_editor() uses it as a JavaScript
					 * identifier for the TinyMCE instance and warns that dashes are not supported.
					 */
					wp_editor(
						'',
						'hpescomposebody',
						[
							'textarea_rows' => 10,
							'media_buttons' => false,
							'teeny'         => true,
						]
					);
					?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: the user name token, 2: the site name token, 3: the year token. All three are filled in automatically and must be left exactly as they appear. */
							esc_html__( 'You can use %1$s for the person receiving it, plus %2$s and %3$s.', 'email-studio-for-hivepress' ),
							'<code>%user_name%</code>',
							'<code>%site_name%</code>',
							'<code>%year%</code>'
						);
						?>
					</p>
				</div>

				<p class="hpes-compose__actions">
					<button type="button" class="button button-secondary hpes-compose-preview"><?php esc_html_e( 'Preview', 'email-studio-for-hivepress' ); ?></button>
					<button type="button" class="button button-secondary hpes-compose-test"><?php esc_html_e( 'Send test to me', 'email-studio-for-hivepress' ); ?></button>
					<button type="button" class="button button-primary hpes-compose-send"><?php esc_html_e( 'Send to everyone chosen', 'email-studio-for-hivepress' ); ?></button>
					<span class="hpes-compose__result" role="status" aria-live="polite"></span>
				</p>
			</div>

			<?php $this->render_campaigns(); ?>
		</section>
		<?php
	}

	/**
	 * Renders the picker for the "specific people" audience.
	 *
	 * Uses HivePress's own user select, which is the same searchable control its settings screens
	 * use, so a site with thousands of members gets a search box rather than a list of thousands.
	 */
	protected function render_user_picker() {
		$field = hp\create_class_instance(
			'\HivePress\Fields\Select',
			[
				[
					'name'       => 'hpes_compose_users',
					'options'    => 'users',
					'multiple'   => true,
					'attributes' => [ 'id' => 'hpes-compose-users' ],
				],
			]
		);

		if ( $field ) {
			echo $field->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the field escapes its own control.
		}
	}

	/**
	 * Renders the record of broadcasts already sent.
	 */
	protected function render_campaigns() {
		$campaigns = hivepress()->hpes_compose->get_campaigns();

		if ( ! $campaigns ) {
			return;
		}

		?>
		<h3><?php esc_html_e( 'Messages you have sent', 'email-studio-for-hivepress' ); ?></h3>

		<div class="hpes__table-wrap">
			<table class="hpes__table hpes__log-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'email-studio-for-hivepress' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'email-studio-for-hivepress' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Progress', 'email-studio-for-hivepress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $campaigns as $campaign ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_time( (int) hp\get_array_value( $campaign, 'created' ) ) ); ?></td>
							<td><?php echo esc_html( (string) hp\get_array_value( $campaign, 'subject' ) ); ?></td>
							<td>
								<?php if ( 'sending' === hp\get_array_value( $campaign, 'status' ) ) : ?>
									<span class="hpes__badge hpes__badge--default"><?php esc_html_e( 'Sending', 'email-studio-for-hivepress' ); ?></span>
								<?php else : ?>
									<span class="hpes__badge hpes__badge--sent"><?php esc_html_e( 'Finished', 'email-studio-for-hivepress' ); ?></span>
								<?php endif; ?>

								<span class="hpes__error">
									<?php
									printf(
										/* translators: 1: emails sent so far, 2: the total, 3: how many failed. */
										esc_html__( '%1$s of %2$s sent, %3$s failed', 'email-studio-for-hivepress' ),
										esc_html( (string) (int) hp\get_array_value( $campaign, 'sent' ) ),
										esc_html( (string) (int) hp\get_array_value( $campaign, 'total' ) ),
										esc_html( (string) (int) hp\get_array_value( $campaign, 'failed' ) )
									);
									?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the design settings.
	 */
	protected function render_design_section() {
		?>
		<section class="hpes__section" id="hpes-section-design">
			<h2><?php esc_html_e( 'Design', 'email-studio-for-hivepress' ); ?></h2>

			<form method="post" action="options.php" class="hpes-design-form hp-form hp-form--table">
				<?php
				settings_fields( Hpes_Settings::GROUP );
				do_settings_sections( Hpes_Settings::GROUP );
				submit_button();
				?>
			</form>
		</section>
		<?php
	}

	/**
	 * Renders the delivery log.
	 */
	protected function render_log_section() {
		$delivery = hivepress()->hpes_delivery;

		if ( ! $delivery->is_log_enabled() ) {
			return;
		}

		$log = array_reverse( $delivery->get_log() );

		?>
		<?php
		/*
		 * The heading is a DIRECT child of the section. It used to sit inside a flex wrapper next to
		 * the Clear log button, and the anchor nav builds itself from `.hpes__section > h2` - so this
		 * section was the one the nav could not see, and the quick links silently listed three
		 * sections out of four.
		 */
		?>
		<section class="hpes__section" id="hpes-section-log">
			<h2><?php esc_html_e( 'Recent deliveries', 'email-studio-for-hivepress' ); ?></h2>

			<?php if ( $log ) : ?>
				<p class="hpes__log-actions">
					<button type="button" class="button button-secondary hpes-clear-log"><?php esc_html_e( 'Clear log', 'email-studio-for-hivepress' ); ?></button>
				</p>
			<?php endif; ?>

			<?php if ( ! $log ) : ?>
				<p class="hpes__log-empty"><?php esc_html_e( 'Nothing sent yet. Emails your site sends from now on are listed here, so you can tell "it never sent" apart from "it sent and did not arrive".', 'email-studio-for-hivepress' ); ?></p>
			<?php else : ?>
				<div class="hpes__table-wrap">
					<table class="hpes__table hpes__log-table widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'email-studio-for-hivepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'email-studio-for-hivepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'To', 'email-studio-for-hivepress' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'email-studio-for-hivepress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $entry ) : ?>
								<?php $this->render_log_row( $entry ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Formats a timestamp in the site's own date and time format.
	 *
	 * @param int $time Timestamp.
	 * @return string
	 */
	protected function format_time( $time ) {
		if ( ! $time ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Renders one delivery log row.
	 *
	 * @param array $entry Log entry.
	 */
	protected function render_log_row( $entry ) {
		$status = (string) hp\get_array_value( $entry, 'status' );
		$error  = (string) hp\get_array_value( $entry, 'error' );

		$label   = (string) hp\get_array_value( $entry, 'label' );
		$subject = (string) hp\get_array_value( $entry, 'subject' );

		// Same reason as the list above: an email whose subject repeats its name would otherwise
		// print the words twice in one cell.
		if ( $subject === $label ) {
			$subject = '';
		}

		?>
		<tr>
			<td><?php echo esc_html( $this->format_time( (int) hp\get_array_value( $entry, 'time' ) ) ); ?></td>

			<td>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( hp\get_array_value( $entry, 'test' ) ) : ?>
					<span class="hpes__badge hpes__badge--test"><?php esc_html_e( 'Test', 'email-studio-for-hivepress' ); ?></span>
				<?php endif; ?>
				<?php if ( $subject ) : ?>
					<span class="hpes__subject"><?php echo esc_html( $subject ); ?></span>
				<?php endif; ?>
			</td>

			<td><?php echo esc_html( (string) hp\get_array_value( $entry, 'to' ) ); ?></td>

			<td>
				<?php if ( 'failed' === $status ) : ?>
					<span class="hpes__badge hpes__badge--failed"><?php esc_html_e( 'Failed', 'email-studio-for-hivepress' ); ?></span>
					<?php if ( $error ) : ?>
						<span class="hpes__error"><?php echo esc_html( $error ); ?></span>
					<?php endif; ?>
				<?php elseif ( 'blocked' === $status ) : ?>
					<span class="hpes__badge hpes__badge--off"><?php esc_html_e( 'Stopped', 'email-studio-for-hivepress' ); ?></span>
					<span class="hpes__error"><?php esc_html_e( 'This email is disabled in the Studio.', 'email-studio-for-hivepress' ); ?></span>
				<?php else : ?>
					<span class="hpes__badge hpes__badge--sent"><?php esc_html_e( 'Sent', 'email-studio-for-hivepress' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the slide-in preview panel.
	 *
	 * The panel is markup rather than something the script builds, so every string in it goes
	 * through the POT and can be reworded in Loco Translate like the rest of the plugin.
	 */
	public function render_preview_panel() {
		?>
		<div class="hpes-panel" id="hpes-panel" hidden>
			<div class="hpes-panel__backdrop" data-hpes-close></div>

			<div class="hpes-panel__dialog" role="dialog" aria-modal="true" aria-labelledby="hpes-panel-title">
				<div class="hpes-panel__head">
					<div>
						<h2 class="hpes-panel__title" id="hpes-panel-title"></h2>
						<p class="hpes-panel__subject"></p>
					</div>

					<button type="button" class="button-link hpes-panel__close" data-hpes-close aria-label="<?php esc_attr_e( 'Close preview', 'email-studio-for-hivepress' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div class="hpes-panel__controls">
					<div class="hpes-panel__group" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'email-studio-for-hivepress' ); ?>">
						<button type="button" class="button hpes-device is-active" data-device="desktop"><?php esc_html_e( 'Desktop', 'email-studio-for-hivepress' ); ?></button>
						<button type="button" class="button hpes-device" data-device="mobile"><?php esc_html_e( 'Mobile', 'email-studio-for-hivepress' ); ?></button>
					</div>

					<div class="hpes-panel__group hpes-panel__versions" role="group" aria-label="<?php esc_attr_e( 'Which version to preview', 'email-studio-for-hivepress' ); ?>" hidden>
						<button type="button" class="button hpes-version is-active" data-default="0"><?php esc_html_e( 'Your version', 'email-studio-for-hivepress' ); ?></button>
						<button type="button" class="button hpes-version" data-default="1"><?php esc_html_e( 'Original', 'email-studio-for-hivepress' ); ?></button>
					</div>

					<?php
					// The layout switch, for WooCommerce emails only. It changes the preview, never
					// the setting: the active button starts on whatever the setting says.
					?>
					<div class="hpes-panel__group hpes-panel__layouts" role="group" aria-label="<?php esc_attr_e( 'Layout to preview', 'email-studio-for-hivepress' ); ?>" hidden>
						<?php foreach ( hivepress()->hpes_woo->get_layouts() as $hpes_layout => $hpes_layout_label ) : ?>
							<button type="button" class="button hpes-layout" data-layout="<?php echo esc_attr( $hpes_layout ); ?>"><?php echo esc_html( $hpes_layout_label ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="hpes-panel__stage">
					<iframe class="hpes-panel__frame" title="<?php esc_attr_e( 'Email preview', 'email-studio-for-hivepress' ); ?>" sandbox="allow-same-origin" src="about:blank"></iframe>
				</div>

				<details class="hpes-panel__tokens">
					<summary><?php esc_html_e( 'Tokens you can use in this email', 'email-studio-for-hivepress' ); ?></summary>
					<div class="hpes-panel__tokens-body"></div>
				</details>

				<div class="hpes-panel__foot">
					<p class="hpes-panel__note"><?php esc_html_e( 'Shown with sample details in place of the real ones. A test send goes to the address below with "[Test]" added to the subject.', 'email-studio-for-hivepress' ); ?></p>

					<div class="hpes-panel__send">
						<label for="hpes-test-address" class="screen-reader-text"><?php esc_html_e( 'Send a test to', 'email-studio-for-hivepress' ); ?></label>
						<input type="email" id="hpes-test-address" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" placeholder="<?php esc_attr_e( 'you@example.com', 'email-studio-for-hivepress' ); ?>" />
						<button type="button" class="button button-primary hpes-send-test"><?php esc_html_e( 'Send test', 'email-studio-for-hivepress' ); ?></button>
					</div>

					<p class="hpes-panel__result" role="status" aria-live="polite"></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds a Preview button to the Publish box on the email edit screen.
	 *
	 * @param object $post Post object.
	 */
	public function render_edit_preview_button( $post ) {
		if ( ! $post || 'hp_email' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$name = (string) $post->post_name;

		if ( ! $name || ! hivepress()->hpes_catalogue->get_email( $name ) ) {
			return;
		}

		?>
		<div class="misc-pub-section hpes-edit-preview">
			<button type="button" class="button button-secondary hpes-preview" data-name="<?php echo esc_attr( $name ); ?>" data-label="<?php echo esc_attr( get_the_title( $post ) ); ?>">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				<?php esc_html_e( 'Preview this email', 'email-studio-for-hivepress' ); ?>
			</button>
			<p class="description"><?php esc_html_e( 'Save your changes first to see them here.', 'email-studio-for-hivepress' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the preview panel on the email edit screen.
	 */
	public function render_edit_panel() {
		if ( ! is_admin() || ! $this->is_email_edit_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_preview_panel();
	}

	/**
	 * Checks the request is allowed, or stops it.
	 *
	 * A nonce proves the request came from our screen and a capability check proves the person is
	 * allowed to act on it. Neither substitutes for the other, so both run on every endpoint.
	 *
	 * @param bool $is_get Read the nonce from the query string?
	 */
	protected function verify_request( $is_get = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( $is_get ) {
				wp_die( esc_html__( 'You are not allowed to preview emails.', 'email-studio-for-hivepress' ), '', [ 'response' => 403 ] );
			}

			wp_send_json_error( [ 'message' => esc_html__( 'You are not allowed to do that.', 'email-studio-for-hivepress' ) ], 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, $is_get ? '_wpnonce' : 'nonce' );
	}

	/**
	 * Reads the email name from the request, checking it names a real email.
	 *
	 * The raw value is matched against the real list of emails and the answer is the key from that
	 * list, never the request's own string. Sanitising an identifier and then trusting the result is
	 * how a value that was never valid gets treated as if it were. Anything that does not resolve is
	 * refused here and answered with a 404 by every caller, rather than falling through to a default.
	 *
	 * @return string
	 */
	protected function get_requested_name() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_request() runs first on every caller.
		$raw = isset( $_REQUEST['name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['name'] ) ) : '';

		if ( ! $raw ) {
			return '';
		}

		foreach ( array_keys( hivepress()->hpes_catalogue->get_emails() ) as $name ) {
			if ( (string) $name === $raw ) {

				// Safe: it came from the allow-list, not from the request.
				return (string) $name;
			}
		}

		return '';
	}

	/**
	 * Renders an email preview for the panel's iframe.
	 */
	public function ajax_preview() {
		$this->verify_request( true );

		$name = $this->get_requested_name();

		if ( ! $name ) {
			wp_die( esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ), '', [ 'response' => 404 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_request() ran above.
		$show_default = ! empty( $_GET['default'] );

		// The layout switch in the panel, for WooCommerce emails. Validated by the renderer against
		// the list of layouts, so an unknown value previews the saved one.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_request() ran above.
		$layout = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : '';

		$output = hivepress()->hpes_catalogue->render_preview( $name, $show_default, $layout );

		$this->send_preview( $output );
	}

	/**
	 * Sends a rendered preview document and stops.
	 *
	 * @param string $output Rendered email.
	 */
	protected function send_preview( $output ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );

			// The preview is an administrator's own email template rendered for their eyes only.
			// It must never be framed by another site or indexed.
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		// The email document is echoed exactly as wp_mail() would receive it, because showing
		// anything else would defeat the purpose of a preview. It is rendered into a sandboxed
		// iframe that cannot run scripts (see render_preview_panel()).
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Returns the list of tokens an email can use.
	 */
	public function ajax_tokens() {
		$this->verify_request();

		$name = $this->get_requested_name();

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		// WooCommerce emails use WooCommerce's own placeholders rather than HivePress tokens, and
		// listing HivePress's here would be advertising something that will not work.
		if ( hivepress()->hpes_woo->is_woo_email( $name ) ) {
			wp_send_json_success( [ 'html' => '<p>' . esc_html__( 'This is a WooCommerce email, so it uses WooCommerce\'s own placeholders. You will find them on its settings screen.', 'email-studio-for-hivepress' ) . '</p>' ] );
		}

		$email_class = hp\get_array_value( hivepress()->get_classes( 'emails' ), $name );

		if ( ! $email_class ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		wp_send_json_success( [ 'html' => hivepress()->hpes_tokens->render_token_list( $email_class ) ] );
	}

	/**
	 * Sends a test email.
	 */
	public function ajax_test() {
		$this->verify_request();

		$name = $this->get_requested_name();

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		$address = $this->get_requested_address();

		if ( ! $address ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That does not look like an email address.', 'email-studio-for-hivepress' ) ], 400 );
		}

		if ( hivepress()->hpes_woo->is_woo_email( $name ) ) {
			$sent = hivepress()->hpes_woo->send_test( $name, $address );
		} else {
			$sent = $this->send_hivepress_test( $name, $address );
		}

		if ( ! $sent ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'Your site could not send the email. This is almost always the site\'s mail setup rather than the email itself, so an SMTP plugin is the usual fix.', 'email-studio-for-hivepress' ),
				],
				500
			);
		}

		wp_send_json_success(
			[
				/* translators: %s: the address the test was sent to. */
				'message' => sprintf( esc_html__( 'Test sent to %s.', 'email-studio-for-hivepress' ), $address ),
			]
		);
	}

	/**
	 * Reads and checks the address a test should go to.
	 *
	 * @return string
	 */
	protected function get_requested_address() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$address = isset( $_POST['address'] ) ? sanitize_email( wp_unslash( $_POST['address'] ) ) : '';

		return is_email( $address ) ? $address : '';
	}

	/**
	 * Sends a test of a HivePress email.
	 *
	 * @param string $name Email name.
	 * @param string $address Address.
	 * @return bool
	 */
	protected function send_hivepress_test( $name, $address ) {
		$catalogue = hivepress()->hpes_catalogue;
		$delivery  = hivepress()->hpes_delivery;

		$email_class = hp\get_array_value( hivepress()->get_classes( 'emails' ), $name );

		if ( ! $email_class ) {
			return false;
		}

		$email = $catalogue->create_email(
			$name,
			[
				'recipient' => $address,
				'tokens'    => $catalogue->get_sample_tokens( $email_class ),
			]
		);

		if ( ! $email ) {
			return false;
		}

		$this->prefix_test_subject( $email->get_subject() );

		$delivery->set_testing( true );

		try {
			$sent = $email->send();
		} finally {
			$delivery->set_testing( false );
		}

		return (bool) $sent;
	}

	/**
	 * Marks the next matching message as a test.
	 *
	 * The subject is prefixed so a test can never be mistaken for the real thing sitting in the same
	 * inbox - these emails say things like "your booking is confirmed", and one arriving unannounced
	 * during a test is a support ticket waiting to happen. Everything else about the message is left
	 * exactly as it would really send.
	 *
	 * @param string $subject Subject to match.
	 */
	protected function prefix_test_subject( $subject ) {
		add_filter(
			'wp_mail',
			function( $args ) use ( $subject ) {
				if ( isset( $args['subject'] ) && $args['subject'] === $subject ) {
					/* translators: %s: the email's own subject line. */
					$args['subject'] = sprintf( esc_html__( '[Test] %s', 'email-studio-for-hivepress' ), $args['subject'] );
				}

				return $args;
			},
			100
		);
	}

	/**
	 * Switches an email on or off.
	 */
	public function ajax_toggle() {
		$this->verify_request();

		$name = $this->get_requested_name();

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$disabled = ! empty( $_POST['disabled'] );

		if ( hivepress()->hpes_woo->is_woo_email( $name ) ) {
			hivepress()->hpes_woo->set_disabled( $name, $disabled );
		} else {
			hivepress()->hpes_delivery->set_disabled( $name, $disabled );
		}

		wp_send_json_success(
			[
				'disabled' => $disabled,
				'message'  => $disabled ? esc_html__( 'Disabled.', 'email-studio-for-hivepress' ) : esc_html__( 'Enabled.', 'email-studio-for-hivepress' ),
			]
		);
	}

	/**
	 * Resets an email to the wording it shipped with.
	 */
	public function ajax_reset() {
		$this->verify_request();

		$name = $this->get_requested_name();

		if ( ! $name || hivepress()->hpes_woo->is_woo_email( $name ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		$post = $this->get_custom_post( $name );

		if ( ! $post ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email is already using its original wording.', 'email-studio-for-hivepress' ) ], 404 );
		}

		/*
		 * Trashed rather than deleted, for two reasons. An owner who reset by mistake can restore
		 * their wording from the Trash, and trashing renames the slug to "{name}__trashed"
		 * (`wp-includes/post.php:4903`, WordPress 7.1), which frees the original slug so the email
		 * can be customised again later without WordPress appending "-2" to it and leaving a
		 * customisation HivePress would never find.
		 */
		if ( ! wp_trash_post( $post->ID ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be reset.', 'email-studio-for-hivepress' ) ], 500 );
		}

		wp_send_json_success( [ 'message' => esc_html__( 'Reset to the original wording.', 'email-studio-for-hivepress' ) ] );
	}

	/**
	 * Creates a customisation for an email and returns where to edit it.
	 *
	 * HivePress's own route to this is Add Email followed by picking the event from a dropdown,
	 * which means knowing that an email has to be created before it can be edited. Seeding the post
	 * with the wording the email already sends turns that into one button.
	 */
	public function ajax_customise() {
		$this->verify_request();

		$name = $this->get_requested_name();

		if ( ! $name || hivepress()->hpes_woo->is_woo_email( $name ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be found.', 'email-studio-for-hivepress' ) ], 404 );
		}

		$existing = $this->get_custom_post( $name );

		if ( $existing ) {
			wp_send_json_success( [ 'url' => (string) get_edit_post_link( $existing->ID, 'raw' ) ] );
		}

		// Built with "default" so the new post starts from the wording HivePress ships, not from
		// another customisation.
		$email = hivepress()->hpes_catalogue->create_email( $name, [ 'default' => true ] );

		if ( ! $email ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be built.', 'email-studio-for-hivepress' ) ], 500 );
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'hp_email',
				'post_status'  => 'publish',
				'post_name'    => $name,
				'post_title'   => (string) $email->get_subject(),
				'post_content' => (string) $email->get_body(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be created.', 'email-studio-for-hivepress' ) ], 500 );
		}

		/*
		 * WordPress appends "-2" to a slug another post of the same type already holds, and
		 * HivePress finds a customisation by exact slug (`components/class-email.php:63-73`) - so a
		 * suffixed slug is a post the site will never read, which would look like an edit screen
		 * that saves and changes nothing.
		 */
		$saved = get_post( $post_id );

		if ( $saved && $saved->post_name !== $name ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_update_post() would run the slug through wp_unique_post_slug() again and undo this.
			$wpdb->update( $wpdb->posts, [ 'post_name' => $name ], [ 'ID' => $post_id ] );

			clean_post_cache( $post_id );
		}

		wp_send_json_success( [ 'url' => (string) get_edit_post_link( $post_id, 'raw' ) ] );
	}

	/**
	 * Empties the delivery log.
	 */
	public function ajax_clear_log() {
		$this->verify_request();

		hivepress()->hpes_delivery->clear_log();

		wp_send_json_success( [ 'message' => esc_html__( 'Log cleared.', 'email-studio-for-hivepress' ) ] );
	}

	/**
	 * Counts the audience currently chosen in the composer.
	 */
	public function ajax_compose_count() {
		$this->verify_request();

		$args = $this->get_compose_args();

		wp_send_json_success( [ 'count' => hivepress()->hpes_compose->count_audience( $args['audience'], $args['user_ids'] ) ] );
	}

	/**
	 * Previews a composed message.
	 */
	public function ajax_compose_preview() {
		$this->verify_request( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_request() ran above.
		$subject = isset( $_GET['subject'] ) ? sanitize_text_field( wp_unslash( $_GET['subject'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_request() ran above.
		$body = isset( $_GET['body'] ) ? wp_kses_post( wp_unslash( $_GET['body'] ) ) : '';

		$this->send_preview( hivepress()->hpes_compose->render_preview( $subject, $body ) );
	}

	/**
	 * Reads the composer's fields from the request.
	 *
	 * @return array
	 */
	protected function get_compose_args() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$audience = isset( $_POST['audience'] ) ? sanitize_key( wp_unslash( $_POST['audience'] ) ) : 'all';

		if ( ! array_key_exists( $audience, hivepress()->hpes_compose->get_audiences() ) ) {
			$audience = 'all';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['user_ids'] ) ) : [];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';

		/*
		 * The body keeps its markup: it is written in a rich-text editor by an administrator, and
		 * wp_kses_post() is the same filter WordPress applies to any post they could publish.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() checked the nonce and the capability before this runs.
		$body = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		return [
			'audience' => $audience,
			'user_ids' => array_values( array_filter( $user_ids ) ),
			'subject'  => $subject,
			'body'     => $body,
		];
	}

	/**
	 * Sends the composer's message to the current user only.
	 */
	public function ajax_compose_test() {
		$this->verify_request();

		$args = $this->get_compose_args();

		if ( ! $args['subject'] || ! trim( wp_strip_all_tags( $args['body'] ) ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Add a subject and a message before sending.', 'email-studio-for-hivepress' ) ], 400 );
		}

		$user = wp_get_current_user();

		$email = hp\create_class_instance(
			'\HivePress\Emails\Hpes_Broadcast',
			[
				[
					'recipient' => $user->user_email,
					'subject'   => $args['subject'],
					'body'      => $args['body'],
					'tokens'    => hivepress()->hpes_compose->get_tokens( $user ),
				],
			]
		);

		if ( ! $email ) {
			wp_send_json_error( [ 'message' => esc_html__( 'That email could not be built.', 'email-studio-for-hivepress' ) ], 500 );
		}

		$this->prefix_test_subject( $email->get_subject() );

		hivepress()->hpes_delivery->set_testing( true );

		try {
			$sent = $email->send();
		} finally {
			hivepress()->hpes_delivery->set_testing( false );
		}

		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Your site could not send the email. An SMTP plugin is the usual fix.', 'email-studio-for-hivepress' ) ], 500 );
		}

		wp_send_json_success(
			[
				/* translators: %s: the address the test was sent to. */
				'message' => sprintf( esc_html__( 'Test sent to %s.', 'email-studio-for-hivepress' ), $user->user_email ),
			]
		);
	}

	/**
	 * Queues the composer's message to its audience.
	 */
	public function ajax_compose_send() {
		$this->verify_request();

		$args = $this->get_compose_args();

		if ( ! $args['subject'] || ! trim( wp_strip_all_tags( $args['body'] ) ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Add a subject and a message before sending.', 'email-studio-for-hivepress' ) ], 400 );
		}

		$campaign = hivepress()->hpes_compose->queue( $args );

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Nobody matched that audience, so nothing was sent.', 'email-studio-for-hivepress' ) ], 400 );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: how many people the message is going to. */
					_n(
						'Queued for %s person. It sends in the background; reload this page to see how far it has got.',
						'Queued for %s people. It sends in the background; reload this page to see how far it has got.',
						(int) $campaign['total'],
						'email-studio-for-hivepress'
					),
					number_format_i18n( $campaign['total'] )
				),
			]
		);
	}

	/**
	 * Gets the customisation post for an email.
	 *
	 * @param string $name Email name.
	 * @return object|null
	 */
	protected function get_custom_post( $name ) {
		$posts = get_posts(
			[
				'name'             => $name,
				'post_type'        => 'hp_email',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'suppress_filters' => ! hivepress()->translator->is_multilingual(),
			]
		);

		return hp\get_first_array_value( $posts );
	}
}
