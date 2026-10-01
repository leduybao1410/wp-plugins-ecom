<?php
/**
 * Plugin management business logic for the MCP abilities.
 *
 * Every public method is a pure, programmatic operation: it loads the
 * wp-admin includes it needs (the MCP/REST request never loads them),
 * checks the filesystem and returns either an array result or a WP_Error.
 * No output is produced, so the result is safe to serialise back as MCP
 * tool output.
 *
 * @package Epic_Plugin_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The MCP-facing plugin controller.
 */
class Epic_Plugin_Manager_Controller {

	/**
	 * Cap to a decoded plugin zip at 128 MB by default.
	 *
	 * @var int
	 */
	const MAX_ZIP_BYTES = 134217728;

	/**
	 * Load the wp-admin files needed for plugin management.
	 *
	 * These are not loaded during a front-end/REST/MCP request, so every
	 * operation calls this first. include_once guards make it idempotent.
	 *
	 * @return void
	 */
	private static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	/**
	 * Return a silent upgrader skin so nothing is echoed into the MCP response.
	 *
	 * @return WP_Upgrader_Skin
	 */
	private static function skin() {
		self::load_admin_includes();

		if ( class_exists( '\Automatic_Upgrader_Skin' ) ) {
			return new \Automatic_Upgrader_Skin();
		}

		return new \WP_Upgrader_Skin();
	}

