<?php
// ShieldPanel Database Connection Helper

function getDBConnection() {
    $host = getenv('DB_HOST') ?: 'postgres';
    $port = getenv('DB_PORT') ?: '5432';
    $db   = getenv('DB_NAME') ?: 'shieldpanel';
    $user = getenv('DB_USER') ?: 'shieldpanel_user';
    $pass = getenv('DB_PASS') ?: 'shieldpanel_secure_pass';

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=disable";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $maxRetries = 5;
    $delay = 1; // start with 1 second delay

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            if ($attempt === $maxRetries) {
                throw new Exception("Database connection failed after $maxRetries attempts: " . $e->getMessage());
            }
            error_log("Database connection attempt $attempt failed. Retrying in $delay seconds...");
            sleep($delay);
            $delay *= 2; // exponential backoff
        }
    }
}
