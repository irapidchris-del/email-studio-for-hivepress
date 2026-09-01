<?php
/**
 * Broadcast email.
 *
 * The message the composer sends. It is a real HivePress email class rather than a bare `wp_mail()`
 * call, which is what makes a composed message go through the identical pipeline as every other
 * email on the site: the same `hivepress/v1/emails/email` filter, the same design wrapper, the same
 * token replacement and the same delivery-log entry. Nothing about a broadcast is a special case
 * downstream of here.
 *
 * **It deliberately declares no `label`.** A truthy label is what makes an email appear on the Email
 * Studio list and become editable as an `hp_email` post (`components/class-email.php:60`). This one
 * has no fixed wording to edit - the subject and body come from whatever the owner typed into the
 * composer - so listing it would offer an edit screen that nothing reads.
 *
 * @package HivePress\EmailStudio\Emails
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to a chosen audience from the Email Studio composer.
 */
class Hpes_Broadcast extends Email {

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
