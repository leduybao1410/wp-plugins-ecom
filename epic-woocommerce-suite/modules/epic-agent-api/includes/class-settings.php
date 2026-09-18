<?php
/**
 * wp-admin screen for the Agent API module: WooCommerce → Agent API.
 *
 * Holds the shared secret the Next.js server sends as `X-Epic-Secret` when
 * it calls this module's `/guard` route, the rate-limit budgets the guard
 * enforces, and a read-only view of recent agent traffic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Agent_Settings {

	const OPTION_KEY_SECRET = 'epic_agent_shared_secret';
	const OPTION_KEY_LIMITS = 'epic_agent_limits';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Agent API', 'epic-agent-api' ),
			__( 'Agent API', 'epic-agent-api' ),
			'manage_woocommerce',
			'epic-agent-api',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_agent_api',
			self::OPTION_KEY_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_shared_secret' ),
				'default'           => '',
			)
		);
		register_setting(
			'epic_agent_api',
			self::OPTION_KEY_LIMITS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_limits' ),
				'default'           => self::default_limits(),
			)
		);
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_KEY_SECRET, '' );
	}

	public static function default_limits() {
		return array(
			'order_per_min'        => 10,
			'write_per_hour'       => 5,
			'write_per_day'        => 20,
			'global_write_per_day' => 200,
		);
	}

	public static function get_limits() {
		$stored = get_option( self::OPTION_KEY_LIMITS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$limits = self::default_limits();
		foreach ( $limits as $key => $default ) {
			if ( isset( $stored[ $key ] ) && (int) $stored[ $key ] > 0 ) {
				$limits[ $key ] = (int) $stored[ $key ];
			}
		}
		return $limits;
	}

	/** Empty POST means "keep the saved secret" — the field renders blank. */
	public static function sanitize_shared_secret( $value ) {
		$value = sanitize_text_field( $value );
		return '' === $value ? self::get_shared_secret() : $value;
	}

	public static function sanitize_limits( $value ) {
		$value  = is_array( $value ) ? $value : array();
		$limits = self::default_limits();
		foreach ( $limits as $key => $default ) {
			if ( isset( $value[ $key ] ) ) {
				$n = (int) $value[ $key ];
				$limits[ $key ] = $n > 0 ? $n : $default;
			}
		}
		return $limits;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$limits = self::get_limits();
		$events = Epic_Agent_Store::recent( 50 );
		$last24 = Epic_Agent_Store::count_since( DAY_IN_SECONDS );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent API', 'epic-agent-api' ); ?></h1>
			<p>
				<?php esc_html_e( 'Backs the website\'s public, no-auth bot API at /api/public/v1. The Next.js server asks this module for a rate-limit verdict before it accepts any public write or order lookup. No lead content is stored here — only traffic metadata.', 'epic-agent-api' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'epic_agent_api' ); ?>

				<h2><?php esc_html_e( 'Shared secret', 'epic-agent-api' ); ?></h2>
				<p>
					<?php esc_html_e( 'Authenticates the website\'s calls into this module\'s REST route. Set the same value in the website\'s EPIC_AGENT_SHARED_SECRET environment variable.', 'epic-agent-api' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_agent_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-agent-api' ); ?></label>
						</th>
						<td>
							<?php $secret_is_set = '' !== self::get_shared_secret(); ?>
							<input
								type="password"
								id="epic_agent_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY_SECRET ); ?>"
								value=""
								placeholder="<?php echo esc_attr( $secret_is_set ? '••••••••••' : '' ); ?>"
								class="regular-text code"
								autocomplete="new-password"
							/>
							<p class="description">
								<?php if ( $secret_is_set ) : ?>
									<?php esc_html_e( 'A secret is saved. Leave blank to keep it, or paste a new value to replace it.', 'epic-agent-api' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Generate a long random string and paste it here.', 'epic-agent-api' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rate limits', 'epic-agent-api' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="epic_agent_order_per_min"><?php esc_html_e( 'Order lookups / minute / IP', 'epic-agent-api' ); ?></label></th>
						<td><input type="number" min="1" id="epic_agent_order_per_min" name="<?php echo esc_attr( self::OPTION_KEY_LIMITS ); ?>[order_per_min]" value="<?php echo esc_attr( $limits['order_per_min'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="epic_agent_write_per_hour"><?php esc_html_e( 'Writes / hour / IP', 'epic-agent-api' ); ?></label></th>
						<td><input type="number" min="1" id="epic_agent_write_per_hour" name="<?php echo esc_attr( self::OPTION_KEY_LIMITS ); ?>[write_per_hour]" value="<?php echo esc_attr( $limits['write_per_hour'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="epic_agent_write_per_day"><?php esc_html_e( 'Writes / day / IP', 'epic-agent-api' ); ?></label></th>
						<td><input type="number" min="1" id="epic_agent_write_per_day" name="<?php echo esc_attr( self::OPTION_KEY_LIMITS ); ?>[write_per_day]" value="<?php echo esc_attr( $limits['write_per_day'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="epic_agent_global_write_per_day"><?php esc_html_e( 'Global writes / day (all IPs)', 'epic-agent-api' ); ?></label></th>
						<td><input type="number" min="1" id="epic_agent_global_write_per_day" name="<?php echo esc_attr( self::OPTION_KEY_LIMITS ); ?>[global_write_per_day]" value="<?php echo esc_attr( $limits['global_write_per_day'] ); ?>" class="small-text" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Recent agent traffic', 'epic-agent-api' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of events in the last 24 hours. */
					esc_html__( '%d guard calls in the last 24 hours. Showing the 50 most recent (IPs are hashed).', 'epic-agent-api' ),
					(int) $last24
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'epic-agent-api' ); ?></th>
						<th><?php esc_html_e( 'Route', 'epic-agent-api' ); ?></th>
						<th><?php esc_html_e( 'Bucket', 'epic-agent-api' ); ?></th>
						<th><?php esc_html_e( 'Result', 'epic-agent-api' ); ?></th>
						<th><?php esc_html_e( 'User agent', 'epic-agent-api' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No agent traffic recorded yet.', 'epic-agent-api' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $event['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) $event['route'] ); ?></td>
							<td><?php echo esc_html( (string) $event['bucket'] ); ?></td>
							<td>
								<?php if ( ! empty( $event['silent'] ) ) : ?>
									<?php esc_html_e( 'honeypot', 'epic-agent-api' ); ?>
								<?php elseif ( ! empty( $event['allowed'] ) ) : ?>
									<?php esc_html_e( 'allowed', 'epic-agent-api' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'rate-limited', 'epic-agent-api' ); ?>
								<?php endif; ?>
							</td>
							<td style="max-width:420px; overflow-wrap:break-word;"><?php echo esc_html( (string) $event['user_agent'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
