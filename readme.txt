=== Kledo ===
Contributors: kledo
Tags: Kledo, WooCommerce, Accounting
Requires at least: 5.3
Tested up to: 6.9
Stable tag: 1.7.4
Requires PHP: 7.4
Text Domain: wc-kledo
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce orders to Kledo accounting: automatic sales orders and invoices, retries, a Transactions screen, and API key monitoring.

== Description ==

Integrates WooCommerce with Kledo accounting software: sales orders and invoices are sent automatically when orders reach the right status, with retries, a Transactions screen to monitor outcomes, and optional manual pushes from the order editor.

= Automatic sync =
* When an order moves to Processing, the plugin can create a Kledo sales order (if enabled in settings).
* When an order is Completed, the plugin can create a Kledo invoice (if enabled in settings).
* Successful deliveries are recorded on the order; failures are queued for retry (see below).

= How it works (lifecycle) =
* WooCommerce fires status changes; the plugin attempts to send the matching document to Kledo.
* On success, the order is marked synced for that type (sales order or invoice) and any stale queue row is removed.
* On failure, the transaction is stored in an internal retry queue with a scheduled next attempt time.
* Automatic retries run until the sync succeeds, the queue entry is removed as stale, or limits are reached (see Retry system).

= Retry system =
* Failed sends are retried automatically using a progressive delay: about 5 minutes after the first failure, then longer steps up to an 8-hour cap for later attempts (see code comments in the plugin for the full schedule).
* Automatic retries stop when: the send succeeds; the entry exceeds the maximum number of attempts; or it stays in the queue longer than the maximum lifetime (two days from when it was first queued). In those stop cases the row may show as Failed and can still be retried manually from the Transactions tab.
* Retries are driven by a WordPress scheduled event (`WP-Cron`). If cron spawning is unreliable on your host, the plugin can also process overdue retries when an administrator loads any wp-admin screen (fallback).

= Transactions screen (WooCommerce > Kledo > Transactions) =
* Lists synced successes (from order meta) and queue rows (failed/retrying). Success rows are built from a scan of recently modified orders that carry Kledo sync flags; very old successes might not appear—use order search if needed.
* Columns: Order (link and name when available); Type (`order` or `invoice`); Status (Success, Retrying, or Failed); Attempts (automatic try count for queue rows); Created At; Next Retry (queue rows); Last Error (truncated text; hover for the full message when truncated); Actions (Retry now on queue rows).
* Filter by status (All, Success, Failed, Retrying), sort key columns, paginate, and use the advanced filter bar for type, exact attempts, a calendar day for Created At or Next Retry, and a “last error contains” search.
* Screen Options let you hide optional columns and set how many rows per page.

= Manual and bulk retry =
* On queue rows, use Retry now for a single row, or select rows with the checkboxes, choose Bulk actions > Retry selected, and click Apply. Requires the `manage_woocommerce` capability.
* Use manual retry after fixing credentials, data, or outages, or when you do not want to wait for the next automatic run.

= Manual sync on the order screen =
* A Kledo meta box and WooCommerce order actions can send or re-send the sales order or invoice according to the same rules as automatic sync (processing/completed and feature toggles). Re-sending after a successful sync may create duplicates in Kledo—confirm when prompted.
* The "Kledo: Check Kledo status now" order action re-checks whether Kledo has closed the linked sales order and records the outcome as an order note, without changing the WooCommerce order status.

= Invoice-to-sales-order linking (Invoice tab) =
* "Link Invoice to Sales Order" controls whether each invoice sent to Kledo is linked to its Kledo sales order, which is what lets Kledo close that sales order once every quantity has been invoiced. On by default, matching how Kledo already behaved.
* "Close Sales Order When Invoiced" controls whether invoicing also asks Kledo to close the linked sales order immediately. On by default. Turning it off keeps the sales order open at the moment of invoicing; Kledo still recalculates and closes it later if the invoice or the order changes again.

= Kledo column on the order list =
* A "Kledo" column on the WooCommerce order list (HPOS and legacy) shows the outcome of the settings above per order: Closed, Waiting, Check Kledo (the automatic check gave up — open the order to see why), Not linked, or Left open.

= API Key Status (Configure tab) =
* Shows what Kledo knows about the saved API key — its name, when it was created, when it was last used, and when it expires, in the store's own timezone — with admin warnings 30 and 7 days before it runs out.
* Says plainly when Kledo has rejected the stored key, or when Kledo's own automatic renewal already replaced it with a key that no longer matches the one shown in Kledo.
* The API key field is masked once a key is saved, showing only the company name and the key's last four characters instead of the full key.

= Quick access =
* A "Settings" link on the Plugins screen opens WooCommerce > Kledo directly.
* On WordPress 6.9 and later, each settings tab can be opened from the WordPress command palette (Ctrl+K / Cmd+K) by typing "kledo".

= Logging and troubleshooting =
* Delivery and retry activity is written to the WooCommerce logger with source `wc-kledo` (WooCommerce > Status > Logs). Order notes also record important Kledo outcomes.
* “Last Error” on the Transactions table shows the latest API or transport message stored for that queue row (sanitized and length-limited for safe display).

= Best practices =
* Keep API connection and feature toggles aligned with how you process orders.
* Ensure WordPress cron can run in production (real server cron hitting `wp-cron.php` is a common setup) if you rely on timely automatic retries; otherwise use the admin fallback or manual retries.
* Use the Transactions tab after incidents to clear or retry stuck items.

= Tutorial =
[youtube https://www.youtube.com/watch?v=0AmD4Aja88c]

== Installation ==

1. Install the Kledo plugin either via the WordPress plugin directory, or by uploading the files to your web server (in the `/wp-content/plugins/` directory).
2. Activate the Kledo plugin through the 'Plugins' menu in WordPress.
3. Navigate to the 'Kledo' settings page to setup API credentials and connect your Kledo account.
4. Configure your invoices in 'Invoice' tab.

<object width="425" height="344"><param name="movie" value="http://www.youtube.com/v/0AmD4Aja88c&hl=en&fs=1&"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="http://www.youtube.com/v/0AmD4Aja88c&hl=en&fs=1&" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="425" height="344"></embed></object>

== Frequently Asked Questions ==

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
4. Transaction page for manual/bulk retry failed transactions
5. Manual sync controls on order screens and order actions

== Changelog ==

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

= 1.7.4 =
Fixes API keys silently failing after Kledo's automatic renewal; adds invoice-to-sales-order linking controls, API key expiry warnings, and an order-list Kledo status column.
