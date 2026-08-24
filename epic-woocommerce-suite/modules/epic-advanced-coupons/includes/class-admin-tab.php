<?php
/**
 * Adds the "Advanced Rules" tab to the native WooCommerce coupon editor
 * and renders/saves every field on it (first-time-customer-only, customer
 * allowlist, day/time schedule, Buy X Get Y, auto-apply). The bulk unique
 * code generator gets its own tab, added by class-bulk-generate.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Admin_Tab {

	public static function init() {
		add_filter( 'woocommerce_coupon_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_coupon_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function add_tab( $tabs ) {
		$tabs['epic_advanced_rules'] = array(
			'label'  => __( 'Advanced Rules', 'epic-advanced-coupons' ),
			'target' => 'epic_advanced_rules_data',
			'class'  => array(),
		);
		return $tabs;
	}

	public static function render_panel() {
		global $post;
		$coupon_id = $post->ID;
		$meta      = Epic_Adv_Coupons_Meta::class;

		$first_order_only = get_post_meta( $coupon_id, $meta::FIRST_ORDER_ONLY, true ) === 'yes';
		$allowlist        = get_post_meta( $coupon_id, $meta::ALLOWLIST, true );
		$schedule_days    = get_post_meta( $coupon_id, $meta::SCHEDULE_DAYS, true );
		$schedule_days    = $schedule_days ? array_map( 'trim', explode( ',', $schedule_days ) ) : array();
		$schedule_start   = get_post_meta( $coupon_id, $meta::SCHEDULE_START, true );
		$schedule_end     = get_post_meta( $coupon_id, $meta::SCHEDULE_END, true );

		$bxgy_enabled  = get_post_meta( $coupon_id, $meta::BXGY_ENABLED, true ) === 'yes';
		$trigger_type  = get_post_meta( $coupon_id, $meta::BXGY_TRIGGER_TYPE, true ) ?: 'product';
		$trigger_id    = (int) get_post_meta( $coupon_id, $meta::BXGY_TRIGGER_ID, true );
		$trigger_qty   = get_post_meta( $coupon_id, $meta::BXGY_TRIGGER_QTY, true ) ?: 1;
		$reward_type   = get_post_meta( $coupon_id, $meta::BXGY_REWARD_TYPE, true ) ?: 'product';
		$reward_id     = (int) get_post_meta( $coupon_id, $meta::BXGY_REWARD_ID, true );
		$reward_qty    = get_post_meta( $coupon_id, $meta::BXGY_REWARD_QTY, true ) ?: 1;
		$discount_type = get_post_meta( $coupon_id, $meta::BXGY_DISCOUNT_TYPE, true ) ?: 'free';
		$discount_val  = get_post_meta( $coupon_id, $meta::BXGY_DISCOUNT_VALUE, true );
		$max_repeats   = get_post_meta( $coupon_id, $meta::BXGY_MAX_REPEATS, true );
		$max_repeats   = '' === $max_repeats ? 1 : $max_repeats;

		$auto_apply_enabled  = get_post_meta( $coupon_id, $meta::AUTO_APPLY_ENABLED, true ) === 'yes';
		$auto_apply_category = (int) get_post_meta( $coupon_id, $meta::AUTO_APPLY_CATEGORY, true );

		$products    = self::get_product_options();
		$categories  = self::get_category_options();
		$day_labels  = array(
			'mon' => __( 'Mon', 'epic-advanced-coupons' ),
			'tue' => __( 'Tue', 'epic-advanced-coupons' ),
			'wed' => __( 'Wed', 'epic-advanced-coupons' ),
			'thu' => __( 'Thu', 'epic-advanced-coupons' ),
			'fri' => __( 'Fri', 'epic-advanced-coupons' ),
			'sat' => __( 'Sat', 'epic-advanced-coupons' ),
			'sun' => __( 'Sun', 'epic-advanced-coupons' ),
		);
		?>
		<div id="epic_advanced_rules_data" class="panel woocommerce_options_panel">

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'First-time customers', 'epic-advanced-coupons' ); ?></strong></p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => $meta::FIRST_ORDER_ONLY,
						'label'       => __( 'First-time customers only', 'epic-advanced-coupons' ),
						'description' => __( 'Only valid if the billing email has no prior order on this store (processing, completed, on-hold, or refunded).', 'epic-advanced-coupons' ),
						'value'       => $first_order_only ? 'yes' : 'no',
					)
				);
				?>
			</div>

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Customer allowlist', 'epic-advanced-coupons' ); ?></strong></p>
				<p class="form-field">
					<label for="epic_allowlist"><?php esc_html_e( 'Emails / phone numbers', 'epic-advanced-coupons' ); ?></label>
					<textarea id="epic_allowlist" name="epic_allowlist" rows="4" style="width:50%;" placeholder="jane@example.com&#10;*@vipclub.com&#10;0901234567"><?php echo esc_textarea( $allowlist ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One email, email wildcard (*@domain.com), or phone number per line. Leave blank for no allowlist restriction. A cart matches if its billing email OR billing phone matches any line.', 'epic-advanced-coupons' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Day / time schedule', 'epic-advanced-coupons' ); ?></strong></p>
				<p class="form-field">
					<label><?php esc_html_e( 'Allowed days', 'epic-advanced-coupons' ); ?></label>
					<?php foreach ( $day_labels as $key => $label ) : ?>
						<label style="display:inline-block;margin-right:10px;font-weight:normal;">
							<input type="checkbox" name="epic_schedule_days[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $schedule_days, true ) || empty( $schedule_days ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<span class="description"><?php esc_html_e( 'All checked (or none checked) = every day allowed.', 'epic-advanced-coupons' ); ?></span>
				</p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => 'epic_schedule_start',
						'label'             => __( 'Start time', 'epic-advanced-coupons' ),
						'placeholder'       => 'HH:MM',
						'value'             => $schedule_start,
						'description'       => __( 'Site local time, 24h HH:MM. Leave both start/end blank for no time-of-day restriction.', 'epic-advanced-coupons' ),
						'desc_tip'          => true,
						'custom_attributes' => array( 'pattern' => '[0-9]{1,2}:[0-9]{2}' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => 'epic_schedule_end',
						'label'             => __( 'End time', 'epic-advanced-coupons' ),
						'placeholder'       => 'HH:MM',
						'value'             => $schedule_end,
						'description'       => __( 'An end time earlier than the start time wraps past midnight (e.g. 22:00-02:00).', 'epic-advanced-coupons' ),
						'desc_tip'          => true,
						'custom_attributes' => array( 'pattern' => '[0-9]{1,2}:[0-9]{2}' ),
					)
				);
				?>
			</div>

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Buy X Get Y / bundle discount', 'epic-advanced-coupons' ); ?></strong></p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => $meta::BXGY_ENABLED,
						'label'       => __( 'Enable Buy X Get Y', 'epic-advanced-coupons' ),
						'description' => __( 'Applies as a separate cart discount line when the trigger condition is met — on top of (or instead of) this coupon\'s own discount amount above.', 'epic-advanced-coupons' ),
						'value'       => $bxgy_enabled ? 'yes' : 'no',
					)
				);
				?>
				<p class="form-field">
					<label><?php esc_html_e( 'Trigger', 'epic-advanced-coupons' ); ?></label>
					<select id="epic_bxgy_trigger_type" name="epic_bxgy_trigger_type" style="width:120px;">
						<option value="product" <?php selected( $trigger_type, 'product' ); ?>><?php esc_html_e( 'Product', 'epic-advanced-coupons' ); ?></option>
						<option value="category" <?php selected( $trigger_type, 'category' ); ?>><?php esc_html_e( 'Category', 'epic-advanced-coupons' ); ?></option>
					</select>
					<select id="epic_bxgy_trigger_id_product" name="epic_bxgy_trigger_id_product" style="width:40%;" class="epic-bxgy-trigger-product">
						<option value=""><?php esc_html_e( '— select product —', 'epic-advanced-coupons' ); ?></option>
						<?php foreach ( $products as $id => $title ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'product' === $trigger_type && $trigger_id === $id ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="epic_bxgy_trigger_id_category" name="epic_bxgy_trigger_id_category" style="width:40%;" class="epic-bxgy-trigger-category">
						<option value=""><?php esc_html_e( '— select category —', 'epic-advanced-coupons' ); ?></option>
						<?php foreach ( $categories as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'category' === $trigger_type && $trigger_id === $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					qty
					<input type="number" min="1" step="1" name="epic_bxgy_trigger_qty" value="<?php echo esc_attr( $trigger_qty ); ?>" style="width:60px;" />
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Reward', 'epic-advanced-coupons' ); ?></label>
					<select id="epic_bxgy_reward_type" name="epic_bxgy_reward_type" style="width:120px;">
						<option value="product" <?php selected( $reward_type, 'product' ); ?>><?php esc_html_e( 'Product', 'epic-advanced-coupons' ); ?></option>
						<option value="category" <?php selected( $reward_type, 'category' ); ?>><?php esc_html_e( 'Category', 'epic-advanced-coupons' ); ?></option>
					</select>
					<select id="epic_bxgy_reward_id_product" name="epic_bxgy_reward_id_product" style="width:40%;" class="epic-bxgy-reward-product">
						<option value=""><?php esc_html_e( '— select product —', 'epic-advanced-coupons' ); ?></option>
						<?php foreach ( $products as $id => $title ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'product' === $reward_type && $reward_id === $id ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="epic_bxgy_reward_id_category" name="epic_bxgy_reward_id_category" style="width:40%;" class="epic-bxgy-reward-category">
						<option value=""><?php esc_html_e( '— select category —', 'epic-advanced-coupons' ); ?></option>
						<?php foreach ( $categories as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'category' === $reward_type && $reward_id === $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					qty
					<input type="number" min="1" step="1" name="epic_bxgy_reward_qty" value="<?php echo esc_attr( $reward_qty ); ?>" style="width:60px;" />
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Reward discount', 'epic-advanced-coupons' ); ?></label>
					<select id="epic_bxgy_discount_type" name="epic_bxgy_discount_type" style="width:120px;">
						<option value="free" <?php selected( $discount_type, 'free' ); ?>><?php esc_html_e( '100% free', 'epic-advanced-coupons' ); ?></option>
						<option value="percent" <?php selected( $discount_type, 'percent' ); ?>><?php esc_html_e( '% off', 'epic-advanced-coupons' ); ?></option>
						<option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'epic-advanced-coupons' ); ?></option>
					</select>
					<input type="number" min="0" step="0.01" id="epic_bxgy_discount_value" name="epic_bxgy_discount_value" value="<?php echo esc_attr( $discount_val ); ?>" style="width:100px;" placeholder="0" />
					<span class="description"><?php esc_html_e( 'Per reward unit. Ignored when "100% free" is selected.', 'epic-advanced-coupons' ); ?></span>
				</p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => $meta::BXGY_MAX_REPEATS,
						'label'             => __( 'Max repeats per order', 'epic-advanced-coupons' ),
						'type'              => 'number',
						'value'             => $max_repeats,
						'custom_attributes' => array(
							'min'  => 0,
							'step' => 1,
						),
						'description'       => __( '0 = unlimited (deal repeats as many times as the trigger quantity allows).', 'epic-advanced-coupons' ),
						'desc_tip'          => true,
					)
				);
				?>
			</div>

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Auto-apply (no code needed)', 'epic-advanced-coupons' ); ?></strong></p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => $meta::AUTO_APPLY_ENABLED,
						'label'       => __( 'Auto-apply this coupon', 'epic-advanced-coupons' ),
						'description' => __( 'Applies automatically once the cart reaches this coupon\'s "Minimum spend" (set in the Usage restriction tab), and is removed automatically if the cart drops back below it — unless the customer removed it themselves.', 'epic-advanced-coupons' ),
						'value'       => $auto_apply_enabled ? 'yes' : 'no',
					)
				);
				?>
				<p class="form-field">
					<label for="epic_auto_apply_category"><?php esc_html_e( 'Also require category in cart', 'epic-advanced-coupons' ); ?></label>
					<select id="epic_auto_apply_category" name="epic_auto_apply_category" style="width:50%;">
						<option value=""><?php esc_html_e( '— no category requirement —', 'epic-advanced-coupons' ); ?></option>
						<?php foreach ( $categories as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $auto_apply_category, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>

		</div>
		<script>
		jQuery( function ( $ ) {
			function epicToggle( typeSelectId, productSelector, categorySelector ) {
				var $type = $( '#' + typeSelectId );
				function apply() {
					var isCategory = $type.val() === 'category';
					$( productSelector ).toggle( ! isCategory );
					$( categorySelector ).toggle( isCategory );
				}
				$type.on( 'change', apply );
				apply();
			}
			epicToggle( 'epic_bxgy_trigger_type', '.epic-bxgy-trigger-product', '.epic-bxgy-trigger-category' );
			epicToggle( 'epic_bxgy_reward_type', '.epic-bxgy-reward-product', '.epic-bxgy-reward-category' );

			function toggleDiscountValue() {
				$( '#epic_bxgy_discount_value' ).closest( 'p' ).toggle( $( '#epic_bxgy_discount_type' ).val() !== 'free' );
			}
			$( '#epic_bxgy_discount_type' ).on( 'change', toggleDiscountValue );
			toggleDiscountValue();
		} );
		</script>
		<?php
	}

	public static function save( $post_id ) {
		$meta = Epic_Adv_Coupons_Meta::class;

		update_post_meta( $post_id, $meta::FIRST_ORDER_ONLY, isset( $_POST[ $meta::FIRST_ORDER_ONLY ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WooCommerce's own coupon-save nonce.

		update_post_meta( $post_id, $meta::ALLOWLIST, isset( $_POST['epic_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['epic_allowlist'] ) ) : '' );

		$days = isset( $_POST['epic_schedule_days'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['epic_schedule_days'] ) ) : array();
		update_post_meta( $post_id, $meta::SCHEDULE_DAYS, implode( ',', $days ) );
		update_post_meta( $post_id, $meta::SCHEDULE_START, isset( $_POST['epic_schedule_start'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_schedule_start'] ) ) : '' );
		update_post_meta( $post_id, $meta::SCHEDULE_END, isset( $_POST['epic_schedule_end'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_schedule_end'] ) ) : '' );

		update_post_meta( $post_id, $meta::BXGY_ENABLED, isset( $_POST[ $meta::BXGY_ENABLED ] ) ? 'yes' : 'no' );

		$trigger_type = isset( $_POST['epic_bxgy_trigger_type'] ) && 'category' === $_POST['epic_bxgy_trigger_type'] ? 'category' : 'product';
		$reward_type  = isset( $_POST['epic_bxgy_reward_type'] ) && 'category' === $_POST['epic_bxgy_reward_type'] ? 'category' : 'product';
		update_post_meta( $post_id, $meta::BXGY_TRIGGER_TYPE, $trigger_type );
		update_post_meta( $post_id, $meta::BXGY_REWARD_TYPE, $reward_type );

		$trigger_field = 'category' === $trigger_type ? 'epic_bxgy_trigger_id_category' : 'epic_bxgy_trigger_id_product';
		$reward_field  = 'category' === $reward_type ? 'epic_bxgy_reward_id_category' : 'epic_bxgy_reward_id_product';
		update_post_meta( $post_id, $meta::BXGY_TRIGGER_ID, isset( $_POST[ $trigger_field ] ) ? (int) $_POST[ $trigger_field ] : 0 );
		update_post_meta( $post_id, $meta::BXGY_REWARD_ID, isset( $_POST[ $reward_field ] ) ? (int) $_POST[ $reward_field ] : 0 );

		update_post_meta( $post_id, $meta::BXGY_TRIGGER_QTY, isset( $_POST['epic_bxgy_trigger_qty'] ) ? max( 1, (int) $_POST['epic_bxgy_trigger_qty'] ) : 1 );
		update_post_meta( $post_id, $meta::BXGY_REWARD_QTY, isset( $_POST['epic_bxgy_reward_qty'] ) ? max( 1, (int) $_POST['epic_bxgy_reward_qty'] ) : 1 );

		$discount_type = isset( $_POST['epic_bxgy_discount_type'] ) ? sanitize_key( $_POST['epic_bxgy_discount_type'] ) : 'free';
		if ( ! in_array( $discount_type, array( 'free', 'percent', 'fixed' ), true ) ) {
			$discount_type = 'free';
		}
		update_post_meta( $post_id, $meta::BXGY_DISCOUNT_TYPE, $discount_type );
		update_post_meta( $post_id, $meta::BXGY_DISCOUNT_VALUE, isset( $_POST['epic_bxgy_discount_value'] ) ? (float) $_POST['epic_bxgy_discount_value'] : 0 );
		update_post_meta( $post_id, $meta::BXGY_MAX_REPEATS, isset( $_POST[ $meta::BXGY_MAX_REPEATS ] ) ? max( 0, (int) $_POST[ $meta::BXGY_MAX_REPEATS ] ) : 1 );

		update_post_meta( $post_id, $meta::AUTO_APPLY_ENABLED, isset( $_POST[ $meta::AUTO_APPLY_ENABLED ] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, $meta::AUTO_APPLY_CATEGORY, isset( $_POST['epic_auto_apply_category'] ) ? (int) $_POST['epic_auto_apply_category'] : 0 );
	}

	/**
	 * @return array<int,string> product_id => title
	 */
	protected static function get_product_options() {
		$options = array();
		$posts   = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $id ) {
			$options[ $id ] = get_the_title( $id );
		}
		return $options;
	}

	/**
	 * @return array<int,string> term_id => name
	 */
	protected static function get_category_options() {
		$options = array();
		$terms   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->term_id ] = $term->name;
			}
		}
		return $options;
	}
}
