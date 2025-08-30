<?php
// public/contact_edit.php – Kontakt bearbeiten (Stammdaten + Bilder + persönliche Bemerkung + Sympathie-Slider)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

function has_column(PDO $pdo, string $table, string $column): bool {
  try { $stmt=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c"); $stmt->execute([':c'=>$column]); return (bool)$stmt->fetch(); }
  catch(Throwable $e){ return false; }
}
function clamp_int($v,$min,$max){ $v=(int)$v; return max($min, min($max, $v)); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ http_response_code(400); echo 'Ungültige ID'; exit; }

// Feature-Flags (DB vorhanden?)
$imagesEnabled = false;
try { $pdo->query("SELECT 1 FROM person_images LIMIT 1"); $imagesEnabled=true; } catch(Throwable $e){}

$hasNoteColumn     = has_column($pdo,'contacts','personal_note');   // TEXT NULL
$hasSympathyColumn = has_column($pdo,'contacts','sympathy');        // TINYINT NOT NULL DEFAULT 0

// Upload-Pfade (analog company_*): /public/uploads/person_images/...
$UPLOAD_WEB_BASE = '/uploads/person_images';
$UPLOAD_FS_BASE  = rtrim(__DIR__, '/').$UPLOAD_WEB_BASE; // /.../public/uploads/person_images

// Kontakt laden
$stmt=$pdo->prepare('SELECT c.*, co.name AS company_name FROM contacts c LEFT JOIN companies co ON co.id=c.company_id WHERE c.id=:id');
$stmt->execute([':id'=>$id]);
$contact=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$contact){ http_response_code(404); echo 'Kontakt nicht gefunden'; exit; }

$errors=[];

