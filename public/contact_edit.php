<?php
// public/contact_edit.php – Kontakt bearbeiten (Stammdaten)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ http_response_code(400); echo 'Ungültige ID'; exit; }

// Laden
$stmt=$pdo->prepare('SELECT c.*, co.name AS company_name FROM contacts c LEFT JOIN companies co ON co.id=c.company_id WHERE c.id=:id');
$stmt->execute([':id'=>$id]);
$contact=$stmt->fetch();
if(!$contact){ http_response_code(404); echo 'Kontakt nicht gefunden'; exit; }

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $sal = trim($_POST['salutation'] ?? '');
  $fn  = trim($_POST['first_name'] ?? '');
  $ln  = trim($_POST['last_name'] ?? '');
  $pos = trim($_POST['position'] ?? '');
  $em  = trim($_POST['email_personal'] ?? '');
  $ph  = trim($_POST['phone_direct'] ?? '');
  $ext = trim($_POST['phone_ext'] ?? '');

  if(empty($errors)){
    try{
      $stmt=$pdo->prepare('UPDATE contacts SET salutation=:sal, first_name=:fn, last_name=:ln, position=:pos, email_personal=:em, phone_direct=:ph, phone_ext=:ext WHERE id=:id');
      $stmt->execute([':sal'=>$sal, ':fn'=>$fn, ':ln'=>$ln, ':pos'=>$pos, ':em'=>$em, ':ph'=>$ph, ':ext'=>$ext, ':id'=>$id]);
      flash('success','Kontakt aktualisiert.');
      header('Location: /public/contacts.php'); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kontakt bearbeiten · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h5 mb-3">Kontakt bearbeiten</h1>
          <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
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
            <div class="col-12 d-grid gap-2 d-sm-flex">
              <button class="btn btn-primary" type="submit">Speichern</button>
              <a href="/public/contacts.php" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
