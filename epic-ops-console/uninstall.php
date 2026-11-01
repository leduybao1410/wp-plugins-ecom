<?php
/**
 * Uninstall handler. The console stores no options; it only owns rewrite rules,
 * which are flushed when the plugin is deactivated. Session rows belong to the
 * EPIC Admin Dashboard API plugin and are intentionally left untouched.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
flush_rewrite_rules( false );
