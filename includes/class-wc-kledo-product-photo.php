<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decides which product photo URL, if any, is sent to Kledo.
 *
 * Kledo downloads the photo when it creates the product. This class only sends a URL that is
 * well formed and points at a JPEG, PNG, GIF or WebP attachment, so Kledo is not asked to fetch
 * an SVG, a broken link or a URL with credentials in it. It does no network I/O: whether the host
 * is reachable and public is decided by Kledo, the server that actually fetches it.
 *
 * A photo that fails a check is left out and the order is still sent: `photo` is optional, and a
 * null value is dropped from the request body. The reason is logged without the URL, which may be
 * a signed link.
 *
 * @since 1.8.3
 */
class WC_Kledo_Product_Photo {
	/**
	 * The longest URL that is sent. Signed offload and CDN links can run to a few thousand
	 * characters.
	 *
	 * @var int
	 * @since 1.8.3
	 */
	public const MAX_URL_LENGTH = 4096;

	/**
	 * The image types that are sent, in the `$mimes` format `wp_check_filetype()` takes.
	 *
	 * @var array<string, string>
	 * @since 1.8.3
	 */
	public const ALLOWED_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * The URL schemes that are sent.
	 *
	 * @var string[]
	 * @since 1.8.3
	 */
	private const ALLOWED_SCHEMES = array( 'http', 'https' );

	/**
	 * The explicit ports that are allowed. A URL without a port uses its scheme's default.
	 *
	 * @var int[]
	 * @since 1.8.3
	 */
	private const ALLOWED_PORTS = array( 80, 443 );

	/**
	 * Get the photo URL to send for a product.
	 *
	 * For a variation, `get_image_id()` falls back to the parent product's image when the
	 * variation has none of its own.
	 *
	 * @param  \WC_Product $product
	 *
	 * @return string|null The full-size attachment URL, or null when no photo is sent.
	 * @since 1.8.3
	 */
	public static function get_url( WC_Product $product ): ?string {
		$image_id = (int) $product->get_image_id();

		// No image is the normal case, not a rejection, so it is not logged.
		if ( $image_id <= 0 ) {
			return null;
		}

		$url    = null;
		$reason = self::check_attachment( $image_id );

		if ( '' === $reason ) {
			$attachment_url = wp_get_attachment_url( $image_id );

			if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
				$url = $attachment_url;
			} else {
				$reason = 'no_url';
			}
		}

		/**
		 * Filters the product photo URL sent to Kledo.
		 *
		 * Runs after the attachment checks and before the URL checks, so a store whose CDN serves
		 * images in an unusual way can send a URL the attachment checks rejected, or return null
		 * to send no photo. The URL checks (http/https, length, no credentials, allowed port) still
		 * run on whatever is returned.
		 *
		 * @param  string|null  $url       The full-size attachment URL, or null when a check failed.
		 * @param  \WC_Product  $product   The product the order line refers to.
		 * @param  int          $image_id  The product's image attachment ID.
		 * @param  string       $reason    Why the attachment was rejected; empty when it passed.
		 *
		 * @since 1.8.3
		 */
		$url = apply_filters( 'wc_kledo_product_photo_url', $url, $product, $image_id, $reason );

		if ( null === $url ) {
			if ( '' !== $reason ) {
				self::log_rejection( $product, $image_id, $reason );
			}

			return null;
		}

		$reason = self::check_url( $url );

		if ( '' !== $reason ) {
			self::log_rejection( $product, $image_id, $reason );

			return null;
		}

		return $url;
	}

	/**
	 * Check that the image ID is an attachment of an allowed image type.
	 *
	 * The type is read from the attachment's stored MIME type and its stored file name, never
	 * from the URL: offload and CDN plugins may rewrite the URL to a path without an extension.
	 * The local file is not opened, because offload plugins delete it.
	 *
	 * @param  int $image_id
	 *
	 * @return string The rejection reason, or an empty string when the attachment passes.
	 * @since 1.8.3
	 */
	private static function check_attachment( int $image_id ): string {
		if ( 'attachment' !== get_post_type( $image_id ) ) {
			return 'not_attachment';
		}

		$mime_type = get_post_mime_type( $image_id );

		if ( ! in_array( $mime_type, self::ALLOWED_TYPES, true ) ) {
			return 'type_not_allowed';
		}

		// The explicit `$mimes` keeps the result independent of the WordPress version (WebP is
		// only in the default list since 5.8) and of the current user, which a cron run lacks.
		$file_path = (string) wp_parse_url( (string) get_post_meta( $image_id, '_wp_attached_file', true ), PHP_URL_PATH );
		$file_type = wp_check_filetype( wp_basename( $file_path ), self::ALLOWED_TYPES );

		if ( $file_type['type'] !== $mime_type ) {
			return 'type_mismatch';
		}

		return '';
	}

	/**
	 * Check that the URL is a well-formed absolute http(s) URL.
	 *
	 * @param  mixed $url
	 *
	 * @return string The rejection reason, or an empty string when the URL passes.
	 * @since 1.8.3
	 */
	private static function check_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return 'no_url';
		}

		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			return 'too_long';
		}

		if ( preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return 'control_chars';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return 'no_host';
		}

		// Also rejects data: URIs, relative paths and protocol-relative `//host/...` URLs.
		if ( ! in_array( strtolower( $parts['scheme'] ?? '' ), self::ALLOWED_SCHEMES, true ) ) {
			return 'bad_scheme';
		}

		if ( empty( $parts['host'] ) ) {
			return 'no_host';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'credentials';
		}

		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], self::ALLOWED_PORTS, true ) ) {
			return 'bad_port';
		}

		// What is sent must be exactly what was checked, so a URL esc_url_raw() would change is
		// rejected rather than cleaned.
		if ( esc_url_raw( $url, self::ALLOWED_SCHEMES ) !== $url ) {
			return 'not_canonical';
		}

		return '';
	}

	/**
	 * Log why a product photo is not sent. The URL is left out: it may be a signed link.
	 *
	 * @param  \WC_Product $product
	 * @param  int         $image_id
	 * @param  string      $reason
	 *
	 * @return void
	 * @since 1.8.3
	 */
	private static function log_rejection( WC_Product $product, int $image_id, string $reason ): void {
		wc_kledo_log_warning(
			sprintf( 'Product %d photo (attachment %d) not sent to Kledo: %s.', $product->get_id(), $image_id, $reason ),
			array(
				'product_id'    => $product->get_id(),
				'attachment_id' => $image_id,
				'reason'        => $reason,
			)
		);
	}
}
