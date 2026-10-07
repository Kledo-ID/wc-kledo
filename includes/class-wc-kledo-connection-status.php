<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Tracks how much life the configured Kledo API key has left, and warns before it runs out.
 *
 * Kledo renews a key that is inside its renewal window by handing back a replacement on the next
 * authenticated call — see `WC_Kledo_Request::maybe_store_rotated_api_key()`. That only works for
 * a shop that is still syncing. A shop that went quiet, or one holding a key that never renews,
 * reaches the expiry date with nothing to catch it, and the first symptom is every sync failing
 * at once. These notices exist to make that arrive as a warning rather than as an outage.
 *
 * @since 1.7.4
 */
class WC_Kledo_Connection_Status {
	/**
	 * Transient holding the last answer from the API.
	 *
	 * Also cleared from the request layer whenever a key is rotated, since the stored expiry then
	 * describes a key that no longer exists.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const TRANSIENT_KEY = 'wc_kledo_connection_status';

	/**
	 * How long a successful answer is trusted.
	 *
	 * Expiry moves in days, so re-reading it more often than this buys nothing and puts a
	 * blocking HTTP request in front of an admin page load for no reason.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const REFRESH_INTERVAL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long to wait before trying again after a failed read.
	 *
	 * Shorter than the success interval so a transient outage recovers quickly, long enough that
	 * a sustained one is not retried on every admin page load.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const FAILURE_BACKOFF = HOUR_IN_SECONDS;

	/**
	 * Days remaining at which the first, gentler warning starts.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const NOTICE_THRESHOLD_DAYS = 30;

	/**
	 * Days remaining at which the warning turns urgent.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const URGENT_THRESHOLD_DAYS = 7;

	/**
	 * The status Kledo answers with when the stored key no longer authenticates.
	 *
	 * Expired and revoked keys are both rejected before the endpoint runs, so this one code
	 * stands in for every way a key can be dead. The distinction does not matter to the shop
	 * either: whatever killed it, the fix is to paste a current key.
	 *
	 * @var int
	 * @since 1.7.4
	 */
	public const UNAUTHENTICATED_RESPONSE_CODE = 401;

	/**
	 * Marker separating the two things a 401 can mean.
	 *
	 * Kledo answers 401 both when the key itself is dead and when the key is perfectly good but
	 * the account behind it has no access to that company — and those need opposite advice.
	 * Kledo's message for the second case is a hardcoded English literal in its middleware, not a
	 * translated string, so it does not move with the reader's locale.
	 *
	 * Matching on message text is normally a bad idea, and this is the exception that earns it:
	 * the alternative is a status code change across an API that other clients share. If Kledo
	 * ever reworded it, the match simply stops hitting and the generic "key no longer valid"
	 * notice shows instead — wrong advice in one rare case, never a fatal error.
	 *
	 * @var string
	 * @since 1.7.4
	 */
	public const NO_WEBSITE_ACCESS_MARKER = 'in this website';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_refresh' ) );

		// Priority 5 so the notices are registered before the notice handler renders at 15.
		add_action( 'admin_notices', array( $this, 'maybe_add_notices' ), 5 );

