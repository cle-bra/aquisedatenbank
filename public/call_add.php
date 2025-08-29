<?php
// public/call_add.php – Telefonat zur Kampagne erfassen
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$agentId = (int)($_SESSION['user']['id'] ?? 0);
$companyId  = isset($_GET['company_id'])  ? (int)$_GET['company_id']  : (int)($_POST['company_id'] ?? 0);
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : (int)($_POST['campaign_id'] ?? 0);

// Firma & Kampagne laden (für Kopfzeile + Validierung)
$company = null; $campaign=null; $contacts=[]; $errors=[];
if ($companyId>0){ $st=$pdo->prepare('SELECT id,name,city FROM companies WHERE id=:id'); $st->execute([':id'=>$companyId]); $company=$st->fetch(); }
if ($campaignId>0){ $st=$pdo->prepare('SELECT id,name,status FROM campaigns WHERE id=:id'); $st->execute([':id'=>$campaignId]); $campaign=$st->fetch(); }
if ($company){
  $st=$pdo->prepare('SELECT id, salutation, first_name, last_name, position, email_personal FROM contacts WHERE company_id=:cid ORDER BY last_name, first_name');
  $st->execute([':cid'=>$companyId]);
  $contacts=$st->fetchAll();
}

if (!$company || !$campaign){ http_response_code(400); echo 'Fehlende oder ungültige Parameter (company_id / campaign_id).'; exit; }