/* -----------------------------------------------------------
   POST: Kontakt speichern (Stammdaten + optional Note/Sympathie)
----------------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? 'save_contact')==='save_contact')){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}

  $sal = trim($_POST['salutation'] ?? '');
  $fn  = trim($_POST['first_name'] ?? '');
  $ln  = trim($_POST['last_name'] ?? '');
  $pos = trim($_POST['position'] ?? '');
  $em  = trim($_POST['email_personal'] ?? '');
  $ph  = trim($_POST['phone_direct'] ?? '');
  $ext = trim($_POST['phone_ext'] ?? '');

  // Optionalfelder
  $note = $hasNoteColumn ? trim($_POST['personal_note'] ?? '') : null;
  $symp = $hasSympathyColumn ? clamp_int($_POST['sympathy'] ?? 0, -5, 5) : null;

  if(empty($errors)){
    try{
      $sets = [
        'salutation=:sal', 'first_name=:fn', 'last_name=:ln',
        'position=:pos', 'email_personal=:em', 'phone_direct=:ph', 'phone_ext=:ext'
      ];
      $params = [
        ':sal'=>$sal!==''?$sal:null, ':fn'=>$fn!==''?$fn:null, ':ln'=>$ln!==''?$ln:null,
        ':pos'=>$pos!==''?$pos:null, ':em'=>$em!==''?$em:null, ':ph'=>$ph!==''?$ph:null,
        ':ext'=>$ext!==''?$ext:null, ':id'=>$id
      ];
      if ($hasNoteColumn){     $sets[]='personal_note=:note'; $params[':note']=($note!==''?$note:null); }
      if ($hasSympathyColumn){ $sets[]='sympathy=:symp';      $params[':symp']=$symp; }

      $sql='UPDATE contacts SET '.implode(', ',$sets).' WHERE id=:id';
      $stmt=$pdo->prepare($sql);
      $stmt->execute($params);

      flash('success','Kontakt aktualisiert.');
      header('Location: /public/contact_edit.php?id='.$id); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}

/* -----------------------------------------------------------
   Bilder: Mehrfach-Upload / Primär setzen / Metadaten speichern / Löschen
----------------------------------------------------------- */
if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? '')==='upload_person_images')){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  if(empty($errors)){
    $ALLOWED_EXT  = ['jpg','jpeg','png','webp'];
    $ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];
    $MAX_BYTES    = 16 * 1024 * 1024;

    $imgSuccess=0; $imgFail=0;
    $baseSort = 0;
    try { $baseSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM person_images WHERE contact_id=".$id)->fetchColumn(); } catch(Throwable $e){ $baseSort=0; }
    $nextSort = $baseSort + 10;

    $files = $_FILES['images'] ?? null;
    $titles= $_POST['img_title'] ?? [];
    $alts  = $_POST['img_alt'] ?? [];
    $sorts = $_POST['img_sort'] ?? [];

    if ($files && is_array($files['name'])){
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

        $subdir   = date('Y/m');
        $targetDir= $UPLOAD_FS_BASE.'/'.$subdir;
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
        if (!is_dir($targetDir) || !is_writable($targetDir)){ $imgFail++; continue; }

        $fname = 'p'.$id.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
        $dest  = $targetDir.'/'.$fname;
        if (!@move_uploaded_file($tmp,$dest)){ $imgFail++; continue; }

        $relWeb = $UPLOAD_WEB_BASE.'/'.$subdir.'/'.$fname;
        $title = trim($titles[$i] ?? '');
        $alt   = trim($alts[$i] ?? '');
        $sort  = is_numeric($sorts[$i] ?? null) ? (int)$sorts[$i] : $nextSort;
        $nextSort += 10;

        try{
          $stmt=$pdo->prepare('INSERT INTO person_images (contact_id, file_path, title, alt_text, is_primary, sort_order, created_by) VALUES (:c,:p,:t,:a,0,:s,:u)');
          $stmt->execute([':c'=>$id, ':p'=>$relWeb, ':t'=>($title!==''?$title:null), ':a'=>($alt!==''?$alt:null), ':s'=>$sort, ':u'=>($_SESSION['user']['id'] ?? null)]);
          $imgSuccess++;
        }catch(Throwable $e){ $imgFail++; }
      }
    }
    if ($imgSuccess>0) flash('success',"Bilder hochgeladen: {$imgSuccess}".($imgFail>0?" (Fehler: {$imgFail})":''));
    elseif ($imgFail>0) flash('error',"Bilder-Upload fehlgeschlagen ({$imgFail}).");

    header('Location: /public/contact_edit.php?id='.$id.'#images'); exit;
  }
}

if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? '')==='make_primary')){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  if ($imgId>0 && empty($errors)){
    try{
      $stmt=$pdo->prepare('SELECT id FROM person_images WHERE id=:i AND contact_id=:c');
      $stmt->execute([':i'=>$imgId, ':c'=>$id]);
      if ($stmt->fetch()){
        $pdo->prepare('UPDATE person_images SET is_primary=0 WHERE contact_id=:c')->execute([':c'=>$id]);
        $pdo->prepare('UPDATE person_images SET is_primary=1 WHERE id=:i')->execute([':i'=>$imgId]);
        flash('success','Primärbild gesetzt.');
      }
    }catch(Throwable $e){ $errors[]='Fehler beim Setzen des Primärbildes: '.$e->getMessage(); }
  }
  header('Location: /public/contact_edit.php?id='.$id.'#images'); exit;
}

