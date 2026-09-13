<?php
declare(strict_types=1);

function agenthub_config(): array
{
    static $config;
    if ($config !== null) return $config;
    $file = __DIR__ . '/../config/config.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit('AgentHub is not configured. Copy config/config.example.php to config/config.php.');
    }
    $config = require $file;
    date_default_timezone_set((string)($config['timezone'] ?? 'UTC'));
    return $config;
}

function agenthub_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = agenthub_config();
    $db = $config['db'] ?? [];
    $pdo = new PDO((string)$db['dsn'], (string)$db['username'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function agenthub_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function agenthub_sql_time(DateTimeInterface $time): string
{
    return (new DateTimeImmutable($time->format('Y-m-d H:i:s.u'), new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}

function agenthub_json_time(string $value): string
{
    $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    return $time->format('Y-m-d\TH:i:s.u\Z');
}
