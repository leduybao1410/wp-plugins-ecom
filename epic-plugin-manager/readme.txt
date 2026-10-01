=== EPIC Plugin Manager MCP ===
Contributors: epiccoffee
Tags: mcp, abilities, ai, plugin management
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WordPress plugin management (list, install, upload, activate, deactivate, update, remove) as MCP abilities.

== Description ==

The MCP Adapter turns anything registered with the WordPress Abilities API into an MCP tool. WordPress core and WooCommerce ship abilities for content, orders and products, but nothing lets an AI agent manage *the plugins themselves*. This plugin fills that gap.

It registers seven abilities in the `epic-plugin-manager` namespace and marks them public, so the default MCP server exposes them:

* `epic-plugin-manager/list-plugins` — installed plugins, version, active status, available updates.
* `epic-plugin-manager/install-plugin` — install from a WordPress.org slug or a direct zip URL.
* `epic-plugin-manager/upload-plugin` — install from a base64-encoded zip (no public URL needed).
* `epic-plugin-manager/activate-plugin` — activate (optionally network-wide).
* `epic-plugin-manager/deactivate-plugin` — deactivate.
* `epic-plugin-manager/update-plugin` — update to the newest WordPress.org version.
* `epic-plugin-manager/delete-plugin` — remove a plugin (force deactivates first).

= Security =

Every ability checks a real WordPress capability (`install_plugins`, `activate_plugins` or `delete_plugins`) and the `DISALLOW_FILE_MODS` guard. The authenticated MCP user must be a genuine administrator; a lower-privileged user receives a permission error.

== Installation ==

1. Upload the `epic-plugin-manager` folder (or its zip) to `/wp-content/plugins/` and activate it in Plugins → Installed Plugins.
2. Ensure the **MCP Adapter** plugin is active and WordPress is 6.9+ (Abilities API in core).
3. Connect an MCP client to `/wp-json/mcp/mcp-adapter-default-server`, then discover the abilities with the `mcp-adapter/discover-abilities` meta-tool.

== Changelog ==

= 1.0.0 =
* Initial release: list, install, upload, activate, deactivate, update and delete plugin abilities over MCP.