if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? '')==='save_image_meta')){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  $title = trim($_POST['title'] ?? '');
  $alt   = trim($_POST['alt_text'] ?? '');
  $sort  = is_numeric($_POST['sort_order'] ?? null) ? (int)$_POST['sort_order'] : null;
  if ($imgId>0 && empty($errors)){
    try{
      $stmt=$pdo->prepare('UPDATE person_images SET title=:t, alt_text=:a, sort_order=:s WHERE id=:i AND contact_id=:c');
      $stmt->execute([':t'=>($title!==''?$title:null), ':a'=>($alt!==''?$alt:null), ':s'=>$sort, ':i'=>$imgId, ':c'=>$id]);
      flash('success','Bild-Metadaten aktualisiert.');
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern der Bilddaten: '.$e->getMessage(); }
  }
  header('Location: /public/contact_edit.php?id='.$id.'#images'); exit;
}

if($imagesEnabled && $_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? '')==='delete_image')){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $imgId = (int)($_POST['image_id'] ?? 0);
  if ($imgId>0 && empty($errors)){
    try{
      $stmt=$pdo->prepare('SELECT file_path FROM person_images WHERE id=:i AND contact_id=:c');
      $stmt->execute([':i'=>$imgId, ':c'=>$id]);
      if ($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        $fileWeb = (string)$row['file_path']; // /uploads/person_images/...
        $abs  = realpath(__DIR__.$fileWeb);
        $base = realpath(__DIR__.$UPLOAD_WEB_BASE);
        if ($abs && $base && strpos($abs,$base)===0 && is_file($abs)) { @unlink($abs); }
        $pdo->prepare('DELETE FROM person_images WHERE id=:i')->execute([':i'=>$imgId]);
        flash('success','Bild gelöscht.');
      }
    }catch(Throwable $e){ $errors[]='Fehler beim Löschen des Bildes: '.$e->getMessage(); }
  }
  header('Location: /public/contact_edit.php?id='.$id.'#images'); exit;
}

/* -----------------------------------------------------------
   Bilder laden
----------------------------------------------------------- */
$images=[]; $primaryImg=null;
if ($imagesEnabled){
  try{
    $stmt=$pdo->prepare('SELECT id, file_path, title, alt_text, is_primary, sort_order, created_at FROM person_images WHERE contact_id=:c ORDER BY is_primary DESC, sort_order ASC, id ASC');
    $stmt->execute([':c'=>$id]); $images=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($images as $img){ if((int)$img['is_primary']===1){ $primaryImg=$img; break; } }
    if(!$primaryImg && !empty($images)) $primaryImg=$images[0];
  } catch(Throwable $e){}
}

