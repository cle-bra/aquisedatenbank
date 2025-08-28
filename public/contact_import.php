<?php
// public/contact_import.php (Standalone)
// CSV-Import für Firmen & Kontakte
// Erwartete Spalten (Header sind optional, Reihenfolge egal):
// company_name, street, zip, city, website, email_general, phone_general,
// salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext
// Optional weitere Felder werden ignoriert.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

// --- DB-Verbindung (PDO) ---
require_once '../includes/mysql.php';

function csrf_token() { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf']; }
function flash($key, $msg = null) { if ($msg !== null) { $_SESSION['flash'][$key] = $msg; return; } $m = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $m; }

// --- Utility ---
function detect_delimiter(string $firstLine): string {
  $c = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
  arsort($c); return (string) array_key_first($c);
}

function normalize_header(string $h): string {
  $h = trim(mb_strtolower($h));
  $map = [
    'firma' => 'company_name', 'company' => 'company_name', 'company_name' => 'company_name', 'firmenname' => 'company_name',
    'strasse' => 'street', 'straße' => 'street', 'strasse hnr' => 'street', 'street' => 'street', 'adresse' => 'street',
    'plz' => 'zip', 'postleitzahl' => 'zip', 'zip' => 'zip',
    'ort' => 'city', 'stadt' => 'city', 'city' => 'city',
    'webseite' => 'website', 'website' => 'website', 'url' => 'website',
    'emailadresse allgemein' => 'email_general', 'e-mail allgemein' => 'email_general', 'email_general' => 'email_general', 'mail' => 'email_general',
    'rufnummer allgemein' => 'phone_general', 'telefon' => 'phone_general', 'phone' => 'phone_general', 'phone_general' => 'phone_general',
    'anrede' => 'salutation', 'salutation' => 'salutation',
    'vorname' => 'first_name', 'first_name' => 'first_name',
    'nachname' => 'last_name', 'last_name' => 'last_name',
    'position' => 'position', 'rolle' => 'position', 'titel' => 'position',
    'email persönlich' => 'email_personal', 'email' => 'email_personal', 'e-mail' => 'email_personal', 'email_personal' => 'email_personal',
    'durchwahl' => 'phone_ext', 'phone_ext' => 'phone_ext',
    'telefon direkt' => 'phone_direct', 'phone_direct' => 'phone_direct', 'mobil' => 'phone_direct'
  ];
  return $map[$h] ?? $h; // unbekannte bleiben, werden später ignoriert
}

function read_csv_to_rows(array $file, ?string $forcedDelimiter = null, bool $hasHeader = true): array {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload fehlgeschlagen (Error '.$file['error'].').');
  }
  $content = file_get_contents($file['tmp_name']);
  if ($content === false) { throw new RuntimeException('Datei konnte nicht gelesen werden.'); }
  $lines = preg_split("~\r\n|\n|\r~", trim($content));
  if (!$lines || count($lines) === 0) { return []; }
  $delimiter = $forcedDelimiter ?: detect_delimiter($lines[0]);

  $rows = [];
  $headers = [];
  foreach ($lines as $i => $line) {
    $fields = str_getcsv($line, $delimiter);
    if ($i === 0 && $hasHeader) {
      $headers = array_map('normalize_header', $fields);
      continue;
    }
    $row = [];
    foreach ($fields as $k => $v) {
      if ($hasHeader) {
        $key = $headers[$k] ?? ('col'.($k+1));
        $row[$key] = trim($v);
      } else {
        $row['col'.($k+1)] = trim($v);
      }
    }
    $rows[] = $row;
  }
  return $rows;
}

