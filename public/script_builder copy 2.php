<?php
// public/script_builder.php – Script-Builder für Dialogbäume
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
$scriptsStmt = $pdo->prepare("SELECT id,name,status,root_node_id FROM campaign_scripts WHERE campaign_id=:cid ORDER BY (status='active') DESC, id DESC");
$scriptsStmt->execute([':cid'=>$campaignId]); $scripts = $scriptsStmt->fetchAll();

// --- Utility: Script-ID wählen für Graph ---
$graphScriptId = (int)($_GET['script_id'] ?? 0);
if ($graphScriptId<=0) { $graphScriptId = $activeScript['id'] ?? ((int)($scripts[0]['id'] ?? 0)); }

// --------- API: GET graph_json (flows/flow_nodes) ----------
if (($_GET['action'] ?? '') === 'graph_json') {
  header('Content-Type: application/json; charset=utf-8');

  // 1) Alle aktiven Flows holen
  $flows = $pdo->query("SELECT id, code, name, description, active FROM flows WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  if (!$flows) {
    echo json_encode(['root' => 'ROOT', 'nodes' => [['data'=>['id'=>'ROOT','label'=>'Keine aktiven Flows','type'=>'INFO']]], 'edges' => []]);
    exit;
  }

  // 2) Alle flow_nodes zu diesen Flows
  $flowIds = array_map(fn($f)=>(int)$f['id'], $flows);
  $in = implode(',', array_fill(0,count($flowIds),'?'));
  $st = $pdo->prepare("SELECT id, flow_id, kind, script_id, question_id, sort_order
                       FROM flow_nodes
                       WHERE flow_id IN ($in)
                       ORDER BY flow_id, sort_order, id");
  $st->execute($flowIds);
  $fnodes = $st->fetchAll(PDO::FETCH_ASSOC);

  // 3) Optional: Titel für Scripts/Questions (nur wenn Tabellen existieren)
  $scriptTitles = []; $questionTitles = [];
  try {
    $sids = array_values(array_unique(array_map(fn($n)=>(int)$n['script_id'], array_filter($fnodes, fn($n)=>$n['kind']==='script' && !empty($n['script_id'])))));
    if ($sids) {
      $ins = implode(',', array_fill(0,count($sids),'?'));
      // Falls bei dir 'scripts' anders heißt: hier anpassen
      $ss = $pdo->prepare("SELECT id, name FROM scripts WHERE id IN ($ins)");
      $ss->execute($sids);
      foreach($ss->fetchAll(PDO::FETCH_ASSOC) as $r){ $scriptTitles[(int)$r['id']] = $r['name']; }
    }
  } catch (Throwable $e) { /* Tabelle scripts nicht vorhanden -> Fallback-Titel */ }

  try {
    $qids = array_values(array_unique(array_map(fn($n)=>(int)$n['question_id'], array_filter($fnodes, fn($n)=>$n['kind']==='question' && !empty($n['question_id'])))));
    if ($qids) {
      $inq = implode(',', array_fill(0,count($qids),'?'));
      // Falls bei dir 'questions' anders heißt: hier anpassen
      $qq = $pdo->prepare("SELECT id, title FROM questions WHERE id IN ($inq)");
      $qq->execute($qids);
      foreach($qq->fetchAll(PDO::FETCH_ASSOC) as $r){ $questionTitles[(int)$r['id']] = $r['title']; }
    }
  } catch (Throwable $e) { /* Tabelle questions nicht vorhanden -> Fallback-Titel */ }

  // 4) Cytoscape-Elements aufbauen
  $nodes = [];
  $edges = [];

  // Virtueller ROOT
  $nodes[] = ['data'=>['id'=>'ROOT', 'label'=>'Flows (Übersicht)', 'type'=>'ROOT']];

  // Indices, um pro Flow Ketten zu bauen
  $byFlow = [];
  foreach ($fnodes as $n) { $byFlow[(int)$n['flow_id']][] = $n; }

  foreach ($flows as $f) {
    $fid = (int)$f['id'];
    $flowNodeId = 'F'.$fid;
    $flowLabel = ($f['name'] ?: ('Flow #'.$fid));
    $nodes[] = ['data'=>['id'=>$flowNodeId, 'label'=>$flowLabel, 'type'=>'FLOW']];
    $edges[] = ['data'=>['id'=>'root_to_'.$flowNodeId, 'source'=>'ROOT', 'target'=>$flowNodeId, 'label'=>'']];

    $chain = $byFlow[$fid] ?? [];
    $prevId = null;
    foreach ($chain as $step) {
      $sid = 'FN'.$step['id'];
      // Label bestimmen
      if ($step['kind'] === 'script') {
        $lbl = 'SCRIPT';
        if (!empty($step['script_id'])) {
          $lbl = ($scriptTitles[(int)$step['script_id']] ?? ('Script #'.$step['script_id']));
        }
        $type = 'SCRIPT';
      } else { // question
        $lbl = 'QUESTION';
        if (!empty($step['question_id'])) {
          $lbl = ($questionTitles[(int)$step['question_id']] ?? ('Question #'.$step['question_id']));
        }
        $type = 'QUESTION';
      }
      $lbl = $lbl . '  (' . strtoupper($step['kind']) . ')';

      $nodes[] = ['data'=>[
        'id'    => $sid,
        'label' => $lbl,
        'type'  => $type
      ]];

      // Kette: Flow -> erster Step, danach Step -> Step
      if ($prevId === null) {
        $edges[] = ['data'=>['id'=>'F'.$fid.'_to_'.$sid, 'source'=>$flowNodeId, 'target'=>$sid, 'label'=>'']];
      } else {
        $edges[] = ['data'=>['id'=>$prevId.'_to_'.$sid, 'source'=>$prevId, 'target'=>$sid, 'label'=>'→']];
      }
      $prevId = $sid;
    }
  }

  echo json_encode(['root'=>'ROOT', 'nodes'=>$nodes, 'edges'=>$edges]);
  exit;
}

// --------- API: POST update_node (AJAX/Modal) ----------
if (($_GET['action'] ?? '') === 'update_node' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
  try {
    $nid = (int)($_POST['id'] ?? 0);
    $kind = $_POST['kind'] ?? 'question';
    if (!in_array($kind,['message','question','action'],true)) $kind='question';
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $is_terminal = isset($_POST['is_terminal']) ? 1 : 0;
    $next_default = (int)($_POST['next_default'] ?? 0);
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    if ($nid<=0) throw new RuntimeException('Node-ID fehlt.');
    $st = $pdo->prepare("UPDATE script_nodes SET kind=:k, title=:t, body=:b, is_terminal=:term, next_default=:nd, sort_order=:so WHERE id=:nid");
    $ok = $st->execute([':k'=>$kind, ':t'=>$title, ':b'=>$body, ':term'=>$is_terminal, ':nd'=>($next_default?:null), ':so'=>$sort_order, ':nid'=>$nid]);
    echo json_encode(['ok'=>$ok]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

// --------- API: POST export_pdf ----------
if (($_GET['action'] ?? '') === 'export_pdf' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
  $b64 = $_POST['png_base64'] ?? '';
  if (!preg_match('/^data:image\/(png|jpeg);base64,/', $b64)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad image']); exit; }
  $imgData = explode(',', $b64, 2)[1];
  $bin = base64_decode($imgData, true);
  if ($bin===false) { echo json_encode(['ok'=>false,'error'=>'decode failed']); exit; }
  $tmp = sys_get_temp_dir().'/tree_'.time().'.png';
  file_put_contents($tmp, $bin);

  // FPDF nutzen (muss vorhanden sein)
  if (!class_exists('FPDF')) {
    $fpdfA = __DIR__.'/fpdf186/fpdf.php';
    if (file_exists($fpdfA)) { require_once $fpdfA; }
  }
  if (!class_exists('FPDF')) { echo json_encode(['ok'=>false,'error'=>'FPDF nicht gefunden']); @unlink($tmp); exit; }

  $pdf = new FPDF('L','mm','A4');
  $pdf->AddPage();
  [$w, $h] = getimagesize($tmp);
  $pageW = $pdf->GetPageWidth() - 20; // Ränder
  $pageH = $pdf->GetPageHeight() - 20;
  $scale = min($pageW/$w, $pageH/$h);
  $imgW = $w * $scale; $imgH = $h * $scale;
  $x = ($pdf->GetPageWidth()-$imgW)/2; $y = ($pdf->GetPageHeight()-$imgH)/2;
  $pdf->Image($tmp, $x, $y, $imgW, $imgH);
  if (!is_dir(__DIR__.'/exports')) { mkdir(__DIR__.'/exports',0775,true); }
  $out = __DIR__.'/exports/script_tree_'.date('Ymd_His').'.pdf';
  $pdf->Output('F',$out);
  @unlink($tmp);
  echo json_encode(['ok'=>true,'path'=>basename($out)]);
  exit;
}

// --------- POST: klassische Aktionen (aus deinem Original) ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && !isset($_GET['action'])) {
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

  header('Location: /public/script_builder.php?campaign_id='.$campaignId.'&script_id='.$graphScriptId); exit;
}

// ---- Daten für UI: Nodes + Optionen der (ggf. aktiven) und aller Scripts
$nodesByScript = [];
$optionsByNode = [];
$scriptIds = array_map(fn($r)=> (int)$r['id'], $scripts);
if (!empty($scriptIds)) {
  $in = implode(',', array_fill(0, count($scriptIds), '?'));
  $st = $pdo->prepare("SELECT * FROM script_nodes WHERE script_id IN ($in) ORDER BY sort_order, id");
  $st->execute($scriptIds);
  while($n = $st->fetch()){
    $sid = (int)$n['script_id'];
    $nodesByScript[$sid][] = $n;
  }
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

  .card-sticky { position: sticky; top: 56px; z-index: 1; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .opt-sent-pos { color: #0a7; }
  .opt-sent-neg { color: #c22; }

  /* NEU: Full-width Graph unten */
  #graph-wrap {
    margin-top: 1rem;
  }
  #cy {
    width: 100%;
    height: 88vh;           /* groß, quasi vollflächig */
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    background: #fff;
  }

  .toolbar { display:flex; gap:.5rem; align-items:center; }
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
    <div class="col-12 col-xxl-7">
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
                  <td class="fw-semibold">
                    <a href="?campaign_id=<?= (int)$campaignId ?>&script_id=<?= (int)$s['id'] ?>" class="text-decoration-none"><?= e($s['name']) ?></a>
                  </td>
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
                            <!-- Bearbeiten-Button (öffnet Modal) -->
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                              onclick='openNodeEdit(<?= (int)$n['id'] ?>, "<?= e($n['kind']) ?>", "<?= e($n['title']) ?>", <?= json_encode($n['body']) ?>, <?= (int)$n['is_terminal'] ?>, <?= (int)($n['next_default']?:0) ?>, <?= (int)$n['sort_order'] ?>)'>
                              Bearbeiten
                            </button>
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
                        <div class="small text-muted">kind: <?= e($n['kind']) ?> · default→ <?= $n['next_default']? '#'.(int)$n['next_default']:'—' ?> · sort: <?= (int)$n['sort_order'] ?></div>
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
    <div class="col-12 col-xxl-5">


      <div class="card shadow-sm">
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
                  <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$graphScriptId?'selected':'' ?>><?= e('#'.$s['id'].' · '.$s['name']) ?><?= $s['status']==='active'?' · aktiv':'' ?></option>
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
  <!-- Full-width Graph unten -->
<div id="graph-wrap" class="container-fluid">
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 m-0">Baumdiagramm<?= $graphScriptId? ' · Script #'.(int)$graphScriptId : '' ?></h2>
        <div class="toolbar">
          <button id="btn-relayout" class="btn btn-sm btn-outline-secondary">Neu anordnen</button>
          <button id="btn-export-png" class="btn btn-sm btn-outline-secondary">PNG</button>
          <form id="pdfForm" class="d-inline" method="post" action="?action=export_pdf&campaign_id=<?= (int)$campaignId ?>&script_id=<?= (int)$graphScriptId ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="png_base64" id="png_base64">
            <button id="btn-export-pdf" type="button" class="btn btn-sm btn-outline-secondary">PDF</button>
          </form>
        </div>
      </div>

      <div class="row g-2 mb-2">
        <div class="col-12 col-md-8">
          <form method="get" class="d-flex gap-2">
            <input type="hidden" name="campaign_id" value="<?= (int)$campaignId ?>">
            <select class="form-select" name="script_id" onchange="this.form.submit()">
              <?php foreach($scripts as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$graphScriptId?'selected':'' ?>>
                  #<?= (int)$s['id'] ?> · <?= e($s['name']) ?><?= $s['status']==='active'?' · aktiv':'' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-check d-flex align-items-center ms-2">
              <input class="form-check-input" type="checkbox" id="reachableOnly" checked>
              <label class="form-check-label ms-1" for="reachableOnly">Nur erreichbare Knoten</label>
            </div>
          </form>
        </div>
      </div>

      <div id="cy"></div>
    </div>
  </div>
</div>

</main>

<!-- Node-Bearbeiten Modal -->
<div class="modal fade" id="nodeEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Node bearbeiten</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="nodeEditForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-2">
            <div class="col-3">
              <label class="form-label">Node-ID</label>
              <input id="ne-id" name="id" class="form-control" readonly>
            </div>
            <div class="col-3">
              <label class="form-label">Kind</label>
              <select id="ne-kind" name="kind" class="form-select">
                <option value="question">question</option>
                <option value="message">message</option>
                <option value="action">action</option>
              </select>
            </div>
            <div class="col-3">
              <label class="form-label">Default →</label>
              <input id="ne-next" name="next_default" type="number" class="form-control">
            </div>
            <div class="col-3">
              <label class="form-label">Sort</label>
              <input id="ne-sort" name="sort_order" type="number" class="form-control" value="0">
            </div>
            <div class="col-9">
              <label class="form-label">Titel</label>
              <input id="ne-title" name="title" class="form-control">
            </div>
            <div class="col-3 form-check mt-4">
              <input class="form-check-input" type="checkbox" id="ne-term" name="is_terminal" value="1">
              <label class="form-check-label" for="ne-term">Terminal</label>
            </div>
            <div class="col-12">
              <label class="form-label">Body</label>
              <textarea id="ne-body" name="body" rows="6" class="form-control"></textarea>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
        <button type="button" class="btn btn-primary" id="ne-save">Speichern</button>
      </div>
    </div>
  </div>
</div>

<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX-Trainingcenter · Aquise Backend</div></footer>
<script>
function fillOptionForm(nodeId, optId, label, leadsTo, sent, sort){
  document.getElementById('opt-edit-'+nodeId).value = optId;
  document.getElementById('opt-label-'+nodeId).value = label;
  document.getElementById('opt-leads-'+nodeId).value = leadsTo || '';
  document.getElementById('opt-sent-'+nodeId).value = sent || 'neu';
  document.getElementById('opt-sort-'+nodeId).value = sort || 0;
  document.getElementById('opt-label-'+nodeId).scrollIntoView({behavior:'smooth',block:'center'});
}

// Modal-Editing
let nodeEditModal, bootstrapModal;
function openNodeEdit(id, kind, title, body, term, nextDefault, sort){
  const idF = document.getElementById('ne-id');
  const kindF = document.getElementById('ne-kind');
  const titleF = document.getElementById('ne-title');
  const bodyF = document.getElementById('ne-body');
  const termF = document.getElementById('ne-term');
  const nextF = document.getElementById('ne-next');
  const sortF = document.getElementById('ne-sort');
  idF.value = id; kindF.value = kind; titleF.value = title || ''; bodyF.value = body || ''; termF.checked = !!term; nextF.value = nextDefault || ''; sortF.value = sort || 0;
  if (!bootstrapModal) { bootstrapModal = new bootstrap.Modal(document.getElementById('nodeEditModal')); }
  bootstrapModal.show();
}

document.getElementById('ne-save').addEventListener('click', async ()=>{
  const fd = new FormData(document.getElementById('nodeEditForm'));
  const resp = await fetch('?action=update_node&campaign_id=<?= (int)$campaignId ?>&script_id=<?= (int)$graphScriptId ?>', { method:'POST', body:fd });
  const j = await resp.json();
  if (j.ok){ location.reload(); } else { alert('Speichern fehlgeschlagen: '+(j.error||'')); }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.umd.min.js"></script>
<script>
(async function(){
  const reachableOnly = document.getElementById('reachableOnly');

  async function loadGraph(){
    const url = `?action=graph_json&campaign_id=<?= (int)$campaignId ?>&script_id=<?= (int)$graphScriptId ?>&reachable_only=${reachableOnly && reachableOnly.checked?1:0}`;
    const res = await fetch(url);
    return await res.json();
  }

  const data = await loadGraph();

  // Fallback: Wenn gar nichts kommt, zeig Hinweis statt „leerer Fläche“
  if (!data || (!data.nodes || data.nodes.length === 0)) {
    const cyDiv = document.getElementById('cy');
    cyDiv.innerHTML = '<div class="text-center text-muted py-5">Keine Knoten gefunden. Lege Nodes an und setze einen Root-Node im Script, dann aktualisieren.</div>';
    return;
  }

  const cy = cytoscape({
    container: document.getElementById('cy'),
    elements: { nodes: data.nodes, edges: data.edges },
    style: [
      { selector: 'node', style: {
          'label': 'data(label)', 'text-valign':'center', 'text-halign':'center',
          'background-color':'#64748b', 'color':'#fff', 'width':'label', 'height':'label', 'padding':'8px',
          'shape':'round-rectangle', 'font-size':'12px', 'text-wrap':'wrap', 'text-max-width': 360
        }
      },
      { selector: 'node[type = "MESSAGE"]', style: {'background-color':'#0ea5e9'} },
      { selector: 'node[type = "QUESTION"]', style: {'background-color':'#10b981'} },
      { selector: 'node[type = "ACTION"]', style: {'background-color':'#a855f7'} },
      { selector: 'node[type = "END"]',     style: {'background-color':'#ef4444'} },
      { selector: 'edge', style: {
          'curve-style':'bezier', 'target-arrow-shape':'triangle',
          'target-arrow-color':'#94a3b8', 'line-color':'#cbd5e1',
          'label':'data(label)', 'font-size':'11px',
          'text-background-color':'#ffffff', 'text-background-opacity':0.85,
          'text-rotation':'autorotate'
        }
      }
    ],
    layout: { name:'breadthfirst', roots: data.root? '#'+data.root : undefined, directed:true, padding: 30, spacingFactor:1.2, avoidOverlap:true }
  });

  function relayout(){
    cy.resize();
    cy.layout({ name:'breadthfirst', roots: data.root? '#'+data.root : undefined, directed:true, padding:30, spacingFactor:1.2 }).run();
  }

  // Erstes Resize + Relayout, falls Container gerade neu angezeigt wurde
  setTimeout(relayout, 50);
  window.addEventListener('resize', () => { cy.resize(); });

  // Toolbar
  const btnRelayout = document.getElementById('btn-relayout');
  if (btnRelayout) btnRelayout.onclick = relayout;

  if (reachableOnly) reachableOnly.addEventListener('change', async ()=>{
    const d = await loadGraph();
    cy.elements().remove();
    if (!d.nodes || d.nodes.length === 0) {
      document.getElementById('cy').innerHTML = '<div class="text-center text-muted py-5">Keine Knoten (Filter) sichtbar.</div>';
      return;
    }
    cy.add(d.nodes);
    cy.add(d.edges);
    data.root = d.root;
    relayout();
  });

  // PNG Export
  const btnPng = document.getElementById('btn-export-png');
  if (btnPng) btnPng.onclick = () => {
    const png = cy.png({ full:true, scale:2, bg:'white' });
    const a = document.createElement('a'); a.href = png; a.download = 'script_tree.png'; a.click();
  };

  // PDF Export
  const btnPdf = document.getElementById('btn-export-pdf');
  if (btnPdf) btnPdf.onclick = async () => {
    const png = cy.png({ full:true, scale:2, bg:'white' });
    document.getElementById('png_base64').value = png;
    const fd = new FormData(document.getElementById('pdfForm'));
    const resp = await fetch(document.getElementById('pdfForm').action, { method:'POST', body:fd });
    const j = await resp.json();
    if(j.ok){ window.location.href = 'exports/'+j.path; } else { alert('PDF-Export fehlgeschlagen: '+(j.error||'')); }
  };

  // Doppelklick auf Node -> Bearbeiten-Modal (nutzt bestehende Funktion)
  cy.on('dblclick tap', 'node', (evt) => {
    const n = evt.target;
    openNodeEdit(n.id(), 'question', '', '', 0, '', 0);
  });
})();
</script>

</body>
</html>
