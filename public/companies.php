<?php
// public/companies.php – Firmenliste mit dynamischer Spaltenauswahl & erweiterter Sortierung
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

/**
 * Spalten-Metadaten: Label, DB-Feld (für Sortierung), Render (optional), Klassen, sortierbar?
 * Reihenfolge hier ist die Reihenfolge im Spaltenpicker; Anzeige-Reihenfolge = Reihenfolge der sichtbaren Keys.
 */
$columns = [
  'id' => [
    'label' => 'ID',
    'db' => 'co.id',
    'sortable' => true,
    'td_class' => 'text-muted',
    'render' => function($r){ return (int)$r['id']; }
  ],
  'name' => [
    'label' => 'Firma',
    'db' => 'co.name',
    'sortable' => true,
    'td_class' => 'company-cell',
    'render' => function($r){
      $html  = '<div class="fw-semibold">'.e($r['name'] ?: '—').'</div>';
      $html .= '<div class="actions"><a href="/public/company_edit.php?id='.(int)$r['id'].'" class="link-secondary link-underline-opacity-0">Bearbeiten</a></div>';
      return $html;
    }
  ],
  'street' => [ 'label'=>'Straße', 'db'=>'co.street', 'sortable'=>true ],
  'zip'    => [ 'label'=>'PLZ', 'db'=>'co.zip', 'sortable'=>true ],
  'city'   => [ 'label'=>'Stadt', 'db'=>'co.city', 'sortable'=>true ],
  'state'  => [ 'label'=>'Bundesland', 'db'=>'co.state', 'sortable'=>true ],
  'industry' => [ 'label'=>'Branche', 'db'=>'co.industry', 'sortable'=>true ],
  'business_purpose' => [
    'label'=>'Unternehmenszweck',
    'db'=>'co.business_purpose',
    'sortable'=>true,
    'td_class'=>'td-wide'
  ],
  'legal_form' => [ 'label'=>'Rechtsform', 'db'=>'co.legal_form', 'sortable'=>true ],
  'register_court'  => [ 'label'=>'Registergericht', 'db'=>'co.register_court', 'sortable'=>true ],
  'register_number' => [ 'label'=>'Registernummer', 'db'=>'co.register_number', 'sortable'=>true ],
  'employees' => [
    'label'=>'Mitarbeiter',
    'db'=>'co.employees',
    'sortable'=>true,
    'td_class'=>'text-end',
    'render'=>function($r){
      if ($r['employees'] === null || $r['employees'] === '') return '—';
      return number_format((int)$r['employees'], 0, ',', '.');
    }
  ],
  'revenue' => [
    'label'=>'Umsatz',
    'db'=>'co.revenue',
    'sortable'=>true,
    'td_class'=>'text-end',
    'render'=>function($r){
      if ($r['revenue'] === null || $r['revenue'] === '') return '—';
      $val = (float)$r['revenue'];
      return number_format($val, 2, ',', '.').' €';
    }
  ],
  'size_class' => [ 'label'=>'Größenklasse', 'db'=>'co.size_class', 'sortable'=>true ],
//  'external_id' => [ 'label'=>'Externe ID', 'db'=>'co.external_id', 'sortable'=>true ],
  'website' => [
    'label'=>'Webseite',
    'db'=>'co.website',
    'sortable'=>true,
    'td_class'=>'td-narrow',
    'render'=>function($r){
      if (!empty($r['website'])) return '<a href="'.e($r['website']).'" target="_blank" rel="noopener noreferrer">'.e($r['website']).'</a>';
      return '—';
    }
  ],
  'email_general' => [
    'label'=>'E‑Mail (allg.)',
    'db'=>'co.email_general',
    'sortable'=>true,
    'td_class'=>'td-narrow',
    'render'=>function($r){
      if (!empty($r['email_general'])) return '<a href="mailto:'.e($r['email_general']).'">'.e($r['email_general']).'</a>';
      return '—';
    }
  ],
  'phone_general' => [ 'label'=>'Telefon (allg.)', 'db'=>'co.phone_general', 'sortable'=>true ],
  'created_at' => [
    'label'=>'Erstellt',
    'db'=>'co.created_at',
    'sortable'=>true,
    'td_class'=>'text-nowrap',
    'render'=>function($r){
      if (empty($r['created_at'])) return '—';
      return date('d.m.Y H:i', strtotime($r['created_at']));
    }
  ],
  'updated_at' => [
    'label'=>'Aktualisiert',
    'db'=>'co.updated_at',
    'sortable'=>true,
    'td_class'=>'text-nowrap',
    'render'=>function($r){
      if (empty($r['updated_at'])) return '—';
      return date('d.m.Y H:i', strtotime($r['updated_at']));
    }
  ],
  'campaigns' => [
    'label'=>'Kampagnen',
    'db'=>null,          // computed
    'sortable'=>false
  ],
];

/** Sort-Map dynamisch aus den Spalten erstellen */
$sortMap = [];
foreach ($columns as $key=>$meta) {
  if (!empty($meta['sortable']) && !empty($meta['db'])) {
    $sortMap[$key] = $meta['db'];
  }
}
$allowedSort = array_keys($sortMap);

