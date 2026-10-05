<?php
// Run only in the disposable epic-security-release-wp container.
if ( getenv( 'WORDPRESS_DB_PASSWORD' ) !== 'isolated-security-test' ) { exit( 'Wrong test environment' ); }
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']='localhost:8097';
$_SERVER['REQUEST_URI']='/';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) { wp_install( 'Security regression fixture', 'security-fixture', 'fixture@example.invalid', false, '', 'isolated-test-password' ); }
function verify_security( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } echo "PASS: $message\n"; }
global $wpdb;
$user = get_user_by( 'login', 'security-fixture' );
// Existing schema and synthetic record exercise the actual dbDelta upgrade.
$sessions = $wpdb->prefix . 'epic_admin_sessions';
$wpdb->query( "CREATE TABLE IF NOT EXISTS $sessions (id bigint unsigned NOT NULL AUTO_INCREMENT, token_hash char(64) NOT NULL, user_id bigint unsigned NOT NULL, created_at datetime NOT NULL, last_used_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash), KEY user_id (user_id), KEY expires_at (expires_at))" );
$old_token = str_repeat( 'L', 43 );
$wpdb->replace( $sessions, array( 'token_hash' => hash( 'sha256', $old_token ), 'user_id' => $user->ID, 'created_at' => gmdate('Y-m-d H:i:s'), 'last_used_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s',time()+3600) ) );
$activation = activate_plugin( 'epic-admin-dashboard/epic-admin-dashboard.php' );
verify_security( ! is_wp_error($activation), 'adapter activation succeeds in real WordPress' );
Epic_Admin_Dashboard::init();
$wpdb->query("DELETE FROM {$wpdb->prefix}epic_admin_limits");
verify_security( $wpdb->get_var("SHOW COLUMNS FROM $sessions LIKE 'auth_version'") === 'auth_version', 'existing sessions table gains credential binding' );
verify_security( (int)$wpdb->get_var("SELECT COUNT(*) FROM $sessions") === 1, 'schema upgrade preserves existing synthetic record' );
$request = new WP_REST_Request( 'GET', '/epic-admin/v1/me' );
$request->set_header( 'x-epic-admin-session', $old_token );
verify_security( is_wp_error(Epic_Admin_Dashboard::require_admin_session($request)), 'legacy token is rejected after migration' );
$verifier = str_repeat('V',43); $code = str_repeat('C',43);
$grant = new WP_REST_Request('POST','/epic-admin/v1/auth/authorize');
$grant->set_header('content-type','application/json');
$grant->set_header('x-epic-admin-client',EPIC_ADMIN_CLIENT_SECRET);
$grant->set_body(wp_json_encode(array('state'=>str_repeat('S',43),'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'callback_url'=>EPIC_ADMIN_DASHBOARD_CALLBACK)));
verify_security(!is_wp_error(Epic_Admin_Dashboard::create_grant($grant)),'rate-protected real grant insertion succeeds');
$wpdb->update($wpdb->prefix.'epic_admin_grants',array('status'=>'authorized','code_hash'=>hash('sha256',$code),'user_id'=>$user->ID,'auth_version'=>Epic_Admin_Security::session_version($user)),array('state_hash'=>hash('sha256',str_repeat('S',43))));
$exchange=new WP_REST_Request('POST','/epic-admin/v1/auth/exchange');
$exchange->set_header('content-type','application/json'); $exchange->set_header('x-epic-admin-client',EPIC_ADMIN_CLIENT_SECRET);
$exchange->set_body(wp_json_encode(array('code'=>$code,'code_verifier'=>$verifier)));
$issued=Epic_Admin_Dashboard::exchange($exchange);
verify_security(!is_wp_error($issued),'real one-use PKCE exchange succeeds');
$token=$issued->get_data()['session'];
verify_security(is_wp_error(Epic_Admin_Dashboard::exchange($exchange)),'authorization code replay is rejected');
$request->set_header('x-epic-admin-session',$token);
verify_security(Epic_Admin_Dashboard::require_admin_session($request)===true,'issued session authenticates');
reset_password($user,'changed-isolated-test-password');
verify_security(is_wp_error(Epic_Admin_Dashboard::require_admin_session($request)),'actual WordPress password reset revokes copied ops token');
$limit_request=new WP_REST_Request('POST','/epic-admin/v1/auth/authorize');
$limit_request->set_header('x-epic-admin-source',hash('sha256','separate-fixture-source'));
for($i=0;$i<10;$i++) verify_security(Epic_Admin_Security::rate_limit($limit_request,'authorize')===true,'real atomic counter allows in-limit request');
$limited=Epic_Admin_Security::rate_limit($limit_request,'authorize');
verify_security(is_wp_error($limited) && $limited->get_error_data()['status']===429,'real database rejects excess authentication requests');
echo "WordPress integration security checks passed.\n";
