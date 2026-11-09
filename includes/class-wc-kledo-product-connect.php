<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provisions a WooCommerce REST key for Kledo and registers it, so Kledo can read this store's
 * catalogue and write stock back.
 *
 * WHY THE PLUGIN CREATES THE KEY
 * The alternative is sending the shop owner to WooCommerce > Settings > Advanced > REST API to
 * generate one and paste three values into Kledo. That is the single most error-prone step we
 * could ask for — wrong permissions, a mistyped URL, or a secret that was never copied (it is
 * shown exactly once). The plugin already knows the store URL and is already authenticated with
 * Kledo, so it can do the whole exchange with one button.
 *
 * WHAT IS STORED LOCALLY
 * Only the connection id, the truncated key, and the key row id — never the secret. The secret
 * exists in memory for the length of one request: it is generated, sent to Kledo, and dropped.
 * WooCommerce itself only keeps a hash of the consumer key, so the plaintext key is not
 * recoverable here either; disconnecting therefore revokes the key row rather than re-reading it.
 *
 * @since 1.9.0
 */
class WC_Kledo_Product_Connect {
	/**
	 * Kledo connection id (`marketplace_connects.id`) once registered.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public const CONNECT_ID_OPTION = 'wc_kledo_product_connect_id';

	/**
	 * Row id in `woocommerce_api_keys` of the key issued to Kledo, so it can be revoked.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public const KEY_ID_OPTION = 'wc_kledo_product_connect_key_id';

	/**
	 * Last 7 characters of the key, for display only.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public const TRUNCATED_KEY_OPTION = 'wc_kledo_product_connect_truncated_key';

	/**
	 * Store URL that was registered, so a later site move is visible in the UI.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	public const STORE_URL_OPTION = 'wc_kledo_product_connect_store_url';

	/**
	 * Description written on the key row, so it is recognisable in WooCommerce's own key list.
	 *
	 * @var string
	 * @since 1.9.0
	 */
	private const KEY_DESCRIPTION = 'Kledo product sync';

	/**
	 * Is product sync connected?
	 *
	 * @return bool
	 * @since 1.9.0
	 */
	public static function is_connected(): bool {
		return '' !== (string) get_option( self::CONNECT_ID_OPTION, '' );
	}

	/**
	 * The Kledo connection id, or 0.
	 *
	 * @return int
	 * @since 1.9.0
	 */
	public static function get_connect_id(): int {
		return (int) get_option( self::CONNECT_ID_OPTION, 0 );
	}

	/**
	 * Generate a key for Kledo, register it, and remember the result.
	 *
	 * Order matters: the key row is created first because Kledo must be able to authenticate with
	 * it during verification, and it is removed again if Kledo refuses. Leaving a live read/write
	 * key behind for a connection that does not exist would be a credential nobody is watching.
	 *
	 * @param  int|null  $warehouse_id  Kledo warehouse stock should land in; null = default.
	 *
	 * @return array{ok: bool, message: string} `message` is safe to show the shop owner.
	 * @since 1.9.0
	 */
	public static function connect( ?int $warehouse_id = null ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'You do not have permission to connect product sync.', 'wc-kledo' ),
			);
		}

		$connection = wc_kledo()->get_connection_handler();

		if ( ! $connection->is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Connect this store to Kledo first, on the Configure tab.', 'wc-kledo' ),
			);
		}

		$key = self::create_key();

		if ( null === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not create a WooCommerce API key for Kledo. Please try again.', 'wc-kledo' ),
			);
		}

		try {
			$request = new WC_Kledo_Request_Product_Connect();
			$result  = $request->connect( $key['consumer_key'], $key['consumer_secret'], $warehouse_id );
		} catch ( Exception $exception ) {
			self::revoke_key( $key['key_id'] );

			wc_kledo_log_warning(
				sprintf( 'Kledo product connect: request failed — %s', $exception->getMessage() )
			);

			return array(
				'ok'      => false,
				'message' => __( 'Could not reach Kledo. Please try again shortly.', 'wc-kledo' ),
			);
		}

		if ( ! $result['ok'] ) {
			// Kledo refused, so the key it was meant for has no purpose. Revoking keeps the
			// store's key list honest instead of accumulating one dead key per failed attempt.
			self::revoke_key( $key['key_id'] );

			return array(
				'ok'      => false,
				'message' => $result['message'],
			);
		}

		// A previous key is replaced, not kept: Kledo only ever holds the newest one, so an older
		// row would be a live credential with no reader.
		$previous_key_id = (int) get_option( self::KEY_ID_OPTION, 0 );

		if ( $previous_key_id > 0 && $previous_key_id !== $key['key_id'] ) {
			self::revoke_key( $previous_key_id );
		}

		update_option( self::CONNECT_ID_OPTION, (string) $result['data']['connect_id'] );
		update_option( self::KEY_ID_OPTION, (string) $key['key_id'] );
		update_option( self::TRUNCATED_KEY_OPTION, $key['truncated_key'] );
		update_option(
			self::STORE_URL_OPTION,
			isset( $result['data']['store_url'] ) ? (string) $result['data']['store_url'] : get_home_url()
		);

		return array(
			'ok'      => true,
			'message' => '' !== $result['message']
				? $result['message']
				: __( 'Product sync is connected.', 'wc-kledo' ),
		);
	}

	/**
	 * Forget the connection locally and revoke the key Kledo was using.
	 *
	 * Both halves matter. Revoking without clearing would leave Kledo pulling with a dead key;
	 * clearing without revoking would leave a live read/write key that nothing reads.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$key_id = (int) get_option( self::KEY_ID_OPTION, 0 );

		if ( $key_id > 0 ) {
			self::revoke_key( $key_id );
		}

		delete_option( self::CONNECT_ID_OPTION );
		delete_option( self::KEY_ID_OPTION );
		delete_option( self::TRUNCATED_KEY_OPTION );
		delete_option( self::STORE_URL_OPTION );
	}

	/**
	 * Create a read/write WooCommerce REST key owned by the current user.
	 *
	 * Mirrors what `WC_Auth::create_keys()` does, because that is the only shape WooCommerce's own
	 * authentication accepts: the consumer key is stored **hashed** (`wc_api_hash()`) and the
	 * plaintext is returned to the caller once and never persisted.
	 *
	 * @return array{key_id: int, consumer_key: string, consumer_secret: string, truncated_key: string}|null
	 * @since 1.9.0
	 */
	private static function create_key(): ?array {
		global $wpdb;

		if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return null;
		}

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => get_current_user_id(),
				'description'     => self::KEY_DESCRIPTION,
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wc_kledo_log_warning( 'Kledo product connect: could not insert a WooCommerce API key row.' );

			return null;
		}

		return array(
			'key_id'          => (int) $wpdb->insert_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'truncated_key'   => substr( $consumer_key, -7 ),
		);
	}

	/**
	 * Delete one key row.
	 *
	 * @param  int  $key_id  Row id in `woocommerce_api_keys`.
	 *
	 * @return void
	 * @since 1.9.0
	 */
	private static function revoke_key( int $key_id ): void {
		global $wpdb;

		if ( $key_id <= 0 ) {
			return;
		}

		$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
	}
}
