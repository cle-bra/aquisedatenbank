<?php
// public/company_edit.php – Firma bearbeiten (General-Infos) + verknüpfte Personen verwalten
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0){ http_response_code(400); echo 'Ungültige ID'; exit; }

// Laden der Firma
$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=:id');
$stmt->execute([':id'=>$id]);
$co=$stmt->fetch();
if(!$co){ http_response_code(404); echo 'Firma nicht gefunden'; exit; }

$errors=[];
// --- POST: Firma speichern ---
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_company'){
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
      header('Location: /public/company_edit.php?id='.$id); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Speichern: '.$e->getMessage(); }
  }
}

// --- POST: Person hinzufügen ---
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_contact'){
  if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){$errors[]='Ungültiges Formular (CSRF).';}
  $sal = trim($_POST['salutation'] ?? '');
  $fn  = trim($_POST['first_name'] ?? '');
  $ln  = trim($_POST['last_name'] ?? '');
  $pos = trim($_POST['position'] ?? '');
  $em  = trim($_POST['email_personal'] ?? '');
  $ph  = trim($_POST['phone_direct'] ?? '');
  $ext = trim($_POST['phone_ext'] ?? '');

  if($fn==='' && $ln==='' && $em===''){
    $errors[] = 'Bitte mindestens Nachname oder E‑Mail angeben.';
  }
  if(empty($errors)){
    try{
      $stmt=$pdo->prepare('INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext) VALUES (:cid,:sal,:fn,:ln,:pos,:em,:ph,:ext)');
      $stmt->execute([':cid'=>$id, ':sal'=>$sal, ':fn'=>$fn, ':ln'=>$ln, ':pos'=>$pos, ':em'=>$em, ':ph'=>$ph, ':ext'=>$ext]);
      flash('success','Person hinzugefügt.');
      header('Location: /public/company_edit.php?id='.$id); exit;
    }catch(Throwable $e){ $errors[]='Fehler beim Hinzufügen: '.$e->getMessage(); }
  }
}

// Personen der Firma laden
$people=[]; try{
  $stmt=$pdo->prepare('SELECT id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext FROM contacts WHERE company_id=:id ORDER BY last_name, first_name');
  $stmt->execute([':id'=>$id]);
  $people=$stmt->fetchAll();
}catch(Throwable $e){}
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
  <?php include ('main_menu.php'); ?>

<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>
  <?php if($m=flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h1 class="h5 mb-3">Firma bearbeiten</h1>
          <form method="post" class="row g-3">
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

    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Zugeordnete Personen (<?= count($people) ?>)</h2>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addPersonModal">＋ Person</button>
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
                    <td class="row-actions"><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeRow(this)">×</button></td>
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

  <!-- Modal: Person hinzufügen -->
  <div class="modal fade" id="addPersonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Person hinzufügen</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_contact">
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-3">
                <label class="form-label">Anrede</label>
                <input type="text" name="salutation" class="form-control" placeholder="Herr/Frau">
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Vorname</label>
                <input type="text" name="first_name" class="form-control">
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label">Nachname</label>
                <input type="text" name="last_name" class="form-control">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Position</label>
                <input type="text" name="position" class="form-control">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">E‑Mail</label>
                <input type="email" name="email_personal" class="form-control">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Telefon direkt</label>
                <input type="text" name="phone_direct" class="form-control">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Durchwahl</label>
                <input type="text" name="phone_ext" class="form-control">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
            <button type="submit" class="btn btn-primary">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</main>
<footer class="mt-auto py-3 bg-white border-top"><div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function removeRow(btn){
    const tr = btn.closest('tr');
    const tbody = tr.parentNode;
    tbody.removeChild(tr);
    if(!tbody.querySelector('tr')){ // immer mind. 1 Zeile haben
      addRow();
    }
  }
</script>
</body>
</html>
