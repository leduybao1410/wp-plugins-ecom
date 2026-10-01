<?php
/**
 * Registers the plugin-management abilities for the WordPress Abilities API.
 *
 * Abilities are exposed to MCP by setting `meta.public` (and `meta.mcp.public`)
 * to true, which makes the default MCP server
 * (wp-json/mcp/mcp-adapter-default-server) able to discover and execute them.
 *
 * @package Epic_Plugin_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability registration.
 */
class Epic_Plugin_Manager_Abilities {

	/**
	 * Ability namespace and custom category slug.
	 *
	 * @var string
	 */
	const CATEGORY = 'epic-plugin-manager';

	/**
	 * Wire up the Abilities API hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_api_notice' ) );
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Warn admins when the Abilities API is unavailable.
	 *
	 * @return void
	 */
	public static function render_missing_api_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>EPIC Plugin Manager MCP:</strong> the WordPress Abilities API is not available. This plugin needs WordPress 6.9+ (or the Abilities API feature plugin) and the MCP Adapter to expose its tools.</p></div>';
	}

	/**
	 * Register the plugin-management ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Plugin Management',
				'description' => 'Install, upload, activate, deactivate, update and remove WordPress plugins through MCP.',
			)
		);
	}

	/**
	 * Register every plugin-management ability.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		foreach ( self::definitions() as $name => $definition ) {
			if ( in_array( $name, self::destructive_abilities(), true ) && ! self::destructive_allowed() ) {
				continue;
			}
			wp_register_ability( self::CATEGORY . '/' . $name, $definition );
		}
	}

	/**
	 * Abilities that can install/execute arbitrary code or remove plugins.
	 *
	 * @return string[]
	 */
	public static function destructive_abilities() {
		return array( 'install-plugin', 'upload-plugin', 'delete-plugin' );
	}

	/**
	 * Whether the destructive abilities are exposed to MCP/REST. Defaults to
	 * true (current behaviour); set the EPIC_PLUGIN_MANAGER_ALLOW_DESTRUCTIVE
	 * constant to false, or filter epic_plugin_manager_allow_destructive_abilities,
	 * to keep them out of the tool list entirely.
	 *
	 * @return bool
	 */
	public static function destructive_allowed() {
		if ( defined( 'EPIC_PLUGIN_MANAGER_ALLOW_DESTRUCTIVE' ) ) {
			return (bool) EPIC_PLUGIN_MANAGER_ALLOW_DESTRUCTIVE;
		}
		return (bool) apply_filters( 'epic_plugin_manager_allow_destructive_abilities', true );
	}

	/**
	 * Shared permission check for read/activate operations.
	 *
	 * @return bool
	 */
	public static function can_activate_plugins() {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Shared permission check for install/update/upload operations.
	 *
	 * @return bool
	 */
	public static function can_install_plugins() {
		return current_user_can( 'install_plugins' ) && Epic_Plugin_Manager_Controller::file_mods_allowed();
	}

	/**
	 * Shared permission check for delete operations.
	 *
	 * @return bool
	 */
	public static function can_delete_plugins() {
		return current_user_can( 'delete_plugins' ) && Epic_Plugin_Manager_Controller::file_mods_allowed();
	}

	/**
	 * Standard ability meta so each definition stays terse.
	 *
	 * @param array $annotations Annotation overrides.
	 * @return array
	 */
	private static function meta( $annotations = array() ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => array_merge(
				array(
					'openWorldHint' => true,
				),
				$annotations
			),
		);
	}

	/**
	 * Every ability definition, keyed by its short name.
	 *
	 * @return array
	 */
	private static function definitions() {
		return array(
			'list-plugins'      => array(
				'label'               => 'List plugins',
				'description'         => 'List installed WordPress plugins with version, active status and any available updates. Filter by status, search text or ask for a fresh WordPress.org update check.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'          => array(
							'type'        => 'string',
							'description' => 'Filter by plugin state.',
							'enum'        => array( 'any', 'active', 'inactive', 'updates' ),
							'default'     => 'any',
						),
						'search'          => array(
							'type'        => 'string',
							'description' => 'Case-insensitive match against plugin name, slug and author.',
						),
						'refresh_updates' => array(
							'type'        => 'boolean',
							'description' => 'When true, check WordPress.org for updates before listing (slower).',
							'default'     => false,
						),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return Epic_Plugin_Manager_Controller::list_plugins( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_activate_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'   => true,
						'idempotent' => true,
						'title'      => 'List plugins',
					)
				),
			),

			'install-plugin'    => array(
				'label'               => 'Install plugin',
				'description'         => 'Install a plugin from WordPress.org by slug, or from a direct plugin .zip URL. Optionally activate it after installing.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array(
							'type'        => 'string',
							'description' => 'WordPress.org plugin slug, e.g. "classic-editor".',
						),
						'zip_url'  => array(
							'type'        => 'string',
							'description' => 'Direct http(s) URL to a plugin zip. Used when slug is empty.',
						),
						'activate' => array(
							'type'        => 'boolean',
							'description' => 'Activate the plugin after a successful install.',
							'default'     => false,
						),
					),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::install_plugin(
						isset( $input['slug'] ) ? $input['slug'] : '',
						isset( $input['zip_url'] ) ? $input['zip_url'] : '',
						! empty( $input['activate'] )
					);
				},
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'title'       => 'Install plugin',
					)
				),
			),

			'upload-plugin'     => array(
				'label'               => 'Upload plugin',
				'description'         => 'Install a plugin from an uploaded zip supplied as base64 (a data: URI prefix is accepted). Use this to push a locally built plugin zip without a public URL.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'zip_base64' => array(
							'type'        => 'string',
							'description' => 'Base64-encoded contents of a plugin .zip.',
						),
						'activate'   => array(
							'type'        => 'boolean',
							'description' => 'Activate the plugin after a successful install.',
							'default'     => false,
						),
						'overwrite'  => array(
							'type'        => 'boolean',
							'description' => 'Replace an existing plugin with the same slug.',
							'default'     => false,
						),
					),
					'required'   => array( 'zip_base64' ),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::upload_plugin(
						isset( $input['zip_base64'] ) ? $input['zip_base64'] : '',
						! empty( $input['activate'] ),
						! empty( $input['overwrite'] )
					);
				},
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'title'       => 'Upload plugin',
					)
				),
			),

			'activate-plugin'   => array(
				'label'               => 'Activate plugin',
				'description'         => 'Activate an installed plugin by its plugin file or slug.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'       => array(
							'type'        => 'string',
							'description' => 'Plugin file ("epic-ghn-shipping/epic-ghn-shipping.php") or slug ("epic-ghn-shipping").',
						),
						'network_wide' => array(
							'type'        => 'boolean',
							'description' => 'Activate for the whole network on multisite.',
							'default'     => false,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::activate_plugin(
						isset( $input['plugin'] ) ? $input['plugin'] : '',
						! empty( $input['network_wide'] )
					);
				},
				'permission_callback' => array( __CLASS__, 'can_activate_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'title'       => 'Activate plugin',
					)
				),
			),

			'deactivate-plugin' => array(
				'label'               => 'Deactivate plugin',
				'description'         => 'Deactivate an active plugin by its plugin file or slug.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'       => array(
							'type'        => 'string',
							'description' => 'Plugin file or slug.',
						),
						'network_wide' => array(
							'type'        => 'boolean',
							'description' => 'Network-wide deactivation on multisite.',
							'default'     => false,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::deactivate_plugin(
						isset( $input['plugin'] ) ? $input['plugin'] : '',
						! empty( $input['network_wide'] )
					);
				},
				'permission_callback' => array( __CLASS__, 'can_activate_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'title'       => 'Deactivate plugin',
					)
				),
			),

			'update-plugin'     => array(
				'label'               => 'Update plugin',
				'description'         => 'Update an installed plugin to the newest version available from WordPress.org. Reports when the plugin is already up to date.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file or slug.',
						),
					),
					'required'   => array( 'plugin' ),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::update_plugin(
						isset( $input['plugin'] ) ? $input['plugin'] : ''
					);
				},
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'title'       => 'Update plugin',
					)
				),
			),

			'delete-plugin'     => array(
				'label'               => 'Delete plugin',
				'description'         => 'Delete (remove) an installed plugin. By default an active plugin is refused; pass force=true to deactivate it first and then delete it.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file or slug.',
						),
						'force'  => array(
							'type'        => 'boolean',
							'description' => 'Deactivate the plugin first when it is active.',
							'default'     => false,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					return Epic_Plugin_Manager_Controller::delete_plugin(
						isset( $input['plugin'] ) ? $input['plugin'] : '',
						! empty( $input['force'] )
					);
				},
				'permission_callback' => array( __CLASS__, 'can_delete_plugins' ),
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
						'title'       => 'Delete plugin',
					)
				),
			),
		);
	}
}
