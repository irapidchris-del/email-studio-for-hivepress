<?php
/**
 * Email design wrapper.
 *
 * Replaces core's templates/email/email-content.php (a bare body echo) with a complete HTML email
 * document. Tables and inline styles throughout, on purpose: email clients ignore stylesheets and
 * several strip <style> blocks entirely.
 *
 * **The layout is fluid, and it has to be.** An earlier version put the message in a table with
 * `width:600px;max-width:100%`, which looks like it shrinks on a narrow screen and does not: a table
 * grows to fit its contents whatever its width says, so the 600px card stayed 600px inside a 390px
 * viewport and was cut off at the right edge - measured in the preview panel on 2026-09-01, where
 * the body text and the footer were both clipped. A block element does honour `max-width`, so the
 * card is a `<div>` and the tables inside it are `width:100%`. Outlook on Windows ignores
 * `max-width` altogether, which is what the MSO conditional table is for: it gives only Outlook a
 * fixed frame and every other client the fluid one.
 *
 * Context arrives through the Part block, which extracts it into scope before including this file
 * (`hivepress/includes/blocks/class-part.php:56`).
 *
 * @var \HivePress\Emails\Email $email The email being rendered.
 *
 * @package HivePress\EmailStudio
 */

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpes_design = hivepress()->hpes_design->get_settings();

$hpes_site_name = get_bloginfo( 'name' );
$hpes_site_url  = home_url( '/' );
$hpes_subject   = (string) $email->get_subject();

// The one font stack every client on every platform already has. It was briefly a setting and was
// dropped: three near-identical choices is not a decision worth asking somebody to make.
$hpes_font = 'Helvetica,Arial,sans-serif';

// 600px is the width email clients are happiest with, and the only one worth offering. Kept as a
// string because it is only ever printed into an attribute, never used in arithmetic.
$hpes_width = '600';

$hpes_body = (string) $email->get_body();

/*
 * Whether this body is plain text, decided before anything is added to it.
 *
 * HivePress edits an email body in a plain textarea and core's template echoes it straight out, so
 * the blank line between two paragraphs is only a newline in the HTML - and HTML collapses that to
 * a space. Measured on 2026-09-01: a three-paragraph body rendered with no <p> and no <br> at all,
 * arriving as one unbroken block of text.
 *
 * Somebody who has hand-written a layout of their own has already decided their spacing, so a body
 * that already contains block-level markup is left exactly as it is. The test runs here, on the
 * original body, because the button below adds a <table> and would otherwise answer it for us.
 */
$hpes_is_plain = ! preg_match( '#<(?:p|div|table|ul|ol|h[1-6]|blockquote|section|article|figure|hr)[\s>/]#i', $hpes_body );

/*
 * Turn an automatically linked URL into a button, on the templates that ask for one.
 *
 * HivePress's own wording ends "click on the following link to view it: %url%", and make_clickable()
 * turns that bare URL into an anchor whose text IS its address. That exact shape - anchor text equal
 * to href - is what marks it as the call to action, and it is why a link somebody wrote themselves,
 * with real words for text, is left alone rather than being guessed at.
 *
 * Plain-text bodies only, for the same reason the paragraphs below are. Seen on staging on
 * 2026-09-01 against a hand-built email that already had its own "View Your Listing" button: the
 * sentence underneath it read "or by pasting the following link into your browser:", and turning
 * that link into a second button left the instruction describing something that was no longer
 * there. Somebody who has laid out their own email has already chosen their call to action.
 */
if ( ! empty( $hpes_design['button'] ) && $hpes_is_plain ) {
	$hpes_body = preg_replace_callback(
		'#<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>\s*\1\s*</a>#i',
		function ( $matches ) use ( $hpes_design, $hpes_font ) {
			return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0;"><tr><td bgcolor="' . esc_attr( $hpes_design['accent'] ) . '" style="border-radius:4px;">'
				. '<a href="' . esc_url( $matches[1] ) . '" style="display:inline-block;padding:12px 24px;font-family:' . esc_attr( $hpes_font ) . ';font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">'
				. esc_html__( 'View details', 'email-studio-for-hivepress' )
				. '</a></td></tr></table>';
		},
		$hpes_body,
		1
	);
}

