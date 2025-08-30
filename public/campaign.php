<?php
// public/campaigns.php – Kampagnenübersicht
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$status = $_GET['status'] ?? '';
$where = [];$params=[];
if ($status!==''){ $where[]='c.status = :st'; $params[':st']=$status; }

$sql = "SELECT c.id, c.name, c.description, c.status, c.created_at,
  (SELECT COUNT(*) FROM campaign_companies cc WHERE cc.campaign_id=c.id AND COALESCE(cc.removed,0)=0) AS companies_count,
  (SELECT COUNT(*) FROM calls ca WHERE ca.campaign_id=c.id) AS calls_count
  FROM campaigns c";
if ($where) $sql .= ' WHERE '.implode(' AND ',$where);
$sql .= ' ORDER BY c.created_at DESC, c.id DESC';
$rows = [];
try{ $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(); }catch(Throwable $e){}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kampagnen · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if($m=flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <h1 class="h5 m-0">Kampagnen</h1>
    <form class="ms-auto d-flex gap-2" method="get">
      <select name="status" class="form-select form-select-sm" style="width:auto">
        <option value="">Alle Status</option>
        <option value="draft" <?= $status==='draft'?'selected':'' ?>>Entwurf</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Aktiv</option>
        <option value="paused" <?= $status==='paused'?'selected':'' ?>>Pausiert</option>
        <option value="done" <?= $status==='done'?'selected':'' ?>>Abgeschlossen</option>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filtern</button>
      <a class="btn btn-sm btn-outline-secondary" href="/campaigns.php">Zurücksetzen</a>
    </form>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle m-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Status</th>
            <th>Firmen</th>
            <th>Telefonate</th>
            <th>Erstellt</th>
            <th style="width:1%"></th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($rows)): ?>
            <tr><td colspan="7" class="text-muted p-3">Keine Kampagnen gefunden.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td class="fw-semibold"><?= e($r['name']) ?><div class="small text-muted"><?= e($r['description'] ?? '') ?></div></td>
              <td>
                <?php $badge='secondary'; if($r['status']==='active')$badge='success'; elseif($r['status']==='paused')$badge='warning'; elseif($r['status']==='done')$badge='dark'; ?>
                <span class="badge bg-<?= $badge ?>"><?= e($r['status'] ?: '—') ?></span>
              </td>
              <td><?= (int)$r['companies_count'] ?></td>
              <td><?= (int)$r['calls_count'] ?></td>
              <td><?= e($r['created_at']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/campaign_view.php?id=<?= (int)$r['id'] ?>">Öffnen</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
