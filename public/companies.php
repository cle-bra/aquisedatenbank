<?php
// public/companies.php – Firmenliste: Bulk-Zuordnung via Checkboxen, dezenter Bearbeiten-Link, ohne Row-Buttons
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

// ---- Optionen ----
$allowedSort = ['name','street','city','zip','state','industry','website','email_general','phone_general','created_at','updated_at'];
$sort = $_GET['sort'] ?? 'name'; if (!in_array($sort,$allowedSort,true)) $sort='name';
$dir  = strtolower($_GET['dir'] ?? 'asc'); $dir = $dir==='desc'?'DESC':'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(1000, max(10, (int)($_GET['per'] ?? 250)));

$q = trim($_GET['q'] ?? '');
$city = trim($_GET['city'] ?? '');
$has_email = isset($_GET['has_email']) ? 1 : 0;
$has_phone = isset($_GET['has_phone']) ? 1 : 0;
$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0; // für Filter
$filter_in_campaign = ($_GET['in_campaign'] ?? '') === '1';

// ---- Bulk-Aktion: ausgewählte Firmen einer Kampagne zuordnen ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='bulk_add_companies_to_campaign'){
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('error','Ungültiges Formular (CSRF).'); header('Location: /public/companies.php'); exit; }
  $targetCampaign = (int)($_POST['target_campaign_id'] ?? 0);
  $ids = array_filter(array_map('intval', $_POST['selected'] ?? []));
  if ($targetCampaign<=0 || empty($ids)) {
    flash('error','Bitte Kampagne und mindestens eine Firma wählen.');
    header('Location: /public/companies.php?'.http_build_query($_GET)); exit;
  }
  $ok=0; $fail=0;
  try {
    $pdo->query("SELECT 1 FROM campaign_companies LIMIT 1");
    $stmt = $pdo->prepare('INSERT IGNORE INTO campaign_companies (campaign_id, company_id, added_by, added_at) VALUES (:c,:k,:u,NOW())');
    foreach ($ids as $idc){
      try { $stmt->execute([':c'=>$targetCampaign, ':k'=>$idc, ':u'=>$_SESSION['user']['id'] ?? null]); $ok += $stmt->rowCount()>0 ? 1 : 0; }
      catch(Throwable $e){ $fail++; }
    }
    flash('success', "Zuordnung abgeschlossen: {$ok} hinzugefügt, {$fail} übersprungen.");
  } catch (Throwable $e) {
    flash('error', 'Zuordnung nicht möglich: Tabelle campaign_companies fehlt. Bitte Mapping-Tabelle anlegen.');
  }
  header('Location: /public/companies.php?'.http_build_query($_GET)); exit;
}

// ---- Kampagnenliste für Filter/Dropdown ----
$campaigns = [];
try { $campaigns = $pdo->query("SELECT id, name, status FROM campaigns ORDER BY name ASC")->fetchAll(); } catch(Throwable $e){}

// ---- Query bauen ----
$where=[]; $params=[];
if ($q!==''){
  $where[] = "(co.name LIKE :q OR co.city LIKE :q OR co.zip LIKE :q OR co.website LIKE :q OR co.email_general LIKE :q OR co.industry LIKE :q OR co.state LIKE :q OR co.street LIKE :q)";
  $params[':q'] = "%$q%";
}
if ($city!==''){ $where[] = "co.city = :city"; $params[':city'] = $city; }
if ($has_email){ $where[] = "co.email_general IS NOT NULL AND co.email_general <> ''"; }
if ($has_phone){ $where[] = "co.phone_general IS NOT NULL AND co.phone_general <> ''"; }
if ($filter_in_campaign && $campaign_id>0){ $where[] = "EXISTS (SELECT 1 FROM campaign_companies cc WHERE cc.company_id=co.id AND cc.campaign_id=:cid)"; $params[':cid']=$campaign_id; }

