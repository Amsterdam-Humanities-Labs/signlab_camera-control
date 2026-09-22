<?php
// 401 unless the request carries a valid portal session (the sessionObject
// cookie login_sc.php signs). The verifier is signCollect-v2's, reused so
// there is one implementation: menu_beta/php_api/session.php. Its db.php is
// required here, at top level, so mysql_config's globals stay global.
// Fails closed when that library is not installed.
//
//   require_once __DIR__ . '/require_login.php';   // first line of an endpoint

require_once __DIR__ . '/sc_paths.php';

$studio_session_lib = sc_path('menu_beta/php_api/session.php');
if (is_readable($studio_session_lib)) {
    require_once dirname($studio_session_lib) . '/db.php';
    require_once $studio_session_lib;
} else {
    error_log('studio_beta: ' . $studio_session_lib . ' missing - refusing request');
}
unset($studio_session_lib);

if (!function_exists('current_session') || current_session() === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'not logged in'));
    exit;
}
