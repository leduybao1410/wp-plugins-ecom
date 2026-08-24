<?php
/**
 * Single source of truth for every custom coupon meta key this plugin
 * reads/writes, so the admin tab, the validators, the cart-effect classes,
 * and the bulk generator (which clones all of them) never drift apart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Meta {

	const FIRST_ORDER_ONLY = '_epic_first_order_only';

	const ALLOWLIST = '_epic_allowlist';

	const SCHEDULE_DAYS  = '_epic_schedule_days';  // comma list of mon,tue,wed,thu,fri,sat,sun
	const SCHEDULE_START = '_epic_schedule_start'; // HH:MM
	const SCHEDULE_END   = '_epic_schedule_end';   // HH:MM

	const BXGY_ENABLED        = '_epic_bxgy_enabled';
	const BXGY_TRIGGER_TYPE   = '_epic_bxgy_trigger_type'; // product|category
	const BXGY_TRIGGER_ID     = '_epic_bxgy_trigger_id';
	const BXGY_TRIGGER_QTY    = '_epic_bxgy_trigger_qty';
	const BXGY_REWARD_TYPE    = '_epic_bxgy_reward_type'; // product|category
	const BXGY_REWARD_ID      = '_epic_bxgy_reward_id';
	const BXGY_REWARD_QTY     = '_epic_bxgy_reward_qty';
	const BXGY_DISCOUNT_TYPE  = '_epic_bxgy_discount_type'; // free|percent|fixed
	const BXGY_DISCOUNT_VALUE = '_epic_bxgy_discount_value';
	const BXGY_MAX_REPEATS    = '_epic_bxgy_max_repeats'; // 0 = unlimited

	const AUTO_APPLY_ENABLED  = '_epic_auto_apply_enabled';
	const AUTO_APPLY_CATEGORY = '_epic_auto_apply_category'; // optional term id, 0/empty = none

	const GENERATED_FROM = '_epic_generated_from';

	/**
	 * All custom meta keys this plugin owns, in one list — used by the bulk
	 * generator to clone a template coupon's advanced rules onto each new
	 * code, and handy for anyone auditing what this plugin touches.
	 *
	 * @return string[]
	 */
	public static function all_keys() {
		return array(
			self::FIRST_ORDER_ONLY,
			self::ALLOWLIST,
			self::SCHEDULE_DAYS,
			self::SCHEDULE_START,
			self::SCHEDULE_END,
			self::BXGY_ENABLED,
			self::BXGY_TRIGGER_TYPE,
			self::BXGY_TRIGGER_ID,
			self::BXGY_TRIGGER_QTY,
			self::BXGY_REWARD_TYPE,
			self::BXGY_REWARD_ID,
			self::BXGY_REWARD_QTY,
			self::BXGY_DISCOUNT_TYPE,
			self::BXGY_DISCOUNT_VALUE,
			self::BXGY_MAX_REPEATS,
			self::AUTO_APPLY_ENABLED,
			self::AUTO_APPLY_CATEGORY,
		);
	}
}