$sortMap = [
  'name' => 'co.name',
  'street' => 'co.street',
  'city' => 'co.city',
  'zip'  => 'co.zip',
  'state'=> 'co.state',
  'industry' => 'co.industry',
  'website' => 'co.website',
  'email_general' => 'co.email_general',
  'phone_general' => 'co.phone_general',
  'created_at' => 'co.created_at',
  'updated_at' => 'co.updated_at',
];
$orderBy = $sortMap[$sort] . ' ' . $dir . ', co.id ASC';

$baseSQL = "FROM companies co";
if ($where) $baseSQL .= ' WHERE '.implode(' AND ',$where);

// Count total
$total = 0; try { $stmt=$pdo->prepare('SELECT COUNT(*) '.$baseSQL); $stmt->execute($params); $total=(int)$stmt->fetchColumn(); } catch(Throwable $e){}
$pages = max(1, (int)ceil($total/$per)); $page = min($page,$pages); $offset = ($page-1)*$per;

// Rows inkl. Kampagnennamen
$rows=[]; try {
  $sql = 'SELECT
            co.id, co.name, co.street, co.zip, co.city, co.state, co.industry,
            co.website, co.email_general, co.phone_general, co.created_at, co.updated_at,
            (SELECT GROUP_CONCAT(ca.name SEPARATOR ", ")
               FROM campaign_companies cc
               JOIN campaigns ca ON ca.id=cc.campaign_id
              WHERE cc.company_id=co.id) AS campaigns
          '.$baseSQL.' ORDER BY '.$orderBy.' LIMIT :lim OFFSET :off';
  $stmt=$pdo->prepare($sql);
  foreach($params as $k=>$v){ $stmt->bindValue($k,$v, PDO::PARAM_STR); }
  $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute(); $rows=$stmt->fetchAll();
} catch(Throwable $e){ $err=$e->getMessage(); }

