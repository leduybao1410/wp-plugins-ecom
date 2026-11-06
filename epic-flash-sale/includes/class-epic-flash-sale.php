<?php
/**
 * Limited-quantity flash sale: keeps a variation's sale price until its stock
 * runs out, then restores the regular price and takes the variation off stock
 * management so it keeps selling at the normal price.
 *
 * A variation opts in by carrying these two meta keys (set once, in wp-admin
 * or through the WooCommerce REST API):
 *
 *   _epic_flash_sale_qty            units in the promotion (e.g. 10)
 *   _epic_flash_sale_regular_price  price to restore afterwards (e.g. 50000)
 *
 * The variation's own `_sale_price` carries the promotional price, so
 * WooCommerce itself shows the struck-through price, charges the sale price,
 * and validates stock — this class only performs the switch back once the
 * promoted units are gone. The meta is deleted when the switch happens, so it
 * runs exactly once per variation.
 *
 * @package Epic_Flash_Sale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Epic_Flash_Sale {

	const QTY_META     = '_epic_flash_sale_qty';
	const REGULAR_META = '_epic_flash_sale_regular_price';

	/** Guards against re-entering the switch while the variation is being saved. */
	private static $working = false;

	/** Variation ids already queued for a deferred check in this request. */
	private static $deferred = array();

	public static function init() {
		// Primary path: an order consumed the last promoted unit.
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'on_order_stock_reduced' ), 20, 1 );
		// Safety net for manual stock edits and REST stock updates. Deferred to
		// shutdown because this hook fires from inside the variation's own save.
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_variation_stock_set' ), 20, 1 );
	}

	/**
	 * @param WC_Order $order Order whose stock was just reduced.
	 */
	public static function on_order_stock_reduced( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product instanceof WC_Product_Variation ) {
				self::end_if_sold_out( $product );
			}
		}
	}

	/**
	 * @param WC_Product $product Variation whose stock field was just written.
	 */
	public static function on_variation_stock_set( $product ) {
		if ( ! $product instanceof WC_Product_Variation ) {
			return;
		}
		$id = $product->get_id();
		if ( ! $id || isset( self::$deferred[ $id ] ) ) {
			return;
		}
		self::$deferred[ $id ] = true;
		add_action(
			'shutdown',
			static function () use ( $id ) {
				$fresh = wc_get_product( $id );
				if ( $fresh instanceof WC_Product_Variation ) {
					self::end_if_sold_out( $fresh );
				}
			}
		);
	}

	/**
	 * Switches the variation back to its regular price once the promoted
	 * quantity is gone. Safe to call more than once: the meta disappears on
	 * the first successful switch.
	 *
	 * @param WC_Product $variation Variation to inspect.
	 */
	public static function end_if_sold_out( $variation ) {
		if ( self::$working || ! $variation instanceof WC_Product_Variation ) {
			return;
		}
		if ( (int) $variation->get_meta( self::QTY_META ) <= 0 ) {
			return;
		}
		$stock = $variation->get_stock_quantity();
		if ( null === $stock || (int) $stock > 0 ) {
			return;
		}

		self::$working = true;
		try {
			$regular = (string) $variation->get_meta( self::REGULAR_META );
			if ( '' !== $regular && is_numeric( $regular ) ) {
				$variation->set_regular_price( wc_format_decimal( $regular ) );
			}
			$variation->set_sale_price( '' );
			$variation->set_date_on_sale_from( null );
			$variation->set_date_on_sale_to( null );
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
			// '' (not null) is what WC_Product::set_stock_quantity turns into a
			// real null; null itself would be cast to 0 by wc_stock_amount().
			$variation->set_stock_quantity( '' );
			$variation->delete_meta_data( self::QTY_META );
			$variation->delete_meta_data( self::REGULAR_META );
			$variation->save();

			$parent_id = $variation->get_parent_id();
			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent ) {
					// Re-save the parent so its cached price range and
					// "on sale" state pick up the new variation price.
					$parent->save();
				}
				wc_delete_product_transients( $parent_id );
			}
		} finally {
			self::$working = false;
		}
	}
}