		// A new key makes every cached answer about the old one wrong, including a rejection the
		// person has just finished fixing. Without this the "no longer valid" error would keep
		// showing for up to an hour after they pasted a working key.
		add_action( 'add_option_' . WC_Kledo_Configure_Screen::SETTING_API_KEY, array( $this, 'invalidate' ) );
		add_action( 'update_option_' . WC_Kledo_Configure_Screen::SETTING_API_KEY, array( $this, 'invalidate' ) );
	}

	/**
	 * Drop the cached status so the next admin page load asks Kledo again.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );

		// The mask describes the previous key. Leaving it would show the old key's masked form
		// over a newly pasted one, which is worse than showing no mask at all.
		delete_option( wc_kledo_get_api_key_mask_option_name() );

		// Whatever the previous key had drifted into stops being true the moment the key changes.
		// A rotation sets this again immediately afterwards; a key pasted by hand does not, which
		// is exactly how someone clears the warning — by connecting a managed key again.
		delete_option( wc_kledo_get_api_key_detached_option_name() );
	}

	/**
	 * Refresh the cached status when it has gone stale.
	 *
	 * Runs at most once per interval rather than on every admin page load, and only for a user
	 * who would be shown the result anyway.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function maybe_refresh(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			return;
		}

		if ( false !== get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}

		$this->refresh();
	}

	/**
	 * Ask Kledo about the current key and cache the answer.
	 *
	 * A failed read is cached too, as an explicit failure marker. Without that the next admin
	 * page load would try again immediately, turning an API outage into a slow admin.
	 *
	 * @return array|null The normalised status, or null when it could not be read.
	 * @since 1.7.4
	 */
	public function refresh(): ?array {
		$request       = new WC_Kledo_Request_Connection();
		$response_code = 0;

		try {
			$response      = $request->get_connection();
			$response_code = (int) $request->get_response_code();
		} catch ( Throwable $exception ) {
			wc_kledo_log_warning(
				sprintf(
					'Kledo connection check failed: %s',
					wc_kledo_sanitize_api_error_message( $exception->getMessage() )
				)
			);

			$response = false;
		}

		// A dead key is told apart from an unreachable Kledo on purpose. Both leave us without an
		// expiry date, but only one of them is the shop's problem to fix, and folding them into
		// one "could not read the status" state would leave the person who most needs telling
		// looking at a shrug.
		if ( self::UNAUTHENTICATED_RESPONSE_CODE === $response_code ) {
			$no_website_access = $this->is_no_website_access_response( $request );

			set_transient(
				self::TRANSIENT_KEY,
				array(
					'unauthenticated'   => true,
					'no_website_access' => $no_website_access,
				),
				self::FAILURE_BACKOFF
			);

			wc_kledo_log_warning(
				$no_website_access
					? 'Kledo accepted the API key but the account behind it has no access to that company (HTTP 401). Syncing will keep failing until the access is restored or a key for the right company is saved.'
					: 'Kledo rejected the stored API key (HTTP 401). Syncing will keep failing until a current key is saved.'
			);

			return null;
		}

		if ( false === $response ) {
			set_transient( self::TRANSIENT_KEY, array( 'failed' => true ), self::FAILURE_BACKOFF );

			// The screen can only say "could not be reached". Put the distinguishing detail where
			// someone can actually read it: a transport failure already logged its WP_Error above,
			// so what is left to record here is the status code, which is 0 when the request never
			// got an answer at all.
			wc_kledo_log_warning(
				sprintf(
					'Kledo connection check could not be completed (HTTP %d). The API Key Status will show as unavailable until the next check.',
					$response_code
				)
			);

			return null;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		$status = $this->normalise( $data );

		// Kept outside the transient so the settings screen can still render the key when Kledo
		// is unreachable. Deleted alongside the transient whenever the key itself changes, so a
		// new key is never shown wearing the old key's mask.
		if ( '' !== $status['short_token_masked'] ) {
			update_option( wc_kledo_get_api_key_mask_option_name(), $status['short_token_masked'], false );
		}

		set_transient( self::TRANSIENT_KEY, $status, self::REFRESH_INTERVAL );

		return $status;
	}

	/**
	 * The cached status, if there is a usable one.
	 *
	 * @return array|null
	 * @since 1.7.4
	 */
	public function get(): ?array {
		$status = get_transient( self::TRANSIENT_KEY );

		// `checked_at` is only written by a successful read, which is what separates a real
		// answer from either of the two failure markers.
		if ( ! is_array( $status ) || ! isset( $status['checked_at'] ) ) {
			return null;
		}

		return $status;
	}

	/**
	 * Whether Kledo refused the request because of who we are, rather than being unreachable.
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	public function is_unauthenticated(): bool {
		$status = get_transient( self::TRANSIENT_KEY );

		return is_array( $status ) && ! empty( $status['unauthenticated'] );
	}

	/**
	 * Whether the key itself is fine and the account simply has no access to that company.
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	public function is_missing_website_access(): bool {
		$status = get_transient( self::TRANSIENT_KEY );

		return is_array( $status )
			&& ! empty( $status['unauthenticated'] )
			&& ! empty( $status['no_website_access'] );
	}

	/**
	 * Read a 401 body to see which of the two refusals it is.
	 *
	 * @param  \WC_Kledo_Request $request  The request that was just answered.
	 *
	 * @return bool True when the account has no access to the company, rather than the key being
	 *              dead. Anything unreadable is treated as the more common case, a dead key.
	 * @since 1.7.4
	 */
	private function is_no_website_access_response( WC_Kledo_Request $request ): bool {
		try {
			$body = $request->get_response();
		} catch ( JsonException $exception ) {
			return false;
		}

		if ( ! is_array( $body ) || empty( $body['message'] ) || ! is_string( $body['message'] ) ) {
			return false;
		}

		return false !== stripos( $body['message'], self::NO_WEBSITE_ACCESS_MARKER );
	}

	/**
	 * Whether the last read failed for a reason that is not the key itself.
	 *
	 * Told apart from "never read" and from a rejected key so the settings screen can say which
	 * of the three it is.
	 *
	 * @return bool
	 * @since 1.7.4
	 */
	public function last_read_failed(): bool {
		$status = get_transient( self::TRANSIENT_KEY );

		return is_array( $status ) && ! empty( $status['failed'] );
	}

	/**
	 * Coerce the API payload into the shape the rest of the plugin reads.
	 *
	 * @param  array $data  The `data` payload.
	 *
	 * @return array{expires_at: string, days_remaining: int|null, auto_renews: bool, timezone: string, name: string, app_code: string, short_token_masked: string, last_used_at: string, created_at: string, checked_at: int}
	 * @since 1.7.4
	 */
	private function normalise( array $data ): array {
		$days_remaining = null;

		if ( isset( $data['days_remaining'] ) && is_numeric( $data['days_remaining'] ) ) {
			$days_remaining = (int) $data['days_remaining'];
		}

		// `is_expired` is deliberately not read. Kledo returns it for shape-compatibility with the
		// by-id endpoint, but a key that has expired is refused by authentication before this
		// endpoint runs, so it is always false here. HTTP 401 is the signal instead.
		return array(
			'expires_at'         => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
			'days_remaining'     => $days_remaining,
			'auto_renews'        => ! empty( $data['auto_renews'] ),
			'timezone'           => isset( $data['timezone'] ) ? (string) $data['timezone'] : '',
			// Absent for an older key that is a raw Passport token rather than a managed alias.
			'name'               => isset( $data['name'] ) ? (string) $data['name'] : '',
			'app_code'           => isset( $data['app_code'] ) ? (string) $data['app_code'] : '',
			'short_token_masked' => isset( $data['short_token_masked'] ) ? (string) $data['short_token_masked'] : '',
			'last_used_at'       => isset( $data['last_used_at'] ) ? (string) $data['last_used_at'] : '',
			'created_at'         => isset( $data['created_at'] ) ? (string) $data['created_at'] : '',
			'checked_at'         => time(),
		);
	}

	/**
	 * Register the expiry notices, when there is anything worth saying.
	 *
	 * @return void
	 * @since 1.7.4
	 */
	public function maybe_add_notices(): void {
		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			return;
		}

		// The Configure tab shows the same warning as a banner with the steps; a notice above it
		// would only repeat it.
		if ( WC_Kledo_Admin::PAGE_ID === wc_kledo_get_requested_value( 'page' )
			&& WC_Kledo_Configure_Screen::ID === wc_kledo_get_requested_value( 'tab', WC_Kledo_Configure_Screen::ID ) ) {
			return;
		}

		$notice = $this->is_unauthenticated()
			? $this->build_rejected_key_notice()
			: $this->build_expiry_notice();

		if ( null === $notice ) {
			return;
		}

		wc_kledo()->get_admin_notice_handler()->add_admin_notice(
			$notice['message'],
			$notice['id'],
			array(
				'dismissible'  => $notice['dismissible'],
				'notice_class' => $notice['class'],
			)
		);
	}

	/**
	 * The notice for a request Kledo refused.
	 *
	 * Two different problems arrive as the same status code and need opposite advice. A dead key
	 * is fixed by pasting a new one; an account that lost access to the company is not — telling
	 * that shop to paste a new key sends them to fetch another key from the same account, which
	 * fails the same way. Neither can be dismissed: syncing is already broken in both cases.
	 *
	 * Every way a key can be dead — expired, revoked by a newer login, mistyped — shares one
	 * message, because Kledo rejects them identically and the shop does the same thing about all
	 * of them.
	 *
	 * @return array{id: string, message: string, class: string, dismissible: bool}
	 * @since 1.7.4
	 */
	private function build_rejected_key_notice(): array {
		if ( $this->is_missing_website_access() ) {
			return array(
				'id'          => 'wc_kledo_api_key_no_website_access',
				'message'     => sprintf(
					/* translators: %s: link to the plugin Configure screen */
					__( 'Kledo: Kledo accepts this store\'s API key, but the account it belongs to has no access to that company, so nothing is syncing. The key itself is fine — pasting a new one from the same account will not help. Check that the account still belongs to the company, or save a key made for the right company in %s.', 'wc-kledo' ),
					$this->get_settings_link()
				),
				'class'       => 'notice-error',
				'dismissible' => false,
			);
		}

		return array(
			'id'          => 'wc_kledo_api_key_rejected',
			'message'     => sprintf(
				/* translators: %s: link to the plugin Configure screen */
				__( 'Kledo: this store\'s API key is no longer valid, so nothing is syncing to Kledo right now. It has either expired or been replaced by a newer login. Paste a current API key in %s.', 'wc-kledo' ),
				$this->get_settings_link()
			) . ' ' . $this->get_create_key_link(),
			'class'       => 'notice-error',
			'dismissible' => false,
		);
	}

	/**
	 * A link to the Kledo page where a new API key is made, for use inside a notice.
	 *
	 * Opens in a new tab: the admin comes back here to paste the key.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	private function get_create_key_link(): string {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><strong>%2$s</strong></a>',
			esc_url( wc_kledo_get_api_key_management_url() ),
			esc_html__( 'Create a new API key in Kledo', 'wc-kledo' )
		);
	}

	/**
	 * What the Configure tab's banner should say about the key, if anything.
	 *
	 * The same situations as the admin notices — a key refused by Kledo, an account without access
	 * to the company, a key expiring within 30 days — but shown where the new key is pasted, with
	 * the steps to get one.
	 *
	 * @return array{level: string, title: string, message: string}|null `level` is `error` or
	 *                                                                    `warning`.
	 * @since 1.8.0
	 */
	public function get_banner(): ?array {
		if ( ! wc_kledo()->get_connection_handler()->is_configured() ) {
			return null;
		}

		if ( $this->is_unauthenticated() ) {
			if ( $this->is_missing_website_access() ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'The API key\'s account has no access to this company — nothing is syncing', 'wc-kledo' ),
					'message' => __( 'Kledo accepts the key, but the account it belongs to no longer has access to the company. Check the account in Kledo, or create a key while logged in to the right company.', 'wc-kledo' ),
				);
			}

			return array(
				'level'   => 'error',
				'title'   => __( 'The API key is no longer valid — nothing is syncing', 'wc-kledo' ),
				'message' => __( 'Kledo refused the saved key: it has expired or was replaced. Orders and invoices are not reaching Kledo until a new key is saved.', 'wc-kledo' ),
			);
		}

		$status = $this->get();

		if ( null === $status || null === $status['days_remaining'] || $status['days_remaining'] > self::NOTICE_THRESHOLD_DAYS ) {
			return null;
		}

		$days = max( 0, (int) $status['days_remaining'] );

		return array(
			'level'   => $days <= self::URGENT_THRESHOLD_DAYS ? 'error' : 'warning',
			'title'   => sprintf(
				/* translators: %d: number of days */
				_n( 'The API key expires in %d day', 'The API key expires in %d days', $days, 'wc-kledo' ),
				$days
			),
			'message' => trim(
				$this->describe_expiry( $status ) . ' ' . ( ! empty( $status['auto_renews'] )
					? __( 'Kledo normally renews it by itself while orders keep syncing. If orders are not reaching Kledo, create a new key now so syncing does not stop.', 'wc-kledo' )
					: __( 'This key does not renew itself. Create a new key before it expires, so orders and invoices keep reaching Kledo automatically.', 'wc-kledo' ) )
			),
		);
	}

	/**
	 * A link to the plugin's Configure screen, for use inside a notice.
	 *
	 * @return string
	 * @since 1.7.4
	 */
	private function get_settings_link(): string {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( wc_kledo()->get_settings_url() ),
			esc_html__( 'WooCommerce > Kledo > Configure', 'wc-kledo' )
		);
	}

	/**
	 * The countdown notice, when the key is close enough to expiry to be worth mentioning.
	 *
	 * Both tiers can be dismissed, but their ids carry the expiry date, so dismissing the 30-day
	 * warning does not also silence the 7-day one, and a renewed key starts the sequence over.
	 *
	 * @return array{id: string, message: string, class: string, dismissible: bool}|null
	 * @since 1.7.4
	 */
	private function build_expiry_notice(): ?array {
		$status = $this->get();

		if ( null === $status ) {
			return null;
		}

		$link           = $this->get_settings_link();
		$days_remaining = $status['days_remaining'];

		if ( null === $days_remaining || $days_remaining > self::NOTICE_THRESHOLD_DAYS ) {
			return null;
		}

		$expiry = $this->describe_expiry( $status );

		// Named in both branches rather than only the one that needs action. A countdown on its own
		// reads as housekeeping; what makes it worth interrupting someone for is that orders and
		// invoices stop reaching Kledo the moment the key lapses, silently and without a failed
		// checkout to notice.
		if ( ! empty( $status['auto_renews'] ) ) {
			$advice = sprintf(
				/* translators: %s: link to the plugin Configure screen */
				__( 'Kledo normally renews this key by itself while the store keeps syncing, so no action is needed as long as orders are still reaching Kledo. If they are not, the key will run out — and once it does, orders and invoices will stop being sent to Kledo automatically until a current key is saved in %s.', 'wc-kledo' ),
				$link
			);
		} else {
			$advice = sprintf(
				/* translators: %s: link to the plugin Configure screen */
				__( 'This key does not renew itself. Once it expires, orders and invoices will stop being sent to Kledo automatically and every sync will keep failing until a current key is saved. Paste a current one in %s before then.', 'wc-kledo' ),
				$link
			);
		}

		$urgent = $days_remaining <= self::URGENT_THRESHOLD_DAYS;

		return array(
			'id'          => sprintf(
				'wc_kledo_api_key_expiring_%1$s_%2$s',
				$urgent ? 'urgent' : 'soon',
				md5( (string) $status['expires_at'] )
			),
			'message'     => sprintf(
				/* translators: 1: number of days, 2: expiry date sentence, 3: what to do about it */
				_n(
					'Kledo: this store\'s API key expires in %1$d day. %2$s %3$s',
					'Kledo: this store\'s API key expires in %1$d days. %2$s %3$s',
					max( 0, $days_remaining ),
					'wc-kledo'
				),
				max( 0, $days_remaining ),
				$expiry,
				$advice
			) . ' ' . $this->get_create_key_link(),
			'class'       => $urgent ? 'notice-error' : 'notice-warning',
			'dismissible' => true,
		);
	}

	/**
	 * One sentence naming the expiry moment, in the store's own timezone.
	 *
	 * Kledo answers in its own zone, so the value is converted before it is shown rather than
	 * printed with Kledo's timezone appended. The reader compares this against the other dates in
	 * their WP Admin, all of which are already in site time.
	 *
	 * @param  array $status
	 *
	 * @return string
	 * @since 1.7.4
	 */
	private function describe_expiry( array $status ): string {
		if ( '' === (string) $status['expires_at'] ) {
			return '';
		}

		return sprintf(
			/* translators: %s: expiry date and time */
			__( 'It expires on %s.', 'wc-kledo' ),
			wc_kledo_format_kledo_datetime( (string) $status['expires_at'], (string) $status['timezone'] )
		);
	}
}
