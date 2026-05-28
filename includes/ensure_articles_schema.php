<?php

/**
 * Ensures `articles` exists with columns used by the homepage and supervisor content manager.
 */
function ensure_articles_schema(PDO $pdo, string $db_driver): void
{
    if ($db_driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            link_url VARCHAR(1000) NOT NULL DEFAULT '',
            attachment_path VARCHAR(500) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_articles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $pdo->exec("ALTER TABLE articles ADD COLUMN link_url VARCHAR(1000) NOT NULL DEFAULT ''");
        } catch (PDOException $e) {
        }
        try {
            $pdo->exec("ALTER TABLE articles ADD COLUMN attachment_path VARCHAR(500) NOT NULL DEFAULT ''");
        } catch (PDOException $e) {
        }
        try {
            $pdo->exec("ALTER TABLE articles ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
        }
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL,
        content TEXT NOT NULL,
        published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        link_url TEXT NOT NULL DEFAULT '',
        attachment_path TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1
    )");
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_articles_slug ON articles(slug)");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE articles ADD COLUMN link_url TEXT NOT NULL DEFAULT ''");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE articles ADD COLUMN attachment_path TEXT NOT NULL DEFAULT ''");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE articles ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
    }
}
