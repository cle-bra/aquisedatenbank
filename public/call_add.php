<?php
// public/call_add.php – Call starten & Script laufen lassen (Runner)
// Erwartet: GET campaign_id, company_id
// Nutzt: calls, campaign_scripts, script_nodes, script_options, call_steps, contacts, campaign_companies

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

// --- Input ---
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$companyId  = (int)($_GET['company_id'] ?? 0);
if ($campaignId<=0 || $companyId<=0){ die('Fehlender Parameter campaign_id oder company_id.'); }

// --- Daten laden ---
$company = $pdo->prepare("SELECT id,name,street,zip,city,phone_general,email_general,website FROM companies WHERE id=:id");
$company->execute([':id'=>$companyId]); $company = $company->fetch();
if(!$company){ die('Firma nicht gefunden.'); }

$campaign = $pdo->prepare("SELECT id,name,status FROM campaigns WHERE id=:id");
$campaign->execute([':id'=>$campaignId]); $campaign = $campaign->fetch();
if(!$campaign){ die('Kampagne nicht gefunden.'); }

// Aktives Script zur Kampagne
$script = $pdo->prepare("SELECT id, root_node_id, name FROM campaign_scripts WHERE campaign_id=:cid AND status='active' LIMIT 1");
$script->execute([':cid'=>$campaignId]); $script = $script->fetch();

// --- Call sicherstellen (einen offenen pro Agent/Firma/Kampagne zulassen) ---
$agentId = (int)($_SESSION['user']['id'] ?? 0);
$call = $pdo->prepare("SELECT * FROM calls WHERE campaign_id=:c AND company_id=:k AND agent_id=:a ORDER BY id DESC LIMIT 1");
$call->execute([':c'=>$campaignId, ':k'=>$companyId, ':a'=>$agentId]); $call = $call->fetch();

if (!$call) {
  $ins = $pdo->prepare("INSERT INTO calls (company_id,campaign_id,agent_id,status,created_at) VALUES (:k,:c,:a,'ok',NOW())");
  $ins->execute([':k'=>$companyId, ':c'=>$campaignId, ':a'=>$agentId]);
  $callId = (int)$pdo->lastInsertId();
  $call = $pdo->prepare("SELECT * FROM calls WHERE id=:id"); $call->execute([':id'=>$callId]); $call = $call->fetch();
}
$callId = (int)$call['id'];

// --- Knoten bestimmen ---
$currentNodeId = (int)($_GET['node'] ?? 0);
if ($currentNodeId<=0 && $script) {
  $currentNodeId = (int)$script['root_node_id'];
}

// Node und Optionen laden (kann null sein, wenn kein Script aktiv)
$currentNode = null; $options = [];
if ($currentNodeId>0) {
  $st = $pdo->prepare("SELECT * FROM script_nodes WHERE id=:id");
  $st->execute([':id'=>$currentNodeId]); $currentNode = $st->fetch();

  $op = $pdo->prepare("SELECT * FROM script_options WHERE node_id=:id ORDER BY sort_order, id");
  $op->execute([':id'=>$currentNodeId]); $options = $op->fetchAll();
}

