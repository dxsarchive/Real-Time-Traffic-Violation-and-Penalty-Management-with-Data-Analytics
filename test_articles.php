<?php
require_once 'api/db.php';
require_once 'auth.php';

if (login('motorist1', 'password123', 'motorist')) {
    echo 'Motorist login successful.' . PHP_EOL;

    global $pdo;
    $conn = $pdo;

    $articles = $conn->query('SELECT title FROM articles ORDER BY created_at DESC');
    echo 'Articles found:' . PHP_EOL;
    while ($a = $articles->fetch(PDO::FETCH_ASSOC)) {
        echo '- ' . $a['title'] . PHP_EOL;
    }
} else {
    echo 'Motorist login failed.' . PHP_EOL;
}
?>
