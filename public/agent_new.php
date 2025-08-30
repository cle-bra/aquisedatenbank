<?php
// agent_new.php
declare(strict_types=1);
session_start();
require_once '../includes/mysql.php'; // $pdo

// === Konfiguration ===
const DEFAULT_COUNTRY_CODE = '+49'; // ggf. anpassen
$errors = [];
$messages = [];

// CSRF-Token erzeugen
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Hilfsfunktionen
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function normalize_mobile(string $raw): string {
  // Entfernt alles außer Ziffern und Plus, erzwingt E.164 so gut wie möglich.
  $s = trim($raw);
  $s = preg_replace('/[^\d+]/', '', $s) ?? '';
  // Fälle:
  // +49...  => ok
  // 0049... => -> +49...
  // 0...    => -> +49...
  // 49...   => -> +49...
  if ($s === '') return $s;
  if (str_starts_with($s, '00')) {
    $s = '+' . substr($s, 2);
  } elseif ($s[0] === '0') {
    $s = DEFAULT_COUNTRY_CODE . substr($s, 1);
  } elseif ($s[0] !== '+') {
    // kein +, keine 0/00 => vermutlich Landesvorwahl ohne +
    $s = '+' . $s;
  }
  // vereinfacht: nur ein + am Anfang zulassen
  $s = '+' . preg_replace('/[^\d]/', '', ltrim($s, '+'));
  return $s;
}

function fetch_campaigns(PDO $pdo): array {
  $stmt = $pdo->query("SELECT kampagne_id, name FROM ab_kampagnen WHERE status IN ('geplant','aktiv') ORDER BY name");
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Submit-Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    $errors[] = 'Ungültiges Formular (CSRF). Bitte erneut versuchen.';
  } else {
    $salutation = $_POST['salutation'] ?? 'Keine Angabe';
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name  = trim((string)($_POST['last_name'] ?? ''));
    $mobile_raw = trim((string)($_POST['mobile'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $role       = $_POST['role'] ?? 'agent';
    $status     = $_POST['status'] ?? 'active';
    $notes      = trim((string)($_POST['notes'] ?? ''));
    $kampagnen  = isset($_POST['kampagnen']) && is_array($_POST['kampagnen']) ? array_map('intval', $_POST['kampagnen']) : [];

    // Validierung
    if ($first_name === '') $errors[] = 'Vorname ist erforderlich.';
    if ($last_name === '')  $errors[]  = 'Nachname ist erforderlich.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige Email ist erforderlich.';
    $mobile = normalize_mobile($mobile_raw);
    if ($mobile === '' || !preg_match('/^\+\d{6,16}$/', $mobile)) $errors[] = 'Gültige Mobilnummer (E.164) ist erforderlich.';

    $allowedSal = ['Herr','Frau','Divers','Keine Angabe'];
    if (!in_array($salutation, $allowedSal, true)) $errors[] = 'Ungültige Anrede.';
    $allowedRoles = ['agent','manager','admin'];
    if (!in_array($role, $allowedRoles, true)) $role = 'agent';
    $allowedStatus = ['active','inactive','blocked'];
    if (!in_array($status, $allowedStatus, true)) $status = 'active';

    if (!$errors) {
      try {
        $pdo->beginTransaction();

        // Agent anlegen
        $sql = "INSERT INTO ab_agents (salutation, first_name, last_name, mobile_e164, email, role, status, notes)
                VALUES (:sal, :fn, :ln, :mobile, :email, :role, :status, :notes)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':sal'    => $salutation,
          ':fn'     => $first_name,
          ':ln'     => $last_name,
          ':mobile' => $mobile,
          ':email'  => mb_strtolower($email),
          ':role'   => $role,
          ':status' => $status,
          ':notes'  => $notes !== '' ? $notes : null,
        ]);
        $agentId = (int)$pdo->lastInsertId();

        // Kampagnen-Zuordnung (optional)
        if (!empty($kampagnen)) {
          $ins = $pdo->prepare("INSERT INTO ab_agent_kampagnen (agent_id, kampagne_id, active) VALUES (:a,:k,1)");
          foreach (array_unique($kampagnen) as $kid) {
            $ins->execute([':a' => $agentId, ':k' => $kid]);
          }
        }

        $pdo->commit();
        $_SESSION['flash_success'] = 'Agent wurde angelegt.';
        header('Location: agents_list.php');
        exit;
      } catch (PDOException $e) {
        $pdo->rollBack();
        // Eindeutige Constraints behandeln
        if (str_contains($e->getMessage(), 'uq_ab_agents_email')) {
          $errors[] = 'Diese Email ist bereits vergeben.';
        } elseif (str_contains($e->getMessage(), 'uq_ab_agents_mobile')) {
          $errors[] = 'Diese Mobilnummer ist bereits vergeben.';
        } else {
          $errors[] = 'Datenbankfehler: ' . h($e->getMessage());
        }
      }
    }
  }
}

$campaigns = fetch_campaigns($pdo);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Agent anlegen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap (falls Sie bereits global laden, diesen Block entfernen) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include ('main_menu.php'); ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Agent anlegen</h1>
    <a class="btn btn-outline-secondary" href="agents_list.php">Zur Übersicht</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card p-3 shadow-sm bg-white">
    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Anrede</label>
        <select class="form-select" name="salutation" required>
          <?php foreach (['Herr','Frau','Divers','Keine Angabe'] as $opt): ?>
            <option value="<?= $opt ?>" <?= (($_POST['salutation'] ?? '') === $opt ? 'selected' : '') ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Vorname *</label>
        <input type="text" class="form-control" name="first_name" value="<?= h($_POST['first_name'] ?? '') ?>" required>
      </div>
      <div class="col-md-5">
        <label class="form-label">Nachname *</label>
        <input type="text" class="form-control" name="last_name" value="<?= h($_POST['last_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Mobilnummer (E.164) *</label>
        <input type="text" class="form-control" name="mobile" placeholder="+491701234567" value="<?= h($_POST['mobile'] ?? '') ?>" required>
        <div class="form-text">Wird automatisch auf E.164 normalisiert (Standard: <?= h(DEFAULT_COUNTRY_CODE) ?>).</div>
      </div>
      <div class="col-md-5">
        <label class="form-label">Email *</label>
        <input type="email" class="form-control" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Rolle</label>
        <select class="form-select" name="role">
          <?php foreach (['agent'=>'Agent','manager'=>'Manager','admin'=>'Admin'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= (($_POST['role'] ?? 'agent') === $val ? 'selected' : '') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12">
        <label class="form-label">Kampagnenzuordnung (optional)</label>
        <select class="form-select" name="kampagnen[]" multiple size="6">
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= (int)$c['kampagne_id'] ?>" <?= (isset($_POST['kampagnen']) && in_array((int)$c['kampagne_id'], array_map('intval', (array)$_POST['kampagnen']), true) ? 'selected' : '') ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Strg/Cmd gedrückt halten für Mehrfachauswahl.</div>
      </div>

      <div class="col-md-12">
        <label class="form-label">Notiz (optional)</label>
        <textarea class="form-control" name="notes" rows="3"><?= h($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <?php foreach (['active'=>'Aktiv','inactive'=>'Inaktiv','blocked'=>'Gesperrt'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= (($_POST['status'] ?? 'active') === $val ? 'selected' : '') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mt-4 d-flex gap-2">
      <button type="submit" class="btn btn-primary">Speichern</button>
      <a href="agents_list.php" class="btn btn-light">Abbrechen</a>
    </div>
  </form>
</div>
</body>
</html>
