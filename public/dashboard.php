<?php
// public/public/dashboard.php (Standalone)
// Geschützte Seite mit Bootstrap 5, eigener PDO-Anbindung und einfachen Kennzahlen
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /public/login.php'); exit; }

// --- DB-Verbindung (PDO) ---
require_once '../includes/mysql.php';

$user = $_SESSION['user'];
$is_admin = ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'supervisor';

// --- KPIs vorbereiten ---
function safe_count_query(PDO $pdo, string $sql, array $params = []): int {
  try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int) $stmt->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}

$total_campaigns = safe_count_query($pdo, 'SELECT COUNT(*) FROM campaigns');
$active_campaigns = safe_count_query($pdo, "SELECT COUNT(*) FROM campaigns WHERE status='active'");
$total_companies = safe_count_query($pdo, 'SELECT COUNT(*) FROM companies');
$total_contacts  = safe_count_query($pdo, 'SELECT COUNT(*) FROM contacts');
$open_followups  = safe_count_query($pdo, "SELECT COUNT(*) FROM followups WHERE status='open'");
$overdue_followups = safe_count_query($pdo, "SELECT COUNT(*) FROM followups WHERE status='open' AND due_at < NOW()");
$today_calls     = safe_count_query($pdo, "SELECT COUNT(*) FROM interactions WHERE type='call' AND DATE(occurred_at)=CURDATE()");

// Letzte Aktivitäten
$recent = [];
try {
  $stmt = $pdo->query("SELECT i.id, i.type, i.occurred_at, i.outcome_code, i.summary, c.first_name, c.last_name, co.name AS company
                       FROM interactions i
                       LEFT JOIN contacts c ON c.id = i.contact_id
                       LEFT JOIN companies co ON co.id = i.company_id
                       ORDER BY i.occurred_at DESC LIMIT 10");
  $recent = $stmt->fetchAll();
} catch (Throwable $e) { $recent = []; }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .card-kpi .display-6 { line-height: 1; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbars" aria-controls="navbars" aria-expanded="false" aria-label="Navigation umschalten">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbars">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link active" href="/public/dashboard.php">Dashboard</a></li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <span class="navbar-text text-white-50">👤 <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
        <a class="btn btn-outline-light btn-sm" href="/public/logout.php">Logout</a>
      </div>
    </div>
  </div>
</nav>

<main class="container py-4">
  <div class="row g-3">
    <div class="col-12">
      <div class="alert alert-info d-flex align-items-center justify-content-between">
        <div>
          <strong>Hallo, <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>!</strong> Willkommen im Aquise‑Dashboard.
        </div>
        <div class="d-flex gap-2">
          <a href="#" class="btn btn-sm btn-primary disabled" aria-disabled="true">Dialer starten</a>
          <a href="#" class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Kontakt‑Import</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card shadow-sm h-100">
        <div class="card-body">
        <h2 class="h5">Kontakte importieren (CSV)</h2>
        <p class="text-muted">Über den CSV‑Importer können Sie Firmen und Kontakte massenhaft anlegen oder aktualisieren.</p>
        <a href="/public/contact_import.php" class="btn btn-primary">Zum CSV‑Import</a>
        </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Aktive Kampagnen</div>
          <div class="display-6 fw-semibold"><?= $active_campaigns ?></div>
          <div class="text-muted small">von <?= $total_campaigns ?> gesamt</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Firmen</div>
          <div class="display-6 fw-semibold"><?= $total_companies ?></div>
          <div class="text-muted small">Kontakte: <?= $total_contacts ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Offene Wiedervorlagen</div>
          <div class="display-6 fw-semibold"><?= $open_followups ?></div>
          <div class="text-muted small <?= $overdue_followups>0?'text-danger':'' ?>">Überfällig: <?= $overdue_followups ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card card-kpi shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Heutige Calls</div>
          <div class="display-6 fw-semibold"><?= $today_calls ?></div>
          <div class="text-muted small">(Datum: <?= date('d.m.Y') ?>)</div>
        </div>
      </div>
    </div>

    <!-- Letzte Aktivitäten -->
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h5 mb-3">Letzte Aktivitäten</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Zeit</th>
                  <th>Typ</th>
                  <th>Kontakt</th>
                  <th>Firma</th>
                  <th>Ergebnis</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($recent)): ?>
                <tr><td colspan="5" class="text-muted">Noch keine Aktivitäten gefunden.</td></tr>
              <?php else: foreach ($recent as $r): ?>
                <tr>
                  <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['occurred_at']))) ?></td>
                  <td><span class="badge text-bg-secondary"><?= htmlspecialchars($r['type']) ?></span></td>
                  <td><?= htmlspecialchars(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: '-') ?></td>
                  <td><?= htmlspecialchars($r['company'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['outcome_code'] ?? '-') ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Actions / Hinweise -->
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h5 mb-3">Schnellaktionen</h2>
          <div class="d-grid gap-2">
            <a href="#"