/*
 * Put the paragraph breaks back, for a plain-text body only.
 *
 * Runs after the button so that wpautop() sees the finished markup: it treats a <table> as a block
 * and closes the paragraph around it rather than nesting one inside the other, which is why the
 * order matters.
 */
if ( $hpes_is_plain ) {
	$hpes_body = wpautop( $hpes_body );
}

// Give links still inside the body the accent colour. Only anchors without their own style
// attribute are touched, so anything deliberately styled in the email editor wins - including the
// button built above.
$hpes_body = preg_replace(
	'/<a\s(?![^>]*style=)/i',
	'<a style="color:' . esc_attr( $hpes_design['accent'] ) . ';" ',
	$hpes_body
);

/*
 * The footer takes the same tokens as the email body, plus two of its own. Running it through the
 * email's own token list is what lets a footer say "You are receiving this because you registered as
 * %user_name%" - those values are already resolved for this recipient.
 */
$hpes_footer = (string) $hpes_design['footer'];

if ( '' !== trim( $hpes_footer ) ) {
	$hpes_footer = hp\replace_tokens(
		array_merge(
			(array) $email->get_tokens(),
			[
				'year'      => wp_date( 'Y' ),
				'site_name' => $hpes_site_name,
			]
		),
		$hpes_footer
	);
}

$hpes_align = $hpes_design['logo_align'];

// The logo, or the site name as text where there is none. Both link home.
$hpes_on_dark = in_array( $hpes_design['header'], [ 'accent', 'dark' ], true );

$hpes_header_bg = '';

if ( in_array( $hpes_design['header'], [ 'accent', 'dark' ], true ) ) {
	$hpes_header_bg = $hpes_design['header_bg'];
}

/*
 * The ink to use on the header bar, chosen from the bar's own brightness rather than assumed.
 *
 * A bar used to be either the accent or a fixed dark slate, so white always worked. Now that the
 * colour is the owner's to pick, a pale one - a cream, a light grey, a pastel - would have put
 * white text on a near-white bar and hidden the site name completely. The coefficients are the
 * usual sRGB luminance weights; 60% is the point either side of which black or white is the
 * stronger contrast.
 */
$hpes_header_ink = '#ffffff';

if ( $hpes_header_bg && preg_match( '/^#([0-9a-f]{6})$/i', $hpes_header_bg, $hpes_rgb ) ) {
	$hpes_luma = (
		0.299 * hexdec( substr( $hpes_rgb[1], 0, 2 ) )
		+ 0.587 * hexdec( substr( $hpes_rgb[1], 2, 2 ) )
		+ 0.114 * hexdec( substr( $hpes_rgb[1], 4, 2 ) )
	) / 255;

	$hpes_header_ink = $hpes_luma > 0.6 ? '#1f2430' : '#ffffff';
}

if ( $hpes_design['logo'] ) {
	$hpes_logo = '<a href="' . esc_url( $hpes_site_url ) . '" style="text-decoration:none;">'
		. '<img src="' . esc_url( $hpes_design['logo'] ) . '" alt="' . esc_attr( $hpes_site_name ) . '" width="' . esc_attr( $hpes_design['logo_width'] ) . '" style="display:inline-block;width:' . esc_attr( $hpes_design['logo_width'] ) . 'px;max-width:100%;height:auto;border:0;" />'
		. '</a>';
} else {
	$hpes_logo_colour = $hpes_on_dark ? $hpes_header_ink : $hpes_design['accent'];

	$hpes_logo = '<a href="' . esc_url( $hpes_site_url ) . '" style="color:' . esc_attr( $hpes_logo_colour ) . ';font-family:' . esc_attr( $hpes_font ) . ';font-size:22px;font-weight:bold;text-decoration:none;">' . esc_html( $hpes_site_name ) . '</a>';
}

// Per-template card styling. Every value comes from a whitelisted template definition, so each
// branch here is reachable and nothing else is.
$hpes_card = 'background:#ffffff;';

switch ( $hpes_design['card'] ) {
	case 'accent-top':
		$hpes_card .= 'border-top:4px solid ' . esc_attr( $hpes_design['accent'] ) . ';';
		break;

	case 'rounded':
		$hpes_card .= 'border:1px solid #e3e6ea;border-radius:8px;';
		break;

	case 'accent-side':
		$hpes_card .= 'border-left:4px solid ' . esc_attr( $hpes_design['accent'] ) . ';border-radius:0 6px 6px 0;';
		break;

	case 'none':
		$hpes_card = 'background:' . esc_attr( $hpes_design['background'] ) . ';';
		break;
}

