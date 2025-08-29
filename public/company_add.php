<?php
// public/company_add.php – Neue Firma anlegen + direkt Personen zuordnen
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }
require_once '../includes/mysql.php'; // $pdo

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

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
      // Firma speichern
      $stmt=$pdo->prepare('INSERT INTO companies (name, street, zip, city, website, email_general, phone_general) VALUES (:name,:street,:zip,:city,:website,:email_general,:phone_general)');
      $stmt->execute([
        ':name'=>$name, ':street'=>$street, ':zip'=>$zip, ':city'=>$city, ':website'=>$website,
        ':email_general'=>$email_general, ':phone_general'=>$phone_general
      ]);
      $newId = (int)$pdo->lastInsertId();

      // Kontakte speichern (nur Zeilen mit minimalen Angaben)
      if (is_array($c_ln) || is_array($c_em)){
        $ins = $pdo->prepare('INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext) VALUES (:cid,:sal,:fn,:ln,:pos,:em,:ph,:ext)');
        $rows = max(count($c_sal), count($c_fn), count($c_ln), count($c_pos), count($c_em), count($c_ph), count($c_ext));
        for($i=0; $i<$rows; $i++){
          $sal = trim($c_sal[$i] ?? '');
          $fn  = trim($c_fn[$i]  ?? '');
          $ln  = trim($c_ln[$i]  ?? '');
          $pos = trim($c_pos[$i] ?? '');
          $em  = trim($c_em[$i]  ?? '');
          $ph  = trim($c_ph[$i]  ?? '');
          $ext = trim($c_ext[$i] ?? '');
          // Mindestens Nachname ODER E-Mail, damit eine Zeile sinnvoll ist
          if ($ln!=='' || $em!=='' || $fn!==''){
            $ins->execute([':cid'=>$newId, ':sal'=>$sal, ':fn'=>$fn, ':ln'=>$ln, ':pos'=>$pos, ':em'=>$em, ':ph'=>$ph, ':ext'=>$ext]);
          }
        }
      }

      $pdo->commit();
      flash('success','Firma und zugehörige Personen angelegt.');
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
  </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <?php foreach($errors as $eMsg): ?><div class="alert alert-danger"><?= e($eMsg) ?></div><?php endforeach; ?>

  <div class="row g-3">
    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h1 class="h5 mb-3">Neue Firma anlegen</h1>
          <form id="companyForm" method="post" class="row g-3">
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
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
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
          <div class="d-grid gap-2 d-sm-flex mt-3">
            <button class="btn btn-primary" type="submit" form="companyForm">Anlegen</button>
            <a href="/public/companies.php" class="btn btn-outline-secondary">Abbrechen</a>
          </div>
        </div>
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
</script>
</body>
</html>