	/**
	 * Whether file modifications are allowed on this install.
	 *
	 * @return bool
	 */
	public static function file_mods_allowed() {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	/**
	 * Record every destructive plugin operation (install/activate/deactivate/
	 * update/delete) so the action is traceable to a user.
	 *
	 * @param string $operation
	 * @param string $target
	 */
	private static function audit( $operation, $target ) {
		$user  = wp_get_current_user();
		$login = ( $user && $user->ID ) ? $user->user_login : 'unknown';
		do_action( 'epic_plugin_manager_audit', $operation, $target, $login );
		error_log( sprintf( '[epic-plugin-manager] %s %s by %s', $operation, $target, $login ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Initialise WP_Filesystem, returning an error when it cannot be used.
	 *
	 * @return true|WP_Error
	 */
	private static function init_filesystem() {
		self::load_admin_includes();

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'epic_pm_filesystem',
				'Could not initialise the WordPress filesystem. The web server may lack write access to wp-content/plugins.'
			);
		}

		return true;
	}

	/**
	 * Normalise a plugin identifier to its plugin basename.
	 *
	 * Accepts a full plugin file ('epic-ghn-shipping/epic-ghn-shipping.php'),
	 * a folder slug ('epic-ghn-shipping') or a main-file basename
	 * ('epic-ghn-shipping.php').
	 *
	 * @param string $identifier User supplied plugin identifier.
	 * @return string|WP_Error Plugin basename, or WP_Error when not found.
	 */
	public static function resolve_plugin_file( $identifier ) {
		self::load_admin_includes();

		$identifier = trim( (string) $identifier );
		if ( '' === $identifier ) {
			return new WP_Error( 'epic_pm_missing_plugin', 'A plugin file or slug is required.' );
		}

		$plugins = get_plugins();

		// Exact plugin basename match.
		if ( isset( $plugins[ $identifier ] ) ) {
			return $identifier;
		}

		$wanted = strtolower( $identifier );

		foreach ( $plugins as $file => $data ) {
			$dir  = strtolower( dirname( $file ) );
			$base = strtolower( basename( $file ) );
			$stem = strtolower( basename( $file, '.php' ) );

			if ( $wanted === $dir || $wanted === $base || $wanted === $stem || $wanted === $dir . '/' . $base ) {
				return $file;
			}
		}

		return new WP_Error(
			'epic_pm_plugin_not_found',
			sprintf( 'No installed plugin matches "%s". Use list-plugins to see the exact plugin file names.', $identifier )
		);
	}

	/**
	 * Describe a single installed plugin.
	 *
	 * @param string      $file             Plugin basename.
	 * @param array       $data             get_plugins() row.
	 * @param object|null $update_transient update_plugins transient.
	 * @return array
	 */
	private static function describe_plugin( $file, $data, $update_transient ) {
		$update_available = false;
		$new_version      = '';

		if ( is_object( $update_transient ) && ! empty( $update_transient->response[ $file ] ) ) {
			$update_available = true;
			$new_version      = isset( $update_transient->response[ $file ]->new_version )
				? (string) $update_transient->response[ $file ]->new_version
				: '';
		}

		$active          = is_plugin_active( $file );
		$network_active  = is_plugin_active_for_network( $file );
		$dir             = dirname( $file );

		return array(
			'plugin'           => $file,
			'slug'             => ( '.' === $dir || '' === $dir ) ? basename( $file, '.php' ) : $dir,
			'name'             => isset( $data['Name'] ) ? $data['Name'] : $file,
			'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'author'           => isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '',
			'description'      => isset( $data['Description'] ) ? wp_strip_all_tags( $data['Description'] ) : '',
			'active'           => (bool) $active,
			'network_active'   => (bool) $network_active,
			'status'           => $active ? ( $network_active ? 'network-active' : 'active' ) : 'inactive',
			'update_available' => $update_available,
			'new_version'      => $new_version,
		);
	}

	/**
	 * List installed plugins.
	 *
	 * @param array $input {
	 *     Optional. Query arguments.
	 *
	 *     @type string $status          any|active|inactive|updates. Default any.
	 *     @type string $search          Case-insensitive name/slug filter.
	 *     @type bool   $refresh_updates Force a check against WordPress.org.
	 * }
	 * @return array
	 */
	public static function list_plugins( $input = array() ) {
		self::load_admin_includes();

		$status          = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any';
		$search          = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$refresh_updates = ! empty( $input['refresh_updates'] );

		if ( $refresh_updates && self::file_mods_allowed() ) {
			wp_update_plugins();
		}

		$update_transient = get_site_transient( 'update_plugins' );
		$plugins          = get_plugins();
		$rows             = array();

		foreach ( $plugins as $file => $data ) {
			$row = self::describe_plugin( $file, $data, $update_transient );

			if ( 'active' === $status && ! $row['active'] ) {
				continue;
			}
			if ( 'inactive' === $status && $row['active'] ) {
				continue;
			}
			if ( 'updates' === $status && ! $row['update_available'] ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = strtolower( $row['name'] . ' ' . $row['slug'] . ' ' . $row['author'] );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$active_count = 0;
		$update_count = 0;
		foreach ( $rows as $row ) {
			if ( $row['active'] ) {
				$active_count++;
			}
			if ( $row['update_available'] ) {
				$update_count++;
			}
		}

		return array(
			'total'             => count( $rows ),
			'active'            => $active_count,
			'inactive'          => count( $rows ) - $active_count,
			'updates_available' => $update_count,
			'plugins'           => $rows,
		);
	}

	/**
	 * Install a plugin from the WordPress.org directory or a remote zip URL.
	 *
	 * @param string $slug    WordPress.org plugin slug.
	 * @param string $zip_url Direct URL to a plugin zip.
	 * @param bool   $activate Activate after a successful install.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $slug, $zip_url = '', $activate = false ) {
		self::load_admin_includes();

		if ( ! self::file_mods_allowed() ) {
			return new WP_Error( 'epic_pm_file_mods_disabled', 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
		}

		$fs = self::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$slug    = sanitize_key( $slug );
		$zip_url = trim( (string) $zip_url );
		$source  = '';

		if ( '' !== $slug ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'    => false,
						'description' => false,
					),
				)
			);

			if ( is_wp_error( $api ) ) {
				return $api;
			}
			if ( empty( $api->download_link ) ) {
				return new WP_Error( 'epic_pm_no_download', sprintf( 'WordPress.org returned no download link for "%s".', $slug ) );
			}
			$source = $api->download_link;
		} elseif ( '' !== $zip_url ) {
			if ( ! wp_http_validate_url( $zip_url ) ) {
				return new WP_Error( 'epic_pm_bad_url', 'The zip_url must be a valid http(s) URL.' );
			}
			$source = $zip_url;
		} else {
			return new WP_Error( 'epic_pm_missing_source', 'Provide a "slug" (WordPress.org) or a "zip_url".' );
		}

		return self::run_install( $source, $slug, (bool) $activate );
	}

	/**
	 * Install a plugin from a base64-encoded zip upload.
	 *
	 * MCP tool arguments are JSON, so an "upload" is the zip bytes encoded
	 * as base64 (a `data:` URI prefix is accepted and stripped).
	 *
	 * @param string $zip_base64 Base64 zip payload.
	 * @param bool   $activate   Activate after a successful install.
	 * @param bool   $overwrite  Replace an existing plugin of the same slug.
	 * @return array|WP_Error
	 */
	public static function upload_plugin( $zip_base64, $activate = false, $overwrite = false ) {
		self::load_admin_includes();

		if ( ! self::file_mods_allowed() ) {
			return new WP_Error( 'epic_pm_file_mods_disabled', 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
		}

		$fs = self::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$zip_base64 = trim( (string) $zip_base64 );
		if ( '' === $zip_base64 ) {
			return new WP_Error( 'epic_pm_missing_zip', 'The zip_base64 payload is required.' );
		}

		// Accept an optional data URI prefix.
		if ( 0 === strpos( $zip_base64, 'data:' ) && false !== strpos( $zip_base64, ',' ) ) {
			$zip_base64 = substr( $zip_base64, strpos( $zip_base64, ',' ) + 1 );
		}

		$zip_base64 = preg_replace( '/\s+/', '', $zip_base64 );
		$bytes      = base64_decode( $zip_base64, true );

		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'epic_pm_bad_base64', 'The zip_base64 payload is not valid base64.' );
		}

		$max = (int) apply_filters( 'epic_plugin_manager_max_zip_bytes', self::MAX_ZIP_BYTES );
		if ( strlen( $bytes ) > $max ) {
			return new WP_Error(
				'epic_pm_zip_too_large',
				sprintf( 'The decoded zip is %d bytes, over the %d byte limit.', strlen( $bytes ), $max )
			);
		}

		// Must look like a zip archive (PK\x03\x04 / PK\x05\x06 / PK\x07\x08).
		if ( "PK\x03\x04" !== substr( $bytes, 0, 4 ) && "PK\x05\x06" !== substr( $bytes, 0, 4 ) && "PK\x07\x08" !== substr( $bytes, 0, 4 ) ) {
			return new WP_Error( 'epic_pm_not_zip', 'The decoded payload is not a valid ZIP archive.' );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = wp_tempnam( 'epic-plugin-upload.zip' );
		if ( ! $temp_file ) {
			return new WP_Error( 'epic_pm_temp_failed', 'Could not create a temporary file for the upload.' );
		}

		if ( false === file_put_contents( $temp_file, $bytes ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'epic_pm_temp_write', 'Could not write the uploaded zip to disk.' );
		}

		$result = self::run_install( $temp_file, '', (bool) $activate, (bool) $overwrite );

		@unlink( $temp_file );

		return $result;
	}

	/**
	 * Run Plugin_Upgrader::install() and report the resulting plugin.
	 *
	 * @param string $package   Local path or remote URL to a plugin zip.
	 * @param string $slug_hint Optional slug used to resolve the plugin file.
	 * @param bool   $activate  Activate after install.
	 * @param bool   $overwrite Overwrite an existing package.
	 * @return array|WP_Error
	 */
	private static function run_install( $package, $slug_hint = '', $activate = false, $overwrite = false ) {
		self::load_admin_includes();
		self::audit( 'install', (string) $slug_hint );

		$skin     = self::skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		$args = array();
		if ( $overwrite ) {
			$args['overwrite_package'] = true;
		}

		$result = $upgrader->install( $package, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error(
				'epic_pm_install_failed',
				'Plugin install failed: ' . self::collect_messages( $skin ),
				array( 'messages' => self::skin_messages( $skin ) )
			);
		}

		$folder      = '';
		$plugin_file = '';

		if ( is_array( $upgrader->result ) && ! empty( $upgrader->result['destination_name'] ) ) {
			$folder = $upgrader->result['destination_name'];
		}

		if ( '' !== $slug_hint ) {
			$plugin_file = self::find_plugin_by_slug( $slug_hint );
		}
		if ( '' === $plugin_file && '' !== $folder ) {
			$plugin_file = self::find_plugin_by_slug( $folder );
		}

		$response = array(
			'installed'   => true,
			'plugin'      => $plugin_file,
			'slug'        => '' !== $folder ? $folder : $slug_hint,
			'activated'   => false,
			'messages'    => self::skin_messages( $skin ),
		);

		if ( $activate && '' !== $plugin_file ) {
			$activation = self::activate_plugin( $plugin_file, false );
			if ( is_wp_error( $activation ) ) {
				$response['activation_error'] = $activation->get_error_message();
			} else {
				$response['activated'] = true;
			}
		}

		return $response;
	}

	/**
	 * Find the plugin basename whose folder matches a slug.
	 *
	 * @param string $slug Folder name.
	 * @return string Plugin basename or empty string.
	 */
	private static function find_plugin_by_slug( $slug ) {
		self::load_admin_includes();

		$slug = trim( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}

		// Fall back to a main-file basename match (single-file plugins).
		foreach ( get_plugins() as $file => $data ) {
			if ( basename( $file, '.php' ) === $slug ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * Activate an installed plugin.
	 *
	 * @param string $plugin       Plugin file or slug.
	 * @param bool   $network_wide Activate for the whole network.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $plugin, $network_wide = false ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'epic_pm_forbidden', 'You are not allowed to activate plugins.' );
		}

		self::load_admin_includes();
		self::audit( 'activate', (string) $plugin );

		$plugin_file = self::resolve_plugin_file( $plugin );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'plugin'         => $plugin_file,
				'active'         => true,
				'network_active' => is_plugin_active_for_network( $plugin_file ),
				'changed'        => false,
				'message'        => 'Plugin is already active.',
			);
		}

		$network_wide = $network_wide && is_multisite();

		$result = activate_plugin( $plugin_file, '', $network_wide, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'plugin'         => $plugin_file,
			'active'         => true,
			'network_active' => $network_wide,
			'changed'        => true,
			'message'        => 'Plugin activated.',
		);
	}

	/**
	 * Deactivate an installed plugin.
	 *
	 * @param string $plugin       Plugin file or slug.
	 * @param bool   $network_wide Network-wide deactivation.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $plugin, $network_wide = false ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'epic_pm_forbidden', 'You are not allowed to deactivate plugins.' );
		}

		self::load_admin_includes();
		self::audit( 'deactivate', (string) $plugin );

		$plugin_file = self::resolve_plugin_file( $plugin );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'plugin'  => $plugin_file,
				'active'  => false,
				'changed' => false,
				'message' => 'Plugin is already inactive.',
			);
		}

		deactivate_plugins( array( $plugin_file ), true, $network_wide && is_multisite() );

		if ( is_plugin_active( $plugin_file ) ) {
			return new WP_Error( 'epic_pm_deactivate_failed', sprintf( 'Could not deactivate "%s".', $plugin_file ) );
		}

		return array(
			'plugin'  => $plugin_file,
			'active'  => false,
			'changed' => true,
			'message' => 'Plugin deactivated.',
		);
	}

	/**
	 * Update an installed plugin to the newest available version.
	 *
	 * @param string $plugin Plugin file or slug.
	 * @return array|WP_Error
	 */
	public static function update_plugin( $plugin ) {
		self::load_admin_includes();
		self::audit( 'update', (string) $plugin );

		if ( ! self::file_mods_allowed() ) {
			return new WP_Error( 'epic_pm_file_mods_disabled', 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
		}

		$plugin_file = self::resolve_plugin_file( $plugin );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}

		$fs = self::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );

		if ( ! is_object( $updates ) || empty( $updates->response[ $plugin_file ] ) ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			return array(
				'plugin'    => $plugin_file,
				'updated'   => false,
				'message'   => 'No update available; plugin is up to date.',
				'version'   => isset( $data['Version'] ) ? $data['Version'] : '',
			);
		}

		$from = isset( $updates->response[ $plugin_file ]->old_version ) ? $updates->response[ $plugin_file ]->old_version : '';
		$to   = isset( $updates->response[ $plugin_file ]->new_version ) ? $updates->response[ $plugin_file ]->new_version : '';

		$skin     = self::skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error(
				'epic_pm_update_failed',
				'Plugin update failed: ' . self::collect_messages( $skin ),
				array( 'messages' => self::skin_messages( $skin ) )
			);
		}

		$was_active = is_plugin_active( $plugin_file );

		return array(
			'plugin'     => $plugin_file,
			'updated'    => true,
			'from'       => $from,
			'to'         => $to,
			'was_active' => (bool) $was_active,
			'messages'   => self::skin_messages( $skin ),
		);
	}