function upsert_company(PDO $pdo, array $r): int {
  $name = $r['company_name'] ?? '';
  if ($name === '') { throw new InvalidArgumentException('company_name fehlt'); }
  $city = $r['city'] ?? null;
  $stmt = $pdo->prepare('SELECT id FROM companies WHERE name = :n AND (city = :c OR (:c IS NULL AND city IS NULL)) LIMIT 1');
  $stmt->execute([':n' => $name, ':c' => $city]);
  $id = $stmt->fetchColumn();
  if ($id) {
    // vorsichtig aktualisieren (nur leere Felder füllen)
    $stmt = $pdo->prepare("UPDATE companies SET
    street = COALESCE(NULLIF(:street, ''), street),
    zip = COALESCE(NULLIF(:zip, ''), zip),
    city = COALESCE(NULLIF(:city, ''), city),
    website = COALESCE(NULLIF(:website, ''), website),
    email_general = COALESCE(NULLIF(:emailg, ''), email_general),
    phone_general = COALESCE(NULLIF(:phoneg, ''), phone_general)
    WHERE id = :id");
 $stmt->execute([
      ':street' => $r['street'] ?? '', ':zip' => $r['zip'] ?? '', ':city' => $r['city'] ?? '', ':website' => $r['website'] ?? '', ':emailg' => $r['email_general'] ?? '', ':phoneg' => $r['phone_general'] ?? '', ':id' => $id
    ]);
    return (int)$id;
  }
  $stmt = $pdo->prepare('INSERT INTO companies (name, street, zip, city, website, email_general, phone_general) VALUES (:name,:street,:zip,:city,:website,:emailg,:phoneg)');
  $stmt->execute([
    ':name' => $name, ':street' => $r['street'] ?? null, ':zip' => $r['zip'] ?? null, ':city' => $r['city'] ?? null, ':website' => $r['website'] ?? null, ':emailg' => $r['email_general'] ?? null, ':phoneg' => $r['phone_general'] ?? null
  ]);
  return (int)$pdo->lastInsertId();
}

function upsert_contact(PDO $pdo, int $companyId, array $r): int {
  $email = $r['email_personal'] ?? '';
  if ($email !== '') {
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE email_personal = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $cid = $stmt->fetchColumn();
    if ($cid) {
    $stmt = $pdo->prepare("UPDATE contacts SET
    company_id = :company_id,
    salutation = COALESCE(NULLIF(:sal, ''), salutation),
    first_name = COALESCE(NULLIF(:fn, ''), first_name),
    last_name = COALESCE(NULLIF(:ln, ''), last_name),
    position = COALESCE(NULLIF(:pos, ''), position),
    phone_direct = COALESCE(NULLIF(:pd, ''), phone_direct),
    phone_ext = COALESCE(NULLIF(:pe, ''), phone_ext)
    WHERE id = :id");
 $stmt->execute([':company_id'=>$companyId, ':sal'=>$r['salutation'] ?? '', ':fn'=>$r['first_name'] ?? '', ':ln'=>$r['last_name'] ?? '', ':pos'=>$r['position'] ?? '', ':pd'=>$r['phone_direct'] ?? '', ':pe'=>$r['phone_ext'] ?? '', ':id'=>$cid]);
      return (int)$cid;
    }
  }
  // fallback match: company + name
  if (!empty($r['first_name']) || !empty($r['last_name'])) {
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE company_id = :cid AND first_name = :fn AND last_name = :ln LIMIT 1');
    $stmt->execute([':cid'=>$companyId, ':fn'=>$r['first_name'] ?? '', ':ln'=>$r['last_name'] ?? '']);
    $cid = $stmt->fetchColumn();
    if ($cid) {
    $stmt = $pdo->prepare("UPDATE contacts SET
    email_personal = COALESCE(NULLIF(:e, ''), email_personal),
    position = COALESCE(NULLIF(:pos, ''), position),
    phone_direct = COALESCE(NULLIF(:pd, ''), phone_direct),
    phone_ext = COALESCE(NULLIF(:pe, ''), phone_ext),
    salutation = COALESCE(NULLIF(:sal, ''), salutation)
    WHERE id = :id");
  $stmt->execute([':e'=>$r['email_personal'] ?? '', ':pos'=>$r['position'] ?? '', ':pd'=>$r['phone_direct'] ?? '', ':pe'=>$r['phone_ext'] ?? '', ':sal'=>$r['salutation'] ?? '', ':id'=>$cid]);
      return (int)$cid;
    }
  }
  // insert new
  $stmt = $pdo->prepare('INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext) VALUES (:company_id,:salutation,:first_name,:last_name,:position,:email,:phone_direct,:phone_ext)');
  $stmt->execute([
    ':company_id'=>$companyId,
    ':salutation'=>$r['salutation'] ?? null,
    ':first_name'=>$r['first_name'] ?? null,
    ':last_name'=>$r['last_name'] ?? null,
    ':position'=>$r['position'] ?? null,
    ':email'=>$r['email_personal'] ?? null,
    ':phone_direct'=>$r['phone_direct'] ?? null,
    ':phone_ext'=>$r['phone_ext'] ?? null,
  ]);
  return (int)$pdo->lastInsertId();
}

$errors = [];
$preview = [];
$result  = ['inserted_companies'=>0,'updated_companies'=>0,'inserted_contacts'=>0,'updated_contacts'=>0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { $errors[] = 'Ungültiges Formular (CSRF).'; }
  $hasHeader = isset($_POST['has_header']);
  $delimiter = $_POST['delimiter'] ?? '';
  $dryRun    = isset($_POST['dry_run']);
  try {
    $rows = read_csv_to_rows($_FILES['csv'] ?? [], $delimiter ?: null, $hasHeader);
    if (empty($rows)) { $errors[] = 'Keine Daten gefunden.'; }
    if (empty($errors)) {
      if ($dryRun) {
        $preview = array_slice($rows, 0, 10);
      } else {
        $pdo->beginTransaction();
        foreach ($rows as $r) {
          if (empty($r['company_name'])) { continue; }
          $beforeCompanyId = null;
          // check existing company quickly
          $stmtChk = $pdo->prepare('SELECT id, street, zip, city, website, email_general, phone_general FROM companies WHERE name = :n AND (city = :c OR (:c IS NULL AND city IS NULL)) LIMIT 1');
          $stmtChk->execute([':n'=>$r['company_name'], ':c'=>$r['city'] ?? null]);
          $existing = $stmtChk->fetch();
          $beforeCompanyId = $existing['id'] ?? null;

          $cid = upsert_company($pdo, $r);
          if ($beforeCompanyId) {
            // prüfen, ob Update stattfand (heuristik: wenn neue Felder geliefert)
            $fields = ['street','zip','city','website','email_general','phone_general'];
            $didUpdate = false;
            foreach ($fields as $f) { if (!empty($r[$f]) && (empty($existing[$f]) || $existing[$f] !== $r[$f])) { $didUpdate = true; break; } }
            if ($didUpdate) { $result['updated_companies']++; }
          } else { $result['inserted_companies']++; }

          // Kontakt (falls vorhanden)
          if (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['email_personal'])) {
            // Existenz vorab prüfen
            $exBefore = null;
            if (!empty($r['email_personal'])) {
              $st = $pdo->prepare('SELECT id FROM contacts WHERE email_personal=:e LIMIT 1');
              $st->execute([':e'=>$r['email_personal']]);
              $exBefore = $st->fetchColumn();
            }
            $kid = upsert_contact($pdo, $cid, $r);
            if ($exBefore) { $result['updated_contacts']++; } else { $result['inserted_contacts']++; }
          }
        }
        $pdo->commit();
        flash('success', 'Import abgeschlossen: Firmen +'.$result['inserted_companies'].' / ~'.$result['updated_companies'].' aktualisiert; Kontakte +'.$result['inserted_contacts'].' / ~'.$result['updated_contacts'].' aktualisiert.');
        header('Location: /contact_import.php'); exit;
      }
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $errors[] = 'Fehler: '.$e->getMessage();
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CSV‑Import · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
    <div class="d-flex">
      <a class="btn btn-outline-light btn-sm" href="/dashboard.php">Dashboard</a>
      <a class="btn btn-warning btn-sm ms-2" href="/logout.php">Logout</a>
    </div>
  </div>
</nav>
<main class="container py-4">
  <div class="row g-3">
    <div class="col-12 col-xl-9">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h4 mb-3">CSV‑Import: Firmen & Kontakte</h1>
          <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
          <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

          <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="col-12">
              <label for="csv" class="form-label">CSV‑Datei</label>
              <input type="file" class="form-control" id="csv" name="csv" accept=".csv,text/csv" required>
              <div class="form-text">Unterstützte Trennzeichen: Semikolon, Komma, Tab. Zeichensatz: UTF‑8 empfohlen.</div>
            </div>

            <div class="col-12 col-md-4">
              <label for="delimiter" class="form-label">Trennzeichen</label>
              <select class="form-select" id="delimiter" name="delimiter">
                <option value="">Automatisch erkennen</option>
                <option value=",">Komma (,)</option>
                <option value=";">Semikolon (;)</option>
                <option value="\t">Tabulator</option>
              </select>
            </div>

            <div class="col-6 col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" value="1" id="has_header" name="has_header" checked>
                <label class="form-check-label" for="has_header">Erste Zeile enthält Überschriften</label>
              </div>
            </div>

            <div class="col-6 col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" value="1" id="dry_run" name="dry_run" checked>
                <label class="form-check-label" for="dry_run">Trockenlauf (nur Vorschau)</label>
              </div>
            </div>

            <div class="col-12 d-grid d-sm-flex gap-2">
              <button type="submit" class="btn btn-primary">Datei prüfen / importieren</button>
              <a href="#beispiel" class="btn btn-outline-secondary">CSV‑Beispiel ansehen</a>
            </div>
          </form>

          <?php if (!empty($preview)): ?>
          <hr>
          <h2 class="h6">Vorschau (erste <?= count($preview) ?> Zeilen)</h2>
          <div class="table-responsive">
            <table class="table table-sm table-striped">
              <thead><tr>
                <?php foreach (array_keys($preview[0]) as $h): ?>
                  <th><?= htmlspecialchars($h) ?></th>
                <?php endforeach; ?>
              </tr></thead>
              <tbody>
                <?php foreach ($preview as $row): ?>
                  <tr>
                    <?php foreach ($row as $v): ?>
                      <td><?= htmlspecialchars($v) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="alert alert-info">Wenn alles passt, bitte den Haken bei <strong>Trockenlauf</strong> entfernen und erneut hochladen.</div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <div class="col-12 col-xl-3" id="beispiel">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6">CSV‑Beispiel</h2>
<pre class="small mb-2">company_name;street;zip;city;website;email_general;phone_general;salutation;first_name;last_name;position;email_personal;phone_direct;phone_ext
KNX Muster GmbH;Musterstraße 1;10115;Berlin;https://knx-muster.de;info@knx-muster.de;030-123456;Herr;Max;Mustermann;Geschäftsführer;m.mustermann@knx-muster.de;030-555;123
Elektro Beispiel AG;Hauptstr. 99;50667;Köln;https://elektro-beispiel.ag;kontakt@elektro-beispiel.ag;0221-9999;Frau;Erika;Beispiel;Leitung Technik;e.beispiel@elektro-beispiel.ag;0172-1234567;
</pre>
          <p class="small text-muted mb-0">Andere Spalten werden ignoriert. Umlaute als UTF‑8 speichern.</p>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="mt-auto py-3 bg-white border-top">
  <div class="container small text-muted">© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>