/** Standard-Spalten (für „Standard“-Button im Picker) – nach Bedarf anpassen */
$standardCols = ['name','street','zip','city','state','industry','website','email_general','phone_general','campaigns'];

/** Sichtbare Spalten aus GET oder Defaults (Default: ALLE Spalten) */
$visibleCols = array_values(array_unique(array_filter((array)($_GET['cols'] ?? []))));
if (empty($visibleCols)) {
  $defaultVisibleCols = array_keys($columns); // ALLE
  $visibleCols = $defaultVisibleCols;
}
/** „name“ niemals ausblendbar (Sicherheit) */
if (!in_array('name', $visibleCols, true)) { array_unshift($visibleCols, 'name'); }

/** --- Optionen / Filter / Paging / Sortierung --- */
$sort = $_GET['sort'] ?? 'name'; if (!in_array($sort, $allowedSort, true)) $sort='name';
$dir  = strtolower($_GET['dir'] ?? 'asc'); $dir = $dir==='desc'?'DESC':'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(1000, max(10, (int)($_GET['per'] ?? 250)));

$q = trim($_GET['q'] ?? '');
$city = trim($_GET['city'] ?? '');
$has_email = isset($_GET['has_email']) ? 1 : 0;
$has_phone = isset($_GET['has_phone']) ? 1 : 0;
$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0; // für Filter
$filter_in_campaign = ($_GET['in_campaign'] ?? '') === '1';

/** --- Bulk-Aktion: ausgewählte Firmen einer Kampagne zuordnen --- */
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

/** --- Kampagnenliste für Filter/Dropdown --- */
$campaigns = [];
try { $campaigns = $pdo->query("SELECT id, name, status FROM campaigns ORDER BY name ASC")->fetchAll(); } catch(Throwable $e){}

/** --- Query bauen --- */
$where=[]; $params=[];

if ($q !== '') {
  // Jede Spalte bekommt einen eigenen Platzhalter (:q0, :q1, ...)
  $searchCols = [
    'co.name','co.city','co.zip','co.website','co.email_general','co.industry','co.state','co.street',
    'co.legal_form','co.register_court','co.register_number','co.phone_general','co.size_class',
    'co.external_id','co.business_purpose'
  ];
  $parts = [];
  foreach ($searchCols as $i => $col) {
    $ph = ":q{$i}";
    $parts[] = "$col LIKE $ph";
    $params[$ph] = "%{$q}%";
  }
  $where[] = '(' . implode(' OR ', $parts) . ')';
}

if ($city !== '') {
  $where[] = "co.city = :city";
  $params[':city'] = $city;
}
if ($has_email) {
  $where[] = "co.email_general IS NOT NULL AND co.email_general <> ''";
}
if ($has_phone) {
  $where[] = "co.phone_general IS NOT NULL AND co.phone_general <> ''";
}
if ($filter_in_campaign && $campaign_id > 0) {
  $where[] = "EXISTS (SELECT 1 FROM campaign_companies cc WHERE cc.company_id=co.id AND cc.campaign_id=:cid)";
  $params[':cid'] = $campaign_id;
}

if ($city!==''){ $where[] = "co.city = :city"; $params[':city'] = $city; }
if ($has_email){ $where[] = "co.email_general IS NOT NULL AND co.email_general <> ''"; }
if ($has_phone){ $where[] = "co.phone_general IS NOT NULL AND co.phone_general <> ''"; }
if ($filter_in_campaign && $campaign_id>0){ $where[] = "EXISTS (SELECT 1 FROM campaign_companies cc WHERE cc.company_id=co.id AND cc.campaign_id=:cid)"; $params[':cid']=$campaign_id; }

$orderBy = ($sortMap[$sort] ?? 'co.name') . ' ' . $dir . ', co.id ASC';

$baseSQL = "FROM companies co";
if ($where) $baseSQL .= ' WHERE '.implode(' AND ',$where);

