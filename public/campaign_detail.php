<?php
// public/public/campaign_detail.php – Detailansicht einer Kampagne (read‑only v1)
// DB-Anbindung via ../includes/mysql.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'Ungültige ID'; exit; }

// Kampagne laden
$stmt = $pdo->prepare('SELECT id, name, description, starts_on, ends_on, status FROM campaigns WHERE id = :id LIMIT 1');
$stmt->execute([':id'=>$id]);
$camp = $stmt->fetch();
if (!$camp) { http_response_code(404); echo 'Kampagne nicht gefunden'; exit; }

// einfache Kennzahlen
$counts = [
  'interactions' => 0,
  'companies' => 0,
  'contacts' => 0,
  'followups_open' => 0,
];
try {
  $counts['interactions'] = (int)$pdo->query("SELECT COUNT(*) FROM interactions WHERE campaign_id = ".(int)$id)->fetchColumn();
  $counts['companies'] = (int)$pdo->query("SELECT COUNT(DISTINCT company_id) FROM interactions WHERE campaign_id = ".(int)$id)->fetchColumn();
  $counts['contacts'] = (int)$pdo->query("SELECT COUNT(DISTINCT contact_id) FROM interactions WHERE campaign_id = ".(int)$id)->fetchColumn();
  $counts['followups_open'] = (int)$pdo->query("SELECT COUNT(*) FROM followups f JOIN interactions i ON i.id=f.interaction_id WHERE i.campaign_id = ".(int)$id." AND f.status='open'")->fetchColumn();
} catch (Throwable $e) {}

// letzte Aktivitäten
$recent = [];
try {
  $stmt = $pdo->prepare("SELECT i.id, i.type, i.occurred_at, i.outcome_code, i.summary, c.first_name, c.last_name, co.name AS company
                         FROM interactions i
                         LEFT JOIN contacts c ON c.id=i.contact_id
                         LEFT JOIN companies co ON co.id=i.company_id
                         WHERE i.campaign_id=:id
                         ORDER BY i.occurred_at DESC LIMIT 15");
  $stmt->execute([':id'=>$id]);
  $recent = $stmt->fetchAll();
} catch (Throwable $e) {}

function badge($st){
  return [
    'active'=>'text-bg-success',
    'planning'=>'text-bg-primary',
    'paused'=>'text-bg-warning',
    'done'=>'text-bg-secondary',
  ][$st] ?? 'text-bg-light';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kampagne: <?= e($camp['name']) ?> · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/public/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item active" aria-current="page">Kampagne #<?= (int)$camp['id'] ?></li>
    </ol>
  </nav>

  <div class="row g-3">
    <div class="col-12 col-xl-8">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between">
            <div>
              <h1 class="h4 mb-1"><?= e($camp['name']) ?></h1>
              <div class="small text-muted">ID #<?= (int)$camp['id'] ?> · Status: <span class="badge <?= badge($camp['status']) ?>"><?= e($camp['status']) ?></span></div>
            </div>
          </div>
          <hr>
          <dl class="row mb-0">
            <dt class="col-sm-3">Zeitraum</dt>
            <dd class="col-sm-9">
              <?= $camp['starts_on'] ? e(date('d.m.Y', strtotime($camp['starts_on']))) : '–' ?> –
              <?= $camp['ends_on'] ? e(date('d.m.Y', strtotime($camp['ends_on']))) : '–' ?>
            </dd>
            <dt class="col-sm-3">Beschreibung</dt>
            <dd class="col-sm-9"><?= $camp['description'] ? nl2br(e($camp['description'])) : '–' ?></dd>
          </dl>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Letzte Aktivitäten</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
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
                  <tr><td colspan="5" class="text-muted">Noch keine Aktivitäten.</td></tr>
                <?php else: foreach ($recent as $r): ?>
                  <tr>
                    <td><?= e(date('d.m.Y H:i', strtotime($r['occurred_at']))) ?></td>
                    <td><span class="badge text-bg-secondary"><?= e($r['type']) ?></span></td>
                    <td><?= e(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: '-') ?></td>
                    <td><?= e($r['company'] ?? '-') ?></td>
                    <td><?= e($r['outcome_code'] ?? '-') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h6">Kennzahlen</h2>
          <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center">Aktivitäten <span class="badge text-bg-secondary"><?= (int)$counts['interactions'] ?></span></li>
            <li class="list-group-item d-flex justify-content-between align-items-center">Firmen <span class="badge text-bg-secondary"><?= (int)$counts['companies'] ?></span></li>
            <li class="list-group-item d-flex justify-content-between align-items-center">Kontakte <span class="badge text-bg-secondary"><?= (int)$counts['contacts'] ?></span></li>
            <li class="list-group-item d-flex justify-content-between align-items-center">Offene Follow-ups <span class="badge text-bg-warning"><?= (int)$counts['followups_open'] ?></span></li>
          </ul>
          <hr>
          <div class="d-grid gap-2">
            <a href="/public/contact_import.php" class="btn btn-outline-primary btn-sm">Kontakte importieren (CSV)</a>
            <a href="#" class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Dialer starten (bald)</a>
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