// Helper: Sortierlink
function sort_link($key,$label){
  $curSort = $_GET['sort'] ?? 'name';
  $curDir  = strtolower($_GET['dir'] ?? 'asc');
  $nextDir = ($curSort===$key && $curDir==='asc') ? 'desc' : 'asc';
  $qs = $_GET; $qs['sort']=$key; $qs['dir']=$nextDir; $url='/public/companies.php?'.http_build_query($qs);
  $indicator = $curSort===$key ? ($curDir==='asc'?'▲':'▼') : '';
  return '<a href="'.e($url).'" class="link-underline link-underline-opacity-0">'.e($label).' '.e($indicator).'</a>';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Firmen · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding-top: 56px; } /* Platz für fixed-top Navbar */
    .table thead th {
      position: sticky; top: 56px; z-index: 1; background: #f8f9fa; white-space: nowrap;
    }
    .table td { white-space: normal; word-break: break-word; }
    .td-narrow { max-width: 240px; }
    .company-cell .actions { font-size:.875rem; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include ('main_menu.php'); ?>

<main class="container-fluid py-4">
  <?php if(isset($err)): ?><div class="alert alert-danger">SQL-Fehler: <?= e($err) ?></div><?php endif; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if($m=flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-md-4">
          <label class="form-label">Suche</label>
          <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Firma, Stadt, PLZ, Website, E-Mail, Branche, Straße, Bundesland">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Stadt</label>
          <input type="text" name="city" class="form-control" value="<?= e($city) ?>">
        </div>
        <div class="col-6 col-md-2 form-check mt-4">
          <input class="form-check-input" type="checkbox" id="has_email" name="has_email" value="1" <?= $has_email? 'checked':'' ?>>
          <label class="form-check-label" for="has_email">Nur mit E-Mail</label>
        </div>
        <div class="col-6 col-md-2 form-check mt-4">
          <input class="form-check-input" type="checkbox" id="has_phone" name="has_phone" value="1" <?= $has_phone? 'checked':'' ?>>
          <label class="form-check-label" for="has_phone">Nur mit Telefon</label>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Pro Seite</label>
          <select name="per" class="form-select">
            <?php foreach([25,50,100,250,500,1000] as $opt): ?>
              <option value="<?= $opt ?>" <?= $per==$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 mt-2">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-4">
              <label class="form-label">Kampagne (Filter/Zuordnung)</label>
              <select name="campaign_id" id="campaign_select" class="form-select">
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
              <a class="btn btn-outline-secondary w-100" href="/public/companies.php">Zurücksetzen</a>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- BULK: eigenes Formular (enthält Checkboxen + Tabelle) -->
  <form method="post" class="card shadow-sm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk_add_companies_to_campaign">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <select name="target_campaign_id" class="form-select form-select-sm" style="width:auto">
            <option value="0">Zu Kampagne hinzufügen…</option>
            <?php foreach($campaigns as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($campaign_id===$c['id'])?'selected':''; ?>>#<?= (int)$c['id'] ?> · <?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary">Auswahl zuordnen</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAll(true)">Alle auf Seite auswählen</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAll(false)">Auswahl aufheben</button>
        </div>
        <div class="small text-muted">Gefunden: <?= (int)$total ?> · Seite <?= (int)$page ?>/<?= (int)$pages ?></div>
      </div>

      <a href="/public/company_add.php" class="btn btn-success mb-3">＋ Neue Firma</a>

      <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:1%"><input class="form-check-input" type="checkbox" id="checkall" onclick="setAll(this.checked)"></th>
              <th><?= sort_link('name','Firma') ?></th>
              <th><?= sort_link('street','Straße') ?></th>
              <th><?= sort_link('zip','PLZ') ?></th>
              <th><?= sort_link('city','Stadt') ?></th>
              <th><?= sort_link('state','Bundesland') ?></th>
              <th><?= sort_link('industry','Branche') ?></th>
              <th class="td-narrow"><?= sort_link('website','Webseite') ?></th>
              <th class="td-narrow"><?= sort_link('email_general','E-Mail (allg.)') ?></th>
              <th><?= sort_link('phone_general','Telefon (allg.)') ?></th>
              <th>Kampagnen</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($rows)): ?>
              <tr><td colspan="11" class="text-muted">Keine Firmen gefunden.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><input class="form-check-input rowcheck" type="checkbox" name="selected[]" value="<?= (int)$r['id'] ?>"></td>
                <td class="company-cell">
                  <div class="fw-semibold"><?= e($r['name'] ?: '—') ?></div>
                  <div class="actions">
                    <a href="/public/company_edit.php?id=<?= (int)$r['id'] ?>" class="link-secondary link-underline-opacity-0">Bearbeiten</a>
                  </div>
                </td>
                <td><?= e($r['street'] ?: '—') ?></td>
                <td><?= e($r['zip'] ?: '—') ?></td>
                <td><?= e($r['city'] ?: '—') ?></td>
                <td><?= e($r['state'] ?: '—') ?></td>
                <td><?= e($r['industry'] ?: '—') ?></td>
                <td class="td-narrow"><?php if(!empty($r['website'])): ?><a href="<?= e($r['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['website']) ?></a><?php else: ?>—<?php endif; ?></td>
                <td class="td-narrow"><?php if(!empty($r['email_general'])): ?><a href="mailto:<?= e($r['email_general']) ?>"><?= e($r['email_general']) ?></a><?php else: ?>—<?php endif; ?></td>
                <td><?= e($r['phone_general'] ?: '—') ?></td>
                <td><?= e($r['campaigns'] ?: '—') ?></td>
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
              <a class="page-link" href="/public/companies.php?<?= e(http_build_query($qs)) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </form>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX-Trainingcenter · Aquise Backend</div></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setAll(checked){
  document.querySelectorAll('.rowcheck').forEach(cb => cb.checked = checked);
  const master = document.getElementById('checkall');
  if (master) master.checked = checked;
}
</script>
</body>
</html>
