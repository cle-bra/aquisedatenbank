
<?php // public/partials/header.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function csrf_token() {
  if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
  return $_SESSION['csrf'];
}
function is_logged_in() { return isset($_SESSION['user']); }
function require_login() {
  if (!is_logged_in()) { header('Location: /login.php'); exit; }
}
function flash($key, $msg = null) {
  if ($msg !== null) { $_SESSION['flash'][$key] = $msg; return; }
  $m = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $m;
}
?>