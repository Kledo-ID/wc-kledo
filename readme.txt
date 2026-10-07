=== Kledo ===
Contributors: kledo
Tags: Kledo, WooCommerce, Accounting
Requires at least: 5.3
Tested up to: 6.9
Stable tag: 1.8.0
Requires PHP: 7.4
Text Domain: wc-kledo
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce orders to Kledo: automatic sales orders and invoices, checked back in Kledo, with resend, sync and diagnostics tools.

== Description ==

Integrates WooCommerce with Kledo accounting software. Sales orders and invoices are sent to Kledo automatically when orders reach the right status, every send is checked back in Kledo, and the plugin gives you the tools to find and resend anything that did not arrive.

= Automatic sync =
* When an order moves to Processing, the plugin creates a Kledo sales order (if "Enable Create Order" is on in the Order tab).
* When an order is Completed, the plugin creates a Kledo invoice (if "Enable Create Invoice" is on in the Invoice tab). The sales order is sent first and the invoice is linked to it.
* An order changed straight to Completed, skipping Processing, gets its sales order first and its invoice once that sales order exists in Kledo. Turn off "Create Sales Order First When an Order Skips Processing" in the Invoice tab to send only the invoice for such orders.

= Every send is checked back in Kledo =
Kledo answers a send as soon as it has queued it, before it creates anything. The plugin therefore reads every sales order and invoice back from Kledo afterwards:
* Waiting for Kledo — accepted, not created yet; checked again automatically.
* In Kledo — found in Kledo, with its Kledo reference number.
* Failed in Kledo — accepted, but it never appeared in Kledo. It is not resent automatically (to avoid duplicates if Kledo is only slow); resend it from the order, the order list, or the Kledo Status tab.
* Send failed / Rejected by Kledo — the send itself failed or Kledo refused the data; the error says why.

= Order list =
* A "Kledo" column shows the sales order and the invoice of each order separately, with the Kledo reference number on hover.
* Filters "Kledo sales order" and "Kledo invoice" (Not sent, Waiting for Kledo, In Kledo, Failed) and an order date range (From–to; WooCommerce itself only filters by whole month).
* Bulk actions "Kledo: Send sales order", "Kledo: Send invoice" and "Kledo: Check status in Kledo" (up to 20 orders per click; anything already in Kledo is skipped).

= Kledo Status tab (WooCommerce > Kledo > Kledo Status) =
* Summary cards and tabs per Kledo status: Waiting for Kledo, In Kledo, Send failed, Rejected by Kledo, Failed in Kledo.
* Check status, Resend and Open order under each order number — Check status and Resend update the row without reloading the page.
* Filters by transaction type, order date range and order number; a "What do the statuses mean?" panel explains each status in plain words.

= Sync tab: orders that are not in Kledo yet =
* Sends Processing and Completed orders that are missing from Kledo — for example orders placed before the plugin was installed — 5 to 10 per minute. The next batch is only sent once Kledo has created the previous one.
* Never runs on its own after an install or update: the plugin only counts the orders of the last 30 days that are missing and shows a notice. You start it with "Process gradually", and can pause, resume or cancel it.
* Optional "Send Automatically Every Day" (off by default) sends the missed orders of the last few days every night.

= Diagnostics tab: why did an order not reach Kledo? =
* Pick the order from a searchable list — orders not in Kledo or failed come first, each with its status, date, total and Kledo status — or type its number, and run a diagnosis. Eight checks run one by one — store, connection, settings, the order, the data that would be sent, status in Kledo, sending history, scheduled tasks — and the most likely cause is explained in plain words with what to do.
* Optionally resend and record exactly what was sent and what Kledo answered.
* Download the report (text, or JSON for developers) and send it to Kledo. The API key is never included and customer data is partly hidden by default.
* "Record for 24 hours" logs every request to Kledo (source `wc-kledo-debug`) for problems that only happen now and then; it switches itself off.

= Retries =
* Failed sends are retried automatically, about 5 minutes after the first failure and then at growing intervals up to 8 hours, for up to two days. Data that Kledo rejects is not retried, since it would be rejected again.
* Retries and checks run on WordPress scheduled tasks (WP-Cron). If those cannot run on your host, overdue work is also processed when an administrator opens a wp-admin page.

= Invoice-to-sales-order linking (Invoice tab) =
* "Link Invoice to Sales Order" links each invoice to its Kledo sales order, so Kledo closes the sales order once every quantity has been invoiced. On by default.
* "Close Sales Order When Invoiced" asks Kledo to close the linked sales order immediately. On by default.

