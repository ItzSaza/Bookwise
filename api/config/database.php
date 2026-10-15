<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('BOOKWISE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOKWISE_DB_PORT') ?: '3306';
    $database = getenv('BOOKWISE_DB_NAME') ?: 'bookwise';
    $username = getenv('BOOKWISE_DB_USER') ?: 'root';
    $password = getenv('BOOKWISE_DB_PASSWORD');
    if ($password === false) {
        $localConfig = __DIR__ . '/database.local.php';
        $password = is_file($localConfig) ? (require $localConfig) : '';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $username, (string)$password, $options);
    } catch (PDOException $e) {
        // Fallback for local environments where root has an empty password
        if ((int)$e->getCode() === 1045 && (string)$password !== '') {
            try {
                return new PDO($dsn, $username, '', $options);
            } catch (PDOException) {
                throw $e;
            }
        }
        throw $e;
    }
}
