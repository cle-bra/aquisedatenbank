<?php
// public/dashboard.php – Kampagnen-Übersicht (aktiv + alle) & Formular „Neue Kampagne“
// DB-Anbindung über ../includes/mysql.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function is_logged_in() { return isset($_SESSION['user']); }
function require_login() { if (!is_logged_in()) { header('Location: /login.php'); exit; } }
require_login();

// --- DB-Verbindung (PDO) ---
require_once '../includes/mysql.php'; // stellt $pdo bereit

// --- Utilities ---
function csrf_token() { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf']; }
function flash($key, $msg = null) { if ($msg !== null) { $_SESSION['flash'][$key] = $msg; return; } $m = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $m; }

// --- Kampagne anlegen ---
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_campaign') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $errors[] = 'Ungültiges Formular (CSRF).';
  } else {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $starts_on = trim($_POST['starts_on'] ?? '');
    $ends_on = trim($_POST['ends_on'] ?? '');
    $status = $_POST['status'] ?? 'planning';

    if ($name === '') { $errors[] = 'Bitte einen Kampagnennamen angeben.'; }
    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';
    if ($starts_on !== '' && !preg_match($dateRe, $starts_on)) { $errors[] = 'Startdatum ist ungültig (YYYY-MM-DD).'; }
    if ($ends_on !== '' && !preg_match($dateRe, $ends_on)) { $errors[] = 'Enddatum ist ungültig (YYYY-MM-DD).'; }
    $allowed = ['planning','active','paused','done'];
    if (!in_array($status, $allowed, true)) { $errors[] = 'Status ist ungültig.'; }

    if (empty($errors)) {
      $stmt = $pdo->prepare('INSERT INTO campaigns (name, description, starts_on, ends_on, status) VALUES (:n,:d,:s,:e,:st)');
      $stmt->execute([':n'=>$name, ':d'=>$description ?: null, ':s'=>$starts_on ?: null, ':e'=>$ends_on ?: null, ':st'=>$status]);
      flash('success', 'Kampagne „'.htmlspecialchars($name).'“ wurde angelegt.');
      header('Location: /dashboard.php'); exit;
    }
  }
}

// --- Filter für "alle Kampagnen" ---
$flt_status = $_GET['status'] ?? '';
$allowed_status = ['','planning','active','paused','done'];
if (!in_array($flt_status, $allowed_status, true)) { $flt_status = ''; }

// --- Aktive Kampagnen laden ---
try {
  $stmt = $pdo->query("SELECT id, name, description, starts_on, ends_on, status FROM campaigns WHERE status='active' ORDER BY COALESCE(starts_on, '1900-01-01') DESC, id DESC");
  $active_campaigns = $stmt->fetchAll();
} catch (Throwable $e) { $active_campaigns = []; }

// --- Alle Kampagnen laden (optional gefiltert) ---
try {
  if ($flt_status === '') {
    $stmt = $pdo->query("SELECT id, name, description, starts_on, ends_on, status FROM campaigns ORDER BY COALESCE(starts_on, '1900-01-01') DESC, id DESC");
    $all_campaigns = $stmt->fetchAll();
  } else {
    $stmt = $pdo->prepare("SELECT id, name, description, starts_on, ends_on, status FROM campaigns WHERE status = :st ORDER BY COALESCE(starts_on, '1900-01-01') DESC, id DESC");
    $stmt->execute([':st'=>$flt_status]);
    $all_campaigns = $stmt->fetchAll();
  }
} catch (Throwable $e) { $all_campaigns = []; }

