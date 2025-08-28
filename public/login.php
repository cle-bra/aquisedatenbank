<?php
// public/public/login.php (Standalone)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- DB-Verbindung (PDO) ---
require_once '../includes/mysql.php';

// --- Helper ---
function csrf_token() { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf']; }
function flash($key, $msg = null) {
  if ($msg !== null) { $_SESSION['flash'][$key] = $msg; return; }
  $m = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $m;
}

// --- Login-Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    flash('error', 'Ungültiges Formular (CSRF)');
    header('Location: /public/login.php'); exit;
  }
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $stmt = $pdo->prepare('SELECT id, username, full_name, role, active, password_hash FROM agents WHERE username = :u LIMIT 1');
  $stmt->execute([':u' => $username]);
  $user = $stmt->fetch();
  if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'] ?? '')) {
    flash('error', 'Falsche Zugangsdaten.');
    header('Location: /public/login.php'); exit;
  }
  unset($user['password_hash']);
  $_SESSION['user'] = $user;
  flash('success', 'Erfolgreich angemeldet.');
  header('Location: /public/dashboard.php'); exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Anmeldung · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
  </div>
</nav>
<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h1 class="h4 mb-3">Anmeldung</h1>
          <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
          <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
          <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="mb-3">
              <label for="username" class="form-label">Benutzername</label>
              <input type="text" class="form-control" id="username" name="username" required>
              <div class="invalid-feedback">Bitte Benutzername angeben.</div>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Passwort</label>
              <input type="password" class="form-control" id="password" name="password" required>
              <div class="invalid-feedback">Bitte Passwort angeben.</div>
            </div>
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary">Einloggen</button>
              <a href="/index.php" class="btn btn-outline-secondary">Zurück</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top">
  <div class="container small text-muted">© <?= date('Y') ?> KNX-Trainingcenter · Aquise Backend</div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict'
  const forms = document.querySelectorAll('.needs-validation')
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) {
        event.preventDefault(); event.stopPropagation();
      }
      form.classList.add('was-validated')
    }, false)
  })
})()
</script>
</body>
</html>