// --- POST-Handler ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { flash('error','Ungültiges Formular (CSRF).'); header('Location: /public/call_add.php?'.http_build_query($_GET)); exit; }

  $action = $_POST['action'] ?? '';

  try {
    // 1) Script-Option geklickt
    if ($action==='choose_option') {
      $optionId = (int)($_POST['option_id'] ?? 0);
      if ($optionId>0) {
        $st = $pdo->prepare("SELECT node_id, leads_to_node_id FROM script_options WHERE id=:id");
        $st->execute([':id'=>$optionId]); $opt = $st->fetch();
        if ($opt) {
          $nodeId = (int)$opt['node_id'];
          $nextId = (int)$opt['leads_to_node_id'];

          $note = trim((string)($_POST['note'] ?? ''));
          $ins = $pdo->prepare("INSERT INTO call_steps (call_id, node_id, option_id, free_note) VALUES (:call,:node,:opt,:note)");
          $ins->execute([':call'=>$callId, ':node'=>$nodeId, ':opt'=>$optionId, ':note'=>$note]);

          $target = $nextId>0 ? $nextId : (int)($currentNode['next_default'] ?? 0);
          if ($target>0) {
            header('Location: /public/call_add.php?'.http_build_query(['campaign_id'=>$campaignId,'company_id'=>$companyId,'node'=>$target])); exit;
          } else {
            flash('success','Schritt gespeichert.');
            header('Location: /public/call_add.php?'.http_build_query(['campaign_id'=>$campaignId,'company_id'=>$companyId])); exit;
          }
        }
      }
      flash('error','Option ungültig.');
    }

    // 2) Freie Notiz hinzufügen (ohne Option)
    if ($action==='add_note') {
      $note = trim((string)($_POST['free_note'] ?? ''));
      if ($note!=='') {
        $nodeId = $currentNodeId>0 ? $currentNodeId : 0;
        $ins = $pdo->prepare("INSERT INTO call_steps (call_id, node_id, option_id, free_note) VALUES (:call,:node,NULL,:note)");
        $ins->execute([':call'=>$callId, ':node'=>$nodeId, ':note'=>$note]);
        flash('success','Notiz gespeichert.');
      }
    }

    // 3) Ansprechpartner neu erfassen
    if ($action==='add_contact') {
      $sal = trim((string)($_POST['salutation'] ?? ''));
      $fn  = trim((string)($_POST['first_name'] ?? ''));
      $ln  = trim((string)($_POST['last_name'] ?? ''));
      $pos = trim((string)($_POST['position'] ?? ''));
      $em  = trim((string)($_POST['email_personal'] ?? ''));
      $ph  = trim((string)($_POST['phone_direct'] ?? ''));
      $pe  = trim((string)($_POST['phone_ext'] ?? ''));

      if ($fn!=='' || $ln!=='') {
        $ins = $pdo->prepare("INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext)
                              VALUES (:cid,:sal,:fn,:ln,:pos,:em,:ph,:pe)");
        $ins->execute([':cid'=>$companyId,':sal'=>$sal,':fn'=>$fn,':ln'=>$ln,':pos'=>$pos,':em'=>($em?:null),':ph'=>($ph?:null),':pe'=>($pe?:null)]);
        $newContactId = (int)$pdo->lastInsertId();

        // Im Call zuordnen
        $upd = $pdo->prepare("UPDATE calls SET contact_id=:ctid, new_contact_name=NULL, new_contact_position=NULL, new_contact_email=NULL, new_contact_phone=NULL WHERE id=:id");
        $upd->execute([':ctid'=>$newContactId, ':id'=>$callId]);
        flash('success','Ansprechpartner angelegt & dem Call zugeordnet.');
      } else {
        flash('error','Bitte mindestens Vor- oder Nachnamen angeben.');
      }
    }

    // 4) Vorhandenen Ansprechpartner dem Call zuordnen
    if ($action==='link_contact') {
      $contactId = (int)($_POST['contact_id'] ?? 0);
      if ($contactId>0) {
        $upd = $pdo->prepare("UPDATE calls SET contact_id=:ctid WHERE id=:id");
        $upd->execute([':ctid'=>$contactId, ':id'=>$callId]);
        flash('success','Ansprechpartner dem Call zugeordnet.');
      }
    }

    // 5) Rückruf planen / Status setzen / Sentiment / Notizen (Call-Header)
    if ($action==='update_call_meta') {
      $status   = $_POST['status'] ?? 'ok';                                     // ok|callback|do_not_call
      $cb_date  = trim((string)($_POST['callback_date'] ?? ''));                // YYYY-MM-DD
      $cb_time  = trim((string)($_POST['callback_time'] ?? ''));                // HH:MM
      $notes    = trim((string)($_POST['notes'] ?? ''));
      $sent     = trim((string)($_POST['sentiment'] ?? ''));                    // frei
      $callback_at = null;
      if ($status==='callback' && $cb_date!=='') {
        $callback_at = $cb_date . ($cb_time!=='' ? (' '.$cb_time.':00') : ' 09:00:00');
      }

      $upd = $pdo->prepare("UPDATE calls SET status=:st, callback_at=:cb, notes=:n, sentiment=:s WHERE id=:id");
      $upd->execute([':st'=>$status, ':cb'=>$callback_at, ':n'=>$notes, ':s'=>$sent, ':id'=>$callId]);

      // DNC → optional aus Kampagne entfernen (Flag setzen, Historie bleibt)
      if ($status==='do_not_call') {
        $rm = $pdo->prepare("UPDATE campaign_companies SET removed=1, removed_reason='do_not_call' WHERE campaign_id=:c AND company_id=:k");
        $rm->execute([':c'=>$campaignId, ':k'=>$companyId]);
      }
      flash('success','Call aktualisiert.');
    }

  } catch(Throwable $e){
    flash('error','Fehler: '.$e->getMessage());
  }

  // zurück zur selben Seite
  header('Location: /public/call_add.php?'.http_build_query(['campaign_id'=>$campaignId,'company_id'=>$companyId,'node'=>$currentNodeId])); exit;
}