$hpes_header_style = $hpes_header_bg ? 'background:' . esc_attr( $hpes_header_bg ) . ';' : '';

if ( 'rounded' === $hpes_design['card'] ) {
	$hpes_header_style .= 'border-radius:8px 8px 0 0;';
}

$hpes_header_padding = $hpes_header_bg ? '24px' : '24px 24px 0 24px';

if ( 'center' === $hpes_align ) {
	$hpes_rule_margin = '16px auto 0 auto';
} elseif ( 'right' === $hpes_align ) {
	$hpes_rule_margin = '16px 0 0 auto';
} else {
	$hpes_rule_margin = '16px auto 0 0';
}

$hpes_footer_html = [
	'a'      => [
		'href'   => [],
		'target' => [],
	],
	'strong' => [],
	'em'     => [],
	'br'     => [],
];

?><!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $hpes_subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $hpes_design['background'] ); ?>;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php echo esc_attr( $hpes_design['background'] ); ?>" style="background:<?php echo esc_attr( $hpes_design['background'] ); ?>;">
		<tr>
			<td align="center" style="padding:32px 12px;">
				<!--[if mso]><table role="presentation" width="<?php echo esc_attr( $hpes_width ); ?>" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
				<div style="max-width:<?php echo esc_attr( $hpes_width ); ?>px;margin:0 auto;">

					<?php if ( 'none' !== $hpes_design['header'] ) : ?>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="<?php echo $hpes_header_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from whitelisted values, each escaped individually. ?>">
							<tr>
								<td style="padding:<?php echo esc_attr( $hpes_header_padding ); ?>;text-align:<?php echo esc_attr( $hpes_align ); ?>;">
									<?php echo $hpes_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from whitelisted values, each escaped individually. ?>

									<?php if ( 'rule' === $hpes_design['header'] ) : ?>
										<div style="width:48px;height:3px;background:<?php echo esc_attr( $hpes_design['accent'] ); ?>;margin:<?php echo esc_attr( $hpes_rule_margin ); ?>;font-size:0;line-height:0;">&nbsp;</div>
									<?php endif; ?>

									<?php if ( 'banner' === $hpes_design['heading'] && $hpes_subject ) : ?>
										<div style="margin:16px 0 0;font-family:<?php echo esc_attr( $hpes_font ); ?>;font-size:24px;line-height:1.3;font-weight:bold;color:<?php echo esc_attr( $hpes_header_ink ); ?>;">
											<?php echo esc_html( $hpes_subject ); ?>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					<?php endif; ?>

					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="<?php echo $hpes_card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from whitelisted values, each escaped individually. ?>">
						<tr>
							<td style="padding:24px;font-family:<?php echo esc_attr( $hpes_font ); ?>;font-size:15px;line-height:1.6;color:<?php echo esc_attr( $hpes_design['text'] ); ?>;text-align:left;">
								<?php if ( 'card' === $hpes_design['heading'] && $hpes_subject ) : ?>
									<div style="margin:0 0 16px;font-family:<?php echo esc_attr( $hpes_font ); ?>;font-size:20px;line-height:1.3;font-weight:bold;color:<?php echo esc_attr( $hpes_design['accent'] ); ?>;">
										<?php echo esc_html( $hpes_subject ); ?>
									</div>
								<?php endif; ?>

								<?php echo $hpes_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the admin-authored email body, echoed raw exactly as core's own email-content.php does. ?>
							</td>
						</tr>
					</table>

					<?php if ( '' !== trim( $hpes_footer ) ) : ?>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
							<tr>
								<td style="padding:20px 24px;font-family:<?php echo esc_attr( $hpes_font ); ?>;font-size:12px;line-height:1.6;color:#7a828c;text-align:center;">
									<?php
									// nl2br runs after wp_kses, so the line breaks an owner typed
									// survive into a context where a bare newline renders as nothing.
									echo nl2br( wp_kses( $hpes_footer, $hpes_footer_html ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() is the escaping here; nl2br only adds <br>.
									?>
								</td>
							</tr>
						</table>
					<?php endif; ?>
				</div>
				<!--[if mso]></td></tr></table><![endif]-->
			</td>
		</tr>
	</table>
</body>
</html>
