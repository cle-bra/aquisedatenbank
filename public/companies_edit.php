<?php
// public/company_edit.php – Firma bearbeiten (General-Infos)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ http_response_code(400); echo 'Ungültige ID'; exit; }

// Laden
$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=:id');
$stmt->execute([':id'=>$id]);
$co=$stmt->fetch();
if(!$co){ http_response_code(404); echo 'Firma nicht gefunden'; exit; }

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $name = trim($_POST['name'] ?? '');
  $street = trim($_POST['street'] ?? '');
  $zip = trim($_POST['zip'] ?? '');
  $city = trim($_POST['city'] ?? '');
  $website = trim($_POST['website'] ?? '');
  $email_general = trim($_POST['email_general'] ?? '');
  $phone_general = trim($_POST['phone_general'] ?? '');

  if($name===''){ $errors[]='Firmenname darf nicht leer sein.'; }

  if(empty($errors)){
    try{
      $stmt=$pdo->prepare('UPDATE companies SET name=:name, street=:street, zip=:zip, city=:city, website=:website, email_general=:email_general, phone_general=:phone_general WHERE id=:id');
      $stmt->execute([
        ':name'=>$name, ':street'=>$street, ':zip'=>$zip, ':city'=>$city, ':website'=>$website,
        ':email_general'=>$email_general, ':phone_general'=>$phone_general, ':id'=>$id
      ]);
      flash('success','Firma aktualisiert.');
      header('Location: /public/companies.php'); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Firma bearbeiten · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
    <div class="d-flex">
      <a class="btn btn-outline-light btn-sm me-2" href="/public/companies.php">← Zurück</a>
      <a class="btn btn-warning btn-sm" href="/public/logout.php">Logout</a>
    </div>
  </div>
</nav>
<main class="container py-4">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h5 mb-3">Firma bearbeiten</h1>
          <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
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
            <div class="col-12 d-grid gap-2 d-sm-flex">
              <button class="btn btn-primary" type="submit">Speichern</button>
              <a href="/public/companies.php" class="btn btn-outline-secondary">Abbrechen</a>
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
