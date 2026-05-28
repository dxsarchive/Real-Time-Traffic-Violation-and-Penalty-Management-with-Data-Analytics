<?php
$pdo = new PDO('sqlite:traffic_management.sqlite');
$pdo->exec('ALTER TABLE violations ADD COLUMN confiscated_items TEXT DEFAULT "None"');
echo 'Column added successfully';
