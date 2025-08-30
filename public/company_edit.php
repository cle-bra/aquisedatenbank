<?php
// public/company_edit.php – Firma komplett bearbeiten + verknüpfte Personen + Firmen-Bilder (Mehrfach-Upload)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }
function parse_int_or_null($v){ $v=trim((string)$v); return $v===''? null : (int)$v; }
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ http_response_code(400); echo 'Ungültige ID'; exit; }

// Firma laden
$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=:id');
$stmt->execute([':id'=>$id]);
$co=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$co){ http_response_code(404); echo 'Firma nicht gefunden'; exit; }

$errors=[];

/* -----------------------------------------------------------
   POST: Firma speichern (alle Felder)
----------------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_company'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}

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
  $created_at_edit = parse_dt_local_or_null($_POST['created_at'] ?? '');
  $updated_at_edit = parse_dt_local_or_null($_POST['updated_at'] ?? '');

  if($name===''){ $errors[]='Firmenname darf nicht leer sein.'; }

  if(empty($errors)){
    try{
      $sets = [
        'name=:name','street=:street','zip=:zip','city=:city','state=:state','industry=:industry',
        'business_purpose=:business_purpose','legal_form=:legal_form','register_court=:register_court','register_number=:register_number',
        'employees=:employees','revenue=:revenue','size_class=:size_class','external_id=:external_id',
        'website=:website','email_general=:email_general','phone_general=:phone_general'
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
        ':phone_general'=>$phone_general!==''?$phone_general:null,
        ':id'=>$id
      ];
      if ($created_at_edit){ $sets[]='created_at=:created_at'; $params[':created_at']=$created_at_edit; }
      if ($updated_at_edit){ $sets[]='updated_at=:updated_at'; $params[':updated_at']=$updated_at_edit; }

      $sql='UPDATE companies SET '.implode(', ',$sets).' WHERE id=:id';
      $stmt=$pdo->prepare($sql);
      $stmt->execute($params);

      flash('success','Firma aktualisiert.');
      header('Location: /public/company_edit.php?id='.$id); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}

/* -----------------------------------------------------------
   Bilder – Optionen & Pfade
   (Mehrfach-Upload, keine Auto-Primär-Logik)
----------------------------------------------------------- */
$imagesEnabled = false;
try { $pdo->query("SELECT 1 FROM company_images LIMIT 1"); $imagesEnabled = true; } catch(Throwable $e){}

$UPLOAD_WEB_BASE = '/uploads/company_images';
$UPLOAD_FS_BASE  = rtrim(__DIR__, '/').$UPLOAD_WEB_BASE; // /.../public/uploads/company_images

/* -----------------------------------------------------------
   POST: Mehrfach-Upload
----------------------------------------------------------- */
if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload_company_images'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  if (empty($errors)) {
    $ALLOWED_EXT  = ['jpg','jpeg','png','webp'];
    $ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];
    $MAX_BYTES    = 16 * 1024 * 1024;

    $imgSuccess=0; $imgFail=0;
    // Sort-Basis
    $baseSort = 0;
    try { $baseSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM company_images WHERE company_id=".$id)->fetchColumn(); } catch(Throwable $e){ $baseSort=0; }
    $nextSort = $baseSort + 10;

    $files = $_FILES['images'] ?? null;
    $titles= $_POST['img_title'] ?? [];
    $alts  = $_POST['img_alt'] ?? [];
    $sorts = $_POST['img_sort'] ?? [];

    if ($files && is_array($files['name'])) {
      $count = count($files['name']);
      for($i=0;$i<$count;$i++){
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err===UPLOAD_ERR_NO_FILE) continue;
        if ($err!==UPLOAD_ERR_OK){ $imgFail++; continue; }

        $size = (int)($files['size'][$i] ?? 0);
        $tmp  = $files['tmp_name'][$i] ?? '';
        $orig = $files['name'][$i] ?? '';
        if ($size<=0 || $size>$MAX_BYTES){ $imgFail++; continue; }

        $mime = function_exists('mime_content_type') ? @mime_content_type($tmp) : null;
        if ($mime && !in_array($mime,$ALLOWED_MIME,true)){ $imgFail++; continue; }

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext,$ALLOWED_EXT,true)){ $imgFail++; continue; }

        $subdir = date('Y/m');
        $targetDir = $UPLOAD_FS_BASE . '/' . $subdir;
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
        if (!is_dir($targetDir) || !is_writable($targetDir)){ $imgFail++; continue; }

        $fname = 'co'.$id.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
        $dest  = $targetDir.'/'.$fname;
        if (!@move_uploaded_file($tmp, $dest)){ $imgFail++; continue; }

        $relWeb = $UPLOAD_WEB_BASE . '/' . $subdir . '/' . $fname;
        $title = trim($titles[$i] ?? '');
        $alt   = trim($alts[$i] ?? '');
        $sort  = parse_int_or_null($sorts[$i] ?? '');
        if ($sort===null){ $sort=$nextSort; $nextSort+=10; }

        try{
          $stmt=$pdo->prepare('INSERT INTO company_images (company_id, file_path, title, alt_text, is_primary, sort_order, created_by) VALUES (:c,:p,:t,:a,0,:s,:u)');
          $stmt->execute([
            ':c'=>$id, ':p'=>$relWeb, ':t'=>($title!==''?$title:null), ':a'=>($alt!==''?$alt:null),
            ':s'=>$sort, ':u'=>($_SESSION['user']['id'] ?? null)
          ]);
          $imgSuccess++;
        }catch(Throwable $e){ $imgFail++; }
      }
    }
    if ($imgSuccess>0) flash('success', "Bilder hochgeladen: {$imgSuccess}".($imgFail>0?" (Fehler: {$imgFail})":''));
    elseif ($imgFail>0) flash('error', "Bilder-Upload fehlgeschlagen ({$imgFail}).");

    header('Location: /public/company_edit.php?id='.$id.'#images'); exit;
  }
}

