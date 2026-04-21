=== Kledo ===
Contributors: kledo
Tags: Kledo, WooCommerce, Accounting
Requires at least: 4.4
Tested up to: 6.9
Stable tag: 1.5.0
Requires PHP: 7.0.0 or greater
Text Domain: wc-kledo
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

== Screenshots ==

1. Connect your WooCommerce with Kledo
2. The Kledo invoice plugin Settings pages

== Changelog ==

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
