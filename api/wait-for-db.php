<?php
$databaseUrl = getenv('DATABASE_URL');
if (!$databaseUrl) {
    echo "DATABASE_URL is not set\n";
    exit(1);
}
$parts = parse_url($databaseUrl);
if (!$parts) {
    echo "Invalid DATABASE_URL\n";
    exit(1);
}
$scheme   = 'pgsql';
$user     = $parts['user'];
$password = $parts['pass'];
$host     = $parts['host'];
$port     = $parts['port'];
$path     = $parts['path'];
$dbname   = ltrim($path, '/');
$dsn = sprintf('%s:host=%s;port=%d;dbname=%s', $scheme, $host, $port, $dbname);
try {
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->query('SELECT 1');
    echo "Database connection successful.\n";
    exit(0);
} catch (PDOException $e) {
    echo "PDOException: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    exit(1);
}