// POST – Call speichern
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { $errors[]='Ungültiges Formular (CSRF).'; }
  $status    = $_POST['status'] ?? 'ok';
  $notes     = trim($_POST['notes'] ?? '');
  $sentiment = trim($_POST['sentiment'] ?? '');
  $contact_id = (int)($_POST['contact_id'] ?? 0);
  $cb_date = trim($_POST['callback_date'] ?? '');
  $cb_time = trim($_POST['callback_time'] ?? '');
  $callback_at = null;
  if ($status==='callback'){
    if ($cb_date!==''){
      $callback_at = $cb_date . ($cb_time!=='' ? ' '.$cb_time.':00' : ' 09:00:00');
    } else {
      $errors[] = 'Bitte Datum (und optional Uhrzeit) für den Rückruf angeben.';
    }
  }

  // Neue Person optional anlegen
  $new_sal = trim($_POST['new_salutation'] ?? '');
  $new_fn  = trim($_POST['new_first_name'] ?? '');
  $new_ln  = trim($_POST['new_last_name'] ?? '');
  $new_pos = trim($_POST['new_position'] ?? '');
  $new_em  = trim($_POST['new_email'] ?? '');
  $new_ph  = trim($_POST['new_phone'] ?? '');
  $new_ext = trim($_POST['new_ext'] ?? '');

  if ($contact_id<=0 && ($new_ln!=='' || $new_em!=='' || $new_fn!=='')){
    // ok – wir legen gleich einen neuen Kontakt an
  } elseif ($contact_id<=0) {
    // weder bestehender noch neuer Kontakt – ist erlaubt, aber Hinweis?
  }

  if (empty($errors)){
    try{
      $pdo->beginTransaction();
      $insertedContactId = null;
      if ($contact_id<=0 && ($new_ln!=='' || $new_em!=='' || $new_fn!=='')){
        $st=$pdo->prepare('INSERT INTO contacts (company_id,salutation,first_name,last_name,position,email_personal,phone_direct,phone_ext) VALUES (:cid,:sal,:fn,:ln,:pos,:em,:ph,:ext)');
        $st->execute([':cid'=>$companyId, ':sal'=>$new_sal, ':fn'=>$new_fn, ':ln'=>$new_ln, ':pos'=>$new_pos, ':em'=>$new_em, ':ph'=>$new_ph, ':ext'=>$new_ext]);
        $insertedContactId = (int)$pdo->lastInsertId();
      }

      $callStmt=$pdo->prepare('INSERT INTO calls (company_id,campaign_id,agent_id,contact_id,new_contact_name,new_contact_position,new_contact_email,new_contact_phone,status,callback_at,notes,sentiment) VALUES (:co,:ca,:ag,:contact_id,:nname,:npos,:nmail,:nphone,:status,:cb,:notes,:sent)');
      $callStmt->execute([
        ':co'=>$companyId,
        ':ca'=>$campaignId,
        ':ag'=>$agentId,
        ':contact_id'=> $contact_id>0? $contact_id : ($insertedContactId ?: null),
        ':nname'=> ($insertedContactId? null : trim(($new_fn.' '.$new_ln))) ?: null,
        ':npos'=> ($insertedContactId? null : ($new_pos ?: null)),
        ':nmail'=> ($insertedContactId? null : ($new_em ?: null)),
        ':nphone'=>($insertedContactId? null : ($new_ph ?: null)),
        ':status'=>$status,
        ':cb'=>$callback_at,
        ':notes'=>$notes ?: null,
        ':sent'=>$sentiment ?: null,
      ]);

      $pdo->commit();
      flash('success','Telefonat gespeichert.');
      if (isset($_POST['save_and_new'])){
        header('Location: /call_add.php?company_id='.$companyId.'&campaign_id='.$campaignId); exit;
      }
      header('Location: /company_edit.php?id='.$companyId); exit;
    }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Telefonat erfassen · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .hint{font-size:.925rem;color:#6c757d}
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h1 class="h5 mb-1">Telefonat erfassen</h1>
          <div class="text-muted">
            Firma: <strong><?= e($company['name']) ?></strong><?= $company['city']? ', '.e($company['city']) : '' ?> · Kampagne: <strong>#<?= (int)$campaign['id'] ?></strong> <?= e($campaign['name']) ?>
          </div>
        </div>
        <div>
          <a href="/company_edit.php?id=<?= (int)$companyId ?>" class="btn btn-outline-secondary btn-sm">← Zur Firma</a>
        </div>
      </div>

      <form method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="company_id" value="<?= (int)$companyId ?>">
        <input type="hidden" name="campaign_id" value="<?= (int)$campaignId ?>">

        <div class="col-12">
          <div class="alert alert-secondary py-2">
            <div class="fw-semibold">Empfohlene Einleitung</div>
            <div class="small">„Guten Tag, mein Name ist <?= e($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '—') ?> vom KNX‑Trainingcenter.com. Wir informieren zurzeit Unternehmen in Berlin über unsere KNX‑Schulungen ab Januar 2026. Dürfte ich fragen, wer bei Ihnen der richtige Ansprechpartner für KNX/Schulungen ist?“</div>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label">Ansprechpartner</label>
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <select name="contact_id" class="form-select">
                <option value="0">— Person auswählen (bereits vorhanden) —</option>
                <?php foreach($contacts as $c): $label=trim(($c['last_name']?:'').' '.($c['first_name']?:'')); $label=$label!==''?$label:($c['email_personal']?:('Kontakt #'.$c['id'])); ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($label) ?><?= $c['position']? ' · '.e($c['position']) : '' ?><?= $c['email_personal']? ' · '.e($c['email_personal']) : '' ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">Oder neuen Ansprechpartner erfassen:</div>
            </div>
            <div class="col-6 col-md-2"><input class="form-control" name="new_salutation" placeholder="Anrede"></div>
            <div class="col-6 col-md-2"><input class="form-control" name="new_first_name" placeholder="Vorname"></div>
            <div class="col-6 col-md-2"><input class="form-control" name="new_last_name" placeholder="Nachname"></div>
            <div class="col-6 col-md-3"><input class="form-control" name="new_position" placeholder="Position"></div>
            <div class="col-12 col-md-3"><input class="form-control" name="new_email" type="email" placeholder="E‑Mail"></div>
            <div class="col-6 col-md-3"><input class="form-control" name="new_phone" placeholder="Telefon direkt"></div>
            <div class="col-6 col-md-2"><input class="form-control" name="new_ext" placeholder="DW"></div>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Status</label>
          <select name="status" id="status" class="form-select">
            <option value="ok">Gespräch geführt / Info hinterlassen</option>
            <option value="callback">Rückruf vereinbart</option>
            <option value="do_not_call">Nie wieder kontaktieren</option>
          </select>
        </div>
        <div class="col-12 col-md-6" id="callbackBox" style="display:none">
          <label class="form-label">Rückruf (Datum/Uhrzeit)</label>
          <div class="row g-2">
            <div class="col-6"><input type="date" name="callback_date" class="form-control"></div>
            <div class="col-6"><input type="time" name="callback_time" class="form-control"></div>
          </div>
          <div class="hint">Wird als Kalendereintrag/Reminder im System gespeichert (Feld <code>callback_at</code>).</div>
        </div>

        <div class="col-12 col-lg-8">
          <label class="form-label">Notizen</label>
          <textarea name="notes" rows="4" class="form-control" placeholder="Kurzprotokoll, z. B. Urlaubsvertretung, KNX-Erfahrung, Budget, …"></textarea>
        </div>
        <div class="col-12 col-lg-4">
          <label class="form-label">Gesprächs‑Eindruck</label>
          <select name="sentiment" class="form-select">
            <option value="">— bitte wählen —</option>
            <option>Sehr nett</option>
            <option>Neutral</option>
            <option>Wir sollten da nachhaken!</option>
            <option>Desinteressiert</option>
          </select>
          <div class="mt-3 small text-muted">Agent: <strong><?= e($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? ('#'.$agentId)) ?></strong></div>
        </div>

        <div class="col-12 d-grid gap-2 d-sm-flex">
          <button class="btn btn-primary" type="submit">Speichern</button>
          <button class="btn btn-outline-primary" type="submit" name="save_and_new" value="1">Speichern & neues Telefonat</button>
          <a class="btn btn-outline-secondary" href="/company_edit.php?id=<?= (int)$companyId ?>">Abbrechen</a>
        </div>
      </form>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const statusSel = document.getElementById('status');
  const cbBox = document.getElementById('callbackBox');
  function toggleCb(){ cbBox.style.display = statusSel.value==='callback' ? '' : 'none'; }
  statusSel.addEventListener('change', toggleCb);
  toggleCb();
</script>
</body>
</html>
