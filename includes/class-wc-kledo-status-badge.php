<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * One vocabulary for the Kledo state of a transaction, wherever it is shown.
 *
 * The order list column, its filter, the order meta box and the Transactions screen all describe
 * the same state; each spelling it out on its own is how "Sent" on one screen came to mean
 * something other than "Sent" on another. Labels, explanations and pill colours live here.
 *
 * The CSS classes are WooCommerce's own order-status pills, already loaded wherever an order
 * list renders.
 *
 * @since 1.8.0
 */
class WC_Kledo_Status_Badge {
	/**
	 * Label, explanation and pill class for one state.
	 *
	 * @param  string $state  A value from `wc_kledo_get_remote_state()`.
	 * @param  string $type   `order` or `invoice`; only changes the wording of the explanation.
	 *
	 * @return array{label: string, description: string, class: string, action: string}
	 *         `action` is the next step for the admin, empty when there is none.
	 * @since 1.8.0
	 */
	public static function describe( string $state, string $type ): array {
		$is_order = 'order' === $type;

		switch ( $state ) {
			case 'confirmed':
				return array(
					'label'       => __( 'In Kledo', 'wc-kledo' ),
					'description' => $is_order
						? __( 'The sales order was read back from Kledo and exists there.', 'wc-kledo' )
						: __( 'The invoice was read back from Kledo and exists there.', 'wc-kledo' ),
					'class'       => 'status-completed',
					'action'      => '',
				);

			case 'verifying':
				return array(
					'label'       => __( 'Waiting for Kledo', 'wc-kledo' ),
					'description' => __( 'Kledo accepted the request and is still creating the transaction. The plugin checks again automatically.', 'wc-kledo' ),
					'class'       => 'status-processing',
					'action'      => '',
				);

			case 'waiting_sales_order':
				return array(
					'label'       => __( 'Waiting for sales order', 'wc-kledo' ),
					'description' => __( 'The invoice is held until its sales order exists in Kledo, so the two can be linked. It is sent automatically.', 'wc-kledo' ),
					'class'       => 'status-processing',
					'action'      => '',
				);

			case 'legacy_synced':
				return array(
					'label'       => __( 'Sent (not checked)', 'wc-kledo' ),
					'description' => __( 'Sent before this version of the plugin, which never checked whether it exists in Kledo.', 'wc-kledo' ),
					'class'       => 'status-on-hold',
					'action'      => __( 'Run "Check status in Kledo" to confirm it.', 'wc-kledo' ),
				);

			case 'retrying':
				return array(
					'label'       => __( 'Send failed, retrying', 'wc-kledo' ),
					'description' => __( 'The request to Kledo failed. The plugin retries automatically.', 'wc-kledo' ),
					'class'       => 'status-on-hold',
					'action'      => __( 'No action needed unless it keeps failing; you can also resend now.', 'wc-kledo' ),
				);

			case 'failed':
				return array(
					'label'       => __( 'Send failed', 'wc-kledo' ),
					'description' => __( 'The request to Kledo kept failing and automatic retries have stopped.', 'wc-kledo' ),
					'class'       => 'status-failed',
					'action'      => __( 'Check the connection settings, then resend.', 'wc-kledo' ),
				);

			case 'rejected':
				return array(
					'label'       => __( 'Rejected by Kledo', 'wc-kledo' ),
					'description' => __( 'Kledo refused the data. Sending the same data again will be refused again.', 'wc-kledo' ),
					'class'       => 'status-failed',
					'action'      => __( 'Fix the data named in the error (product, customer, account), then resend.', 'wc-kledo' ),
				);

			case 'missing':
				return array(
					'label'       => __( 'Failed in Kledo', 'wc-kledo' ),
					'description' => __( 'Kledo accepted the request, but the transaction never appeared in Kledo.', 'wc-kledo' ),
					'class'       => 'status-failed',
					'action'      => __( 'Check it in Kledo, then resend.', 'wc-kledo' ),
				);
		}

		return array(
			'label'       => __( 'Not sent', 'wc-kledo' ),
			'description' => $is_order
				? __( 'No sales order has been sent to Kledo for this order.', 'wc-kledo' )
				: __( 'No invoice has been sent to Kledo for this order.', 'wc-kledo' ),
			'class'       => '',
			'action'      => '',
		);
	}

	/**
	 * The pill markup for one state, escaped and ready to echo.
	 *
	 * @param  string $state
	 * @param  string $type
	 * @param  string $reference  Kledo reference to add to the tooltip, if known.
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public static function render( string $state, string $type, string $reference = '' ): string {
		$badge = self::describe( $state, $type );
		$title = $badge['description'];

		if ( '' !== $reference ) {
			/* translators: 1: state explanation, 2: Kledo reference number */
			$title = sprintf( __( '%1$s Kledo reference: %2$s.', 'wc-kledo' ), $title, $reference );
		}

		if ( '' === $badge['class'] ) {
			return sprintf(
				'<span class="wc-kledo-badge wc-kledo-badge--none" title="%1$s">%2$s</span>',
				esc_attr( $title ),
				esc_html( $badge['label'] )
			);
		}

		return sprintf(
			'<mark class="order-status wc-kledo-badge %1$s" title="%2$s"><span>%3$s</span></mark>',
			esc_attr( $badge['class'] ),
			esc_attr( $title ),
			esc_html( $badge['label'] )
		);
	}

	/**
	 * Label of a transaction type, as shown to admins.
	 *
	 * @param  string $type
	 *
	 * @return string
	 * @since 1.8.0
	 */
	public static function type_label( string $type ): string {
		return 'order' === $type ? __( 'Sales order', 'wc-kledo' ) : __( 'Invoice', 'wc-kledo' );
	}

	/**
	 * Choices offered by the state filters, keyed by `wc_kledo_get_remote_state_meta_query()` group.
	 *
	 * @return array<string, string>
	 * @since 1.8.0
	 */
	public static function filter_choices(): array {
		return array(
			'not_sent'  => __( 'Not sent', 'wc-kledo' ),
			'pending'   => __( 'Waiting for Kledo', 'wc-kledo' ),
			'confirmed' => __( 'In Kledo', 'wc-kledo' ),
			'failed'    => __( 'Failed (needs attention)', 'wc-kledo' ),
		);
	}
}
