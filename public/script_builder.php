<?php
// public/script_builder.php – Minimaler Script-Builder für Dialogbäume
// Aufruf: /public/script_builder.php?campaign_id=123
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$campaignId = (int)($_GET['campaign_id'] ?? 0);
if ($campaignId<=0){ die('campaign_id fehlt.'); }

// Kampagne laden
$campaign = $pdo->prepare("SELECT id,name,status FROM campaigns WHERE id=:id");
$campaign->execute([':id'=>$campaignId]); $campaign = $campaign->fetch();
if(!$campaign){ die('Kampagne nicht gefunden.'); }

// Aktives Script laden (falls vorhanden)
$activeScript = $pdo->prepare("SELECT id,name,root_node_id,status FROM campaign_scripts WHERE campaign_id=:cid AND status='active' LIMIT 1");
$activeScript->execute([':cid'=>$campaignId]); $activeScript = $activeScript->fetch();

// Alle Scripts der Kampagne
$scripts = $pdo->prepare("SELECT id,name,status,root_node_id FROM campaign_scripts WHERE campaign_id=:cid ORDER BY (status='active') DESC, id DESC");
$scripts->execute([':cid'=>$campaignId]); $scripts = $scripts->fetchAll();

// --------- POST: Aktionen ----------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('error','Ungültiges Formular (CSRF).'); header('Location: /public/script_builder.php?campaign_id='.$campaignId); exit; }
  $action = $_POST['action'] ?? '';

  try {
    // Script anlegen
    if ($action==='create_script') {
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name==='') throw new RuntimeException('Name fehlt.');
      $st = $pdo->prepare("INSERT INTO campaign_scripts (campaign_id,name,status) VALUES (:cid,:n,'draft')");
      $st->execute([':cid'=>$campaignId, ':n'=>$name]);
      flash('success','Script angelegt.');
    }

    // Script aktiv setzen
    if ($action==='activate_script') {
      $sid = (int)($_POST['script_id'] ?? 0);
      // erst alle auf draft setzen (archived bleibt archived)
      $pdo->prepare("UPDATE campaign_scripts SET status='draft' WHERE campaign_id=:cid AND status='active'")->execute([':cid'=>$campaignId]);
      $pdo->prepare("UPDATE campaign_scripts SET status='active' WHERE id=:id AND campaign_id=:cid")->execute([':id'=>$sid, ':cid'=>$campaignId]);
      flash('success','Script aktiviert.');
    }

    // Root-Node setzen
    if ($action==='set_root') {
      $sid = (int)($_POST['script_id'] ?? 0);
      $nid = (int)($_POST['node_id'] ?? 0);
      $pdo->prepare("UPDATE campaign_scripts SET root_node_id=:nid WHERE id=:sid AND campaign_id=:cid")->execute([':nid'=>$nid, ':sid'=>$sid, ':cid'=>$campaignId]);
      flash('success','Root-Node gesetzt.');
    }

    // Node anlegen/bearbeiten
    if ($action==='save_node') {
      $sid = (int)($_POST['script_id'] ?? 0);
      $nid = (int)($_POST['node_id'] ?? 0);
      $kind = $_POST['kind'] ?? 'question';
      if (!in_array($kind,['message','question','action'],true)) $kind='question';
      $title = trim((string)($_POST['title'] ?? ''));
      $body  = trim((string)($_POST['body'] ?? ''));
      $is_terminal = isset($_POST['is_terminal']) ? 1 : 0;
      $next_default = (int)($_POST['next_default'] ?? 0);
      $sort_order = (int)($_POST['sort_order'] ?? 0);

      if ($nid>0) {
        $st = $pdo->prepare("UPDATE script_nodes SET kind=:k, title=:t, body=:b, is_terminal=:term, next_default=:nd, sort_order=:so WHERE id=:nid AND script_id=:sid");
        $st->execute([':k'=>$kind, ':t'=>$title, ':b'=>$body, ':term'=>$is_terminal, ':nd'=>($next_default?:null), ':so'=>$sort_order, ':nid'=>$nid, ':sid'=>$sid]);
        flash('success','Node aktualisiert.');
      } else {
        $st = $pdo->prepare("INSERT INTO script_nodes (script_id,kind,title,body,is_terminal,next_default,sort_order) VALUES (:sid,:k,:t,:b,:term,:nd,:so)");
        $st->execute([':sid'=>$sid, ':k'=>$kind, ':t'=>$title, ':b'=>$body, ':term'=>$is_terminal, ':nd'=>($next_default?:null), ':so'=>$sort_order]);
        $nid = (int)$pdo->lastInsertId();
        flash('success','Node angelegt (#'.$nid.').');
      }
    }

    // Node löschen (nur wenn nicht Ziel einer Option o. Root)
    if ($action==='delete_node') {
      $nid = (int)($_POST['node_id'] ?? 0);
      $sid = (int)($_POST['script_id'] ?? 0);
      // Check: Root?
      $isRoot = $pdo->prepare("SELECT COUNT(*) FROM campaign_scripts WHERE id=:sid AND root_node_id=:nid");
      $isRoot->execute([':sid'=>$sid, ':nid'=>$nid]);
      if ($isRoot->fetchColumn() > 0) throw new RuntimeException('Node ist Root des Scripts.');

      // Check: Irgendwo referenziert?
      $ref = $pdo->prepare("SELECT COUNT(*) FROM script_options WHERE leads_to_node_id=:nid");
      $ref->execute([':nid'=>$nid]);
      if ($ref->fetchColumn() > 0) throw new RuntimeException('Node ist Ziel von Optionen – zuerst Optionen umlenken/löschen.');

      // Optionen am Node löschen, dann Node löschen
      $pdo->prepare("DELETE FROM script_options WHERE node_id=:nid")->execute([':nid'=>$nid]);
      $pdo->prepare("DELETE FROM script_nodes WHERE id=:nid AND script_id=:sid")->execute([':nid'=>$nid, ':sid'=>$sid]);
      flash('success','Node gelöscht.');
    }

    // Option anlegen/bearbeiten
    if ($action==='save_option') {
      $nodeId = (int)($_POST['node_id'] ?? 0);
      $optId  = (int)($_POST['option_id'] ?? 0);
      $label  = trim((string)($_POST['label'] ?? ''));
      $leads  = (int)($_POST['leads_to_node_id'] ?? 0);
      $sent   = $_POST['sentiment'] ?? 'neu';
      if (!in_array($sent,['pos','neu','neg'],true)) $sent='neu';
      $so     = (int)($_POST['sort_order'] ?? 0);

      if ($optId>0) {
        $st = $pdo->prepare("UPDATE script_options SET label=:l, leads_to_node_id=:to, sentiment=:s, sort_order=:so WHERE id=:id AND node_id=:nid");
        $st->execute([':l'=>$label, ':to'=>($leads?:null), ':s'=>$sent, ':so'=>$so, ':id'=>$optId, ':nid'=>$nodeId]);
        flash('success','Option aktualisiert.');
      } else {
        $st = $pdo->prepare("INSERT INTO script_options (node_id,label,leads_to_node_id,sentiment,sort_order) VALUES (:nid,:l,:to,:s,:so)");
        $st->execute([':nid'=>$nodeId, ':l'=>$label, ':to'=>($leads?:null), ':s'=>$sent, ':so'=>$so]);
        flash('success','Option angelegt.');
      }
    }

    // Option löschen
    if ($action==='delete_option') {
      $optId = (int)($_POST['option_id'] ?? 0);
      $pdo->prepare("DELETE FROM script_options WHERE id=:id")->execute([':id'=>$optId]);
      flash('success','Option gelöscht.');
    }

  } catch (Throwable $e) {
    flash('error','Fehler: '.$e->getMessage());
  }

  header('Location: /public/script_builder.php?campaign_id='.$campaignId); exit;
}