// --- bestehende Kontakte der Firma (für Zuordnung) ---
$contacts = $pdo->prepare("SELECT id, salutation, first_name, last_name, position, email_personal, phone_direct FROM contacts WHERE company_id=:cid ORDER BY last_name, first_name");
$contacts->execute([':cid'=>$companyId]); $contacts = $contacts->fetchAll();

// --- Historie für diese Firma in dieser Kampagne ---
$history = $pdo->prepare("SELECT c.id as call_id, c.created_at, c.status, c.callback_at, c.sentiment, c.notes,
 COALESCE(CONCAT(ct.first_name,' ',ct.last_name), c.new_contact_name) AS contact_name
 FROM calls c
 LEFT JOIN contacts ct ON ct.id=c.contact_id
 WHERE c.campaign_id=:c AND c.company_id=:k
 ORDER BY c.created_at DESC
 LIMIT 25");
$history->execute([':c'=>$campaignId, ':k'=>$companyId]); $history = $history->fetchAll();

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Call starten · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding-top: 56px; }
    .node-card pre { white-space: pre-wrap; }
    .sticky-col { position: sticky; top: 56px; z-index: 1; }
    .btn-option { min-width: 100px; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>

<main class="container-fluid py-3">
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if($m=flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="row g-3">
    <!-- Linke Spalte: Script-Runner -->
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm node-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold"><?= e($campaign['name']) ?> · Call #<?= (int)$callId ?></div>
            <div class="small text-muted">
              Firma: <a href="/public/company_edit.php?id=<?= (int)$company['id'] ?>"><?= e($company['name']) ?></a>
              · <?= e(trim(($company['street']??'').' '.$company['zip'].' '.$company['city'])) ?>
            </div>
          </div>
          <?php if($script): ?>
            <span class="badge bg-primary"><?= e($script['name']) ?></span>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Kein aktives Script</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if($currentNode && $script): ?>
            <?php if(!empty($currentNode['title'])): ?><h2 class="h6"><?= e($currentNode['title']) ?></h2><?php endif; ?>
            <div class="mb-3"><pre><?= e($currentNode['body']) ?></pre></div>

            <?php if(!empty($options)): ?>
              <form method="post" class="row g-2">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="choose_option">
                <input type="hidden" name="node_id" value="<?= (int)$currentNode['id'] ?>">
                <div class="col-12 d-flex flex-wrap gap-2 mb-2">
                  <?php foreach($options as $opt): ?>
                    <button class="btn btn-outline-primary btn-option" name="option_id" value="<?= (int)$opt['id'] ?>" type="submit">
                      <?= e($opt['label']) ?>
                    </button>
                  <?php endforeach; ?>
                </div>
                <div class="col-12">
                  <textarea class="form-control" name="note" rows="2" placeholder="Optionale Notiz zu diesem Schritt…"></textarea>
                </div>
              </form>
            <?php else: ?>
              <div class="alert alert-info mb-0">Keine Antwortoptionen. <?php if((int)$currentNode['is_terminal']===1): ?>Dies ist ein Endknoten.<?php endif; ?></div>
            <?php endif; ?>

          <?php else: ?>
            <div class="alert alert-warning mb-0">
              Für diese Kampagne ist aktuell kein aktives Script gesetzt – du kannst trotzdem Notizen erfassen oder den Call planen/abschließen.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Freie Notiz -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_note">
            <div class="col-12">
              <label class="form-label">Freie Notiz</label>
              <textarea class="form-control" name="free_note" rows="2" placeholder="Spontane Hinweise, Einwände, etc."></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-secondary">Notiz speichern</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Historie -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h2 class="h6 mb-3">Historie (Firma in dieser Kampagne)</h2>
          <?php if(empty($history)): ?>
            <div class="text-muted">Keine Einträge vorhanden.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-striped">
                <thead><tr>
                  <th>Datum</th><th>Status</th><th>Rückruf</th><th>Kontakt</th><th>Stimmung</th><th>Notizen</th>
                </tr></thead>
                <tbody>
                <?php foreach($history as $h): ?>
                  <tr>
                    <td><?= e($h['created_at']) ?></td>
                    <td><?= e($h['status']) ?></td>
                    <td><?= e($h['callback_at'] ?: '—') ?></td>
                    <td><?= e($h['contact_name'] ?: '—') ?></td>
                    <td><?= e($h['sentiment'] ?: '—') ?></td>
                    <td class="small"><?= nl2br(e($h['notes'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Rechte Spalte: Kontakt + Call-Metadaten -->
    <div class="col-12 col-lg-4">
      <!-- Call-Meta -->
      <div class="card shadow-sm sticky-col">
        <div class="card-body">
          <h2 class="h6 mb-3">Call-Einstellungen</h2>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_call_meta">

            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php
                  $st = $call['status'] ?? 'ok';
                  foreach (['ok'=>'OK (kein besonderer Status)','callback'=>'Rückruf planen','do_not_call'=>'Nicht mehr anrufen'] as $k=>$lbl):
                ?>
                  <option value="<?= e($k) ?>" <?= $st===$k?'selected':'' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-7">
              <label class="form-label">Rückruf Datum</label>
              <input type="date" name="callback_date" class="form-control" value="<?= e(substr((string)($call['callback_at'] ?? ''),0,10)) ?>">
            </div>
            <div class="col-5">
              <label class="form-label">Uhrzeit</label>
              <input type="time" name="callback_time" class="form-control" value="<?= e(substr((string)($call['callback_at'] ?? ''),11,5)) ?>">
            </div>

            <div class="col-12">
              <label class="form-label">Stimmung</label>
              <input type="text" name="sentiment" class="form-control" value="<?= e($call['sentiment'] ?? '') ?>" placeholder='z. B. "war sehr nett"'>
            </div>

            <div class="col-12">
              <label class="form-label">Gesprächs-Notizen</label>
              <textarea name="notes" class="form-control" rows="3"><?= e($call['notes'] ?? '') ?></textarea>
            </div>

            <div class="col-12 d-grid">
              <button class="btn btn-primary">Speichern</button>
            </div>
          </form>
          <hr>
          <!-- Kontakte zuordnen/neu anlegen -->
          <h2 class="h6 mb-2">Ansprechpartner</h2>
          <?php if(!empty($contacts)): ?>
            <form method="post" class="d-flex gap-2 mb-3">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="link_contact">
              <select name="contact_id" class="form-select">
                <?php foreach($contacts as $ct): ?>
                  <option value="<?= (int)$ct['id'] ?>">
                    <?= e(trim(($ct['salutation']?'('.$ct['salutation'].') ':'').$ct['first_name'].' '.$ct['last_name'].' · '.$ct['position'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline-secondary">Zuordnen</button>
            </form>
          <?php endif; ?>

          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_contact">
            <div class="col-4">
              <label class="form-label">Anrede</label>
              <input type="text" name="salutation" class="form-control" placeholder="Herr/Frau">
            </div>
            <div class="col-8">
              <label class="form-label">Position</label>
              <input type="text" name="position" class="form-control" placeholder="z. B. GF, Technikleitung">
            </div>
            <div class="col-6">
              <label class="form-label">Vorname</label>
              <input type="text" name="first_name" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Nachname</label>
              <input type="text" name="last_name" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">E-Mail</label>
              <input type="email" name="email_personal" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Telefon direkt</label>
              <input type="text" name="phone_direct" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Durchwahl</label>
              <input type="text" name="phone_ext" class="form-control">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-outline-primary">Ansprechpartner anlegen</button>
            </div>
          </form>

          <hr>
          <div class="small">
            <div><strong>Firma</strong>: <?= e($company['name']) ?></div>
            <?php if(!empty($company['phone_general'])): ?><div>Tel: <?= e($company['phone_general']) ?></div><?php endif; ?>
            <?php if(!empty($company['email_general'])): ?><div>E-Mail: <?= e($company['email_general']) ?></div><?php endif; ?>
            <?php if(!empty($company['website'])): ?><div>Web: <a href="<?= e($company['website']) ?>" target="_blank"><?= e($company['website']) ?></a></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX-Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