// Werte für optional Felder (falls Spalten fehlen -> neutrale Defaults)
$personalNote = $hasNoteColumn ? ($contact['personal_note'] ?? '') : '';
$sympathyVal  = $hasSympathyColumn ? clamp_int($contact['sympathy'] ?? 0, -5, 5) : 0;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kontakt bearbeiten · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .avatar { width:64px; height:64px; object-fit:cover; border-radius:50%; }
    .img-thumb { width: 120px; height: 90px; object-fit: cover; }
    .img-meta-grid { display:grid; grid-template-columns: 1fr 1fr 120px; gap:.5rem; }
    .img-meta-grid .file-label { grid-column: 1 / -1; font-size:.875rem; color:#6c757d; }
    .range-labels { display:flex; justify-content:space-between; font-size:.875rem; color:#6c757d; }
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

  <div class="row g-3">
    <!-- Linke Spalte: Stammdaten + Note/Sympathie -->
    <div class="col-12 col-xl-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h1 class="h5 mb-0">Kontakt bearbeiten</h1>
            <?php if($primaryImg): ?>
              <img class="avatar" src="<?= e($primaryImg['file_path']) ?>" alt="<?= e($primaryImg['alt_text'] ?? '') ?>">
            <?php endif; ?>
          </div>

          <form method="post" class="row g-3 mt-2">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_contact">

            <div class="col-12">
              <label class="form-label">Firma</label>
              <input type="text" class="form-control" value="<?= e($contact['company_name'] ?? '—') ?>" disabled>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Anrede</label>
              <input type="text" name="salutation" class="form-control" value="<?= e($contact['salutation']) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Vorname</label>
              <input type="text" name="first_name" class="form-control" value="<?= e($contact['first_name']) ?>">
            </div>
            <div class="col-12 col-md-5">
              <label class="form-label">Nachname</label>
              <input type="text" name="last_name" class="form-control" value="<?= e($contact['last_name']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Position</label>
              <input type="text" name="position" class="form-control" value="<?= e($contact['position']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">E‑Mail</label>
              <input type="email" name="email_personal" class="form-control" value="<?= e($contact['email_personal']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Telefon direkt</label>
              <input type="text" name="phone_direct" class="form-control" value="<?= e($contact['phone_direct']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Durchwahl</label>
              <input type="text" name="phone_ext" class="form-control" value="<?= e($contact['phone_ext']) ?>">
            </div>

            <!-- Persönliche Bemerkung -->
            <div class="col-12">
              <label class="form-label">Persönliche Bemerkung</label>
              <?php if($hasNoteColumn): ?>
                <textarea name="personal_note" class="form-control" rows="3" placeholder="z. B. bevorzugt telefonische Kontaktaufnahme, Hobbys, Smalltalk‑Anker …"><?= e($personalNote) ?></textarea>
              <?php else: ?>
                <textarea class="form-control" rows="3" disabled>Spalte contacts.personal_note fehlt – siehe Migrations-SQL unten.</textarea>
              <?php endif; ?>
            </div>

            <!-- Sympathie-Slider -->
            <div class="col-12 col-md-8">
              <label class="form-label d-flex justify-content-between">
                <span>Sympathie</span>
                <span class="text-muted" id="sympValLabel"><?= $hasSympathyColumn ? e($sympathyVal) : '—' ?></span>
              </label>
              <?php if($hasSympathyColumn): ?>
                <input type="range" name="sympathy" id="sympathy" class="form-range" min="-5" max="5" step="1" value="<?= e($sympathyVal) ?>">
              <?php else: ?>
                <input type="range" class="form-range" min="-5" max="5" step="1" value="0" disabled>
              <?php endif; ?>
              <div class="range-labels">
                <span>unsympathisch</span><span>neutral</span><span>sympathisch</span>
              </div>
            </div>

            <div class="col-12 d-grid gap-2 d-sm-flex">
              <button class="btn btn-primary" type="submit">Speichern</button>
              <a href="/public/contacts.php" class="btn btn-outline-secondary">Zurück</a>
            </div>
          </form>

          <?php if(!$hasNoteColumn || !$hasSympathyColumn): ?>
            <div class="alert alert-warning mt-3">
              Hinweis: Zusätzliche Felder noch nicht in der Datenbank vorhanden. Siehe SQL‑Migration weiter unten.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Rechte Spalte: Bilder -->
    <div class="col-12 col-xl-5" id="images">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Bilder</h2>
          </div>

          <?php if(!$imagesEnabled): ?>
            <div class="alert alert-warning mt-3">
              Die Tabelle <code>person_images</code> existiert noch nicht. (SQL siehe unten)
            </div>
          <?php else: ?>
            <!-- Mehrfach-Upload -->
            <form class="row g-2 align-items-end mt-2" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="upload_person_images">
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

            <!-- Liste -->
            <div class="row g-3 mt-3">
              <?php if(empty($images)): ?>
                <div class="col-12 text-muted">Noch keine Bilder vorhanden.</div>
              <?php else: foreach($images as $img): ?>
                <div class="col-12">
                  <div class="border rounded p-2">
                    <div class="d-flex align-items-start gap-2">
                      <img src="<?= e($img['file_path']) ?>" alt="<?= e($img['alt_text'] ?? '') ?>" class="img-thumb rounded">
                      <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                          <div class="small text-muted">#<?= (int)$img['id'] ?> · <?= e(date('d.m.Y H:i', strtotime($img['created_at']))) ?></div>
                          <?php if($img['is_primary']): ?><span class="badge text-bg-primary">Primär</span><?php endif; ?>
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
  </div>
</main>

<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Sympathie-Wert live anzeigen
  const symp = document.getElementById('sympathy');
  const lbl  = document.getElementById('sympValLabel');
  if (symp && lbl){
    symp.addEventListener('input', ()=> lbl.textContent = symp.value);
  }

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
