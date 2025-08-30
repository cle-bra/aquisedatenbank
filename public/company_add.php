<?php
// public/company_add.php – Neue Firma anlegen (+ alle Company-Felder, Kontakte, optionale Mehrfach-Bilder)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

function parse_int_or_null($v){ $v=trim((string)$v); return $v===''? null : (int)$v; }
/** "1.234,56" oder "1234.56" -> "1234.56" (max 2 Nachkommastellen) */
function parse_decimal_or_null($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (strpos($s, ',')!==false && strpos($s, '.')!==false){
    if (strrpos($s, ',') > strrpos($s, '.')) { $s=str_replace('.', '', $s); $s=str_replace(',', '.', $s); }
    else { $s=str_replace(',', '', $s); }
  } elseif (strpos($s, ',')!==false){ $s=str_replace(',', '.', $s); }
  if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) return null;
  return $s;
}
function parse_dt_local_or_null($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  $ts = strtotime($s);
  return $ts? date('Y-m-d H:i:s',$ts) : null;
}

/** Bilder-Feature nur, wenn Tabelle existiert */
$imagesEnabled = false;
try { $pdo->query("SELECT 1 FROM company_images LIMIT 1"); $imagesEnabled=true; } catch(Throwable $e){}

/** Upload-Pfade (Web + Filesystem) */
$UPLOAD_WEB_BASE = '/uploads/company_images';
$UPLOAD_FS_BASE  = rtrim(__DIR__, '/').$UPLOAD_WEB_BASE; // /path/to/app/public/uploads/company_images

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}

  // --- Firmenfelder (alle aus Tabelle companies) ---
  $name            = trim($_POST['name'] ?? '');
  $street          = trim($_POST['street'] ?? '');
  $zip             = trim($_POST['zip'] ?? '');
  $city            = trim($_POST['city'] ?? '');
  $state           = trim($_POST['state'] ?? '');
  $industry        = trim($_POST['industry'] ?? '');
  $business_purpose= trim($_POST['business_purpose'] ?? '');
  $legal_form      = trim($_POST['legal_form'] ?? '');
  $register_court  = trim($_POST['register_court'] ?? '');
  $register_number = trim($_POST['register_number'] ?? '');
  $employees       = parse_int_or_null($_POST['employees'] ?? '');
  $revenue         = parse_decimal_or_null($_POST['revenue'] ?? '');
  $size_class      = trim($_POST['size_class'] ?? '');
  $external_id     = trim($_POST['external_id'] ?? '');
  $website         = trim($_POST['website'] ?? '');
  $email_general   = trim($_POST['email_general'] ?? '');
  $phone_general   = trim($_POST['phone_general'] ?? '');

  // Systemfelder (optional manuell setzen)
  $created_at_edit = parse_dt_local_or_null($_POST['created_at'] ?? '');
  $updated_at_edit = parse_dt_local_or_null($_POST['updated_at'] ?? '');

  // Kontakte (Arrays aus dynamischer Tabelle)
  $c_sal = $_POST['c_salutation'] ?? [];
  $c_fn  = $_POST['c_first_name'] ?? [];
  $c_ln  = $_POST['c_last_name'] ?? [];
  $c_pos = $_POST['c_position'] ?? [];
  $c_em  = $_POST['c_email'] ?? [];
  $c_ph  = $_POST['c_phone'] ?? [];
  $c_ext = $_POST['c_ext'] ?? [];

  if($name===''){ $errors[]='Firmenname darf nicht leer sein.'; }

  if(empty($errors)){
    try{
      $pdo->beginTransaction();

      // Insert Company (nur Felder setzen, die wir liefern; NULL ist ok)
      $cols = [
        'name','street','zip','city','state','industry',
        'business_purpose','legal_form','register_court','register_number',
        'employees','revenue','size_class','external_id',
        'website','email_general','phone_general'
      ];
      $ph   = [
        ':name',':street',':zip',':city',':state',':industry',
        ':business_purpose',':legal_form',':register_court',':register_number',
        ':employees',':revenue',':size_class',':external_id',
        ':website',':email_general',':phone_general'
      ];
      $params = [
        ':name'=>$name, ':street'=>$street!==''?$street:null, ':zip'=>$zip!==''?$zip:null, ':city'=>$city!==''?$city:null,
        ':state'=>$state!==''?$state:null, ':industry'=>$industry!==''?$industry:null,
        ':business_purpose'=>$business_purpose!==''?$business_purpose:null,
        ':legal_form'=>$legal_form!==''?$legal_form:null,
        ':register_court'=>$register_court!==''?$register_court:null,
        ':register_number'=>$register_number!==''?$register_number:null,
        ':employees'=>$employees, ':revenue'=>$revenue,
        ':size_class'=>$size_class!==''?$size_class:null,
        ':external_id'=>$external_id!==''?$external_id:null,
        ':website'=>$website!==''?$website:null,
        ':email_general'=>$email_general!==''?$email_general:null,
        ':phone_general'=>$phone_general!==''?$phone_general:null
      ];
      if ($created_at_edit){ $cols[]='created_at'; $ph[]=':created_at'; $params[':created_at']=$created_at_edit; }
      if ($updated_at_edit){ $cols[]='updated_at'; $ph[]=':updated_at'; $params[':updated_at']=$updated_at_edit; }

      $sql = 'INSERT INTO companies ('.implode(',',$cols).') VALUES ('.implode(',',$ph).')';
      $stmt=$pdo->prepare($sql); $stmt->execute($params);
      $newId = (int)$pdo->lastInsertId();

      // Kontakte speichern (nur sinnvolle Zeilen)
      if (is_array($c_ln) || is_array($c_em) || is_array($c_fn)){
        $ins = $pdo->prepare('INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext) VALUES (:cid,:sal,:fn,:ln,:pos,:em,:ph,:ext)');
        $rows = max(count($c_sal), count($c_fn), count($c_ln), count($c_pos), count($c_em), count($c_ph), count($c_ext));
        for($i=0; $i<$rows; $i++){
          $sal = trim($c_sal[$i] ?? '');
          $fn  = trim($c_fn[$i]  ?? '');
          $ln  = trim($c_ln[$i]  ?? '');
          $pos = trim($c_pos[$i] ?? '');
          $em  = trim($c_em[$i]  ?? '');
          $phn = trim($c_ph[$i]  ?? '');
          $ext = trim($c_ext[$i]  ?? '');
          if ($ln!=='' || $em!=='' || $fn!==''){
            $ins->execute([':cid'=>$newId, ':sal'=>$sal!==''?$sal:null, ':fn'=>$fn!==''?$fn:null, ':ln'=>$ln!==''?$ln:null, ':pos'=>$pos!==''?$pos:null, ':em'=>$em!==''?$em:null, ':ph'=>$phn!==''?$phn:null, ':ext'=>$ext!==''?$ext:null]);
          }
        }
      }

      $pdo->commit();

      // ---- Bilder-Upload (optional, nicht in der DB-Transaktion) ----
      $imgSuccess=0; $imgFail=0;
      if ($imagesEnabled && isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $ALLOWED_EXT  = ['jpg','jpeg','png','webp'];
        $ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];
        $MAX_BYTES    = 16 * 1024 * 1024; // 16 MB

        // Basissortierung ermitteln, dann je Bild +10
        $baseSort = 0;
        try { $baseSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM company_images WHERE company_id=".$newId)->fetchColumn(); } catch(Throwable $e){ $baseSort = 0; }
        $nextSort = $baseSort + 10;

        $count = count($_FILES['images']['name']);
        for($i=0;$i<$count;$i++){
          $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
          if ($err===UPLOAD_ERR_NO_FILE) continue;
          if ($err!==UPLOAD_ERR_OK){ $imgFail++; continue; }

          $size = (int)($_FILES['images']['size'][$i] ?? 0);
          $tmp  = $_FILES['images']['tmp_name'][$i] ?? '';
          $orig = $_FILES['images']['name'][$i] ?? '';
          if ($size<=0 || $size>$MAX_BYTES){ $imgFail++; continue; }

          $mime = function_exists('mime_content_type') ? @mime_content_type($tmp) : null;
          if ($mime && !in_array($mime,$ALLOWED_MIME,true)){ $imgFail++; continue; }

          $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if (!in_array($ext,$ALLOWED_EXT,true)){ $imgFail++; continue; }

          // Zielordner
          $subdir = date('Y/m');
          $targetDir = $UPLOAD_FS_BASE . '/' . $subdir;
          if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
          if (!is_dir($targetDir) || !is_writable($targetDir)){ $imgFail++; continue; }

          $fname = 'co'.$newId.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
          $dest  = $targetDir.'/'.$fname;
          if (!@move_uploaded_file($tmp, $dest)){ $imgFail++; continue; }

          $relWeb = $UPLOAD_WEB_BASE . '/' . $subdir . '/' . $fname;
          $title = trim($_POST['img_title'][$i] ?? '');
          $alt   = trim($_POST['img_alt'][$i] ?? '');
          $sort  = parse_int_or_null($_POST['img_sort'][$i] ?? '');
          if ($sort===null){ $sort=$nextSort; $nextSort+=10; }

          try{
            $stmt=$pdo->prepare('INSERT INTO company_images (company_id, file_path, title, alt_text, is_primary, sort_order, created_by) VALUES (:c,:p,:t,:a,0,:s,:u)');
            $stmt->execute([
              ':c'=>$newId, ':p'=>$relWeb, ':t'=>($title!==''?$title:null), ':a'=>($alt!==''?$alt:null),
              ':s'=>$sort, ':u'=>($_SESSION['user']['id'] ?? null)
            ]);
            $imgSuccess++;
          }catch(Throwable $e){ $imgFail++; }
        }
      }

      $msg = "Firma angelegt.";
      // Kontakte grob mitzählen
      $cntContacts = 0;
      if (is_array($c_ln) || is_array($c_em) || is_array($c_fn)){
        $rows = max(count($c_sal), count($c_fn), count($c_ln), count($c_pos), count($c_em), count($c_ph), count($c_ext));
        for($i=0; $i<$rows; $i++){
          $fn=trim($c_fn[$i]??''); $ln=trim($c_ln[$i]??''); $em=trim($c_em[$i]??'');
          if ($ln!=='' || $em!=='' || $fn!=='') $cntContacts++;
        }
      }
      if ($cntContacts>0) $msg .= " Personen: {$cntContacts}.";
      if ($imagesEnabled) $msg .= " Bilder hochgeladen: {$imgSuccess}".($imgFail>0?" (Fehler: {$imgFail})":'');

      flash('success',$msg);
      header('Location: /public/company_edit.php?id='.$newId); exit;

    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='Fehler beim Anlegen: '.$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Neue Firma anlegen · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .row-actions button{ opacity:.7 }
    .row-actions button:hover{ opacity:1 }
    .img-meta-grid { display:grid; grid-template-columns: 1fr 1fr 120px; gap:.5rem; }
    .img-meta-grid .file-label { grid-column: 1 / -1; font-size:.875rem; color:#6c757d; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>

  <div class="row g-3">
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h1 class="h5 mb-3">Neue Firma anlegen</h1>
          <form id="companyForm" method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="col-12">
              <label class="form-label">Firmenname *</label>
              <input type="text" name="name" class="form-control" required>
            </div>

            <div class="col-12 col-md-8">
              <label class="form-label">Straße</label>
              <input type="text" name="street" class="form-control">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">PLZ</label>
              <input type="text" name="zip" class="form-control">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Stadt</label>
              <input type="text" name="city" class="form-control">
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Bundesland</label>
              <input type="text" name="state" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Branche</label>
              <input type="text" name="industry" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Unternehmenszweck</label>
              <textarea name="business_purpose" class="form-control" rows="3"></textarea>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Rechtsform</label>
              <input type="text" name="legal_form" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Registergericht</label>
              <input type="text" name="register_court" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Registernummer</label>
              <input type="text" name="register_number" class="form-control">
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Mitarbeiterzahl</label>
              <input type="number" inputmode="numeric" name="employees" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Umsatz (EUR)</label>
              <input type="text" name="revenue" class="form-control" placeholder="z. B. 1.234,56">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Größenklasse</label>
              <input type="text" name="size_class" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Externe ID</label>
              <input type="text" name="external_id" class="form-control">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Webseite</label>
              <input type="url" name="website" class="form-control" placeholder="https://…">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">E‑Mail (allgemein)</label>
              <input type="email" name="email_general" class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Telefon (allgemein)</label>
              <input type="text" name="phone_general" class="form-control">
            </div>

            <div class="col-12">
              <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#systemFields" role="button" aria-expanded="false" aria-controls="systemFields">Systemfelder (optional)</a>
              <div class="collapse mt-2" id="systemFields">
                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label">Erstellt am</label>
                    <input type="datetime-local" name="created_at" class="form-control">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label">Aktualisiert am</label>
                    <input type="datetime-local" name="updated_at" class="form-control">
                  </div>
                </div>
              </div>
            </div>

            <?php if($imagesEnabled): ?>
              <div class="col-12">
                <label class="form-label">Bilder (optional, Mehrfachauswahl)</label>
                <input type="file" id="imagesInput" name="images[]" class="form-control" accept=".jpg,.jpeg,.png,.webp" multiple>
                <div id="imageMetaList" class="mt-2"></div>
                <div class="form-text">Max. 16 MB pro Datei. Erlaubt: JPG/PNG/WebP.</div>
              </div>
            <?php else: ?>
              <div class="col-12">
                <div class="alert alert-warning small mb-0">Hinweis: Die Tabelle <code>company_images</code> existiert noch nicht – Bilder-Upload ist erst nach Anlage der Tabelle möglich.</div>
              </div>
            <?php endif; ?>

            <div class="col-12 d-grid gap-2 d-sm-flex">
              <button class="btn btn-primary" type="submit">Anlegen</button>
              <a href="/public/companies.php" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Kontakte rechts -->
    <div class="col-12 col-xl-5">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Personen zuordnen</h2>
            <button class="btn btn-sm btn-success" type="button" id="addRow">＋ Zeile</button>
          </div>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle" id="contactsTable">
              <thead>
                <tr>
                  <th style="width:12%">Anrede</th>
                  <th style="width:18%">Vorname</th>
                  <th style="width:18%">Nachname</th>
                  <th style="width:18%">Position</th>
                  <th style="width:18%">E‑Mail</th>
                  <th style="width:12%">Telefon</th>
                  <th style="width:8%">DW</th>
                  <th style="width:1%"></th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><input name="c_salutation[]" form="companyForm" class="form-control form-control-sm" placeholder="Herr/Frau"></td>
                  <td><input name="c_first_name[]" form="companyForm" class="form-control form-control-sm"></td>
                  <td><input name="c_last_name[]" form="companyForm" class="form-control form-control-sm"></td>
                  <td><input name="c_position[]" form="companyForm" class="form-control form-control-sm"></td>
                  <td><input name="c_email[]" form="companyForm" type="email" class="form-control form-control-sm"></td>
                  <td><input name="c_phone[]" form="companyForm" class="form-control form-control-sm"></td>
                  <td><input name="c_ext[]" form="companyForm" class="form-control form-control-sm"></td>
                  <td class="row-actions"><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeRow(this)">×</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="text-muted small">Mindestens Nachname, Vorname oder E‑Mail, damit eine Zeile gespeichert wird.</div>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Kontakte: Zeilen hinzufügen/entfernen
  function removeRow(btn){
    const tr = btn.closest('tr'); const tbody = tr.parentNode; tbody.removeChild(tr);
    if(!tbody.querySelector('tr')){ addRow(); }
  }
  function addRow(){
    const tbody = document.querySelector('#contactsTable tbody');
    const tpl = `
    <tr>
      <td><input name="c_salutation[]" form="companyForm" class="form-control form-control-sm" placeholder="Herr/Frau"></td>
      <td><input name="c_first_name[]" form="companyForm" class="form-control form-control-sm"></td>
      <td><input name="c_last_name[]" form="companyForm" class="form-control form-control-sm"></td>
      <td><input name="c_position[]" form="companyForm" class="form-control form-control-sm"></td>
      <td><input name="c_email[]" form="companyForm" type="email" class="form-control form-control-sm"></td>
      <td><input name="c_phone[]" form="companyForm" class="form-control form-control-sm"></td>
      <td><input name="c_ext[]" form="companyForm" class="form-control form-control-sm"></td>
      <td class="row-actions"><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeRow(this)">×</button></td>
    </tr>`;
    tbody.insertAdjacentHTML('beforeend', tpl);
  }
  document.getElementById('addRow').addEventListener('click', addRow);

  // Bilder: pro ausgewählter Datei Felder für Titel/Alt/Sortierung erzeugen
  const imagesInput = document.getElementById('imagesInput');
  if (imagesInput){
    imagesInput.addEventListener('change', function(){
      const list = document.getElementById('imageMetaList');
      list.innerHTML = '';
      const files = Array.from(imagesInput.files || []);
      files.forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'img-meta-grid border rounded p-2 mb-2';
        row.innerHTML = `
          <div class="file-label">Datei: ${f.name}</div>
          <input type="text" name="img_title[]" class="form-control form-control-sm" placeholder="Titel (optional)">
          <input type="text" name="img_alt[]" class="form-control form-control-sm" placeholder="Alt‑Text (optional)">
          <input type="number" name="img_sort[]" class="form-control form-control-sm" placeholder="Sort." title="Sortierreihenfolge">
        `;
        list.appendChild(row);
      });
    });
  }
</script>
</body>
</html>
