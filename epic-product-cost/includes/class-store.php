<?php
/**
 * Data layer: cost-history table, CRUD, weight-based scaling, and order-cost estimation.
 *
 * Cost is tracked per PARENT product id, at a single baseline pack size — 250g — because that's
 * the smallest size every coffee in the catalog sells (see product_variations: all ten converted
 * products use the `Trọng lượng` attribute with values 250g/500g/1kg; the three newest coffees are
 * still Simple products sold in 250g only). A 500g cost is exactly 2× the 250g cost and a 1kg cost
 * is exactly 4× — cost scales linearly with the weight of beans that actually goes into the bag,
 * unlike retail price, which gets a bulk discount at larger sizes. This is a deliberate v1 decision
 * (see PLAN.md) — it does not assume prices scale linearly, only cost.
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_Cost_Store {

	/**
	 * The pack size, in grams, that cost is entered against. Every multiplier is relative to this.
	 */
	const BASE_GRAMS = 250;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_product_cost_history';
	}

	/**
	 * Create/upgrade the table. Called on activation, and defensively from the admin screen in
	 * case the plugin was ever activated without WooCommerce present.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			cost_250g DECIMAL(14,2) NOT NULL DEFAULT 0,
			effective_date DATE NOT NULL,
			note VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY effective_date (effective_date),
			UNIQUE KEY product_effective_date (product_id, effective_date)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	// ---------------------------------------------------------------------
	// Weight parsing / scaling — mirrors the website's parseWeightLabelToGrams()
	// (src/lib/data.ts) so a "Trọng lượng" value always resolves the same way.
	// ---------------------------------------------------------------------

	/**
	 * Parse a pack-size label like "250g", "500 g", "1kg", "1.0kg" into grams.
	 *
	 * @param string $label
	 * @return int|null Null if the label isn't a recognizable weight.
	 */
	public static function parse_weight_label_to_grams( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return null;
		}
		if ( preg_match( '/^([\d.,]+)\s*kg$/i', $label, $m ) ) {
			return (int) round( (float) str_replace( ',', '.', $m[1] ) * 1000 );
		}
		if ( preg_match( '/^([\d.,]+)\s*g$/i', $label, $m ) ) {
			return (int) round( (float) str_replace( ',', '.', $m[1] ) );
		}
		return null;
	}

	/**
	 * Cost multiplier for a given pack size relative to the 250g baseline (500g → 2, 1kg → 4).
	 */
	public static function multiplier_for_grams( $grams ) {
		return ( (float) $grams ) / self::BASE_GRAMS;
	}

	// ---------------------------------------------------------------------
	// Cost history CRUD
	// ---------------------------------------------------------------------

	/**
	 * The cost row in effect on a given date: the most recent entry with effective_date <= $date.
	 * If $date predates every recorded entry, falls back to the EARLIEST known entry — a best-guess
	 * assumption that cost didn't change before tracking started — rather than returning nothing.
	 *
	 * @param int         $product_id
	 * @param string|null $date Y-m-d. Defaults to today.
	 * @return object{cost_250g:float,effective_date:string}|null Null if this product has no cost data at all.
	 */
	public static function get_cost_as_of( $product_id, $date = null ) {
		global $wpdb;
		$table = self::table();
		$date  = $date ? $date : current_time( 'Y-m-d' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cost_250g, effective_date FROM {$table}
				WHERE product_id = %d AND effective_date <= %s
				ORDER BY effective_date DESC LIMIT 1",
				$product_id,
				$date
			)
		); // phpcs:ignore

		if ( $row ) {
			return $row;
		}

		// Nothing on or before $date — fall back to the earliest entry we do have, if any.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cost_250g, effective_date FROM {$table}
				WHERE product_id = %d
				ORDER BY effective_date ASC LIMIT 1",
				$product_id
			)
		); // phpcs:ignore
	}

	/**
	 * Every history row for a product, most recent first.
	 */
	public static function get_history( $product_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY effective_date DESC, id DESC",
				$product_id
			)
		); // phpcs:ignore
	}

	/**
	 * Record a cost effective from a given date. Updates in place if a row already exists for the
	 * exact same (product_id, effective_date) — re-saving the same date is a correction, not a new
	 * historical fact — otherwise inserts a new row, preserving the earlier ones.
	 *
	 * @return int The row id.
	 */
	public static function upsert_cost( $product_id, $cost_250g, $effective_date, $note = '' ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND effective_date = %s",
				$product_id,
				$effective_date
			)
		); // phpcs:ignore

		$fields = array(
			'product_id'      => (int) $product_id,
			'cost_250g'       => (float) $cost_250g,
			'effective_date'  => $effective_date,
			'note'            => sanitize_text_field( $note ),
			'updated_at'      => $now,
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $existing_id ) ); // phpcs:ignore
			return (int) $existing_id;
		}

		$fields['created_by'] = get_current_user_id();
		$fields['created_at'] = $now;
		$wpdb->insert( $table, $fields ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	public static function delete_history_entry( $id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->delete( $table, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * The 250g regular price, for the reference/margin columns on the admin screen. Variable
	 * products: the price of whichever variation is tagged 250g. Simple products: the product's own
	 * regular price (every simple coffee in this catalog is sold in 250g only).
	 *
	 * @param WC_Product $product
	 * @return float|null
	 */
	public static function get_reference_price_250g( $product ) {
		if ( ! $product ) {
			return null;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			$price = $product->get_regular_price();
			return '' === $price ? null : (float) $price;
		}
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation ) {
				continue;
			}
			foreach ( $variation->get_attributes() as $value ) {
				if ( self::BASE_GRAMS === self::parse_weight_label_to_grams( $value ) ) {
					$price = $variation->get_regular_price();
					return '' === $price ? null : (float) $price;
				}
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Order-cost estimation (used by EPIC Distributor Profit's order picker)
	// ---------------------------------------------------------------------

	/**
	 * Pack size (in grams) actually sold on an order line item: the variation's "Trọng lượng" (or
	 * any attribute meta containing "lượng"/"trọng"/"weight"), falling back to 250g for a Simple
	 * product or an unrecognized/missing attribute — every Simple coffee in this catalog is 250g-only.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return int
	 */
	private static function grams_for_order_item( $item ) {
		if ( ! $item->get_variation_id() ) {
			return self::BASE_GRAMS;
		}

		// The visible order-item meta (what shows on the order screen/emails) carries the actual
		// chosen label ("250g"/"500g"/"1kg") regardless of how the attribute slug got sanitized.
		foreach ( $item->get_meta_data() as $meta ) {
			$key = trim( (string) $meta->key );
			if ( '' === $key || '_' === $key[0] ) {
				continue; // hidden/internal meta.
			}
			if ( false !== stripos( $key, 'lượng' ) || false !== stripos( $key, 'trọng' ) || 0 === strcasecmp( $key, 'weight' ) ) {
				$grams = self::parse_weight_label_to_grams( $meta->value );
				if ( $grams ) {
					return $grams;
				}
			}
		}

		// Fall back to asking the variation object directly.
		$variation = wc_get_product( $item->get_variation_id() );
		if ( $variation ) {
			foreach ( $variation->get_attributes() as $value ) {
				$grams = self::parse_weight_label_to_grams( $value );
				if ( $grams ) {
					return $grams;
				}
			}
		}

		return self::BASE_GRAMS;
	}

	/**
	 * Estimated cost for one order line item, scaled for pack size, as of a given date.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $as_of_date Y-m-d.
	 * @return array{unit_cost:float,line_cost:float,grams:int,known:bool}
	 */
	public static function estimate_line_item_cost( $item, $as_of_date ) {
		$product_id = $item->get_product_id();
		$base       = self::get_cost_as_of( $product_id, $as_of_date );

		if ( ! $base ) {
			return array(
				'unit_cost' => 0.0,
				'line_cost' => 0.0,
				'grams'     => self::BASE_GRAMS,
				'known'     => false,
			);
		}

		$grams      = self::grams_for_order_item( $item );
		$multiplier = self::multiplier_for_grams( $grams );
		$unit_cost  = round( (float) $base->cost_250g * $multiplier, 2 );
		$line_cost  = round( $unit_cost * (int) $item->get_quantity(), 2 );

		return array(
			'unit_cost' => $unit_cost,
			'line_cost' => $line_cost,
			'grams'     => $grams,
			'known'     => true,
		);
	}

	/**
	 * Total estimated product cost for a whole order, as of the order's own creation date — so a
	 * later cost change never rewrites the estimate for an order placed under the old cost.
	 *
	 * @param WC_Order $order
	 * @return array{total:float,complete:bool}
	 */
	public static function estimate_order_cost( $order ) {
		$as_of_date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : current_time( 'Y-m-d' );

		$total    = 0.0;
		$complete = true;

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$line = self::estimate_line_item_cost( $item, $as_of_date );
			$total += $line['line_cost'];
			if ( ! $line['known'] ) {
				$complete = false;
			}
		}

		return array(
			'total'    => round( $total, 2 ),
			'complete' => $complete,
		);
	}
}
