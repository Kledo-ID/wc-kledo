<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Guide & FAQ tab: how to use the plugin from the first API key to finding a lost order.
 *
 * Content is data — sections of steps and a list of questions — rendered by one template, so a new
 * feature only needs an entry here. Every text is translatable, and every section and question has
 * an anchor (`#guide-…`, `#faq-…`) that other screens can link to.
 *
 * @since 1.8.0
 */
class WC_Kledo_Help_Screen extends WC_Kledo_Settings_Screen {
	/**
	 * The screen id.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const ID = 'help';

	/**
	 * The class constructor.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function __construct() {
		$this->id = self::ID;

		add_action(
			'load-woocommerce_page_wc-kledo',
			function () {
				$this->label = __( 'Guide & FAQ', 'wc-kledo' );
				$this->title = __( 'Guide & FAQ', 'wc-kledo' );
			}
		);
	}

	/**
	 * This screen has no WooCommerce settings fields.
	 *
	 * @return array
	 * @since 1.8.0
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * URL of a section or question of this tab.
	 *
	 * @param  string $anchor  `guide-…` or `faq-…`, without the hash.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public static function get_url( string $anchor = '' ): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . self::ID ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render(): void {
		$sections = $this->get_sections();
		$faqs     = $this->get_faqs();

		?>
		<div class="wc-kledo-help">
			<p class="wc-kledo-help-intro">
				<?php esc_html_e( 'Everything about using this plugin, in the order you need it: connect to Kledo, choose what is created, see what reached Kledo, send what did not, and find out why an order is missing. Click a topic to open it, or search below.', 'wc-kledo' ); ?>
			</p>

			<p class="wc-kledo-help-search">
				<label for="wc-kledo-help-search" class="screen-reader-text"><?php esc_html_e( 'Search the guide', 'wc-kledo' ); ?></label>
				<input type="search" id="wc-kledo-help-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search, e.g. API key, invoice, old orders…', 'wc-kledo' ); ?>"/>
				<span class="wc-kledo-help-no-result" hidden><?php esc_html_e( 'Nothing found. Try another word, or ask Kledo in the Support tab.', 'wc-kledo' ); ?></span>
			</p>

			<nav class="wc-kledo-help-toc" aria-label="<?php esc_attr_e( 'Guide contents', 'wc-kledo' ); ?>">
				<?php foreach ( $sections as $index => $section ) : ?>
					<a href="#<?php echo esc_attr( $section['id'] ); ?>"><?php echo esc_html( ( $index + 1 ) . '. ' . $section['title'] ); ?></a>
				<?php endforeach; ?>
				<a href="#faq"><?php esc_html_e( 'Frequently asked questions', 'wc-kledo' ); ?></a>
			</nav>

			<h2><?php esc_html_e( 'Guide', 'wc-kledo' ); ?></h2>

			<?php foreach ( $sections as $index => $section ) : ?>
				<details class="wc-kledo-help-item" id="<?php echo esc_attr( $section['id'] ); ?>" <?php echo 0 === $index ? 'open' : ''; ?>>
					<summary><?php echo esc_html( ( $index + 1 ) . '. ' . $section['title'] ); ?></summary>
					<div class="wc-kledo-help-body">
						<?php if ( ! empty( $section['intro'] ) ) : ?>
							<p><?php echo esc_html( $section['intro'] ); ?></p>
						<?php endif; ?>

						<?php if ( ! empty( $section['steps'] ) ) : ?>
							<ol>
								<?php foreach ( $section['steps'] as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>

						<?php if ( ! empty( $section['table'] ) ) : ?>
							<table class="widefat striped wc-kledo-help-table">
								<thead>
									<tr>
										<?php foreach ( $section['table']['head'] as $heading ) : ?>
											<th scope="col"><?php echo esc_html( $heading ); ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $section['table']['rows'] as $row ) : ?>
										<tr>
											<?php foreach ( $row as $cell ) : ?>
												<td><?php echo esc_html( $cell ); ?></td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

						<?php if ( ! empty( $section['notes'] ) ) : ?>
							<ul class="wc-kledo-help-notes">
								<?php foreach ( $section['notes'] as $note ) : ?>
									<li><?php echo esc_html( $note ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php $this->render_links( $section['links'] ?? array() ); ?>
					</div>
				</details>
			<?php endforeach; ?>

			<h2 id="faq"><?php esc_html_e( 'Frequently asked questions', 'wc-kledo' ); ?></h2>

			<?php foreach ( $faqs as $faq ) : ?>
				<details class="wc-kledo-help-item wc-kledo-help-faq" id="<?php echo esc_attr( $faq['id'] ); ?>">
					<summary><?php echo esc_html( $faq['question'] ); ?></summary>
					<div class="wc-kledo-help-body">
						<p><?php echo esc_html( $faq['answer'] ); ?></p>
						<?php $this->render_links( $faq['links'] ?? array() ); ?>
					</div>
				</details>
			<?php endforeach; ?>

			<p class="wc-kledo-help-footer">
				<?php esc_html_e( 'Still stuck? Run a diagnosis in the Diagnostics tab and send the report to Kledo from the Support tab.', 'wc-kledo' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Buttons under a section or answer.
	 *
	 * @param  array<int, array{0: string, 1: string, 2?: bool}> $links  Label, URL, opens elsewhere.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_links( array $links ): void {
		if ( empty( $links ) ) {
			return;
		}

		echo '<p class="wc-kledo-help-links">';

		foreach ( $links as $link ) {
			$external = ! empty( $link[2] );

			printf(
				'<a class="button" href="%1$s"%2$s>%3$s%4$s</a> ',
				esc_url( $link[1] ),
				$external ? ' target="_blank" rel="noopener noreferrer"' : '',
				esc_html( $link[0] ),
				$external ? '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'wc-kledo' ) . '</span>' : ''
			);
		}

		echo '</p>';
	}

	/**
	 * URL of another tab of the plugin.
	 *
	 * @param  string $tab
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . WC_Kledo_Admin::PAGE_ID . '&tab=' . $tab );
	}

	/**
	 * The guide, in the order a shop goes through it.
	 *
	 * @return array<int, array>
	 * @since 1.8.0
	 */
	private function get_sections(): array {
		$orders_url = wc_kledo_uses_hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );

