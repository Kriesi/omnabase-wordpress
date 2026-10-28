=== OmnaBase Connector ===
Contributors: kriesi
Tags: support, helpdesk, tickets, woocommerce, bbpress
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to OmnaBase, the support inbox: forum topics, form messages and your customers' orders on every ticket.

== Description ==

OmnaBase is a shared support inbox for small teams. This plugin connects your WordPress site to your OmnaBase workspace in one approval, with no key to copy, and brings what your site knows into every ticket.

* **Customer data on every ticket.** When a customer writes in, your team sees what they bought on your site: their account, WooCommerce and Easy Digital Downloads orders, subscriptions and licences (WooCommerce Subscriptions, EDD Recurring, EDD Software Licensing, License Manager for WooCommerce). Every source stays off until you turn it on, and licence keys are always masked.
* **bbPress forums.** Topics in the forums you tick become tickets; each team member's answer appears under their own forum account when they are a moderator there with the same email address, else under the support user you choose, with a closing line you can word. Moderators' replies reach the ticket too.
* **Private data and other post fields.** Name a topic's or reply's extra fields (post meta, such as a forum's "Private data" box) under Post fields: they travel with the message to OmnaBase, and your team can fill the same field on its forum reply.
* **Your forms from OmnaBase.** Build forms in OmnaBase and put `[omnabase_form id="…"]` on any page: messages become tickets. Signed-in customers find their details filled in, and your team sees them as verified.
* **A message centre.** Put `[omnabase_messages]` on a page you pick: signed-in customers read and answer their tickets there, and see their earlier email conversations and forum topics once they confirm their address. A "Support" link in WooCommerce's My Account leads there if you want one.
* **Nothing gets lost.** What the site sends to OmnaBase waits on your site when OmnaBase cannot be reached and goes out once it is back; the Activity tab of Settings › OmnaBase shows what waits, with Send now, Retry and Discard.
* **Light or dark, in your colour.** The form and the message centre look like the OmnaBase app, light, dark or following the visitor's device, in OmnaBase's blue or your own colour, in your theme's font or OmnaBase's; one switch leaves them to your theme alone.
* **Health at a glance.** One line on the Connection tab says whether the connection works both ways, and the screen warns about anything that would stop the plugin working, such as WordPress's timer turned off or an outdated shop plugin.

The plugin needs an OmnaBase workspace and a site with a public https address.

= Shortcodes =

* The easy way: click "Build a shortcode" on the Forms and messages tab of Settings › OmnaBase, choose what to show, and copy the shortcode, or click "Create the page for me" and the page is published for you.
* `[omnabase_form]` a contact form built in OmnaBase. `id="slug"` names the form (without it, the project's default form); `project="slug"` names one of your site's projects (needed when it has several); `subject="…"` fills in the form's subject field.
* `[omnabase_messages]` the message centre, on the page picked on the Forms and messages tab of Settings › OmnaBase. `guest="form"` shows the contact form to visitors who are not signed in; `project="slug"` names the project new questions go to.

== Installation ==

1. Upload the plugin's zip under Plugins › Add New Plugin › Upload Plugin, and activate it.
2. Open Settings › OmnaBase and click Connect on the Connection tab. Approve the connection on OmnaBase: you come back connected.
3. Turn on what you need, each on its own tab: the sources on Customer data, the forums to send on Forums, the message centre's page on Forms and messages.
4. Put `[omnabase_form]` or `[omnabase_messages]` on a page.

Connect needs pretty permalinks (Settings › Permalinks, anything but "Plain") and https on the standard port. WordPress may live in a subfolder, and each site of a multisite network connects on its own.

== Frequently Asked Questions ==

= Does my site need https? =

Yes. OmnaBase delivers replies and asks for customer data over https on the standard port, so the site needs a public https address.

= Does it work in a subfolder or on a multisite? =

Yes. A WordPress in a subfolder (such as example.com/blog) connects with that address. On a multisite network each site has its own connection: activate the plugin for the network or per site, then click Connect on each site that should use OmnaBase.

= Is anything sent before I connect? =

No. The plugin makes no call to OmnaBase until an administrator clicks Connect, and afterwards only what the features you turn on need.

= Which customer data does my team see? =

Only the sources you tick on the Customer data tab of Settings › OmnaBase: the profile (registered since, roles and the user fields you pick), orders, subscriptions and licences. Passwords, tokens and keys are never sent, even when ticked.

= What happens when OmnaBase cannot be reached? =

Topics, replies and licence changes wait on your site and are sent once OmnaBase answers again; the Activity tab shows them. Nothing is dropped without you. Forms are sent by the visitor's browser straight to OmnaBase.

= My host turned WordPress's timer (WP-Cron) off. Does the plugin still work? =

Yes, as long as your host runs the timer from the server every minute instead. The Connection tab of Settings › OmnaBase warns while the timer is off.

= How do I put the contact form or the message centre on a page? =

Click "Build a shortcode" on the Forms and messages tab of Settings › OmnaBase and choose what to show. Copy the shortcode into any page or post, or click "Create the page for me": the plugin publishes the page, and a new message centre page becomes the picked one.

= Can I use my theme's styles for the form? =

Yes. Turn on "Theme styles only" on the Appearance tab of Settings › OmnaBase, and the plugin loads no stylesheet or font of its own.

= Can the form and the message centre be dark, or in my colours? =

Yes. On the Appearance tab of Settings › OmnaBase choose Light, Dark or "Follow the visitor's system", OmnaBase's blue or your own colour, and your theme's font or OmnaBase's. Pages kept by a cache show the new look once the cache is cleared.

= Which font does the plugin bring? =

Plus Jakarta Sans, under the SIL Open Font License 1.1 (the licence ships beside the font files in assets/fonts). It loads from your own site, never from a font service, and only when you choose "Load OmnaBase's font".

= How does a forum's "Private data" box reach my team? =

Under Settings › OmnaBase › Forums, Post fields, add a row: the Field is the name the box's value is saved under (for example `_av_supp_private_info`), the Name is what your team reads ("Private data"). The value then shows as a small table under the customer's message in OmnaBase, and a "Private data" button under the reply box writes your team's value into the same field of the forum reply. At most 10 fields; a key is lower-case letters, digits, `_` and `-`; bbPress's, WordPress's and the plugin's own keys are refused. OmnaBase never writes a field you have not named.

= Can a developer add fields or keep them elsewhere? =

Yes, with three hooks (named moments where a plugin's code runs):

* `omnabase_forum_fields` (filter): the declared fields as an array key ⇒ name; add yours, under the same rules as the Post fields rows.
* `omnabase_forum_field_value( $value, $key, $post_id )` (filter): the value sent for a field of a topic or reply; return it from wherever you keep it.
* `omnabase_forum_field_saved( $reply_id, $key, $value )` (action): runs after the team's value was saved as the forum reply's post meta, for example to store it elsewhere too.

= Whose name does a team answer appear under on the forum? =

Under the team member's own forum account when the site has a user with their OmnaBase sign-in email address who is a forum moderator (keymasters are too). Otherwise, and for every answer the AI sends, under the support user chosen under Settings › OmnaBase › Forums. The closing line names the team member either way.

A developer can choose another poster with the filter `omnabase_forum_reply_author( $user_id, $message, $topic )`: it gets the poster chosen so far, OmnaBase's message and the topic, and returns a user id. A user without moderator rights is replaced by the support user.

= What happens when I delete the plugin? =

It stops the site's key at OmnaBase and removes everything it stored on your site: its settings, what was waiting to be sent, its notes on users and posts, its scheduled jobs and the key file that protects its stored connection. On a multisite network it does this on every site. Your tickets stay in OmnaBase.

= Someone disconnected my site in OmnaBase. What now? =

The Connection tab says "Disconnected in OmnaBase", and the site stops calling OmnaBase. Click Connect to connect it again; anything that was waiting goes out then. If you do not want to connect again, click "Forget this connection" to clear it from the site.

== External services ==

This plugin connects to **OmnaBase** (https://app.omnabase.com), a support inbox run by Kriesi Media GmbH, Germany. Nothing is sent before you connect: the connection starts when an administrator clicks Connect and approves it on OmnaBase. From then on the plugin sends only what the features you turn on need:

* **Connect and health:** the site's address and name when you connect; Check connection asks OmnaBase who the key is and to call the site back.
* **Forum:** forum topics and replies in the forums you mark (title, text, the author's name and email address), when they are published.
* **Contact form:** a page with `[omnabase_form]` loads OmnaBase's form script from app.omnabase.com in the visitor's browser, which sends the visitor's form messages straight to OmnaBase. For a signed-in customer the site asks OmnaBase for a short-lived note vouching for their email address, name, newest WooCommerce order number and licence key (when they hold exactly one).
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

= 0.2.0 =

* Added: Post fields under Settings › OmnaBase › Forums: a topic's or reply's private data (such as a "Private data" box) travels with the message to OmnaBase, and your team's private data from OmnaBase lands in the same field of its forum reply.
* Added: A screenshot your team pasted into a reply now shows in the message centre inside the reply, where they put it; forum replies say "[Picture: name]" in its place.
* The plugin is now called "OmnaBase Connector" in the plugins list and on its settings page; nothing else changes, an installed copy updates in place.

* Changed: Check connection says when Cloudflare's security rules stop OmnaBase from reaching your site, and which addresses to let through.
* Changed: The warning about WordPress's timer shows only when messages to OmnaBase are really waiting, not on every site that runs the timer from the server.
* Changed: The message centre asks a customer to confirm their email only when earlier conversations by email or in the forum wait behind it, and says so in plain words.
* Fixed: A member of your support team who opens the message centre page no longer reads "cannot be shown right now"; it says why the page is empty and links to the OmnaBase inbox.
* Added: An Appearance tab under Settings › OmnaBase: the contact form and the message centre in light, dark or following the visitor's device, in OmnaBase's blue or your own colour, in your theme's font or OmnaBase's.
* Changed: The contact form now shows a form you build in OmnaBase (Settings › Forms there): [omnabase_form id="…"] picks the form, a shortcode without one shows the project's default form, and the form wears your Appearance choices.
* Added: Signed-in customers find their email, name, newest WooCommerce order number and their licence (when they hold exactly one) already filled in, and your team sees those values as verified.
* Added: The Forms and messages tab lists your forms with their shortcodes and links to Settings › Forms in OmnaBase; "Build a shortcode" lets you pick one of your forms.
* Added: The contact form links your site's privacy policy page in a small line under the form, when the site has one.
* Removed: The old built-in contact form and its settings (products to pick, the licence field); a message it could not send before the update shows as needing attention under Activity and is then cleaned up.
* Changed: The message centre has a new look, the OmnaBase app's inbox: each conversation with its status in words and its date, your messages told apart from the team's, the answer box as in the app.
* Changed: The "Theme styles only" switch moved to the Appearance tab; it now also keeps OmnaBase's font away.
* Fixed: Error messages, notices and help text on the form and the message centre are easier to read, and keyboard users see a clear focus ring in both light and dark.
* Changed: The message centre shows each message as your team wrote it, with paragraphs, bold words, lists, tables, quotes, code and clickable links, never pictures or scripts.
* Changed: Team answers posted on the forum are cleaned more tightly: pictures, inline styles and class names from the message no longer reach the forum post.

= 0.1.0 =

* Added: Settings › OmnaBase, the plugin's settings screen, showing whether the site is connected to OmnaBase.
* Added: Connect from Settings › OmnaBase: approve the site once on OmnaBase and it is connected, with no key to copy; the screen names the workspace, the project, what the site may do and the key's last four characters.
* Added: Disconnect stops the site's key at OmnaBase and forgets it; Check connection tests both directions, the site calling OmnaBase and OmnaBase calling the site.
* Added: The site's key and secrets are stored encrypted; if the stored connection can no longer be read, every admin page says "OmnaBase: reconnect needed" and why, until you connect again.
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
