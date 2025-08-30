<?php
// agents_list.php
declare(strict_types=1);
session_start();
require_once '../includes/mysql.php'; // $pdo

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$flash = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$q = trim((string)($_GET['q'] ?? ''));
$kampagneFilter = isset($_GET['kampagne_id']) ? (int)$_GET['kampagne_id'] : 0;

// Kampagnen für Filter
$kampStmt = $pdo->query("SELECT kampagne_id, name FROM ab_kampagnen ORDER BY name");
$kampagnen = $kampStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Basis-SQL (View)
$sql = "SELECT agent_id, salutation, first_name, last_name, email, mobile_e164, role, status, kampagnen
        FROM v_ab_agents_kampagnen";
$where = [];
$params = [];

// Freitextsuche (Name/Email/Mobil)
if ($q !== '') {
  $where[] = "(first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR mobile_e164 LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

// Nach Kampagne filtern (JOIN direkt auf Tabellen, falls Filter gesetzt)
if ($kampagneFilter > 0) {
  $sql = "SELECT a.agent_id, a.salutation, a.first_name, a.last_name, a.email, a.mobile_e164, a.role, a.status,
                 GROUP_CONCAT(k.name ORDER BY k.name SEPARATOR ', ') AS kampagnen
          FROM ab_agents a
          LEFT JOIN ab_agent_kampagnen ak ON ak.agent_id = a.agent_id AND ak.active = 1
          LEFT JOIN ab_kampagnen k ON k.kampagne_id = ak.kampagne_id
          WHERE ak.kampagne_id = :kid
          " . ($q !== '' ? " AND (a.first_name LIKE :q OR a.last_name LIKE :q OR a.email LIKE :q OR a.mobile_e164 LIKE :q)" : "") . "
          GROUP BY a.agent_id, a.salutation, a.first_name, a.last_name, a.email, a.mobile_e164, a.role, a.status";
  $params[':kid'] = $kampagneFilter;
} else {
  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }
  $sql .= " ORDER BY last_name, first_name";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Agents & Kampagnen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .table td, .table th { vertical-align: middle; }
    .badge-role { text-transform: uppercase; letter-spacing: .02em; }
  </style>
</head>
<body class="bg-light">
    <?php include ('main_menu.php'); ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Agents &amp; zugeordnete Kampagnen</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-primary" href="agent_new.php">+ Agent anlegen</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success"><?= h($flash) ?></div>
  <?php endif; ?>

  <form class="row gy-2 gx-2 align-items-end mb-3" method="get">
    <div class="col-md-5">
      <label class="form-label">Suche (Name, Email, Mobil)</label>
      <input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="z. B. Müller oder mueller@...">
    </div>
    <div class="col-md-5">
      <label class="form-label">Kampagne</label>
      <select class="form-select" name="kampagne_id">
        <option value="0">Alle</option>
        <?php foreach ($kampagnen as $k): ?>
          <option value="<?= (int)$k['kampagne_id'] ?>" <?= $kampagneFilter === (int)$k['kampagne_id'] ? 'selected' : '' ?>>
            <?= h($k['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-outline-secondary w-100" type="submit">Filtern</button>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
      <thead class="table-light">
        <tr>
          <th style="width: 24%">Name</th>
          <th style="width: 18%">Email</th>
          <th style="width: 14%">Mobil</th>
          <th style="width: 10%">Rolle</th>
          <th style="width: 10%">Status</th>
          <th style="width: 16%">Kampagnen</th>
          <th style="width: 8%">Aktion</th> <!-- NEU -->
        </tr>
      </thead>

        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Keine Einträge gefunden.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['salutation'] . ' ' . $r['first_name'] . ' ' . $r['last_name']) ?></td>
              <td><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
              <td><a href="tel:<?= h($r['mobile_e164']) ?>"><?= h($r['mobile_e164']) ?></a></td>
              <td>
                <?php
                  $role = (string)$r['role'];
                  $roleClass = match ($role) {
                    'admin' => 'bg-danger-subtle text-danger-emphasis',
                    'manager' => 'bg-warning-subtle text-warning-emphasis',
                    default => 'bg-primary-subtle text-primary-emphasis',
                  };
                ?>
                <span class="badge badge-role <?= $roleClass ?>"><?= strtoupper(h($role)) ?></span>
              </td>
              <td>
                <?php
                  $status = (string)$r['status'];
                  $stClass = match ($status) {
                    'active' => 'text-success',
                    'inactive' => 'text-muted',
                    'blocked' => 'text-danger',
                    default => 'text-body',
                  };
                ?>
                <span class="<?= $stClass ?>"><?= h($status) ?></span>
              </td>
              <td><?= h($r['kampagnen'] ?? '') ?></td>
              <td>
  <a class="btn btn-sm btn-outline-primary"
     href="agent_edit.php?agent_id=<?= (int)$r['agent_id'] ?>">
     Edit
  </a>
</td>

            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
