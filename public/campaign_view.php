<?php
// public/campaign_view.php – Kampagnendetail: zugeordnete Firmen, Anrufhistorie, Entfernen
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

// --- Optionale Schema-Erweiterung (Hinweis)
// campaign_companies: Spalten removed TINYINT(1) DEFAULT 0, removed_reason VARCHAR(255) NULL, removed_at DATETIME NULL, removed_by INT NULL
// SQL Beispiel:
// ALTER TABLE campaign_companies ADD COLUMN removed TINYINT(1) NOT NULL DEFAULT 0 AFTER added_at,
//   ADD COLUMN removed_reason VARCHAR(255) NULL AFTER removed,
//   ADD COLUMN removed_at DATETIME NULL AFTER removed_reason,
//   ADD COLUMN removed_by INT NULL AFTER removed_at;

$campId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($campId<=0){ http_response_code(400); echo 'Kampagnen-ID fehlt.'; exit; }

// Kampagne laden
$camp=null; $errors=[];
$st=$pdo->prepare('SELECT id,name,description,status,created_at FROM campaigns WHERE id=:id');
$st->execute([':id'=>$campId]);
$camp=$st->fetch();
if(!$camp){ http_response_code(404); echo 'Kampagne nicht gefunden.'; exit; }

// POST: Firma aus Kampagne entfernen (soft)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='remove_company'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')) { $errors[]='Ungültiges Formular (CSRF).'; }
  $cid = (int)($_POST['company_id'] ?? 0);
  $reason = trim($_POST['reason'] ?? '');
  if ($cid>0 && empty($errors)){
    try{
      $stmt=$pdo->prepare('UPDATE campaign_companies SET removed=1, removed_reason=:r, removed_at=NOW(), removed_by=:u WHERE campaign_id=:ca AND company_id=:co');
      $stmt->execute([':r'=>$reason ?: null, ':u'=>($_SESSION['user']['id'] ?? null), ':ca'=>$campId, ':co'=>$cid]);
      flash('success','Firma aus Kampagne entfernt. Historie bleibt erhalten.');
      header('Location: /campaign_view.php?id='.$campId); exit;
    }catch(Throwable $e){ $errors[]='Konnte nicht entfernen: '.$e->getMessage(); }
  }
}

// Filter / Sort / Paging
$showRemoved = isset($_GET['show_removed']);
$sort = $_GET['sort'] ?? 'name';
$dir = strtolower($_GET['dir'] ?? 'asc'); $dir = $dir==='desc' ? 'DESC' : 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(100, max(10, (int)($_GET['per'] ?? 25)));

$where = ['cc.campaign_id=:id']; $params=[':id'=>$campId];
if(!$showRemoved){ $where[] = 'COALESCE(cc.removed,0)=0'; }

$sortMap=['name'=>'co.name','city'=>'co.city','last'=>'last_call_at','status'=>'last_status'];
$orderBy = ($sortMap[$sort] ?? 'co.name').' '.$dir.', co.id ASC';

// Count
$base = ' FROM campaign_companies cc JOIN companies co ON co.id=cc.company_id';
if ($where) $base .= ' WHERE '.implode(' AND ',$where);
$total=0; $st=$pdo->prepare('SELECT COUNT(*)'.$base); $st->execute($params); $total=(int)$st->fetchColumn();
$pages = max(1,(int)ceil($total/$per)); $page=min($page,$pages); $offset=($page-1)*$per;

// Firmen + letzte Call-Infos je Firma innerhalb der Kampagne
$sql = 'SELECT co.id, co.name, co.city, co.zip, co.email_general, co.phone_general, cc.removed, cc.removed_reason, cc.added_at,
  (SELECT MAX(created_at) FROM calls c WHERE c.campaign_id=cc.campaign_id AND c.company_id=co.id) AS last_call_at,
  (SELECT c2.status FROM calls c2 WHERE c2.campaign_id=cc.campaign_id AND c2.company_id=co.id ORDER BY c2.created_at DESC LIMIT 1) AS last_status,
  (SELECT c2.sentiment FROM calls c2 WHERE c2.campaign_id=cc.campaign_id AND c2.company_id=co.id ORDER BY c2.created_at DESC LIMIT 1) AS last_sentiment,
  (SELECT COUNT(*) FROM calls c3 WHERE c3.campaign_id=cc.campaign_id AND c3.company_id=co.id) AS calls_count
  '.$base.' ORDER BY '.$orderBy.' LIMIT :lim OFFSET :off';
