=== OmnaBase ===
Contributors: kriesi
Tags: support, helpdesk, tickets, woocommerce, bbpress
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to OmnaBase, the support inbox: forum topics, form messages and your customers' orders on every ticket.

== Description ==

OmnaBase is a shared support inbox for small teams. This plugin connects your WordPress site to your OmnaBase workspace in one approval, with no key to copy, and brings what your site knows into every ticket.

* **Customer data on every ticket.** When a customer writes in, your team sees what they bought on your site: their account, WooCommerce and Easy Digital Downloads orders, subscriptions and licences (WooCommerce Subscriptions, EDD Recurring, EDD Software Licensing, License Manager for WooCommerce). Every source stays off until you turn it on, and licence keys are always masked.
* **bbPress forums.** Topics in the forums you tick become tickets; your team's answers appear as forum replies under one support user, with a closing line you can word. Moderators' replies reach the ticket too.
* **A contact form.** Put `[omnabase_form]` on any page: messages become tickets, with your project's product list, licence field and privacy link. Bots and double clicks file nothing.
* **A message centre.** Put `[omnabase_messages]` on a page you pick: signed-in customers read and answer their tickets there, and see their earlier email conversations and forum topics once they confirm their address. A "Support" link in WooCommerce's My Account leads there if you want one.
* **Nothing gets lost.** What the site sends to OmnaBase waits on your site when OmnaBase cannot be reached and goes out once it is back; the Activity tab of Settings › OmnaBase shows what waits, with Send now, Retry and Discard.
* **Your theme's look.** The form and the message centre bring a small stylesheet in your theme's font; one switch leaves them to your theme alone.
* **Health at a glance.** One line on the Connection tab says whether the connection works both ways, and the screen warns about anything that would stop the plugin working, such as WordPress's timer turned off or an outdated shop plugin.

The plugin needs an OmnaBase workspace and a site with a public https address.

= Shortcodes =

* `[omnabase_form]` the contact form. `project="slug"` names one of your site's projects (needed when it has several); `subject="…"` fills in the subject.
* `[omnabase_messages]` the message centre, on the page picked on the Forms and messages tab of Settings › OmnaBase. `guest="form"` shows the contact form to visitors who are not signed in; `project="slug"` names the project new questions go to.

== Installation ==

1. Upload the plugin's zip under Plugins › Add New Plugin › Upload Plugin, and activate it.
2. Open Settings › OmnaBase and click Connect on the Connection tab. Approve the connection on OmnaBase: you come back connected.
3. Turn on what you need, each on its own tab: the sources on Customer data, the forums to send on Forums, the message centre's page on Forms and messages.
4. Put `[omnabase_form]` or `[omnabase_messages]` on a page.

Connect needs pretty permalinks (Settings › Permalinks, anything but "Plain"), https, and WordPress installed at the root of its domain.

== Frequently Asked Questions ==

= Does my site need https? =

Yes. OmnaBase delivers replies and asks for customer data over https on the standard port, so the site needs a public https address.

= Is anything sent before I connect? =

No. The plugin makes no call to OmnaBase until an administrator clicks Connect, and afterwards only what the features you turn on need.

= Which customer data does my team see? =

Only the sources you tick on the Customer data tab of Settings › OmnaBase: the profile (registered since, roles and the user fields you pick), orders, subscriptions and licences. Passwords, tokens and keys are never sent, even when ticked.

= What happens when OmnaBase cannot be reached? =

Topics, replies, licence changes and form messages wait on your site and are sent once OmnaBase answers again; the Activity tab shows them. Nothing is dropped without you.

= My host turned WordPress's timer (WP-Cron) off. Does the plugin still work? =

Yes, as long as your host runs the timer from the server every minute instead. The Connection tab of Settings › OmnaBase warns while the timer is off.

= Can I use my theme's styles for the form? =

Yes. Tick "Use the theme's styles only" on the Forms and messages tab of Settings › OmnaBase, and the plugin loads no stylesheet of its own.

= What happens when I delete the plugin? =

It stops the site's key at OmnaBase and removes everything it stored on your site: its settings, what was waiting to be sent, its notes on users and posts, its scheduled jobs. Your tickets stay in OmnaBase.

= Someone disconnected my site in OmnaBase. What now? =

The Connection tab says "Disconnected in OmnaBase", and the site stops calling OmnaBase. Click Connect to connect it again; anything that was waiting goes out then.

== External services ==

