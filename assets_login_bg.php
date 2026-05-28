<?php
$candidates = [
    'C:\\Users\\user\\.cursor\\projects\\c-xampp-htdocs-Real-Time-Traffic-Violation-and-Penalty-Management-System-Design\\assets\\c__Users_user_AppData_Roaming_Cursor_User_workspaceStorage_afadf37b5df442359891d9b90d85808d_images_pototan-iloilo-philippines-april-24-260nw-2324288989-e0e53a76-6f22-4f99-99cf-808c11282e13.png',
];

$image_path = '';
foreach ($candidates as $candidate) {
    if (is_string($candidate) && $candidate !== '' && file_exists($candidate) && is_readable($candidate)) {
        $image_path = $candidate;
        break;
    }
}

if ($image_path === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Background image not found.';
    exit();
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
readfile($image_path);
exit();
?>