		return array(
			array(
				'id'    => 'guide-overview',
				'title' => __( 'How the plugin works', 'wc-kledo' ),
				'intro' => __( 'The plugin creates transactions in Kledo from your WooCommerce orders, by itself, when an order reaches the right status:', 'wc-kledo' ),
				'table' => array(
					'head' => array( __( 'Order status in WooCommerce', 'wc-kledo' ), __( 'Created in Kledo', 'wc-kledo' ) ),
					'rows' => array(
						array( __( 'Processing', 'wc-kledo' ), __( 'A sales order (if "Enable Create Order" is on)', 'wc-kledo' ) ),
						array( __( 'Completed', 'wc-kledo' ), __( 'An invoice (if "Enable Create Invoice" is on), linked to the sales order', 'wc-kledo' ) ),
						array( __( 'Any other status', 'wc-kledo' ), __( 'Nothing', 'wc-kledo' ) ),
					),
				),
				'notes' => array(
					__( 'Kledo first queues what it receives and creates it a moment later, so the plugin checks back with Kledo until it finds the transaction. That is why you see "Waiting for Kledo" before "In Kledo".', 'wc-kledo' ),
					__( 'If something fails, the plugin retries by itself. Anything that still did not arrive can be sent again from the order, the order list, the Kledo Status tab, or the Sync tab.', 'wc-kledo' ),
				),
			),
			array(
				'id'    => 'guide-api-key',
				'title' => __( 'Create an API key in Kledo and connect', 'wc-kledo' ),
				'intro' => __( 'The API key is how this store logs in to your Kledo company. Without it nothing is sent.', 'wc-kledo' ),
				'steps' => array(
					__( 'Log in to Kledo with an account that has access to the company you want to send orders to.', 'wc-kledo' ),
					__( 'In Kledo, open Settings › Integrations, then choose "API Key" in the Developer & Security section. The button below opens that page directly.', 'wc-kledo' ),
					__( 'Create a new API key for WooCommerce and copy it.', 'wc-kledo' ),
					__( 'In this plugin, open the Configure tab and tick "Enable Integration".', 'wc-kledo' ),
					__( 'Paste the key into "API Key" and fill in "API Endpoint URL" with the API address Kledo gives for your company. Ask Kledo support if you do not know it.', 'wc-kledo' ),
					__( 'Click "Save changes". "API Key Status" then shows the key\'s name, when it was last used, and when it expires.', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'After saving, the key is shown masked: only the company name and the last four characters. To replace it, paste a new key over it.', 'wc-kledo' ),
					__( 'From 30 days before the key expires, a message appears in the admin and a banner on the Configure tab with a button to create a new key. Replace it before it expires so syncing never stops.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Create a new API key in Kledo', 'wc-kledo' ), wc_kledo_get_api_key_management_url(), true ),
					array( __( 'Open the Configure tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Configure_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-sales-order',
				'title' => __( 'Set up sales orders', 'wc-kledo' ),
				'intro' => __( 'A sales order is created in Kledo when an order becomes Processing.', 'wc-kledo' ),
				'steps' => array(
					__( 'Open the Order tab.', 'wc-kledo' ),
					__( 'Tick "Enable Create Order" if you want sales orders in Kledo. Leave it off if you only want invoices.', 'wc-kledo' ),
					__( 'Set the "Order Prefix" (the start of the Kledo number, for example WC/SO/), the "Warehouse" the stock comes from, and the "Tags" to put on each sales order.', 'wc-kledo' ),
					__( 'Click "Save changes".', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Order tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Order_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-invoice',
				'title' => __( 'Set up invoices', 'wc-kledo' ),
				'intro' => __( 'An invoice is created in Kledo when an order becomes Completed.', 'wc-kledo' ),
				'steps' => array(
					__( 'Open the Invoice tab and tick "Enable Create Invoice".', 'wc-kledo' ),
					__( 'Set the "Invoice Prefix", and choose whether invoices are created as Paid or Unpaid. For Paid, also choose the "Payment Account" the payment is recorded on.', 'wc-kledo' ),
					__( 'Choose the "Warehouse" and the "Tags".', 'wc-kledo' ),
					__( 'Check the sales order link settings below (all on by default), then click "Save changes".', 'wc-kledo' ),
				),
				'table' => array(
					'head' => array( __( 'Setting', 'wc-kledo' ), __( 'What it does', 'wc-kledo' ) ),
					'rows' => array(
						array( __( 'Link Invoice to Sales Order', 'wc-kledo' ), __( 'The invoice is attached to its sales order, so the sales order counts those items as invoiced.', 'wc-kledo' ) ),
						array( __( 'Close Sales Order When Invoiced', 'wc-kledo' ), __( 'Kledo closes the sales order as soon as everything on it is invoiced.', 'wc-kledo' ) ),
						array( __( 'Create Sales Order First When an Order Skips Processing', 'wc-kledo' ), __( 'An order changed straight to Completed still gets its sales order first, then its invoice. Turn off to send only the invoice for such orders.', 'wc-kledo' ) ),
					),
				),
				'notes' => array(
					__( 'The invoice due date is the day the order was completed, or one month after the order date if it has no completion date.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Invoice tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Invoice_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-statuses',
				'title' => __( 'Check what reached Kledo', 'wc-kledo' ),
				'intro' => __( 'The Kledo column in the WooCommerce order list shows two lines per order — the sales order and the invoice — each with one of these statuses:', 'wc-kledo' ),
				'table' => array(
					'head' => array( __( 'Status', 'wc-kledo' ), __( 'Meaning', 'wc-kledo' ), __( 'What to do', 'wc-kledo' ) ),
					'rows' => $this->get_status_rows(),
				),
				'notes' => array(
					__( 'Hover over a status to see the Kledo reference number.', 'wc-kledo' ),
					__( 'The same statuses are used in the Kledo Status tab, the Sync tab and the Kledo box on the order page.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the order list', 'wc-kledo' ), $orders_url ),
				),
			),
			array(
				'id'    => 'guide-send-one',
				'title' => __( 'Send or check one order', 'wc-kledo' ),
				'intro' => __( 'Open the order in WooCommerce. The Kledo box on the right shows the status of its sales order and invoice in Kledo.', 'wc-kledo' ),
				'steps' => array(
					__( 'To send it: in "Order actions", choose "Kledo: Send sales order (first manual)" or "Kledo: Send invoice (first manual)", then click the arrow button. The Kledo box also has send buttons.', 'wc-kledo' ),
					__( 'To check it: choose "Kledo: Check Kledo status now" in "Order actions". The result appears at the top of the page and in the order notes.', 'wc-kledo' ),
					__( 'If it is still missing: click "Not in Kledo? Diagnose this order" in the Kledo box.', 'wc-kledo' ),
				),
				'notes' => array(
					__( '"Re-send" appears for something already sent and may create a duplicate in Kledo — use it only if you are sure it is not in Kledo.', 'wc-kledo' ),
				),
			),
			array(
				'id'    => 'guide-order-list',
				'title' => __( 'Find and send many orders from the order list', 'wc-kledo' ),
				'steps' => array(
					__( 'Open WooCommerce › Orders.', 'wc-kledo' ),
					__( 'Use the filters: "Kledo sales order" and "Kledo invoice" (Not sent, Waiting for Kledo, In Kledo, Failed) and the order date range "From … to …". You can combine them with the order status.', 'wc-kledo' ),
					__( 'Click "Filter", tick the orders (or all of them), choose "Kledo: Send sales order", "Kledo: Send invoice" or "Kledo: Check status in Kledo" in "Bulk actions", and click "Apply".', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'Example: status Completed, Kledo invoice "Not sent", dates 15 to 17 October → every completed order of those days whose invoice is not in Kledo.', 'wc-kledo' ),
					__( 'Up to 20 orders are sent per click, and anything already in Kledo is skipped. For more, use the "Send all of them gradually with Sync" button that appears next to the filters.', 'wc-kledo' ),
					__( 'The date range replaces the "All dates" month filter when you fill it in.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the order list', 'wc-kledo' ), $orders_url ),
				),
			),
			array(
				'id'    => 'guide-transactions',
				'title' => __( 'The Kledo Status tab', 'wc-kledo' ),
				'intro' => __( 'Every sales order and invoice the plugin sent, with its status in Kledo.', 'wc-kledo' ),
				'steps' => array(
					__( 'The cards at the top count what is in Kledo, waiting, needs attention, and was not sent. Click a card to see those orders.', 'wc-kledo' ),
					__( 'Use the tabs (Waiting for Kledo, In Kledo, Send failed, Rejected by Kledo, Failed in Kledo) and the filters for type, order dates and order number.', 'wc-kledo' ),
					__( 'Under each order number: "Check status" and "Resend" work without reloading the page; "Diagnose" opens the Diagnostics tab for that order.', 'wc-kledo' ),
					__( 'To act on many rows: tick them, choose "Resend selected (failed only)" or "Check status in Kledo", and click "Apply".', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'The "Notes" column says what went wrong and what to do. Open "What do the statuses mean?" for an explanation of every status.', 'wc-kledo' ),
					__( 'Screen Options (top right) shows or hides the Transaction, Attempts and Notes columns.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Kledo Status tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Transactions_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-sync',
				'title' => __( 'Send old or missed orders (Sync tab)', 'wc-kledo' ),
				'intro' => __( 'For orders that were placed before the plugin was installed, or that were missed. Nothing is sent until you start it.', 'wc-kledo' ),
				'steps' => array(
					__( 'Open the Sync tab. Choose the order dates (empty means the last 30 days) and click "Count again" to see how many orders need sending.', 'wc-kledo' ),
					__( 'Check the list below: "Only orders that need sending" shows exactly what will be sent.', 'wc-kledo' ),
					__( 'Click "Process gradually" and read the confirmation before accepting.', 'wc-kledo' ),
					__( 'Watch the progress, or leave the page — it continues in the background. Use Pause, Resume or Cancel at any time.', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'Orders are sent 5 to 10 per minute ("Speed"), and the next ones only after Kledo has created the previous ones. Orders already in Kledo are skipped.', 'wc-kledo' ),
					__( 'Each transaction gets its order date, so reports of earlier months in Kledo change, and invoices reduce warehouse stock on that date. Kledo does not refuse dates inside a period you closed (locked), so do not choose dates inside one.', 'wc-kledo' ),
					__( 'If Kledo has not finished a batch after 30 minutes, the sync pauses itself; resume it once Kledo works normally again.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Sync tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Sync_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-daily',
				'title' => __( 'Send missed orders automatically every night', 'wc-kledo' ),
				'intro' => __( 'Optional, off by default. When on, every night the plugin looks for orders of the last few days that are not in Kledo yet, and sends them by itself, slowly.', 'wc-kledo' ),
				'steps' => array(
					__( 'Open the Sync tab and tick "Send Automatically Every Day".', 'wc-kledo' ),
					__( 'Choose how many days back to look ("Look Back", 7 by default) and the "Start Time" (a quiet hour for your store).', 'wc-kledo' ),
					__( 'Click "Save changes".', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'You see a message only if something failed. Results are in the Kledo Status tab.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Sync tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Sync_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-diagnostics',
				'title' => __( 'An order did not reach Kledo — find out why', 'wc-kledo' ),
				'steps' => array(
					__( 'Open the Diagnostics tab (or click "Diagnose" in the Kledo Status tab or on the order page).', 'wc-kledo' ),
					__( 'Choose the order from the list — orders with a problem in Kledo are at the top, and you can search by number, customer name or email, or type an order number — then click "Run diagnosis". Eight checks run one by one: ✔ passed, ⚠ warning, ✖ problem. Open "Details" on any of them to see more.', 'wc-kledo' ),
					__( 'Read "Likely cause" and "What to do" at the end, and follow it.', 'wc-kledo' ),
					__( 'To try again at once, tick "Also resend to Kledo and record what happens" before running. Only what Kledo does not have yet is resent.', 'wc-kledo' ),
					__( 'If it is still not solved, click "Download report" and send the file to Kledo, mentioning the report code (DIAG-…).', 'wc-kledo' ),
				),
				'notes' => array(
					__( 'For problems that only happen now and then, click "Record for 24 hours", wait until it happens again, then run a diagnosis for that order.', 'wc-kledo' ),
					__( 'The report never contains your API key, and customer details are partly hidden unless you choose otherwise.', 'wc-kledo' ),
				),
				'links' => array(
					array( __( 'Open the Diagnostics tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Diagnostics_Screen::ID ) ),
				),
			),
			array(
				'id'    => 'guide-retries',
				'title' => __( 'Automatic retries and scheduled tasks', 'wc-kledo' ),
				'notes' => array(
					__( 'A failed send is retried by itself: after about 5 minutes, then less and less often up to every 8 hours, for up to two days.', 'wc-kledo' ),
					__( 'Data that Kledo rejected is not retried, because it would be rejected again. Fix the data, then resend.', 'wc-kledo' ),
					__( 'Retries, checks and the Sync tab run on WordPress scheduled tasks (WP-Cron). If your host blocks them, the work also runs when an administrator opens wp-admin, but a real cron job is better — ask your hosting provider.', 'wc-kledo' ),
					__( 'Everything the plugin does is written to WooCommerce › Status › Logs, source "wc-kledo", and important results to the order notes.', 'wc-kledo' ),
				),
			),
		);
	}

	/**
	 * One table row per status, from the shared vocabulary.
	 *
	 * @return array<int, string[]>
	 * @since 1.8.0
	 */
	private function get_status_rows(): array {
		$rows = array();

		foreach ( array( 'not_sent', 'verifying', 'waiting_sales_order', 'confirmed', 'legacy_synced', 'retrying', 'failed', 'rejected', 'missing' ) as $state ) {
			$badge  = WC_Kledo_Status_Badge::describe( $state, 'order' );
			$rows[] = array( $badge['label'], $badge['description'], '' !== $badge['action'] ? $badge['action'] : '—' );
		}

		return $rows;
	}

	/**
	 * The questions shops ask most.
	 *
	 * @return array<int, array{id: string, question: string, answer: string, links?: array}>
	 * @since 1.8.0
	 */
	private function get_faqs(): array {
		return array(
			array(
				'id'       => 'faq-not-in-kledo',
				'question' => __( 'An order is not in Kledo. What should I do?', 'wc-kledo' ),
				'answer'   => __( 'Run a diagnosis for it in the Diagnostics tab. It tells you the likely cause and what to do, and can resend it. If that does not help, send the report to Kledo.', 'wc-kledo' ),
				'links'    => array( array( __( 'Open the Diagnostics tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Diagnostics_Screen::ID ) ) ),
			),
			array(
				'id'       => 'faq-failed-in-kledo',
				'question' => __( 'What does "Failed in Kledo" mean?', 'wc-kledo' ),
				'answer'   => __( 'Kledo accepted the order but never created it — the problem happened inside Kledo. It is not resent by itself, so a slow Kledo cannot cause duplicates. Resend it; if it fails again, run a diagnosis and send the report to Kledo.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-waiting',
				'question' => __( 'An order has been "Waiting for Kledo" for a long time. Is that normal?', 'wc-kledo' ),
				'answer'   => __( 'Usually it turns into "In Kledo" within minutes. The plugin keeps checking for about 6 hours; if it never appears, the status becomes "Failed in Kledo". You can also click "Check status" to ask right away.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-duplicates',
				'question' => __( 'Can an order be created twice in Kledo?', 'wc-kledo' ),
				'answer'   => __( 'The plugin avoids it: bulk actions, the Sync tab and the diagnosis only send what Kledo does not have yet, and Kledo recognises an order it already has by its WooCommerce order number. Only "Re-send" on the order page sends again on purpose. A transaction you created by hand in Kledo with a different number is not recognised.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-old-orders',
				'question' => __( 'Does the plugin send my old orders by itself after I install or update it?', 'wc-kledo' ),
				'answer'   => __( 'No. It only counts the orders of the last 30 days that are not in Kledo and shows a message. Nothing is sent until you press "Process gradually" in the Sync tab, or switch on "Send Automatically Every Day".', 'wc-kledo' ),
				'links'    => array( array( __( 'Open the Sync tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Sync_Screen::ID ) ) ),
			),
			array(
				'id'       => 'faq-straight-completed',
				'question' => __( 'I changed an order straight to Completed. Is the sales order still created?', 'wc-kledo' ),
				'answer'   => __( 'Yes, by default: the sales order is created first, and the invoice follows once the sales order is in Kledo. To send only the invoice for such orders, turn off "Create Sales Order First When an Order Skips Processing" in the Invoice tab.', 'wc-kledo' ),
				'links'    => array( array( __( 'Open the Invoice tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Invoice_Screen::ID ) ) ),
			),
			array(
				'id'       => 'faq-invoice-waiting',
				'question' => __( 'Why is an invoice "Waiting for sales order"?', 'wc-kledo' ),
				'answer'   => __( 'With "Link Invoice to Sales Order" on, the invoice is only sent once its sales order exists in Kledo, so the two can be linked. It is sent by itself as soon as the sales order is there.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-edit-cancel',
				'question' => __( 'I edited, cancelled or refunded an order after it was sent. Does Kledo update?', 'wc-kledo' ),
				'answer'   => __( 'No. The plugin only creates transactions when an order becomes Processing or Completed; it does not change or delete them afterwards. Adjust the transaction in Kledo yourself.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-data-sent',
				'question' => __( 'What data is sent to Kledo?', 'wc-kledo' ),
				'answer'   => __( 'The customer\'s name, email, phone and address; the order number and date; each product\'s name, SKU, description, quantity, price and photo link; shipping cost, discount and whether tax applies; the warehouse, tags and payment settings you chose; and shipment tracking when WooCommerce Shipment Tracking is used.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-products',
				'question' => __( 'How are my products matched in Kledo?', 'wc-kledo' ),
				'answer'   => __( 'Each product is sent with its name and SKU. Give every product a SKU so Kledo can recognise it. Products deleted from the store cannot be sent; the diagnosis warns about missing SKUs and deleted products.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-api-key-expiry',
				'question' => __( 'My API key is about to expire. What now?', 'wc-kledo' ),
				'answer'   => __( 'Click "Create a new API key in Kledo" in the message or on the Configure tab, create the key, paste it into "API Key" and click "Save changes". Do it before the expiry date; after it, nothing reaches Kledo until a new key is saved.', 'wc-kledo' ),
				'links'    => array(
					array( __( 'Create a new API key in Kledo', 'wc-kledo' ), wc_kledo_get_api_key_management_url(), true ),
					array( __( 'Open the Configure tab', 'wc-kledo' ), $this->tab_url( WC_Kledo_Configure_Screen::ID ) ),
				),
			),
			array(
				'id'       => 'faq-rejected-key',
				'question' => __( 'The plugin says Kledo refuses my API key.', 'wc-kledo' ),
				'answer'   => __( 'The key has expired or was replaced, or the account behind it lost access to the company. Create a new key while logged in to the right company in Kledo, and save it in the Configure tab.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-turn-off',
				'question' => __( 'How do I stop sending to Kledo for a while?', 'wc-kledo' ),
				'answer'   => __( 'Untick "Enable Integration" in the Configure tab. Orders that change status meanwhile are not sent; send them later from the Sync tab.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-deactivate',
				'question' => __( 'What happens if I deactivate or delete the plugin?', 'wc-kledo' ),
				'answer'   => __( 'Deactivating stops sending and pauses a running sync; your settings and history stay, and everything continues when you activate it again. Transactions already in Kledo are never removed.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-cron',
				'question' => __( 'Retries never seem to happen.', 'wc-kledo' ),
				'answer'   => __( 'WordPress scheduled tasks (WP-Cron) are probably not running on your host. The diagnosis checks this ("Queues and scheduled tasks"). Ask your hosting provider to enable WP-Cron or a real cron job.', 'wc-kledo' ),
			),
			array(
				'id'       => 'faq-logs',
				'question' => __( 'Where can I see what the plugin did?', 'wc-kledo' ),
				'answer'   => __( 'In the order notes of each order, in the Kledo Status tab, and in WooCommerce › Status › Logs (source "wc-kledo"; "wc-kledo-debug" while recording for 24 hours).', 'wc-kledo' ),
				'links'    => array( array( __( 'Open the logs', 'wc-kledo' ), admin_url( 'admin.php?page=wc-status&tab=logs' ) ) ),
			),
			array(
				'id'       => 'faq-palette',
				'question' => __( 'Is there a faster way to open these tabs?', 'wc-kledo' ),
				'answer'   => __( 'Yes. On WordPress 6.9 and later, press Ctrl+K (Cmd+K on a Mac) anywhere in wp-admin and type "kledo". The Plugins screen also has a "Settings" link under Kledo.', 'wc-kledo' ),
			),
		);
	}
}
