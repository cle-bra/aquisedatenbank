<?php
// public/public/contacts.php – Kontaktliste mit Sortierung, Filtern, Bearbeiten-Link und (optional) Bulk-Zuordnung zu Kampagne
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($key,$msg=null){ if($msg!==null){$_SESSION['flash'][$key]=$msg;return;} $m=$_SESSION['flash'][$key]??null; unset($_SESSION['flash'][$key]); return $m; }

// ---- Optionen ----
$allowedSort = ['last_name','first_name','company','city','email_personal'];
$sort = $_GET['sort'] ?? 'last_name'; if (!in_array($sort,$allowedSort,true)) $sort='last_name';
$dir  = strtolower($_GET['dir'] ?? 'asc'); $dir = $dir==='desc'?'DESC':'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(100, max(10, (int)($_GET['per'] ?? 25)));

$q = trim($_GET['q'] ?? '');
$city = trim($_GET['city'] ?? '');
$has_email = isset($_GET['has_email']) ? 1 : 0;
$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0; // für Filter/Zuordnung
$filter_in_campaign = ($_GET['in_campaign'] ?? '') === '1';

// ---- Bulk-Aktion: ausgewählte Kontakte einer Kampagne zuordnen ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='bulk_add_to_campaign'){
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('error','Ungültiges Formular (CSRF).'); header('Location: /public/contacts.php'); exit; }
  $targetCampaign = (int)($_POST['target_campaign_id'] ?? 0);
  $ids = array_filter(array_map('intval', $_POST['selected'] ?? []));
  if ($targetCampaign<=0 || empty($ids)) { flash('error','Bitte Kampagne und mindestens einen Kontakt wählen.'); header('Location: /public/contacts.php'); exit; }
  $ok=0; $fail=0;
  try {
    // Prüfen ob Mapping-Tabelle existiert
    $pdo->query("SELECT 1 FROM campaign_contacts LIMIT 1");
    $stmt = $pdo->prepare('INSERT IGNORE INTO campaign_contacts (campaign_id, contact_id, added_by, added_at) VALUES (:c,:k,:u,NOW())');
    foreach ($ids as $idc){
      try { $stmt->execute([':c'=>$targetCampaign, ':k'=>$idc, ':u'=>$_SESSION['user']['id'] ?? null]); $ok += $stmt->rowCount()>0 ? 1 : 0; }
      catch(Throwable $e){ $fail++; }
    }
    flash('success', "Zuordnung abgeschlossen: {$ok} hinzugefügt, {$fail} übersprungen.");
  } catch (Throwable $e) {
    flash('error', 'Zuordnung nicht möglich: Tabelle campaign_contacts fehlt. Bitte Mapping-Tabelle anlegen.');
  }
  header('Location: /public/contacts.php?'.http_build_query($_GET)); exit;
}

// ---- Kampagnenliste für Filter/Dropdown ----
$campaigns = [];
try { $campaigns = $pdo->query("SELECT id, name, status FROM campaigns ORDER BY name ASC")->fetchAll(); } catch(Throwable $e){}

// ---- Query bauen ----
$where=[]; $params=[];
if ($q!==''){ $where[] = "(c.first_name LIKE :q OR c.last_name LIKE :q OR co.name LIKE :q OR co.city LIKE :q OR co.zip LIKE :q)"; $params[':q'] = "%$q%"; }
if ($city!==''){ $where[] = "co.city = :city"; $params[':city'] = $city; }
if ($has_email){ $where[] = "c.email_personal IS NOT NULL AND c.email_personal <> ''"; }
if ($filter_in_campaign && $campaign_id>0){ $where[] = "EXISTS (SELECT 1 FROM campaign_contacts cc WHERE cc.contact_id=c.id AND cc.campaign_id=:cid)"; $params[':cid']=$campaign_id; }

$sortMap = [
  'last_name' => 'c.last_name',
  'first_name'=> 'c.first_name',
  'company'   => 'co.name',
  'city'      => 'co.city',
  'email_personal' => 'c.email_personal',
];
$orderBy = $sortMap[$sort] . ' ' . $dir . ', c.id ASC';

$baseSQL = "FROM contacts c LEFT JOIN companies co ON co.id=c.company_id";
if ($where) $baseSQL .= ' WHERE '.implode(' AND ',$where);

// Count total
$total = 0; try { $stmt=$pdo->prepare('SELECT COUNT(*) '.$baseSQL); $stmt->execute($params); $total=(int)$stmt->fetchColumn(); } catch(Throwable $e){}
$pages = max(1, (int)ceil($total/$per)); $page = min($page,$pages); $offset = ($page-1)*$per;

