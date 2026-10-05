<?php
// Standalone behavioral checks; no WordPress installation or real records used.
define( 'ABSPATH', __DIR__ . '/' );
define( 'EPIC_ADMIN_CLIENT_SECRET', 'test-client-secret' );
$hooks = array(); $meta = array();
function add_action( $name, $callback, $priority = 10, $args = 1 ) { global $hooks; $hooks[$name][] = $callback; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { add_action( $name, $callback, $priority, $args ); }
function get_user_meta( $id, $key, $single = true ) { global $meta; return $meta[$id][$key] ?? ''; }
function update_user_meta( $id, $key, $value ) { global $meta; $meta[$id][$key] = $value; return true; }
function wp_generate_password( $length, $special = true ) { return bin2hex( random_bytes( $length ) ); }
function wp_salt( $scheme ) { return 'test-salt'; }
function get_current_user_id() { return 7; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_next_scheduled( $name ) { return true; }
function wp_schedule_event() {}
function get_option( $name, $default = false ) { return $default; }
function get_user_by( $type, $id ) { global $user; return $user; }
function user_can( $user, $cap ) { return true; }
function wp_set_current_user( $id ) {}
function rest_ensure_response( $data ) { return $data; }
function admin_url() { return 'https://admin.epicroastery.coffee/wp-admin/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    public $code; public $data;
    function __construct( $code, $message, $data ) { $this->code=$code; $this->data=$data; }
}
class FakeDatabase {
    public $prefix = 'wp_'; public $deleted = array(); public $fail = false; public $buckets = array(); public $row;
    function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    function query( $query ) {
        if ( $this->fail ) return false;
        if ( is_array( $query ) && strpos( $query[0], 'INSERT INTO' ) !== false ) {
            $key=$query[1][0]; $this->buckets[$key]=($this->buckets[$key] ?? 0)+1;
        }
        return 1;
    }
    function get_var( $query ) { return $this->fail ? null : ($this->buckets[$query[1][0]] ?? 0); }
    function delete( $table, $where, $formats = array() ) { $this->deleted[]=$table; return $this->fail ? false : 1; }
    function insert( ...$args ) { return $this->fail ? false : 1; }
    function get_row( $query ) { return $this->row; }
    function update( ...$args ) { return $this->fail ? false : 1; }
}
class FakeRequest {
    public $origin=''; public $source;
    function __construct() { $this->source=str_repeat('a',64); }
    function get_header( $name ) {
        if ( $name==='origin' ) return $this->origin;
        if ( $name==='x-epic-admin-source' ) return $this->source;
        if ( $name==='x-epic-admin-session' ) return str_repeat('A',43);
        if ( $name==='x-epic-admin-client' ) return EPIC_ADMIN_CLIENT_SECRET;
        return '';
    }
    function get_route() { return '/epic-admin/v1/me'; }
    function get_json_params() { return array('code'=>str_repeat('B',43),'code_verifier'=>str_repeat('C',43)); }
}
function check( $value, $message ) { if (!$value) throw new Exception($message); echo "PASS: $message\n"; }

require __DIR__ . '/../includes/class-epic-admin-dashboard.php';
require __DIR__ . '/../includes/class-epic-admin-security.php';
$wpdb=new FakeDatabase(); $user=(object)array('ID'=>7,'user_pass'=>'hash-one');
Epic_Admin_Security::init();
$version=Epic_Admin_Security::session_version($user);
check($version!==Epic_Admin_Security::session_version((object)array('ID'=>7,'user_pass'=>'hash-two')), 'password change invalidates credential-bound version');
$wpdb->row=(object)array('id'=>1,'user_id'=>7,'last_used_at'=>gmdate('Y-m-d H:i:s'),'auth_version'=>$version);
$request=new FakeRequest();
check(Epic_Admin_Dashboard::require_admin_session($request)===true, 'current credential-bound session is accepted');
$wpdb->row->code_challenge=rtrim(strtr(base64_encode(hash('sha256',str_repeat('C',43),true)),'+/','-_'),'=');
$wpdb->row->auth_version='stale-grant-version';
check(is_wp_error(Epic_Admin_Dashboard::exchange($request)), 'stale authorization grant cannot mint a fresh session');
$wpdb->row->auth_version=$version;
foreach ($hooks['after_password_reset'] as $callback) call_user_func($callback,$user);
$denied=Epic_Admin_Dashboard::require_admin_session($request);
check(is_wp_error($denied), 'password-reset hook rejects a copied old token');
check(in_array('wp_epic_admin_sessions',$wpdb->deleted,true) && in_array('wp_epic_admin_grants',$wpdb->deleted,true), 'recovery deletes sessions and grants');
$before=Epic_Admin_Security::session_version($user);
foreach ($hooks['deleted_user_meta'] as $callback) call_user_func($callback,array(1),7,'session_tokens',array());
check($before!==Epic_Admin_Security::session_version($user), 'WordPress destroy-all-sessions invalidates ops epoch');
$wpdb->row->auth_version='';
check(is_wp_error(Epic_Admin_Dashboard::require_admin_session($request)), 'legacy unbound token fails closed');
$wpdb->fail=true;
check(is_wp_error(Epic_Admin_Dashboard::revoke($request)), 'failed session deletion is not reported as success');
check(is_wp_error(Epic_Admin_Security::rate_limit($request,'authorize')), 'rate limiter storage failure fails closed');
$wpdb->fail=false;
for ($i=0;$i<10;$i++) check(Epic_Admin_Security::rate_limit($request,'authorize')===true,'auth attempt within source limit accepted');
check(Epic_Admin_Security::rate_limit($request,'authorize')->data['status']===429, 'excess source auth request rejected');
$wpdb->buckets=array();
for ($i=0;$i<60;$i++) { $request->source=hash('sha256',(string)$i); check(Epic_Admin_Security::rate_limit($request,'authorize')===true,'request within global limit accepted'); }
$request->source=hash('sha256','next');
check(Epic_Admin_Security::rate_limit($request,'authorize')->data['status']===429,'changing sources cannot bypass global auth limit');
$request->origin='https://attacker.invalid';
check(is_wp_error(Epic_Admin_Security::check_origin(null,null,$request)),'external browser origin rejected');
$request->origin='https://ops.epicroastery.coffee';
check(is_wp_error(Epic_Admin_Security::check_origin(null,null,$request)),'ops browser cannot call WordPress API directly');
$request->origin='';
check(Epic_Admin_Security::check_origin(null,null,$request)===null,'server-to-server no-Origin requests remain allowed');
$request->origin='https://admin.epicroastery.coffee';
check(Epic_Admin_Security::check_origin(null,null,$request)===null,'same-origin WordPress requests remain allowed');
echo "All security behaviors passed.\n";
