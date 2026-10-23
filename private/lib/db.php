<?php
declare(strict_types=1);

/** Loads config.php (tests point E3_CONFIG at a throwaway file; on the server the variable is unset). */
function load_config(): array
{
    return require (getenv('E3_CONFIG') ?: __DIR__ . '/../config.php');
}

/** One place that opens the database, so every endpoint stores times the same way (UTC). */
function connect_db(array $config): PDO
{
    date_default_timezone_set('UTC');
    $pdo = new PDO($config['db']['dsn'], $config['db']['user'] ?? null, $config['db']['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