/* -----------------------------------------------------------
   POST: Primär setzen / Metadaten speichern / Löschen
----------------------------------------------------------- */
if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='make_primary'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  if ($imgId>0 && empty($errors)) {
    try{
      $stmt=$pdo->prepare('SELECT id FROM company_images WHERE id=:i AND company_id=:c');
      $stmt->execute([':i'=>$imgId, ':c'=>$id]);
      if ($stmt->fetch()){
        $pdo->prepare('UPDATE company_images SET is_primary=0 WHERE company_id=:c')->execute([':c'=>$id]);
        $pdo->prepare('UPDATE company_images SET is_primary=1 WHERE id=:i')->execute([':i'=>$imgId]);
        flash('success','Primärbild gesetzt.');
      }
    }catch(Throwable $e){ $errors[]='Fehler beim Setzen des Primärbildes: '.$e->getMessage(); }
  }
  header('Location: /public/company_edit.php?id='.$id.'#images'); exit;
}

if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_image_meta'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  $title = trim($_POST['title'] ?? '');
  $alt   = trim($_POST['alt_text'] ?? '');
  $sort  = parse_int_or_null($_POST['sort_order'] ?? '');
  if ($imgId>0 && empty($errors)) {
    try{
      $stmt=$pdo->prepare('UPDATE company_images SET title=:t, alt_text=:a, sort_order=:s WHERE id=:i AND company_id=:c');
      $stmt->execute([':t'=>$title!==''?$title:null, ':a'=>$alt!==''?$alt:null, ':s'=>$sort, ':i'=>$imgId, ':c'=>$id]);
      flash('success','Bild-Metadaten aktualisiert.');
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern der Bilddaten: '.$e->getMessage(); }
  }
  header('Location: /public/company_edit.php?id='.$id.'#images'); exit;
}

if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_image'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  if ($imgId>0 && empty($errors)) {
    try{
      $stmt=$pdo->prepare('SELECT file_path FROM company_images WHERE id=:i AND company_id=:c');
      $stmt->execute([':i'=>$imgId, ':c'=>$id]);
      $row=$stmt->fetch(PDO::FETCH_ASSOC);
      if ($row){
        $fileWeb = (string)$row['file_path'];              // /uploads/company_images/...
        $abs  = realpath(__DIR__ . $fileWeb);              // /.../public/uploads/company_images/...
        $base = realpath(__DIR__ . $UPLOAD_WEB_BASE);      // /.../public/uploads/company_images
        if ($abs && $base && strpos($abs, $base)===0 && is_file($abs)) { @unlink($abs); }
        $pdo->prepare('DELETE FROM company_images WHERE id=:i')->execute([':i'=>$imgId]);
        flash('success','Bild gelöscht.');
      }
    }catch(Throwable $e){ $errors[]='Fehler beim Löschen des Bildes: '.$e->getMessage(); }
  }
  header('Location: /public/company_edit.php?id='.$id.'#images'); exit;
}