// ---- Daten für UI: Nodes + Optionen der (ggf. aktiven) und aller Scripts
$nodesByScript = [];
$optionsByNode = [];

// alle Script-IDs einsammeln
$scriptIds = array_map(fn($r)=> (int)$r['id'], $scripts);
if (!empty($scriptIds)) {
  $in = implode(',', array_fill(0, count($scriptIds), '?'));
  $st = $pdo->prepare("SELECT * FROM script_nodes WHERE script_id IN ($in) ORDER BY sort_order, id");
  $st->execute($scriptIds);
  while($n = $st->fetch()){
    $sid = (int)$n['script_id'];
    $nodesByScript[$sid][] = $n;
  }

  // Optionen für alle Nodes laden
  $allNodeIds = [];
  foreach($nodesByScript as $list) foreach($list as $n) $allNodeIds[] = (int)$n['id'];
  if (!empty($allNodeIds)) {
    $in2 = implode(',', array_fill(0, count($allNodeIds), '?'));
    $st2 = $pdo->prepare("SELECT * FROM script_options WHERE node_id IN ($in2) ORDER BY sort_order, id");
    $st2->execute($allNodeIds);
    while($o = $st2->fetch()){
      $nid = (int)$o['node_id'];
      $optionsByNode[$nid][] = $o;
    }
  }
}