	/**
	 * Remove an installed plugin.
	 *
	 * @param string $plugin Plugin file or slug.
	 * @param bool   $force  Deactivate first when the plugin is active.
	 * @return array|WP_Error
	 */
	public static function delete_plugin( $plugin, $force = false ) {
		self::load_admin_includes();
		self::audit( 'delete', (string) $plugin );

		if ( ! self::file_mods_allowed() ) {
			return new WP_Error( 'epic_pm_file_mods_disabled', 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
		}

		$plugin_file = self::resolve_plugin_file( $plugin );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}

		$deactivated = false;
		if ( is_plugin_active( $plugin_file ) ) {
			if ( ! $force ) {
				return new WP_Error(
					'epic_pm_plugin_active',
					sprintf( '"%s" is active. Pass force=true to deactivate and delete it, or deactivate it first.', $plugin_file )
				);
			}
			$deactivation = self::deactivate_plugin( $plugin_file, false );
			if ( is_wp_error( $deactivation ) ) {
				return $deactivation;
			}
			$deactivated = true;
		}

		$fs = self::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$result = delete_plugins( array( $plugin_file ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'epic_pm_delete_failed', sprintf( 'Could not delete "%s".', $plugin_file ) );
		}

		return array(
			'plugin'      => $plugin_file,
			'deleted'     => true,
			'deactivated' => $deactivated,
			'message'     => 'Plugin removed.',
		);
	}

	/**
	 * Extract feedback messages from a skins' stored upgrade messages.
	 *
	 * @param WP_Upgrader_Skin $skin Upgrader skin.
	 * @return array
	 */
	private static function skin_messages( $skin ) {
		if ( is_object( $skin ) && method_exists( $skin, 'get_upgrade_messages' ) ) {
			return array_values( (array) $skin->get_upgrade_messages() );
		}
		return array();
	}

	/**
	 * Join skin messages into a single line for an error message.
	 *
	 * @param WP_Upgrader_Skin $skin Upgrader skin.
	 * @return string
	 */
	private static function collect_messages( $skin ) {
		$messages = self::skin_messages( $skin );
		if ( empty( $messages ) ) {
			return 'no further detail available.';
		}
		return implode( ' ', array_map( 'wp_strip_all_tags', $messages ) );
	}
}
