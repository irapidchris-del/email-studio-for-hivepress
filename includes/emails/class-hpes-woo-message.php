<?php
/**
 * WooCommerce message stand-in.
 *
 * The design wrapper is a template part that reads its subject, body and tokens from a HivePress
 * email object (templates/hpes-email/wrapper.php). A WooCommerce email is not one of those, so when
 * the owner asks for WooCommerce emails to wear the wrapper, the message WooCommerce built is
 * carried through the same render path inside this object: the wrapper sees a HivePress email and
 * asks it nothing it cannot answer.
 *
 * **It deliberately declares no `label`**, for the same reason as class-hpes-broadcast.php: a label
 * is what lists an email on the Email Studio screen and makes it editable, and there is nothing here
 * to edit. Nothing ever calls send() on it either; it exists to be rendered.
 *
 * @package HivePress\EmailStudio\Emails
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Carries a WooCommerce email body through the design wrapper.
 */
class Hpes_Woo_Message extends Email {

	/**
	 * Class constructor.
	 *
	 * @param array $args Email arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'subject' => '',
				'body'    => '',
			],
			$args
		);

		parent::__construct( $args );
	}
}