// Hilfsfunktion: Node-Label
function node_caption($n){
  $p = '#'.$n['id'].' · '.($n['title'] ?: strtoupper($n['kind']));
  if ((int)$n['is_terminal']===1) $p .= ' · [terminal]';
  return $p;
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Script Builder · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding-top: 56px; }
    .card-sticky { position: sticky; top: 56px; z-index: 1; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .opt-sent-pos { color: #0a7; }
    .opt-sent-neg { color: #c22; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>

<main class="container-fluid py-3">
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if($m=flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <h1 class="h5 m-0">Script-Builder · Kampagne: <?= e($campaign['name']) ?></h1>
    <div class="ms-auto">
      <?php if($activeScript): ?>
        <span class="badge bg-primary">Aktiv: <?= e($activeScript['name']) ?><?= $activeScript['root_node_id']? ' (Root #'.(int)$activeScript['root_node_id'].')':'' ?></span>
      <?php else: ?>
        <span class="badge bg-warning text-dark">Kein aktives Script</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3">
    <!-- Linke Spalte: Scripts & Nodes -->
    <div class="col-12 col-xl-8">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h6">Scripts</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light"><tr><th>ID</th><th>Name</th><th>Status</th><th>Root</th><th style="width:1%"></th></tr></thead>
              <tbody>
              <?php if(empty($scripts)): ?>
                <tr><td colspan="5" class="text-muted">Keine Scripts vorhanden.</td></tr>
              <?php else: foreach($scripts as $s): ?>
                <tr>
                  <td>#<?= (int)$s['id'] ?></td>
                  <td class="fw-semibold"><?= e($s['name']) ?></td>
                  <td><?= e($s['status']) ?></td>
                  <td><?= $s['root_node_id']? '#'.(int)$s['root_node_id']:'—' ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="activate_script">
                      <input type="hidden" name="script_id" value="<?= (int)$s['id'] ?>">
                      <button class="btn btn-sm btn-outline-primary" <?= $s['status']==='active'?'disabled':'' ?>>Aktiv setzen</button>
                    </form>
                  </td>
                </tr>
                <?php if(!empty($nodesByScript[(int)$s['id']]??[])): ?>
                  <tr><td></td><td colspan="4">
                    <?php foreach($nodesByScript[(int)$s['id']] as $n): ?>
                      <div class="border rounded p-2 mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                          <div class="fw-semibold mono"><?= e(node_caption($n)) ?></div>
                          <div class="d-flex gap-2">
                            <!-- Root setzen -->
                            <form method="post" class="d-inline">
                              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="set_root">
                              <input type="hidden" name="script_id" value="<?= (int)$s['id'] ?>">
                              <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
                              <button class="btn btn-sm btn-outline-secondary" <?= ($s['root_node_id']==$n['id'])?'disabled':'' ?>>Als Root</button>
                            </form>
                            <!-- Löschen -->
                            <form method="post" class="d-inline" onsubmit="return confirm('Diesen Node wirklich löschen? Optionen werden mitgelöscht, falls nicht referenziert.');">
                              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="delete_node">
                              <input type="hidden" name="script_id" value="<?= (int)$s['id'] ?>">
                              <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger">Löschen</button>
                            </form>
                          </div>
                        </div>
                        <div class="small text-muted">kind: <?= e($n['kind']) ?> · default→ <?= $n['next_default']? '#'.(int)$n['next_default']:'—' ?></div>
                        <pre class="mb-2" style="white-space:pre-wrap"><?= e($n['body']) ?></pre>

                        <!-- Optionen anzeigen -->
                        <?php $opts = $optionsByNode[(int)$n['id']] ?? []; ?>
                        <?php if(!empty($opts)): ?>
                          <div class="table-responsive">
                            <table class="table table-sm mb-2">
                              <thead class="table-light"><tr><th>#</th><th>Label</th><th>→ Node</th><th>Sentiment</th><th>Sort</th><th style="width:1%"></th></tr></thead>
                              <tbody>
                                <?php foreach($opts as $o): ?>
                                  <tr>
                                    <td>#<?= (int)$o['id'] ?></td>
                                    <td><?= e($o['label']) ?></td>
                                    <td><?= $o['leads_to_node_id']? '#'.(int)$o['leads_to_node_id']:'—' ?></td>
                                    <td class="<?= $o['sentiment']==='pos'?'opt-sent-pos':($o['sentiment']==='neg'?'opt-sent-neg':'') ?>"><?= e($o['sentiment']) ?></td>
                                    <td><?= (int)$o['sort_order'] ?></td>
                                    <td class="text-end">
                                      <!-- Option bearbeiten: oben im Formular ausfüllen und senden -->
                                      <button class="btn btn-sm btn-outline-secondary" type="button"
                                        onclick="fillOptionForm(<?= (int)$n['id'] ?>,<?= (int)$o['id'] ?>,'<?= e($o['label']) ?>','<?= (int)$o['leads_to_node_id'] ?>','<?= e($o['sentiment']) ?>','<?= (int)$o['sort_order'] ?>')">
                                        Bearbeiten
                                      </button>
                                      <form method="post" class="d-inline" onsubmit="return confirm('Option löschen?');">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_option">
                                        <input type="hidden" name="option_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Löschen</button>
                                      </form>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php endif; ?>

                        <!-- Option hinzufügen / bearbeiten -->
                        <form method="post" class="row g-2 align-items-end">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="save_option">
                          <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
                          <input type="hidden" id="opt-edit-<?= (int)$n['id'] ?>" name="option_id" value="">
                          <div class="col-12 col-md-4">
                            <label class="form-label">Option-Label</label>
                            <input type="text" class="form-control" id="opt-label-<?= (int)$n['id'] ?>" name="label" placeholder="Ja / Nein / usw.">
                          </div>
                          <div class="col-6 col-md-3">
                            <label class="form-label">leads→Node</label>
                            <input type="number" class="form-control" id="opt-leads-<?= (int)$n['id'] ?>" name="leads_to_node_id" placeholder="Node-ID">
                          </div>
                          <div class="col-6 col-md-2">
                            <label class="form-label">Sentiment</label>
                            <select class="form-select" id="opt-sent-<?= (int)$n['id'] ?>" name="sentiment">
                              <option value="neu">neutral</option>
                              <option value="pos">positiv</option>
                              <option value="neg">negativ</option>
                            </select>
                          </div>
                          <div class="col-6 col-md-2">
                            <label class="form-label">Sort</label>
                            <input type="number" class="form-control" id="opt-sort-<?= (int)$n['id'] ?>" name="sort_order" value="0">
                          </div>
                          <div class="col-12 col-md-1 d-grid">
                            <button class="btn btn-outline-primary">Speichern</button>
                          </div>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </td></tr>
                <?php endif; ?>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Rechte Spalte: Formulare -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm card-sticky">
        <div class="card-body">
          <h2 class="h6 mb-2">Neues Script</h2>
          <form method="post" class="row g-2 mb-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_script">
            <div class="col-12">
              <input type="text" name="name" class="form-control" placeholder="z. B. KNX Berlin v1">
            </div>
            <div class="col-12 d-grid"><button class="btn btn-primary">Anlegen</button></div>
          </form>

          <hr>
          <h2 class="h6 mb-2">Node anlegen/bearbeiten</h2>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_node">
            <div class="col-4">
              <label class="form-label">Script</label>
              <select class="form-select" name="script_id" required>
                <?php foreach($scripts as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= e('#'.$s['id'].' · '.$s['name']) ?><?= $s['status']==='active'?' · aktiv':'' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <label class="form-label">Node-ID (bearb.)</label>
              <input type="number" name="node_id" class="form-control" placeholder="leer = neu">
            </div>
            <div class="col-4">
              <label class="form-label">Kind</label>
              <select class="form-select" name="kind">
                <option value="question">question</option>
                <option value="message">message</option>
                <option value="action">action</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Titel (optional)</label>
              <input type="text" name="title" class="form-control" placeholder="z. B. Einstieg">
            </div>
            <div class="col-12">
              <label class="form-label">Text/Body</label>
              <textarea name="body" class="form-control" rows="4" placeholder="z. B. ‚Das KNX-Trainingcenter.com ... Kennen Sie KNX?‘"></textarea>
            </div>
            <div class="col-6">
              <label class="form-label">Default → Node-ID</label>
              <input type="number" name="next_default" class="form-control" placeholder="optional">
            </div>
            <div class="col-3 form-check mt-4">
              <input class="form-check-input" type="checkbox" id="is_terminal" name="is_terminal" value="1">
              <label class="form-check-label" for="is_terminal">Terminal</label>
            </div>
            <div class="col-3">
              <label class="form-label">Sort</label>
              <input type="number" name="sort_order" class="form-control" value="0">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-outline-primary">Node speichern</button>
            </div>
          </form>

          <hr>
          <h2 class="h6">Testen</h2>
          <p class="small text-muted">Zum Test den Call-Runner mit einer beliebigen Firma dieser Kampagne öffnen:</p>
          <form class="row g-2" method="get" action="/public/call_add.php">
            <input type="hidden" name="campaign_id" value="<?= (int)$campaignId ?>">
            <div class="col-8">
              <input type="number" class="form-control" name="company_id" placeholder="Company-ID">
            </div>
            <div class="col-4 d-grid">
              <button class="btn btn-outline-secondary">Call öffnen</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div>
</main>

<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX-Trainingcenter · Aquise Backend</div></footer>
<script>
function fillOptionForm(nodeId, optId, label, leadsTo, sent, sort){
  document.getElementById('opt-edit-'+nodeId).value = optId;
  document.getElementById('opt-label-'+nodeId).value = label;
  document.getElementById('opt-leads-'+nodeId).value = leadsTo || '';
  document.getElementById('opt-sent-'+nodeId).value = sent || 'neu';
  document.getElementById('opt-sort-'+nodeId).value = sort || 0;
  // sanft scrollen zum Formular
  document.getElementById('opt-label-'+nodeId).scrollIntoView({behavior:'smooth',block:'center'});
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