This plugin connects to **OmnaBase** (https://app.omnabase.com), a support inbox run by Kriesi Media GmbH, Germany. Nothing is sent before you connect: the connection starts when an administrator clicks Connect and approves it on OmnaBase. From then on the plugin sends only what the features you turn on need:

* **Connect and health:** the site's address and name when you connect; Check connection asks OmnaBase who the key is and to call the site back.
* **Forum:** forum topics and replies in the forums you mark (title, text, the author's name and email address), when they are published.
* **Contact form:** form messages (name, email address, subject, message, and the product and licence when asked) when a visitor sends the form.
* **Message centre:** the signed-in customer's name, email address and an id for their account, each time they open the message centre; their new questions and answers when they send them.
* **Customer data:** when OmnaBase asks about a customer, their account, orders, subscriptions and licences from the sources you switch on; licence changes when they happen.

The site receives the replies and status changes of its own tickets from OmnaBase, OmnaBase's questions about a customer, and a test call when you check the connection. Each of these calls is signed by OmnaBase and checked by the plugin.

* Terms of service: https://app.omnabase.com/legal/terms
* Privacy policy: https://app.omnabase.com/legal/privacy

== Privacy ==

OmnaBase processes the data above on your behalf, as your processor. The plugin suggests a paragraph for your privacy policy under Settings › Privacy › Policy Guide, naming OmnaBase and what each feature sends.

On your site the plugin stores, per customer: the id OmnaBase knows them by, whether OmnaBase knows them, and for the message centre a fingerprint of the name and address last sent, the times confirmation links were asked for, and the words of a question that could not be sent (for an hour). WordPress's Tools › Export Personal Data and Erase Personal Data include all of it. Forum topics keep their ticket numbers.

OmnaBase keeps its own copy of support conversations. To export or delete what OmnaBase holds about a customer, use the workspace's tools in OmnaBase.

== Changelog ==

= 0.1.0 =

* Added: Settings › OmnaBase, the plugin's settings screen, showing whether the site is connected to OmnaBase.
* Added: Connect from Settings › OmnaBase: approve the site once on OmnaBase and it is connected, with no key to copy; the screen names the workspace, the project, what the site may do and the key's last four characters.
* Added: Disconnect stops the site's key at OmnaBase and forgets it; Check connection tests both directions, the site calling OmnaBase and OmnaBase calling the site.
* Added: The site's key and secrets are stored encrypted; if the site's secret keys change, every admin page says "OmnaBase: reconnect needed" until you connect again.
* Added: The site receives OmnaBase's signed calls and turns away anything unsigned; the screen lists the last 20 calls received, without their content.
* Added: Customer data on your tickets: choose under Settings › OmnaBase what your team sees when a customer writes in (account, chosen user fields, WooCommerce and Easy Digital Downloads orders, subscriptions and licences); everything stays off until you turn it on.
* Added: Licence keys are always shown masked, and user fields that look like passwords, tokens or keys are never sent, even when ticked.
* Added: Licence changes (renewals, refunds, expiries, activations) reach OmnaBase on their own, also after an outage; the new outbox on the settings screen shows anything waiting or needing your attention, with Retry and Discard.
* Added: Works with WooCommerce's newer order storage (HPOS).
* Added: bbPress forums: topics in the forums you tick become OmnaBase tickets, also on a busy day or during an outage; topics in hidden forums are marked not public.
* Added: Forum replies by the topic's author and by moderators reach the ticket; your team's answers appear on the forum under the support user you choose, with a closing line you can word.
* Added: Optionally close a forum topic when its ticket is resolved.
* Added: A contact form by shortcode: put [omnabase_form] on any page and visitors' messages become tickets in OmnaBase, with your project's products, licence field and privacy link; bots and double clicks file nothing, and messages sent during an outage arrive once OmnaBase is back.
* Added: A message centre for signed-in customers: put [omnabase_messages] on the page you pick under Settings › OmnaBase, and customers read their tickets, answer them and ask new questions there; their earlier email conversations and forum topics show once they confirm their address through a link we mail them.
* Added: The message centre's page is never cached, also when a page builder made it, and [omnabase_messages guest="form"] gives visitors who are not signed in the contact form instead of a login link.
* Added: When a customer changes their email address in WordPress, OmnaBase learns the new address and the message centre asks them to confirm it again.
* Added: Settings › OmnaBase has a Shortcodes section that explains both shortcodes and each of their options.
* Added: WooCommerce's My Account menu can show a "Support" link to the message centre (a switch under Settings › OmnaBase, off until you turn it on).
* Added: A "theme styles only" switch: the contact form and the message centre then follow your theme's look alone.
* Added: A suggested privacy-policy text under Settings › Privacy names OmnaBase and what each feature sends; WordPress's tools to export and erase personal data now include what the plugin stores.
* Added: "Send now" on the outbox sends a waiting call at once instead of at its next try.
* Added: Health shows when OmnaBase last asked your site for customer data, and warns when WP-Cron is turned off on your site.
* Added: The customer-data settings say when a shop plugin is older than the version the plugin supports.
* Added: When a new question in the message centre cannot be sent, the customer finds their words still in the form.
* Added: Ready for translation: every text on screen can be translated, with a translation template included.
* Added: Deleting the plugin also stops the site's key at OmnaBase.