$rows=[]; $st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':lim',$per,PDO::PARAM_INT); $st->bindValue(':off',$offset,PDO::PARAM_INT);
$st->execute(); $rows=$st->fetchAll();

function badgeForStatus($s){
  switch($s){
    case 'callback': return '<span class="badge bg-warning text-dark">Rückruf</span>';
    case 'do_not_call': return '<span class="badge bg-danger">Nicht mehr anrufen</span>';
    case 'ok': return '<span class="badge bg-success">OK</span>';
    default: return '<span class="badge bg-secondary">—</span>';
  }
}
function sort_link($key,$label){
  $curSort=$_GET['sort']??'name'; $curDir=strtolower($_GET['dir']??'asc'); $next=($curSort===$key && $curDir==='asc')?'desc':'asc';
  $qs=$_GET; $qs['sort']=$key; $qs['dir']=$next; $url='/campaign_view.php?'.http_build_query($qs);
  $ind=$curSort===$key?($curDir==='asc'?'▲':'▼'):'';
  return '<a class="link-underline link-underline-opacity-0" href="'.e($url).'">'.e($label).' '.e($ind).'</a>';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kampagne #<?= (int)$camp['id'] ?> · <?= e($camp['name']) ?> · Aquise</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.table thead th{white-space:nowrap}</style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h5 mb-1">Kampagne: <?= e($camp['name']) ?></h1>
      <div class="text-muted">Status: <span class="badge bg-secondary"><?= e($camp['status']) ?></span> · Erstellt: <?= e($camp['created_at']) ?></div>
      <?php if (!empty($camp['description'])): ?><div class="small mt-1"><?= nl2br(e($camp['description'])) ?></div><?php endif; ?>
    </div>
    <div>
      <a class="btn btn-outline-secondary btn-sm" href="/public/campaigns.php">← Zur Übersicht</a>
      <a class="btn btn-outline-secondary btn-sm" href="/public/script_builder.php?campaign_id=<?= $campId?>">Scriptbuilder</a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <form class="row g-2 align-items-end mb-3" method="get">
        <input type="hidden" name="id" value="<?= (int)$campId ?>">
        <div class="col-12 col-md-3">
          <label class="form-label">Sortierung</label>
          <select class="form-select" name="sort">
            <option value="name" <?= ($sort==='name')?'selected':'' ?>>Firma</option>
            <option value="city" <?= ($sort==='city')?'selected':'' ?>>Stadt</option>
            <option value="last" <?= ($sort==='last')?'selected':'' ?>>Letzter Anruf</option>
            <option value="status" <?= ($sort==='status')?'selected':'' ?>>Status</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Richtung</label>
          <select class="form-select" name="dir">
            <option value="asc" <?= ($dir==='ASC')?'selected':'' ?>>Aufsteigend</option>
            <option value="desc" <?= ($dir==='DESC')?'selected':'' ?>>Absteigend</option>
          </select>
        </div>
        <div class="col-6 col-md-2 form-check mt-4">
          <input class="form-check-input" type="checkbox" id="show_removed" name="show_removed" value="1" <?= $showRemoved?'checked':'' ?>>
          <label class="form-check-label" for="show_removed">Entfernte Firmen anzeigen</label>
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label">Pro Seite</label>
          <select name="per" class="form-select">
            <?php foreach([25,50,100] as $opt): ?>
              <option value="<?= $opt ?>" <?= $per==$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <button class="btn btn-primary w-100" type="submit">Anwenden</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead>
            <tr>
              <th><?= sort_link('name','Firma') ?></th>
              <th>Stadt</th>
              <th>Kontakt</th>
              <th>Anrufe</th>
              <th>Letzter Anruf</th>
              <th>Status</th>
              <th>Notiz/Kommentar</th>
              <th style="width:1%"></th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($rows)): ?>
              <tr><td colspan="8" class="text-muted">Keine Firmen zugeordnet.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <?php
                // kurze Historie laden (letzten 3 Calls)
                $hist=[]; $hSt=$pdo->prepare('SELECT 
                                              c.id, 
                                              c.status, 
                                              c.sentiment, 
                                              c.callback_at, 
                                              c.created_at, 
                                              u.full_name AS agent 
                                              FROM calls c 
                                              LEFT JOIN 
                                              users u ON u.id=c.agent_id 
                                              WHERE c.campaign_id=:ca AND c.company_id=:co 
                                              ORDER BY c.created_at DESC LIMIT 3');
                $hSt->execute([':ca'=>$campId, ':co'=>$r['id']]);
                $hist=$hSt->fetchAll();
                $statusBadge = badgeForStatus($r['last_status']);
              ?>
              <tr class="<?= $r['removed']? 'table-secondary' : '' ?>">
                <td class="fw-semibold">
                  <?= e($r['name']) ?>
                  <?php if($r['removed']): ?><div class="small text-muted">entfernt<?= $r['removed_reason']? ': '.e($r['removed_reason']) : '' ?></div><?php endif; ?>
                </td>
                <td><?= e($r['city'] ?: '—') ?></td>
                <td>
                  <?php if(!empty($r['email_general'])): ?><a href="mailto:<?= e($r['email_general']) ?>"><?= e($r['email_general']) ?></a><br><?php endif; ?>
                  <?= e($r['phone_general'] ?: '—') ?>
                </td>
                <td><?= (int)$r['calls_count'] ?></td>
                <td><?= e($r['last_call_at'] ?: '—') ?></td>
                <td><?= $statusBadge ?></td>
                <td>
                  <?php if(empty($hist)): ?>—
                  <?php else: ?>
                    <div class="small text-muted">
                      <?php foreach($hist as $h): ?>
                        <div>• <?= e($h['created_at']) ?> – <?= e($h['agent'] ?: 'Agent') ?>: <?= e($h['sentiment'] ?: '-') ?> <?= $h['status']? '('.e($h['status']).')':'' ?> <?= $h['callback_at']? '↩ '.e($h['callback_at']) : '' ?></div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <?php if(!$r['removed']): ?>
                      <a class="btn btn-outline-primary" href="/public/call_add.php?company_id=<?= (int)$r['id'] ?>&campaign_id=<?= (int)$campId ?>">📞</a>
                      <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rm<?= (int)$r['id'] ?>">✖</button>
                    <?php else: ?>
                      <span class="btn btn-outline-secondary disabled">—</span>
                    <?php endif; ?>
                  </div>

                  <!-- Modal Entfernen -->
                  <div class="modal fade" id="rm<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                      <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Firma aus Kampagne entfernen</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <form method="post">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="remove_company">
                          <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                          <div class="modal-body">
                            <p>„<?= e($r['name']) ?>“ aus der Kampagne entfernen? Die Anrufhistorie bleibt erhalten.</p>
                            <label class="form-label">Vermerk/Grund (optional)</label>
                            <input class="form-control" name="reason" placeholder="z. B. wünscht keinen Kontakt, bereits Kunde, falscher Ansprechpartner, …">
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button type="submit" class="btn btn-danger">Entfernen</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <nav aria-label="Seiten">
        <ul class="pagination pagination-sm justify-content-center">
          <?php $qs=$_GET; for($p=1;$p<=$pages;$p++): $qs['page']=$p; ?>
            <li class="page-item <?= $p===$page?'active':'' ?>">
              <a class="page-link" href="/campaign_view.php?<?= e(http_build_query($qs)) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>

    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