function status_badge(string $st): string {
  return [
    'active' => 'text-bg-success',
    'planning' => 'text-bg-primary',
    'paused' => 'text-bg-warning',
    'done' => 'text-bg-secondary',
  ][$st] ?? 'text-bg-light';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

  <?php include ('main_menu.php'); ?>
  
<main class="container py-4">
  <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

  <div class="row g-3">
    <!-- Aktive Kampagnen -->
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h1 class="h5 mb-3">Aktive Kampagnen</h1>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Zeitraum</th>
                  <th>Beschreibung</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($active_campaigns)): ?>
                  <tr><td colspan="5" class="text-muted">Keine aktiven Kampagnen gefunden.</td></tr>
                <?php else: foreach ($active_campaigns as $c): ?>
                  <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
                    <td>
                      <?php if ($c['starts_on']): ?><?= htmlspecialchars(date('d.m.Y', strtotime($c['starts_on']))) ?><?php endif; ?> –
                      <?php if ($c['ends_on']): ?><?= htmlspecialchars(date('d.m.Y', strtotime($c['ends_on']))) ?><?php endif; ?>
                    </td>
                    <td class="text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($c['description'] ?? '') ?>"><?= htmlspecialchars($c['description'] ?? '–') ?></td>
                    <td><span class="badge <?= status_badge($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td class="text-end"><a href="/public/campaign_detail.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Öffnen</a></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <a href="/contact_import.php" class="btn btn-outline-primary btn-sm">Kontakte importieren (CSV)</a>
        </div>
      </div>
    </div>

    <!-- Formular: Neue Kampagne -->
    <div class="col-12 col-xl-5">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h5 mb-3">Neue Kampagne anlegen</h2>
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_campaign">
            <div class="col-12">
              <label for="name" class="form-label">Name *</label>
              <input type="text" class="form-control" id="name" name="name" required placeholder="z. B. Berlin 2026 Telefonaquise">
            </div>
            <div class="col-12">
              <label for="description" class="form-label">Beschreibung</label>
              <textarea class="form-control" id="description" name="description" rows="3" placeholder="Kurzbeschreibung der Kampagne"></textarea>
            </div>
            <div class="col-6">
              <label for="starts_on" class="form-label">Start (YYYY-MM-DD)</label>
              <input type="date" class="form-control" id="starts_on" name="starts_on">
            </div>
            <div class="col-6">
              <label for="ends_on" class="form-label">Ende (YYYY-MM-DD)</label>
              <input type="date" class="form-control" id="ends_on" name="ends_on">
            </div>
            <div class="col-12">
              <label for="status" class="form-label">Status</label>
              <select class="form-select" id="status" name="status">
                <option value="planning">planning</option>
                <option value="active" selected>active</option>
                <option value="paused">paused</option>
                <option value="done">done</option>
              </select>
            </div>
            <div class="col-12 d-grid">
              <button type="submit" class="btn btn-primary">Kampagne anlegen</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Alle Kampagnen (mit Filter) -->
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h5 mb-0">Alle Kampagnen</h2>
            <form method="get" class="d-flex gap-2">
              <select class="form-select form-select-sm" name="status" onchange="this.form.submit()" style="width:auto">
                <option value="" <?= $flt_status===''?'selected':'' ?>>alle</option>
                <option value="planning" <?= $flt_status==='planning'?'selected':'' ?>>planning</option>
                <option value="active" <?= $flt_status==='active'?'selected':'' ?>>active</option>
                <option value="paused" <?= $flt_status==='paused'?'selected':'' ?>>paused</option>
                <option value="done" <?= $flt_status==='done'?'selected':'' ?>>done</option>
              </select>
              <?php if ($flt_status!==''): ?><a class="btn btn-outline-secondary btn-sm" href="/dashboard.php">Reset</a><?php endif; ?>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Zeitraum</th>
                  <th>Status</th>
                  <th>Beschreibung</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($all_campaigns)): ?>
                  <tr><td colspan="6" class="text-muted">Keine Kampagnen gefunden.</td></tr>
                <?php else: foreach ($all_campaigns as $c): ?>
                  <tr>
                    <td class="text-muted">#<?= (int)$c['id'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
                    <td>
                      <?php if ($c['starts_on']): ?><?= htmlspecialchars(date('d.m.Y', strtotime($c['starts_on']))) ?><?php endif; ?> –
                      <?php if ($c['ends_on']): ?><?= htmlspecialchars(date('d.m.Y', strtotime($c['ends_on']))) ?><?php endif; ?>
                    </td>
                    <td><span class="badge <?= status_badge($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td class="text-truncate" style="max-width: 360px;" title="<?= htmlspecialchars($c['description'] ?? '') ?>"><?= htmlspecialchars($c['description'] ?? '–') ?></td>
                    <td class="text-end"><a href="/public/campaign_detail.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Details</a></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top">
  <div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>