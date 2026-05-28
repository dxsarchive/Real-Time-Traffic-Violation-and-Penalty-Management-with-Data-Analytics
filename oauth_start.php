<?php
session_start();

$provider = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
$mode = isset($_GET['mode']) && $_GET['mode'] === 'signup' ? 'signup' : 'signin';
if (!in_array($provider, ['google', 'facebook'], true)) {
    header('Location: index.php?roles=1&role=motorist&oauth_error=Invalid%20OAuth%20provider');
    exit();
}

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/config_private.php')) {
    include __DIR__ . '/config_private.php';
}

$google_client_id = isset($google_client_id) ? trim((string)$google_client_id) : '';
$facebook_app_id = isset($facebook_app_id) ? trim((string)$facebook_app_id) : '';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirect_uri = $scheme . '://' . $host . '/Real-Time%20Traffic%20Violation%20and%20Penalty%20Management%20System%20Design/oauth_callback.php?provider=' . urlencode($provider);

if ($provider === 'google' && $google_client_id === '') {
    header('Location: index.php?roles=1&role=motorist&oauth_error=Google%20OAuth%20is%20not%20configured');
    exit();
}
if ($provider === 'facebook' && $facebook_app_id === '') {
    header('Location: index.php?roles=1&role=motorist&oauth_error=Facebook%20OAuth%20is%20not%20configured');
    exit();
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_mode'] = $mode;
$_SESSION['oauth_provider'] = $provider;

if ($provider === 'google') {
    $params = [
        'client_id' => $google_client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    ];
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
} else {
    $params = [
        'client_id' => $facebook_app_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'email,public_profile',
        'state' => $state,
    ];
    $auth_url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params);
}

header('Location: ' . $auth_url);
exit();
?>