// Count total
$total = 0; try { $stmt=$pdo->prepare('SELECT COUNT(*) '.$baseSQL); 
  foreach($params as $k=>$v){ $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $stmt->execute(); $total=(int)$stmt->fetchColumn(); 
} catch(Throwable $e){}
$pages = max(1, (int)ceil($total/$per)); $page = min($page,$pages); $offset = ($page-1)*$per;

// Rows inkl. Kampagnennamen
$rows=[]; try {
  // Alle benötigten Felder selektieren (nicht nur die sichtbaren, um Umschalten ohne Reload der Daten zu ermöglichen)
  $sql = 'SELECT
            co.id, co.name, co.street, co.zip, co.city, co.state, co.industry,
            co.business_purpose, co.legal_form, co.register_court, co.register_number,
            co.employees, co.revenue, co.size_class, co.external_id,
            co.website, co.email_general, co.phone_general, co.created_at, co.updated_at,
            (SELECT GROUP_CONCAT(ca.name SEPARATOR ", ")
               FROM campaign_companies cc
               JOIN campaigns ca ON ca.id=cc.campaign_id
              WHERE cc.company_id=co.id) AS campaigns
          '.$baseSQL.' ORDER BY '.$orderBy.' LIMIT :lim OFFSET :off';
  $stmt=$pdo->prepare($sql);
  foreach($params as $k=>$v){ $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
  $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute(); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $err=$e->getMessage(); }

// Helper: Sortierlink
function sort_link($key,$label){
  global $sortMap;
  $curSort = $_GET['sort'] ?? 'name';
  $curDir  = strtolower($_GET['dir'] ?? 'asc');
  $nextDir = ($curSort===$key && $curDir==='asc') ? 'desc' : 'asc';
  if (!isset($sortMap[$key])) {
    return e($label); // nicht sortierbar
  }
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
    /* Headline der Tabelle */
    .table thead th {
      position: sticky;
      top: 0;                 /* statt 56px */
      z-index: 2;
      background: #f8f9fa;
      white-space: nowrap;
    }
/* Neu hinzufügen */
th.col-id, td.col-id { width: 90px; }  /* passe 90px nach Bedarf an */

/* Optional: vertikales Scrollen im Tabellencontainer, damit sticky „oben“ Sinn hat */
.table-responsive {
  max-height: 75vh;
  overflow-y: auto;
}

    .table td { white-space: normal; word-break: break-word; }
    .td-narrow { max-width: 240px; }
    .td-wide { max-width: 480px; }
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
          <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Firma, Stadt, PLZ, Website, E-Mail, Branche, Straße, Bundesland, Rechtsform, Register…">
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
                <?php foreach($campaigns as $c): $cid=(int)$c['id']; ?>
                  <option value="<?= $cid ?>" <?= ((int)$campaign_id===$cid)?'selected':'' ?>>#<?= $cid ?> · <?= e($c['name']) ?> (<?= e($c['status']) ?>)</option>
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

        <!-- Spaltenauswahl (ausklappbar) -->
        <div class="col-12 mt-3">
          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#colPicker" aria-expanded="false" aria-controls="colPicker">
            Spalten auswählen
          </button>
        </div>
        <div class="collapse mt-2" id="colPicker">
          <div class="border rounded p-3 bg-light">
            <div class="d-flex flex-wrap gap-3">
              <?php foreach($columns as $key=>$meta): ?>
                <div class="form-check">
                  <input class="form-check-input colbox" type="checkbox" id="col_<?= e($key) ?>" name="cols[]" value="<?= e($key) ?>" <?= in_array($key,$visibleCols,true)?'checked':'' ?>>
                  <label class="form-check-label" for="col_<?= e($key) ?>"><?= e($meta['label']) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-3 d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="colsSelectAll(true)">Alle</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="colsSelectAll(false)">Keine</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="colsSelectDefault()">Standard</button>
            </div>
            <div class="form-text mt-1">„Firma“ bleibt aus Gründen der Bedienbarkeit immer sichtbar.</div>
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
            <?php foreach($campaigns as $c): $cid=(int)$c['id']; ?>
              <option value="<?= $cid ?>" <?= ((int)$campaign_id===$cid)?'selected':''; ?>>#<?= $cid ?> · <?= e($c['name']) ?></option>
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
              <?php foreach($visibleCols as $key): 
                $meta = $columns[$key] ?? null; if (!$meta) continue; ?>
                <th class="<?= e($meta['th_class'] ?? '') ?>">
                  <?= !empty($meta['sortable']) && !empty($meta['db']) ? sort_link($key, $meta['label']) : e($meta['label']) ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($rows)): ?>
              <tr><td colspan="<?= 1 + count($visibleCols) ?>" class="text-muted">Keine Firmen gefunden.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><input class="form-check-input rowcheck" type="checkbox" name="selected[]" value="<?= (int)$r['id'] ?>"></td>
                <?php foreach($visibleCols as $key):
                  $meta = $columns[$key] ?? null; if (!$meta) continue;
                  $tdClass = $meta['td_class'] ?? ''; ?>
                  <td class="<?= e($tdClass) ?>">
                    <?php
                      if (isset($meta['render']) && is_callable($meta['render'])) {
                        echo $meta['render']($r);
                      } else {
                        $val = $r[$key] ?? null;
                        echo e($val!==null && $val!=='' ? $val : '—');
                      }
                    ?>
                  </td>
                <?php endforeach; ?>
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

// Spaltenpicker-Utils
const defaultCols = <?= json_encode(array_values($standardCols)) ?>;
function colsSelectAll(all){
  document.querySelectorAll('#colPicker .colbox').forEach(cb => cb.checked = !!all);
  // Name nie deaktivieren:
  const nameBox = document.getElementById('col_name');
  if (nameBox) nameBox.checked = true;
}
function colsSelectDefault(){
  document.querySelectorAll('#colPicker .colbox').forEach(cb => cb.checked = false);
  defaultCols.forEach(k => {
    const el = document.getElementById('col_' + k);
    if (el) el.checked = true;
  });
  const nameBox = document.getElementById('col_name');
  if (nameBox) nameBox.checked = true;
}
</script>
</body>
</html>