// Fetch rows
$rows=[]; try { $stmt=$pdo->prepare('SELECT 
                                        c.id, 
                                        c.salutation, 
                                        c.first_name, 
                                        c.last_name, 
                                        c.position, 
                                        c.email_personal, 
                                        c.phone_direct, 
                                        c.phone_ext, 
                                        co.name AS company, 
                                        co.city, 
                                        co.zip '
                                        .$baseSQL.' 
                                        ORDER BY '
                                        .$orderBy.' 
                                        LIMIT :lim OFFSET :off');
  foreach($params as $k=>$v){ $stmt->bindValue($k,$v, PDO::PARAM_STR); }
  $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute(); $rows=$stmt->fetchAll();
} catch(Throwable $e){}

function sort_link($key,$label){
  $curSort = $_GET['sort'] ?? 'last_name';
  $curDir  = strtolower($_GET['dir'] ?? 'asc');
  $nextDir = ($curSort===$key && $curDir==='asc') ? 'desc' : 'asc';
  $qs = $_GET; $qs['sort']=$key; $qs['dir']=$nextDir; $url='/public/contacts.php?'.http_build_query($qs);
  $indicator = $curSort===$key ? ($curDir==='asc'?'▲':'▼') : '';
  return '<a href="'.e($url).'" class="link-underline link-underline-opacity-0">'.e($label).' '.e($indicator).'</a>';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kontakte · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.table thead th { white-space: nowrap; }</style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if($m=flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-md-4">
          <label class="form-label">Suche</label>
          <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Name, Firma, Stadt, PLZ">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Stadt</label>
          <input type="text" name="city" class="form-control" value="<?= e($city) ?>">
        </div>
        <div class="col-6 col-md-3 form-check mt-4">
          <input class="form-check-input" type="checkbox" id="has_email" name="has_email" value="1" <?= $has_email? 'checked':'' ?>>
          <label class="form-check-label" for="has_email">Nur mit E‑Mail</label>
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label">Pro Seite</label>
          <select name="per" class="form-select">
            <?php foreach([25,50,100] as $opt): ?>
              <option value="<?= $opt ?>" <?= $per==$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-4">
              <label class="form-label">Kampagne (Filter/Zuordnung)</label>
              <select name="campaign_id" class="form-select">
                <option value="0">— keine Auswahl —</option>
                <?php foreach($campaigns as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $campaign_id===$c['id']?'selected':'' ?>>#<?= (int)$c['id'] ?> · <?= e($c['name']) ?> (<?= e($c['status']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2 form-check mt-4">
              <input class="form-check-input" type="checkbox" id="in_campaign" name="in_campaign" value="1" <?= $filter_in_campaign?'checked':'' ?>>
              <label class="form-check-label" for="in_campaign">Nur in Kampagne</label>
            </div>
            <div class="col-12 col-md-3 col-lg-2">
              <button class="btn btn-primary w-100" type="submit">Filtern</button>
            </div>
            <div class="col-12 col-md-3 col-lg-2">
              <a class="btn btn-outline-secondary w-100" href="/public/contacts.php">Zurücksetzen</a>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <form method="post" class="card shadow-sm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk_add_to_campaign">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center gap-2">
          <select name="target_campaign_id" class="form-select form-select-sm" style="width:auto">
            <option value="0">Zu Kampagne hinzufügen…</option>
            <?php foreach($campaigns as $c): ?>
              <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> · <?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Auswahl zuordnen</button>
        </div>
        <div class="small text-muted">Gefunden: <?= (int)$total ?> · Seite <?= (int)$page ?>/<?= (int)$pages ?></div>
      </div>

      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead>
            <tr>
              <th style="width:1%"><input class="form-check-input" type="checkbox" id="checkall" onclick="document.querySelectorAll('.rowcheck').forEach(cb=>cb.checked=this.checked)"></th>
              <th><?= sort_link('last_name','Nachname') ?></th>
              <th><?= sort_link('first_name','Vorname') ?></th>
              <th><?= sort_link('company','Firma') ?></th>
              <th>Position</th>
              <th><?= sort_link('city','Stadt') ?></th>
              <th><?= sort_link('email_personal','E‑Mail') ?></th>
              <th>Telefon</th>
              <th style="width:1%"></th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($rows)): ?>
              <tr><td colspan="9" class="text-muted">Keine Kontakte gefunden.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><input class="form-check-input rowcheck" type="checkbox" name="selected[]" value="<?= (int)$r['id'] ?>"></td>
                <td><?= e($r['last_name'] ?: '—') ?></td>
                <td><?= e($r['first_name'] ?: '—') ?></td>
                <td><?= e($r['company'] ?: '—') ?></td>
                <td><?= e($r['position'] ?: '—') ?></td>
                <td><?= e($r['city'] ?: '—') ?></td>
                <td><a href="mailto:<?= e($r['email_personal']) ?>"><?= e($r['email_personal'] ?: '—') ?></a></td>
                <td><?= e($r['phone_direct'] ?: ($r['phone_ext'] ?: '—')) ?></td>
                <td class="text-end"><a href="/public/contact_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">Bearbeiten</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <nav aria-label="Seiten">
        <ul class="pagination pagination-sm justify-content-center">
          <?php $qs=$_GET; for($p=1;$p<=$pages;$p++): $qs['page']=$p; ?>
            <li class="page-item <?= $p===$page?'active':'' ?>">
              <a class="page-link" href="/public/contacts.php?<?= e(http_build_query($qs)) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </form>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
