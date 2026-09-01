=== Email Studio for HivePress ===

Contributors: ChrisB
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give HivePress emails a proper design, preview every one before it sends, send yourself test copies, and see what your site actually delivered.

== Description ==

HivePress sends its emails with no design at all. Whatever wording is in the editor is the entire
message: no logo, no header, no footer, nothing to say the email came from your site rather than
from nowhere in particular.

Email Studio fixes that, and adds the tools that were missing around it.

**One screen for every email your site can send.** HivePress spreads its emails across a plain list
with no preview and no way to try one. Email Studio puts them all in one place, grouped by the
extension that provides them, with a search box and filters. Emails added by an extension you
install next month appear on their own; nothing needs updating for them to show up.

**See exactly what arrives.** Preview any email at desktop or mobile width, rendered through the
very same code that builds the real message, with sample details filled in. If you have edited an
email you can flip between your version and the original to compare them.

**Send yourself a test.** One button sends the email you are looking at to any address, so you can
see it in a real inbox on a real phone. Test copies get "[Test]" added to the subject so they can
never be mistaken for the genuine article.

**A design applied to everything.** Pick one of six templates - from Clean and Minimal, which stay
out of the way, to Bold, Banner and Panel, which give the message a coloured header, show the subject
as a heading and turn a plain link into a button. Set your accent, background and text colours,
choose a logo from your Media Library and write a footer. Every email HivePress and its extensions
send is wrapped in it, including ones from extensions added later.

**It reads properly on a phone.** The message is laid out fluidly rather than pinned to a fixed
width, so it reflows on a narrow screen instead of being cut off at the edge, and Outlook still gets
the fixed-width frame it needs.

**Every token, including the ones HivePress hides.** HivePress lists the tokens you can drop into an
email, but its list leaves out every attribute stored as a dropdown or a checkbox, which is most of
the attributes site owners actually create. Those tokens work perfectly, they are simply never
mentioned. Email Studio lists them all, grouped, labelled with the names you gave them, and
click-to-copy.

**Switch individual emails off.** Some sites do not want every notification HivePress sends. Turn
off the ones you do not want, one at a time. Emails people need in order to finish signing in or
signing up are marked, and ask twice before they go.

**Write your own and send it to your members.** The composer sends a one-off email to everyone with
an account, to your vendors, to everyone except your vendors, or to people you pick by name, wrapped
in the same design as everything else. It goes out in the background a batch at a time, so a large
list never holds up your site, and you can preview it and send yourself a test first.

**WooCommerce emails too.** If you run WooCommerce, its emails are listed here alongside HivePress's
so one screen answers "what does this site send?". You can preview them, send yourself a test and
switch them on or off. Their appearance stays WooCommerce's own, because WooCommerce already has a
complete email template of its own and putting a second design on top of it would give you two.

**A delivery log.** A short record of what your site sent, to whom, and whether it went. It answers
the question every site owner eventually asks: did that email actually send, or is the problem at
the other end?

= Requirements =

HivePress must be installed and active. Email Studio works with HivePress core and every HivePress
extension, including ones it has never heard of.

== Frequently Asked Questions ==

= Will this change the wording of my emails? =

No. Wording stays exactly where HivePress keeps it, and this plugin never edits it for you. The
design wraps around your wording; the wording itself is yours.

= What happens to my emails if I remove the plugin? =

They go back to sending exactly as HivePress sends them, unstyled, with the wording you have set.
Nothing you have written is lost, because customised emails belong to HivePress rather than to this
plugin.

= Does the preview show real customer details? =

No. Previews use sample details, and where your site has real content the preview borrows a listing
title so the layout looks realistic. Passwords and reset links are never shown, even as samples.

= Can I use my listing attributes in an email? =

Yes, and this is the part HivePress does not tell you about. Any attribute you have created is
available as a token such as `%listing.condition%`. Open any email in the Studio, or the email's own
edit screen, and the full list is there with your attribute names beside each one.

= Why is an email I switched off still showing in the log? =

That is deliberate. When your site tries to send an email you have switched off, the log records it
as stopped, so you can see the plugin doing what you asked rather than wondering why nothing
arrived.

= My test email did not arrive. Is that a bug in this plugin? =

Almost certainly not. Sending is WordPress's job, and many hosts do not send mail reliably without
help. If a test never arrives, an SMTP plugin is the usual fix, and the delivery log will show
whether your site managed to hand the message over at all.

== Screenshots ==

1. Every email your site can send, grouped by source, with status at a glance.
2. Previewing an email at mobile width, with a test send underneath.
3. The design settings: template, colours, logo and footer, on the same screen as the previews.
4. The full token list, including the attributes HivePress leaves out.

== Changelog ==

= 1.3.2 =
* The button on Bold, Banner and Panel is now centred in the message rather than sitting against the
  left edge.

= 1.3.1 =
* New: a Header Bar Colour setting, for the templates that have a bar across the top - Bold, Banner
  and Panel. Panel's dark bar could not be changed at all before this. Leave it empty and each
  template keeps the colour it always had, so nothing changes unless you want it to.
* The site name and heading sitting on that bar now switch between white and dark text on their own,
  whichever reads better against the colour you chose - so a pale header no longer hides them.