= API Key Status (Configure tab) =
* Shows the saved key's name, creation date, last use and expiry, with warnings 30 and 7 days before it runs out, and says plainly when Kledo rejects the key.
* When the key is about to expire or no longer works, the Configure tab shows the steps to replace it and a button that opens the API key page in Kledo, so syncing does not stop.
* The saved key is masked, showing only the company name and its last four characters.

= Quick access =
* A "Settings" link on the Plugins screen, and on WordPress 6.9 and later every tab can be opened from the command palette (Ctrl+K / Cmd+K) by typing "kledo".

= Guide & FAQ tab =
* Everything above, step by step, inside the plugin: create the API key, set up sales orders and invoices, check and send orders, the Kledo Status, Sync and Diagnostics tabs, and answers to the most common questions. Searchable, and every settings tab links to its part of the guide.

= Logging =
* Activity is written to the WooCommerce logger with source `wc-kledo` (WooCommerce > Status > Logs), and important outcomes are recorded as order notes.

= Tutorial =
[youtube https://www.youtube.com/watch?v=0AmD4Aja88c]

== Installation ==

1. Install the Kledo plugin either via the WordPress plugin directory, or by uploading the files to your web server (in the `/wp-content/plugins/` directory).
2. Activate the Kledo plugin through the 'Plugins' menu in WordPress.
3. Navigate to the 'Kledo' settings page to setup API credentials and connect your Kledo account.
4. Configure your invoices in 'Invoice' tab.

<object width="425" height="344"><param name="movie" value="http://www.youtube.com/v/0AmD4Aja88c&hl=en&fs=1&"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="http://www.youtube.com/v/0AmD4Aja88c&hl=en&fs=1&" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="425" height="344"></embed></object>

== Frequently Asked Questions ==

= An order is not in Kledo. What should I do? =
Open WooCommerce > Kledo > Diagnostics, enter the order number and click "Run diagnosis". It tells you the most likely cause and what to do. If it does not solve it, download the report and send it to Kledo support.

= What does "Failed in Kledo" mean? =
Kledo accepted the order but did not create it — the problem happened inside Kledo, and only Kledo's logs say why. The plugin does not resend it automatically, so a slow Kledo cannot cause duplicates. Resend it from the order, the order list, or the Kledo Status tab, and run a diagnosis if it fails again.

= How do I send orders that were placed before I installed the plugin? =
Use the Sync tab. It lists the Processing and Completed orders of the chosen period that are not in Kledo yet, and "Process gradually" sends them a few per minute. Orders already in Kledo are skipped.

= Does the plugin send old orders by itself after I install or update it? =
No. It only counts them and shows a notice. Nothing is sent until you press "Process gradually" in the Sync tab, or switch on "Send Automatically Every Day".

= What should I know before sending old orders? =
Each transaction is dated with its order date, so reports of earlier months in Kledo change, and invoices reduce warehouse stock on that date. Kledo currently does not refuse dates inside a period you have locked (closed books), so choose a date range that does not reach into one.

= Is customer data or my API key sent anywhere by the Diagnostics tab? =
No. The report is only stored in your store for 7 days, and only leaves it when you download it. The API key is never included, and the customer's name, email, phone and address are partly hidden unless you tick "Include full customer data in the report".

= What does "Link Invoice to Sales Order" do, and should I turn it off? =
When on (the default), every invoice sent to Kledo is linked to its Kledo sales order, so the sales order's invoiced quantities and closure follow that invoice. Turn it off only if you manage sales order closure separately in Kledo and do not want invoices from this store to affect it — an unlinked invoice never counts toward the sales order.

= What does "Close Sales Order When Invoiced" do? =
When on (the default), invoicing also asks Kledo to close the linked sales order immediately. Turning it off keeps the sales order open at the moment of invoicing, but Kledo still recalculates and closes it later if anything else changes the invoice or the order — this setting only affects the immediate close, not the eventual one.

= Where do I see whether Kledo actually closed the sales order? =
On the "Kledo" column on the WooCommerce order list, or by running the "Kledo: Check Kledo status now" order action on an individual order, which records the result as an order note.

= Why is my saved API key shown as a company name and a few characters instead of the full key? =
The Configure tab masks a saved key for display, showing only the company it belongs to and the key's last four characters. The masked value is never treated as a new key — save a real key to replace it.

= What do the API Key Status warnings on the Configure tab mean? =
The Configure tab reads how much life the saved key has left and warns 30 and 7 days before it expires. If Kledo has already rejected the key, or if Kledo's own automatic renewal replaced it with a different key, the tab says so directly instead of letting syncs fail silently.

= I don't see a "kledo" command in the WordPress command palette =
The command palette integration needs WordPress 6.9 or later — earlier versions only expose the palette inside the block and site editors — and the `manage_woocommerce` capability. Confirm both your WordPress version and your user role.

== Screenshots ==

1. Connect your WooCommerce with Kledo
2. Invoice plugin settings page
3. Order plugin settings page
4. Kledo Status tab: Kledo status of every sales order and invoice
5. Kledo status, actions and diagnosis on the order screen

== Changelog ==

= 1.8.0 =
* feat: check every sales order and invoice back in Kledo after sending it. Kledo answers a send as soon as it has queued it, so a transaction Kledo then failed to create used to show as sent forever; it now reads "Waiting for Kledo" until it is found, and "Failed in Kledo" if it never appears, which makes it resendable. Nothing is resent automatically, so a slow Kledo cannot cause duplicates
* feat: the "Kledo" column on the order list now shows the sales order and the invoice separately — Not sent, Waiting for Kledo, In Kledo, Send failed, Rejected by Kledo or Failed in Kledo — with the Kledo reference number on hover
* feat: two filters on the order list, "Kledo sales order" and "Kledo invoice", to list for example every Completed order whose invoice is not in Kledo yet
* feat: an order date range filter (From–to) on the order list; WooCommerce itself only filters by whole month, and a range replaces the month chosen there
* feat: bulk actions on the order list: "Kledo: Send sales order", "Kledo: Send invoice" and "Kledo: Check status in Kledo". Bulk sending skips anything already in Kledo or on its way there
* feat: rebuilt WooCommerce > Kledo > Transactions and renamed it Kledo Status, a name that says what it shows: summary cards, tabs per Kledo status, the Kledo reference and last check, a next step for every failed row, and a "What do the statuses mean?" panel. Five columns instead of ten, with Check status / Resend / Open order under the order number so they stay in view on narrow screens. Check status and Resend update the row in place without reloading the page. The date filter is a range. The list is now paginated by the database, so orders older than the 2,500 most recent ones no longer drop off it
* feat: new Sync tab that sends orders missing from Kledo — for example orders placed before the plugin was installed — 5 to 10 per minute, starting the next batch only once Kledo has created the previous one. Orders already in Kledo are skipped. It can be paused, resumed and cancelled, and pauses itself when Kledo has not finished a batch after 30 minutes. It never runs on its own after an install or update: the plugin only counts the orders of the last 30 days that are missing and shows a notice
* feat: optional "Send Automatically Every Day" on the Sync tab (off by default) that sends the missed orders of the last few days every night
* feat: a "Send all of them gradually with Sync" link on the order list when it is filtered to orders not sent or failed, since bulk actions stop at 20 orders a click
* feat: new "Create Sales Order First When an Order Skips Processing" setting on the Invoice tab. On by default, which keeps the existing behaviour: an order moved straight to Completed gets its sales order first and its invoice once that sales order exists in Kledo. Turn it off to send only the invoice for those orders, unlinked
* feat: the Kledo box on the order screen summarises where the sales order and invoice stand in Kledo, and "Check Kledo status now" now also confirms both exist
* feat: "Check status in Kledo" recognises a transaction that already exists in Kledo but was never recorded as sent, and marks it as sent so it is not created twice
* feat: new Diagnostics tab that works out why an order did not reach Kledo — eight checks run one by one and the likely cause is explained in plain words, with an optional resend that records exactly what was sent and what Kledo answered. The report can be downloaded (text or JSON) for Kledo support; the API key is never included and customer data is partly hidden by default. A "Record for 24 hours" switch logs every request to Kledo for problems that only happen now and then. Reachable from the Kledo Status tab, the order screen and the Support tab
* feat: when the API key is about to expire (from 30 days before) or Kledo has refused it, the Configure tab shows a banner with the steps and a "Create a new API key in Kledo" button that opens the API key page in Kledo; the admin notices on other pages carry the same link
* feat: new Guide & FAQ tab next to Support: a step-by-step guide from creating the API key to finding a missing order, 17 frequently asked questions, a search box and buttons to the related tabs; every settings tab links to its part of the guide
* feat: a count bubble on WooCommerce > Kledo in the admin menu, like the one on Orders, showing how many orders need checking: Processing or Completed orders of the last 30 days not sent to Kledo yet, plus orders whose sales order or invoice failed, was rejected or never appeared in Kledo. Shows "99+" above 99, and nothing at zero or while the integration is off or not connected. The Sync tab (not sent) and the Kledo Status tab (failed in Kledo) show their part of that number, and the bubbles follow the admin color scheme
* fix: clear the plugin's scheduled events when it is deactivated

= 1.7.4 =
Upgrading from 1.5.0 goes straight to this release. Versions 1.6.0 through 1.7.3 were never published — the source carried @since tags for them while the released version stayed at 1.5.0, so everything built under those numbers is listed here.

* fix: keep the API key alive. Kledo replaces a key that is close to expiring and returns the replacement with the response; the plugin ignored it and carried on with the key Kledo had just revoked, which made every sync fail at once and looked like the key had expired without warning. The replacement is now saved as soon as it arrives.
* feat: new "Link Invoice to Sales Order" setting deciding whether each invoice is linked to its Kledo sales order, and with it whether Kledo closes that sales order once every quantity has been invoiced (on by default, matching how Kledo already behaved)
* feat: new "Close Sales Order When Invoiced" setting to keep the sales order open at the moment of invoicing while still linking the invoice to it (on by default). Note that Kledo recalculates the sales order when the invoice or the order changes later, and closes it then
* feat: "API Key Status" on the Configure tab showing what Kledo knows about the saved key — its name, when it was created, when it was last used and when it expires, each in the store's own timezone — plus admin warnings 30 and 7 days before it runs out
* feat: say so on the Configure tab when Kledo's automatic renewal has replaced the saved key with one that no longer matches the key listed in Kledo, so managing it from Kledo stops quietly having no effect
* feat: hide the saved API key on the Configure tab, showing only the part that names the company and the last four characters, so the page no longer prints a working credential in full
* feat: say so plainly when Kledo refuses the stored API key, instead of leaving the shop to work out why nothing is syncing any more — and tell a key that has stopped working apart from an account that has lost access to the company, since only one of the two is fixed by pasting a new key
* feat: new "Kledo" column on the order list showing whether Kledo closed the sales order, is still working on it, needs a look, or was asked not to link the invoice at all
* feat: record a note on the order when Kledo reports its sales order closed, without changing the WooCommerce order status
* feat: order action "Check Kledo status now" to confirm the Kledo closure on demand
* feat: "Settings" link on the Plugins screen that opens WooCommerce > Kledo directly
* feat: each settings tab can be reached from the WordPress command palette (Ctrl+K / Cmd+K) by typing "kledo", on WordPress 6.9 and later where that palette works outside the editors
* fix: treat a payload Kledo rejects (HTTP 400) as a permanent failure instead of retrying it for two days
* fix: harden input sanitization across the admin screens, AJAX handlers and helpers
* refactor: move the plugin bootstrap out of kledo.php into a dedicated loader class
* chore: drive PHPCS from a Composer-managed project ruleset, and align the plugin with the WordPress Coding Standards
* chore: ship a wc-kledo.pot template and bring both translation catalogs back in sync with the source
* fix: correct the plugin version, which still read 1.5.0 while the code had already moved to 1.7.x

= 1.5.0 =
* feat: failed transaction retry queue with WP-Cron and admin fallback
* feat: Transactions admin screen (filters, manual/bulk retry, column preferences)
* feat: manual Kledo sync controls on order screens and order actions
* feat: WooCommerce logger integration for delivery and retry events
* security: admin AJAX and dismiss-notice hardening (nonce and capabilities)

= 1.4.1 =
* fix: update translations

= 1.4.0 =
* feat: use api key

= 1.3.1 =
* fix: cannot create new order when status changed to processing
* fix: some deprecation notice

= 1.3.0 =
* added: create new order on Kledo when order status is processing
* added: allowed to add multiple tags when creating transaction (invoice & order)
* added: support [WooCommerce Shipment Tracking](https://woocommerce.com/products/shipment-tracking/)
* fix: internationalization improvements
* tweak: display button when all credentials filled on save
* tweak: add default value on first plugin install

= 1.2.1 =
* update: plugin translation files

= 1.2.0 =
* added: compatibility with WooCommerce plugin HPOS
* fix: disable ssl verify
* fix: translation

= 1.1.5 =
* Added: Additional discount amount
* Tweak: Only process request token when http code 200

= 1.1.4 =
* Fix: select2 not displayed

= 1.1.3 =
* Tweak: Update the readme documentation

= 1.1.2 =
* Fix: Fixed a potential security vulnerability

= 1.0.0 =
* Launched the Kledo plugin!

== Upgrade Notice ==

= 1.8.0 =
Checks every sent sales order and invoice back in Kledo and flags the ones Kledo failed to create; adds order-list filters and bulk actions, a clearer Transactions screen renamed Kledo Status, a Sync tab to send orders missing from Kledo gradually, and a Diagnostics tab to find out why an order did not arrive.

= 1.7.4 =
Fixes API keys silently failing after Kledo's automatic renewal; adds invoice-to-sales-order linking controls, API key expiry warnings, and an order-list Kledo status column.
