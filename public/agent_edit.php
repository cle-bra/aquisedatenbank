<?php
// agent_list.php
declare(strict_types=1);
session_start();
require_once '../includes/mysql.php'; // $pdo

// (Optional) Berechtigungen prüfen:
// if (empty($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? 'agent', ['manager','admin'], true)) { http_response_code(403); exit('Forbidden'); }

const DEFAULT_COUNTRY_CODE = '+49';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function normalize_mobile(string $raw): string {
  $s = trim($raw);
  $s = preg_replace('/[^\d+]/', '', $s) ?? '';
  if ($s === '') return $s;
  if (str_starts_with($s, '00'))       $s = '+' . substr($s, 2);
  elseif ($s[0] === '0')               $s = DEFAULT_COUNTRY_CODE . substr($s, 1);
  elseif ($s[0] !== '+')               $s = '+' . $s;
  $s = '+' . preg_replace('/[^\d]/', '', ltrim($s, '+'));
  return $s;
}


function fetch_campaigns(PDO $pdo): array {
  // Lies aus campaigns und mappe Spaltennamen auf die bisher verwendeten Keys
  $sql = "SELECT id AS kampagne_id, name
          FROM campaigns
          WHERE status IN ('planning','active')   -- ggf. anpassen
          ORDER BY name";
  $stmt = $pdo->query($sql);
  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_agent(PDO $pdo, int $id): ?array {
  $stmt = $pdo->prepare("SELECT * FROM ab_agents WHERE agent_id = :id");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetch_agent_active_campaign_ids(PDO $pdo, int $agentId): array {
  $stmt = $pdo->prepare("SELECT kampagne_id FROM ab_agent_kampagnen WHERE agent_id = :a AND active = 1");
  $stmt->execute([':a' => $agentId]);
  return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'kampagne_id'));
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$errors = [];
$messages = [];

$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : (int)($_POST['agent_id'] ?? 0);
if ($agentId <= 0) { http_response_code(400); exit('Ungültige Agent-ID.'); }

$agent = fetch_agent($pdo, $agentId);
if (!$agent) { http_response_code(404); exit('Agent nicht gefunden.'); }

$campaigns = fetch_campaigns($pdo);
$selectedCampaignIds = fetch_agent_active_campaign_ids($pdo, $agentId);

// Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
    $errors[] = 'Ungültiges Formular (CSRF). Bitte erneut versuchen.';
  } else {
    $salutation = $_POST['salutation'] ?? $agent['salutation'];
    $first_name = trim((string)($_POST['first_name'] ?? $agent['first_name']));
    $last_name  = trim((string)($_POST['last_name'] ?? $agent['last_name']));
    $mobile_raw = trim((string)($_POST['mobile'] ?? $agent['mobile_e164']));
    $email      = trim((string)($_POST['email'] ?? $agent['email']));
    $role       = $_POST['role'] ?? $agent['role'];
    $status     = $_POST['status'] ?? $agent['status'];
    $notes      = trim((string)($_POST['notes'] ?? (string)$agent['notes']));
    $kampagnen  = isset($_POST['kampagnen']) && is_array($_POST['kampagnen']) ? array_map('intval', $_POST['kampagnen']) : [];

    if ($first_name === '') $errors[] = 'Vorname ist erforderlich.';
    if ($last_name === '')  $errors[] = 'Nachname ist erforderlich.';
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

        // Update Agent
        $sql = "UPDATE ab_agents
                SET salutation = :sal, first_name = :fn, last_name = :ln,
                    mobile_e164 = :mobile, email = :email, role = :role,
                    status = :status, notes = :notes
                WHERE agent_id = :id";
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
          ':id'     => $agentId,
        ]);

        // Kampagnen-Zuordnungen synchronisieren
        // 1) alle auf inactive setzen
        $pdo->prepare("UPDATE ab_agent_kampagnen SET active = 0 WHERE agent_id = :a")->execute([':a' => $agentId]);

        // 2) ausgewählte aktiv setzen (insert/upsert)
        if (!empty($kampagnen)) {
          $ins = $pdo->prepare("
            INSERT INTO ab_agent_kampagnen (agent_id, kampagne_id, active)
            VALUES (:a, :k, 1)
            ON DUPLICATE KEY UPDATE active = VALUES(active)
          ");
          foreach (array_unique($kampagnen) as $kid) {
            $ins->execute([':a' => $agentId, ':k' => (int)$kid]);
          }
        }

        $pdo->commit();
        $_SESSION['flash_success'] = 'Agent wurde aktualisiert.';
        header('Location: agent_list.php');
        exit;
      } catch (PDOException $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'uq_ab_agents_email')) {
          $errors[] = 'Diese Email ist bereits vergeben.';
        } elseif (str_contains($e->getMessage(), 'uq_ab_agents_mobile')) {
          $errors[] = 'Diese Mobilnummer ist bereits vergeben.';
        } else {
          $errors[] = 'Datenbankfehler: ' . h($e->getMessage());
        }
      }
    }

    // Falls Fehler: für erneutes Anzeigen die Auswahl übernehmen
    $selectedCampaignIds = $kampagnen;
    $agent = array_merge($agent, [
      'salutation'  => $salutation,
      'first_name'  => $first_name,
      'last_name'   => $last_name,
      'mobile_e164' => $mobile,
      'email'       => mb_strtolower($email),
      'role'        => $role,
      'status'      => $status,
      'notes'       => $notes,
    ]);
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Agent bearbeiten</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Agent bearbeiten</h1>
    <a class="btn btn-outline-secondary" href="agent_list.php">Zur Übersicht</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card p-3 shadow-sm bg-white">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="agent_id" value="<?= (int)$agentId ?>">

    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Anrede</label>
        <select class="form-select" name="salutation" required>
          <?php foreach (['Herr','Frau','Divers','Keine Angabe'] as $opt): ?>
            <option value="<?= $opt ?>" <?= ($agent['salutation'] === $opt ? 'selected' : '') ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Vorname *</label>
        <input class="form-control" name="first_name" value="<?= h($agent['first_name']) ?>" required>
      </div>
      <div class="col-md-5">
        <label class="form-label">Nachname *</label>
        <input class="form-control" name="last_name" value="<?= h($agent['last_name']) ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Mobilnummer (E.164) *</label>
        <input class="form-control" name="mobile" value="<?= h($agent['mobile_e164']) ?>" required>
        <div class="form-text">Wird automatisch normalisiert (Standardland: <?= h(DEFAULT_COUNTRY_CODE) ?>).</div>
      </div>
      <div class="col-md-5">
        <label class="form-label">Email *</label>
        <input type="email" class="form-control" name="email" value="<?= h($agent['email']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Rolle</label>
        <select class="form-select" name="role">
          <?php foreach (['agent'=>'Agent','manager'=>'Manager','admin'=>'Admin'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= ($agent['role'] === $val ? 'selected' : '') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12">
        <label class="form-label">Kampagnenzuordnung</label>
        <select class="form-select" name="kampagnen[]" multiple size="8">
          <?php foreach ($campaigns as $c): $kid=(int)$c['kampagne_id']; ?>
            <option value="<?= $kid ?>" <?= in_array($kid, $selectedCampaignIds, true) ? 'selected' : '' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Strg/Cmd für Mehrfachauswahl. Nicht ausgewählte Zuordnungen werden inaktiv gesetzt.</div>
      </div>

      <div class="col-md-12">
        <label class="form-label">Notiz</label>
        <textarea class="form-control" name="notes" rows="3"><?= h((string)$agent['notes']) ?></textarea>
      </div>

      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <?php foreach (['active'=>'Aktiv','inactive'=>'Inaktiv','blocked'=>'Gesperrt'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= ($agent['status'] === $val ? 'selected' : '') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Speichern</button>
      <a class="btn btn-light" href="agent_list.php">Abbrechen</a>
    </div>
  </form>
</div>
</body>
</html>