= 1.3.0 =
* Three more design templates. Banner and Panel give the message a coloured or dark header, show the
  subject as a heading and turn a plain link in the message into a button; Minimal strips everything
  back. Clean, Boxed and Bold are unchanged.
* Fixed: emails did not reflow on a narrow screen. The message was laid out in a fixed-width table,
  which grows to fit its contents whatever its width says, so on a phone it was cut off at the right
  edge rather than shrinking. It is now fluid, with a fixed-width frame kept for Outlook.
* Removed: social links. Email programs cannot display icon fonts and a free plugin cannot ship other
  companies' logos, so the row could only ever be named buttons - not worth the settings it cost.
* Removed: the font and email width choices. Three near-identical font stacks and a width almost
  nobody moves are not decisions worth asking somebody to make.
* The counters at the top of the screen now use your own admin colour scheme's accent instead of a
  fixed blue.
* The email list's columns are given sensible widths, so a wide screen no longer leaves Plugin, Goes
  to and Status huddled against the actions.
* "Compose" is now "Email Composer".
* Fixed: paragraphs in an email body were lost. HivePress edits a body in a plain text box and the
  blank line between two paragraphs is only a newline, which HTML collapses to a space - so a
  three-paragraph message arrived as one unbroken block of text. Plain-text bodies now keep their
  paragraphs and line breaks. A body you have laid out in HTML yourself is left exactly as it is.
* Fixed: the settings could scroll sideways on a phone. WordPress only narrows some field types, so
  the logo URL field kept its desktop width and pushed the page past the screen edge.
* An email you have laid out in HTML yourself is now left completely alone - no button is put in and
  no paragraphs are added. On Banner, Bold and Panel a hand-built email that already had its own
  button was getting a second one, under a sentence telling the reader to paste a link that was no
  longer there. Emails using HivePress's original wording are unaffected.

= 1.2.1 =
* Fixed: changing the composer's audience twice quickly could leave the wrong number of recipients
  on screen, because two counts were in flight and the slower one won.

= 1.2.0 =
* New: click any column heading to sort the list, so every disabled email can be grouped together.
* New: an option to hand your colours, logo and footer to WooCommerce's own email template, so shop
  emails match the rest. Off by default, and it never writes to your WooCommerce settings.
* The "From" column is now called "Plugin", which is what it actually says.
* WooCommerce emails addressed to the shop now say "Site admin" instead of printing your own email
  address; the address is on the cell if you hover it.
* Logo Position is now a plain Left / Centre / Right choice.
* Fixed: the composer counted 0 recipients after picking people by name, because the picker announces
  a choice in a way the counter was not listening for. The confirmation now also re-counts before it
  asks, so the number in the question is always the number that will be sent to.
* Fixed: "1 people" now reads "1 person" everywhere it can.
* The Message label above the composer's editor has gone; the editor speaks for itself.

= 1.1.1 =
* Fixed: upgrading from 1.0.x removed the footer from your emails. Before 1.1.0 an empty Footer Text
  box meant "write the copyright line for me"; in 1.1.0 it means "no footer". Sites arriving from an
  older version now get the default wording written into the box, so nothing changes for them and
  they can see what it says.

= 1.1.0 =
* The whole plugin is now one screen. The design settings have moved off the HivePress settings tab
  and onto the Email Studio page, beside the previews they change, with quick links to each section,
  a floating Save button and a back-to-top button.
* New: a composer for sending your own email to everyone, to vendors, to everyone except vendors, or
  to people you pick. It sends in the background a batch at a time and can be previewed and tested
  first.
* New: WooCommerce emails are listed, previewed, tested and switched on or off from the same screen.
  Their design stays WooCommerce's own.
* New design options: text colour, font, email width and logo position.
* The footer box now shows its wording instead of looking empty, and understands %year%, %site_name%
  and any token the email itself offers, so a footer can greet the person receiving it. Line breaks
  are kept.
* An email whose wording you have cleared - HivePress's own way of stopping one - is now shown as
  disabled instead of active.
* A Preview button on the email edit screen, so checking a change no longer means changing screen.
* The plugin an email comes from now has its own column.
* Emails that ship without a description, all of them from HivePress Bookings, now have one.
* Token lists no longer repeat a label that only restates the token.
* Fixed: the confirmation shown when disabling an email displayed `&quot;` instead of quote marks.
* "Switch on/off" is now "Enable/Disable", and "Edit wording" is now "Edit".
* Icons on the counters at the top of the screen.

= 1.0.1 =
* The example in the token help text now uses a token from the email you are actually editing,
  rather than a fixed one that may not exist on your site.

= 1.0.0 =
* First release.
* Email Studio screen listing every email the site can send, discovered automatically from HivePress
  and every active extension.
* Live previews at desktop and mobile width, rendered through the same code path as a real send.
* Test sends to any address, with a "[Test]" subject prefix.
* Three email design templates, with accent colour, background colour, logo and footer.
* Complete token lists including taxonomy and checkbox attributes, which HivePress does not list.
* Per-email on/off switches, with a second confirmation on emails needed to sign in or sign up.
* One-click customising, which creates the email and opens it ready to edit.
* Reset to original wording, which moves your version to the Trash rather than deleting it.
* Delivery log recording what was sent, to whom, and whether it succeeded.
