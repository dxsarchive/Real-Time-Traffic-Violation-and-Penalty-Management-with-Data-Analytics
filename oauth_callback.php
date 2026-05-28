<?php
session_start();

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/config_private.php')) {
    include __DIR__ . '/config_private.php';
}
require_once __DIR__ . '/db.php';

function redirect_oauth_error($message, $mode = 'signin') {
    $auth = $mode === 'signup' ? '&auth=signup' : '';
    header('Location: index.php?roles=1&role=motorist' . $auth . '&oauth_error=' . urlencode($message));
    exit();
}

function http_post_form($url, $data) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http_code, $response];
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 20,
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    $http_code = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $http_code = (int)$m[1];
    }
    return [$http_code, $response];
}

function http_get_json($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http_code, $response];
    }

    $response = @file_get_contents($url);
    $http_code = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $http_code = (int)$m[1];
    }
    return [$http_code, $response];
}

$provider = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$mode = isset($_SESSION['oauth_mode']) && $_SESSION['oauth_mode'] === 'signup' ? 'signup' : 'signin';

if (!in_array($provider, ['google', 'facebook'], true) || $code === '') {
    redirect_oauth_error('OAuth callback is invalid.', $mode);
}
if (!isset($_SESSION['oauth_state']) || !hash_equals((string)$_SESSION['oauth_state'], $state)) {
    redirect_oauth_error('OAuth security check failed. Please try again.', $mode);
}
if (!isset($_SESSION['oauth_provider']) || $_SESSION['oauth_provider'] !== $provider) {
    redirect_oauth_error('OAuth provider mismatch. Please try again.', $mode);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirect_uri = $scheme . '://' . $host . '/Real-Time%20Traffic%20Violation%20and%20Penalty%20Management%20System%20Design/oauth_callback.php?provider=' . urlencode($provider);

$google_client_id = isset($google_client_id) ? trim((string)$google_client_id) : '';
$google_client_secret = isset($google_client_secret) ? trim((string)$google_client_secret) : '';
$facebook_app_id = isset($facebook_app_id) ? trim((string)$facebook_app_id) : '';
$facebook_app_secret = isset($facebook_app_secret) ? trim((string)$facebook_app_secret) : '';

try {
    if ($provider === 'google') {
        if ($google_client_id === '' || $google_client_secret === '') {
            redirect_oauth_error('Google OAuth keys are missing.', $mode);
        }
        list($token_status, $token_body) = http_post_form('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $google_client_id,
            'client_secret' => $google_client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        ]);
        $token_json = json_decode((string)$token_body, true);
        if ($token_status >= 400 || empty($token_json['access_token'])) {
            redirect_oauth_error('Google token exchange failed.', $mode);
        }
        $access_token = $token_json['access_token'];
        list($profile_status, $profile_body) = http_get_json('https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . urlencode($access_token));
        $profile = json_decode((string)$profile_body, true);
        if ($profile_status >= 400 || empty($profile['id'])) {
            redirect_oauth_error('Unable to read Google profile.', $mode);
        }
        $oauth_id = (string)$profile['id'];
        $display_name = trim((string)($profile['name'] ?? 'Google Motorist'));
    } else {
        if ($facebook_app_id === '' || $facebook_app_secret === '') {
            redirect_oauth_error('Facebook OAuth keys are missing.', $mode);
        }
        list($token_status, $token_body) = http_get_json('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
            'client_id' => $facebook_app_id,
            'client_secret' => $facebook_app_secret,
            'redirect_uri' => $redirect_uri,
            'code' => $code,
        ]));
        $token_json = json_decode((string)$token_body, true);
        if ($token_status >= 400 || empty($token_json['access_token'])) {
            redirect_oauth_error('Facebook token exchange failed.', $mode);
        }
        $access_token = $token_json['access_token'];
        list($profile_status, $profile_body) = http_get_json('https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,email',
            'access_token' => $access_token,
        ]));
        $profile = json_decode((string)$profile_body, true);
        if ($profile_status >= 400 || empty($profile['id'])) {
            redirect_oauth_error('Unable to read Facebook profile.', $mode);
        }
        $oauth_id = (string)$profile['id'];
        $display_name = trim((string)($profile['name'] ?? 'Facebook Motorist'));
    }

    $username = $provider . '_' . $oauth_id;
    $stmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user && $mode === 'signin') {
        redirect_oauth_error('No motorist account linked to this ' . ucfirst($provider) . ' profile. Please sign up first.', 'signin');
    }

    if (!$user) {
        $random_password = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
        $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, 'motorist', 'active')");
        $insert_stmt->execute([$username, $random_password, $display_name === '' ? 'Motorist User' : $display_name]);
        $user_id = (int)$pdo->lastInsertId();
        $user = [
            'id' => $user_id,
            'username' => $username,
            'full_name' => $display_name === '' ? 'Motorist User' : $display_name,
            'role' => 'motorist',
            'status' => 'active',
        ];

        $license_base = 'MOTO-' . str_pad((string)$user_id, 6, '0', STR_PAD_LEFT);
        $license = $license_base;
        $motorist_ins = $pdo->prepare("INSERT INTO motorists (user_id, license_number, full_name) VALUES (?, ?, ?)");
        $suffix = 0;
        while (true) {
            try {
                $motorist_ins->execute([$user_id, $license, $user['full_name']]);
                break;
            } catch (Throwable $e) {
                $suffix++;
                if ($suffix > 30) {
                    break;
                }
                $license = $license_base . '-' . $suffix;
            }
        }
    } else {
        if (($user['role'] ?? '') !== 'motorist') {
            redirect_oauth_error('This social account is not linked as motorist.', $mode);
        }
        if (($user['status'] ?? '') !== 'active') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)$user['id']]);
            $user['status'] = 'active';
        }
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['role'] = 'motorist';
    $_SESSION['login_time'] = time();

    unset($_SESSION['oauth_state'], $_SESSION['oauth_mode'], $_SESSION['oauth_provider']);
    header('Location: motorist/dashboard.php');
    exit();
} catch (Throwable $e) {
    redirect_oauth_error('Social login failed. Please try again.', $mode);
}
?>
