<?php
/**
 * One-time "starter costs" import — the 2026-09-12 GIÁ VỐN cost sheet the user provided when this
 * plugin was built, matched to live product IDs. Idempotent: only ever touches a product that has
 * NO cost history yet, so it's safe to leave the button visible indefinitely and re-run any time —
 * it never overwrites a cost someone already entered.
 *
 * Matched by product name against the live catalog on admin.epicroastery.coffee at build time (see
 * PLAN.md for the full mapping and the one sheet row that had no matching product yet: "Ethiopia
 * Guji" — the only live Ethiopia product is Yirgacheffe).
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_Cost_Seed {

	const EFFECTIVE_DATE = '2026-09-12';
	const NOTE            = 'Bảng chi phí 2026-09-12';

	/**
	 * product_id => cost per 250g (VND). IDs are this store's actual WooCommerce product IDs.
	 */
	private static function starter_costs() {
		return array(
			13  => 77750,   // Robusta Natural
			14  => 107750,  // Arabica
			15  => 98750,   // Robusta Honey
			16  => 132000,  // Peru — Cajamarca Jaen
			17  => 126000,  // Brazil — Guima Cafe Rodomunho "32248"
			18  => 192000,  // Ethiopia (Yirgacheffe)
			19  => 109500,  // Daily Muse Blend
			20  => 118500,  // Sweet Love Blend
			21  => 115500,  // Cozy Blend
			22  => 129000,  // Gentle Brew Blend 4
			296 => 228000,  // Kenya — Nyeri Nyeshun
			297 => 467500,  // Peru — Cajamarca Gesha Honey
			298 => 533500,  // Colombia — Paraiso Ultrasound Anaerobic
		);
	}

	/**
	 * Which of the starter-cost product ids still have zero history rows (so the "Load starter
	 * costs" button only appears/offers to fill in what's actually missing).
	 *
	 * @return int[]
	 */
	public static function unseeded_product_ids() {
		$unseeded = array();
		foreach ( array_keys( self::starter_costs() ) as $product_id ) {
			if ( ! wc_get_product( $product_id ) ) {
				continue; // product no longer exists on this site.
			}
			if ( empty( Epic_Product_Cost_Store::get_history( $product_id ) ) ) {
				$unseeded[] = $product_id;
			}
		}
		return $unseeded;
	}

	/**
	 * Insert the starter cost for every product that has no history yet. Returns a human-readable
	 * summary for the admin notice.
	 */
	public static function run() {
		$applied = 0;
		$skipped = 0;

		foreach ( self::starter_costs() as $product_id => $cost ) {
			if ( ! wc_get_product( $product_id ) ) {
				continue;
			}
			if ( ! empty( Epic_Product_Cost_Store::get_history( $product_id ) ) ) {
				++$skipped;
				continue;
			}
			Epic_Product_Cost_Store::upsert_cost( $product_id, $cost, self::EFFECTIVE_DATE, self::NOTE );
			++$applied;
		}

		return sprintf(
			/* translators: 1: number of products seeded, 2: number already had a cost and were left alone */
			__( 'Loaded starter costs for %1$d product(s); %2$d already had a cost and were left unchanged.', 'epic-product-cost' ),
			$applied,
			$skipped
		);
	}
}