/* -----------------------------------------------------------
   Personen der Firma laden
----------------------------------------------------------- */
$people=[]; try{
  $stmt=$pdo->prepare('SELECT id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext FROM contacts WHERE company_id=:id ORDER BY last_name, first_name');
  $stmt->execute([':id'=>$id]);
  $people=$stmt->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){}

/* -----------------------------------------------------------
   Bilder laden
----------------------------------------------------------- */
$images=[]; $hasImages=false;
if ($imagesEnabled){
  try{
    $stmt=$pdo->prepare('SELECT id, file_path, title, alt_text, is_primary, sort_order, created_at FROM company_images WHERE company_id=:c ORDER BY is_primary DESC, sort_order ASC, id ASC');
    $stmt->execute([':c'=>$id]); $images=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasImages = !empty($images);
  } catch(Throwable $e){}
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Firma bearbeiten · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .img-thumb { width: 120px; height: 90px; object-fit: cover; }
    .badge-primary-flag { background:#0d6efd; }
    .img-meta-grid { display:grid; grid-template-columns: 1fr 1fr 120px; gap:.5rem; }
    .img-meta-grid .file-label { grid-column: 1 / -1; font-size:.875rem; color:#6c757d; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

  <div class="row g-3">
    <!-- Linke Spalte: Firma + Bilder -->
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h1 class="h5 mb-0">Firma bearbeiten</h1>
            <a href="/public/companies.php" class="btn btn-outline-secondary btn-sm">← Zur Liste</a>
          </div>
          <form method="post" class="row g-3 mt-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_company">
            <div class="col-12">
              <label class="form-label">Firmenname *</label>
              <input type="text" name="name" class="form-control" value="<?= e($co['name']) ?>" required>
            </div>

            <div class="col-12 col-md-8">
              <label class="form-label">Straße</label>
              <input type="text" name="street" class="form-control" value="<?= e($co['street']) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">PLZ</label>
              <input type="text" name="zip" class="form-control" value="<?= e($co['zip']) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Stadt</label>
              <input type="text" name="city" class="form-control" value="<?= e($co['city']) ?>">
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Bundesland</label>
              <input type="text" name="state" class="form-control" value="<?= e($co['state']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Branche</label>
              <input type="text" name="industry" class="form-control" value="<?= e($co['industry']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Unternehmenszweck</label>
              <textarea name="business_purpose" class="form-control" rows="3"><?= e($co['business_purpose']) ?></textarea>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Rechtsform</label>
              <input type="text" name="legal_form" class="form-control" value="<?= e($co['legal_form']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Registergericht</label>
              <input type="text" name="register_court" class="form-control" value="<?= e($co['register_court']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Registernummer</label>
              <input type="text" name="register_number" class="form-control" value="<?= e($co['register_number']) ?>">
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">Mitarbeiterzahl</label>
              <input type="number" inputmode="numeric" name="employees" class="form-control" value="<?= e($co['employees']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Umsatz (EUR)</label>
              <input type="text" name="revenue" class="form-control" placeholder="z. B. 1.234,56" value="<?= e($co['revenue']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Größenklasse</label>
              <input type="text" name="size_class" class="form-control" value="<?= e($co['size_class']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Externe ID</label>
              <input type="text" name="external_id" class="form-control" value="<?= e($co['external_id']) ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Webseite</label>
              <input type="url" name="website" class="form-control" value="<?= e($co['website']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">E‑Mail (allgemein)</label>
              <input type="email" name="email_general" class="form-control" value="<?= e($co['email_general']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Telefon (allgemein)</label>
              <input type="text" name="phone_general" class="form-control" value="<?= e($co['phone_general']) ?>">
            </div>

            <div class="col-12">
              <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#systemFields" role="button" aria-expanded="false" aria-controls="systemFields">Systemfelder (optional)</a>
              <div class="collapse mt-2" id="systemFields">
                <div class="row g-3">
                  <?php
                    $fmtCreated = $co['created_at'] ? date('Y-m-d\TH:i', strtotime($co['created_at'])) : '';
                    $fmtUpdated = $co['updated_at'] ? date('Y-m-d\TH:i', strtotime($co['updated_at'])) : '';
                  ?>
                  <div class="col-12 col-md-6">
                    <label class="form-label">Erstellt am</label>
                    <input type="datetime-local" name="created_at" class="form-control" value="<?= e($fmtCreated) ?>">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label">Aktualisiert am</label>
                    <input type="datetime-local" name="updated_at" class="form-control" value="<?= e($fmtUpdated) ?>">
                  </div>
                  <div class="form-text">Leer lassen, um vorhandene Werte zu behalten.</div>
                </div>
              </div>
            </div>

            <div class="col-12 d-grid gap-2 d-sm-flex">
              <button class="btn btn-primary" type="submit">Speichern</button>
              <a href="/public/companies.php" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm" id="images">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Bilder</h2>
          </div>

          <?php if(!$imagesEnabled): ?>
            <div class="alert alert-warning mt-3">
              Die Tabelle <code>company_images</code> existiert noch nicht. (Siehe SQL aus der vorigen Nachricht.)
            </div>
          <?php else: ?>
            <!-- Mehrfach-Upload -->
            <form class="row g-2 align-items-end mt-2" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="upload_company_images">
              <div class="col-12">
                <label class="form-label">Bilder (Mehrfachauswahl)</label>
                <input type="file" id="imagesInput" name="images[]" class="form-control" accept=".jpg,.jpeg,.png,.webp" multiple>
                <div id="imageMetaList" class="mt-2"></div>
                <div class="form-text">Max. 16 MB pro Datei. Erlaubt: JPG/PNG/WebP.</div>
              </div>
              <div class="col-12">
                <button class="btn btn-sm btn-outline-primary">Hochladen</button>
              </div>
            </form>

            <!-- Bestehende Bilder -->
            <div class="row g-3 mt-3">
              <?php if(empty($images)): ?>
                <div class="col-12 text-muted">Noch keine Bilder vorhanden.</div>
              <?php else: foreach($images as $img): ?>
                <div class="col-12 col-md-6 col-lg-4">
                  <div class="border rounded p-2 h-100">
                    <div class="d-flex align-items-start gap-2">
                      <img src="<?= e($img['file_path']) ?>" alt="<?= e($img['alt_text'] ?? '') ?>" class="img-thumb rounded">
                      <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                          <div class="small text-muted">#<?= (int)$img['id'] ?> · <?= e(date('d.m.Y H:i', strtotime($img['created_at']))) ?></div>
                          <?php if($img['is_primary']): ?><span class="badge badge-primary-flag">Primär</span><?php endif; ?>
                        </div>
                        <form class="mt-1" method="post">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="save_image_meta">
                          <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                          <div class="mb-1">
                            <input type="text" name="title" class="form-control form-control-sm" placeholder="Titel" value="<?= e($img['title']) ?>">
                          </div>
                          <div class="mb-1">
                            <input type="text" name="alt_text" class="form-control form-control-sm" placeholder="Alt‑Text" value="<?= e($img['alt_text']) ?>">
                          </div>
                          <div class="d-flex align-items-center gap-2">
                            <input type="number" name="sort_order" class="form-control form-control-sm" style="max-width:120px" placeholder="Sort." value="<?= e($img['sort_order']) ?>">
                            <button class="btn btn-sm btn-outline-secondary">Speichern</button>
                          </div>
                        </form>
                        <div class="d-flex gap-2 mt-2">
                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="make_primary">
                            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" <?= $img['is_primary']?'disabled':'' ?>>Als Primär</button>
                          </form>
                          <form method="post" onsubmit="return confirm('Bild wirklich löschen?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_image">
                            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Löschen</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Rechte Spalte: Personen -->
    <div class="col-12 col-xl-5">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Zugeordnete Personen (<?= count($people) ?>)</h2>
            <a class="btn btn-sm btn-success" href="/public/contact_add.php?company_id=<?= (int)$id ?>">＋ Person</a>
          </div>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Position</th>
                  <th>E‑Mail</th>
                  <th>Telefon</th>
                  <th style="width:1%"></th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($people)): ?>
                  <tr><td colspan="5" class="text-muted">Noch keine Personen erfasst.</td></tr>
                <?php else: foreach($people as $p): ?>
                  <tr>
                    <td><?= e(trim(($p['salutation']? $p['salutation'].' ':'').($p['first_name'] ?? '').' '.($p['last_name'] ?? ''))) ?: '—' ?></td>
                    <td><?= e($p['position'] ?: '—') ?></td>
                    <td><?php if(!empty($p['email_personal'])): ?><a href="mailto:<?= e($p['email_personal']) ?>"><?= e($p['email_personal']) ?></a><?php else: ?>—<?php endif; ?></td>
                    <td><?= e($p['phone_direct'] ?: ($p['phone_ext'] ?: '—')) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/public/contact_edit.php?id=<?= (int)$p['id'] ?>">Bearbeiten</a></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
