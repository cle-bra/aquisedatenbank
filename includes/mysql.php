
<?php // includes/mysql.php
// PDO-Setup mit Fehlerbehandlung und optionalen ENV-Variablen
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'etzaecnk3';
$DB_USER = getenv('DB_USER') ?: 'eozipgrgi4';
$DB_PASS = getenv('DB_PASS') ?: '4xa4QWpp83ttv!!!!!';
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
  http_response_code(500);
  echo 'DB-Verbindungsfehler.'; // keine sensiblen Details
  error_log('PDO error: ' . $e->getMessage());
  exit;
}
?>
