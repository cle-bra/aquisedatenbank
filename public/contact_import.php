<?php
// public/contact_import.php – CSV-Import (Companies + Contacts erweitert)
// Erwartete (optionale) Spalten – Reihenfolge egal, Header empfohlen:
// Firmenname;Plz;Stadt;Straße;Hausnr.;Bundesland;Webseite;Unternehmensgegenstand;Branche;Telefon;E-Mail;Größenklasse;Umsatz;Mitarbeiter;Rechtsform;Registergericht;Aktenzeichen;Listflix-ID
// Kontakte: Anrede/Vorname/Nachname für Primärkontakt, GF1..3, Prokura1..3, Inhaber1..3

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /login.php'); exit; }

// --- DB-Verbindung (PDO) ---
require_once '../includes/mysql.php';

// Helpers
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash($k,$m=null){ if($m!==null){$_SESSION['flash'][$k]=$m;return;} $mm=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $mm; }

// --- Utility ---
function detect_delimiter(string $firstLine): string {
  $c = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
  arsort($c); return (string) array_key_first($c);
}

function s_trim($v): string { return trim((string)$v); }

function normalize_header(string $h): string {
  $h = trim(mb_strtolower($h));

  // Leerzeichen normalisieren (ein paar Quellen haben Sonder-Whitespaces)
  $h = preg_replace('~\s+~u', ' ', $h);

  $map = [
    // Company Core
    'firmenname' => 'company_name',
    'plz' => 'zip',
    'stadt' => 'city',
    'straße' => 'street', 'strasse' => 'street',
    'hausnr.' => 'house_number', 'hausnr' => 'house_number',
    'bundesland' => 'state',
    'webseite' => 'website',
    'unternehmensgegenstand' => 'business_purpose',
    'branche' => 'industry',
    'telefon' => 'phone_general',
    'fax' => 'fax_general', // (wird aktuell nicht gespeichert)
    'e-mail' => 'email_general', 'email' => 'email_general',
    'größenklasse' => 'size_class',
    'bilanzsumme' => 'balance_total',   // (optional, aktuell nicht gespeichert)
    'ergebnis' => 'result',             // (optional, aktuell nicht gespeichert)
    'rohertrag' => 'gross_profit',      // (optional, aktuell nicht gespeichert)
    'umsatz' => 'revenue',
    'mitarbeiter' => 'employees',
    'rechtsform' => 'legal_form',
    'registergericht' => 'register_court',
    'aktenzeichen' => 'register_number',
    'sitz' => 'seat',                   // (optional, aktuell nicht gespeichert)
    'stammkapital' => 'share_capital',  // (optional, aktuell nicht gespeichert)

    // Personenfelder
    'anrede primärkontakt' => 'pc_sal',
    'vorname primärkontakt' => 'pc_fn',
    'nachname primärkontakt' => 'pc_ln',

    'anrede (gf1)' => 'gf1_sal', 'vorname (gf1)' => 'gf1_fn', 'nachname (gf1)' => 'gf1_ln',
    'anrede (gf2)' => 'gf2_sal', 'vorname (gf2)' => 'gf2_fn', 'nachname (gf2)' => 'gf2_ln',
    'anrede (gf3)' => 'gf3_sal', 'vorname (gf3)' => 'gf3_fn', 'nachname (gf3)' => 'gf3_ln',

    'anrede (prokura 1)' => 'pk1_sal', 'vorname (prokura 1)' => 'pk1_fn', 'nachname (prokura 1)' => 'pk1_ln',
    'anrede (prokura 2)' => 'pk2_sal', 'vorname (prokura 2)' => 'pk2_fn', 'nachname (prokura 2)' => 'pk2_ln',
    'anrede (prokura 3)' => 'pk3_sal', 'vorname (prokura 3)' => 'pk3_fn', 'nachname (prokura 3)' => 'pk3_ln',

    'anrede (inhaber 1)' => 'ih1_sal', 'vorname (inhaber 1)' => 'ih1_fn', 'nachname (inhaber 1)' => 'ih1_ln',
    'anrede (inhaber 2)' => 'ih2_sal', 'vorname (inhaber 2)' => 'ih2_fn', 'nachname (inhaber 2)' => 'ih2_ln',
    'anrede (inhaber 3)' => 'ih3_sal', 'vorname (inhaber 3)' => 'ih3_fn', 'nachname (inhaber 3)' => 'ih3_ln',

    'geschäftsführung' => 'management_freeform', // optional: Freitext, aktuell ignoriert

    // Externe ID
    'listflix-id' => 'external_id',
  ];

  return $map[$h] ?? $h;
}

function is_na($v): bool {
  $v = trim(mb_strtolower((string)$v));
  if ($v === '') return false; // leeres Feld bleibt leer, nicht "n.a."
  return in_array($v, ['n.a.', 'n.a', 'na', 'k.a.', 'k.a', 'nicht vorhanden', '—', '-', 'n/a'], true);
}

function clean_money(?string $v): ?string {
  if ($v === null) return null;
  $t = trim($v);
  if ($t === '' || is_na($t)) return null;
  // Entferne Euro, Punkte/Spaces, ersetze Komma durch Punkt
  $t = preg_replace('~[€\\s]~u', '', $t);
  $t = str_replace('.', '', $t);
  $t = str_replace(',', '.', $t);
  // Übrig bleibt z. B. 107408,50 -> 107408.50
  return $t === '' ? null : $t;
}

function clean_external_id(?string $v): ?string {
  if ($v === null) return null;
  $t = trim($v);
  if ($t === '' || is_na($t)) return null;
  // Muster: entity_id:|:company:|:yx85e9...
  if (strpos($t, ':|:') !== false) {
    $parts = explode(':|:', $t);
    $last = end($parts);
    return $last !== '' ? $last : null;
  }
  return $t;
}

function clean_scalar(?string $v): ?string {
  if ($v === null) return null;
  $t = trim($v);
  if ($t === '' || is_na($t)) return null;
  return $t;
}


function read_csv_to_rows(array $file, ?string $forcedDelimiter = null, bool $hasHeader = true): array {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload fehlgeschlagen (Error '.$file['error'].').');
  }
  $content = file_get_contents($file['tmp_name']);

  // UTF-8 BOM entfernen (sonst stimmt der erste Header-Key nicht)
  $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);


  if ($content === false) { throw new RuntimeException('Datei konnte nicht gelesen werden.'); }
  $lines = preg_split("~\r\n|\n|\r~", trim($content));
  if (!$lines || count($lines) === 0) { return []; }
  $delimiter = $forcedDelimiter ?: detect_delimiter($lines[0]);
  if ($delimiter === '\\t') { $delimiter = "\t"; } // Tab-Fix


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
        $row[$key] = s_trim($v);
      } else {
        $row['col'.($k+1)] = trim($v);
      }
    }

    // Werte reinigen: 'n.a.' -> null, money, external_id
    foreach ($row as $k => $v) {
      if (in_array($k, ['revenue','balance_total','result','gross_profit'], true)) {
        $row[$k] = clean_money($v);
      } elseif ($k === 'external_id') {
        $row[$k] = clean_external_id($v);
      } else {
        $row[$k] = clean_scalar($v);
      }
    }


    $rows[] = $row;
  }
  return $rows;
}

// --- Company UPSERT ---
function upsert_company(PDO $pdo, array $r): int {
  $name = trim($r['company_name'] ?? '');
  if ($name === '') { throw new InvalidArgumentException('company_name (Firmenname) fehlt'); }

  // Straße + Hausnummer zusammenziehen
  $street = trim(($r['street'] ?? '').' '.($r['house_number'] ?? ''));
  $zip = trim($r['zip'] ?? '');
  $city = trim($r['city'] ?? '');
  $state = trim($r['state'] ?? '');
  $website = trim($r['website'] ?? '');
  $emailg = trim($r['email_general'] ?? '');
  $phoneg = trim($r['phone_general'] ?? '');
  $industry = trim($r['industry'] ?? '');
  $purpose = trim($r['business_purpose'] ?? '');
  $legal = trim($r['legal_form'] ?? '');
  $court = trim($r['register_court'] ?? '');
  $regno = trim($r['register_number'] ?? '');
  $sizec = trim($r['size_class'] ?? '');
  // WICHTIG: external_id darf nie '' sein (sonst UNIQUE-Kollision) -> NULL
$external = isset($r['external_id']) && trim((string)$r['external_id']) !== ''
    ? trim((string)$r['external_id'])
    : null;


  // numerisch vorsichtig
  $employees = isset($r['employees']) && $r['employees'] !== '' ? (int)$r['employees'] : null;
  $revenue   = isset($r['revenue']) && $r['revenue'] !== '' ? str_replace(['.',' '],['',''], str_replace(',','.', $r['revenue'])) : null; // 1.234,56 -> 1234.56

  // 1) Match über external_id
  $id = null;
  if ($external !== '') {
    $st = $pdo->prepare('SELECT id FROM companies WHERE external_id = :x LIMIT 1');
    $st->execute([':x'=>$external]);
    $id = $st->fetchColumn();
  }
  // 2) sonst Name + (PLZ oder Stadt)
  if (!$id) {
    $st = $pdo->prepare('SELECT id FROM companies WHERE name = :n AND (zip = :z OR city = :c) LIMIT 1');
    $st->execute([':n'=>$name, ':z'=>$zip, ':c'=>$city]);
    $id = $st->fetchColumn();
  }

  if ($id) {
    // vorsichtig aktualisieren (nur leere Felder füllen)
    $sql = "UPDATE companies SET
      street = COALESCE(NULLIF(:street, ''), street),
      zip = COALESCE(NULLIF(:zip, ''), zip),
      city = COALESCE(NULLIF(:city, ''), city),
      state = COALESCE(NULLIF(:state, ''), state),
      website = COALESCE(NULLIF(:website, ''), website),
      email_general = COALESCE(NULLIF(:emailg, ''), email_general),
      phone_general = COALESCE(NULLIF(:phoneg, ''), phone_general),
      industry = COALESCE(NULLIF(:industry, ''), industry),
      business_purpose = COALESCE(NULLIF(:purpose, ''), business_purpose),
      legal_form = COALESCE(NULLIF(:legal, ''), legal_form),
      register_court = COALESCE(NULLIF(:court, ''), register_court),
      register_number = COALESCE(NULLIF(:regno, ''), register_number),
      size_class = COALESCE(NULLIF(:sizec, ''), size_class),
      external_id = COALESCE(NULLIF(:external, ''), external_id),
      employees = COALESCE(:employees, employees),
      revenue = COALESCE(:revenue, revenue)
    WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':street'=>$street, ':zip'=>$zip, ':city'=>$city, ':state'=>$state,
      ':website'=>$website, ':emailg'=>$emailg, ':phoneg'=>$phoneg,
      ':industry'=>$industry, ':purpose'=>$purpose, ':legal'=>$legal,
      ':court'=>$court, ':regno'=>$regno, ':sizec'=>$sizec, ':external'=>$external,
      ':employees'=>$employees, ':revenue'=>$revenue, ':id'=>$id
    ]);
    return (int)$id;
  }

  // insert
  $sql = "INSERT INTO companies
    (name, street, zip, city, state, website, email_general, phone_general,
     industry, business_purpose, legal_form, register_court, register_number,
     employees, revenue, size_class, external_id)
    VALUES
    (:name,:street,:zip,:city,:state,:website,:emailg,:phoneg,
     :industry,:purpose,:legal,:court,:regno,:employees,:revenue,:sizec,:external)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':name'=>$name, ':street'=>$street, ':zip'=>$zip, ':city'=>$city, ':state'=>$state,
    ':website'=>$website, ':emailg'=>$emailg, ':phoneg'=>$phoneg,
    ':industry'=>$industry, ':purpose'=>$purpose, ':legal'=>$legal,
    ':court'=>$court, ':regno'=>$regno,
    ':employees'=>$employees, ':revenue'=>$revenue, ':sizec'=>$sizec, ':external'=>$external
  ]);
  return (int)$pdo->lastInsertId();
}

// --- Contact UPSERT ---
function upsert_contact(PDO $pdo, int $companyId, array $r): int {
// ALT:
// $email = trim($r['email_personal'] ?? '');
// ...
// NEU:
$email = s_trim($r['email_personal'] ?? null);
$fn    = s_trim($r['first_name'] ?? null);
$ln    = s_trim($r['last_name'] ?? null);
$sal   = s_trim($r['salutation'] ?? null);
$pos   = s_trim($r['position'] ?? null);
$pd    = s_trim($r['phone_direct'] ?? null);
$pe    = s_trim($r['phone_ext'] ?? null);


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
      $stmt->execute([':company_id'=>$companyId, ':sal'=>$sal, ':fn'=>$fn, ':ln'=>$ln, ':pos'=>$pos, ':pd'=>$pd, ':pe'=>$pe, ':id'=>$cid]);
      return (int)$cid;
    }
  }
  // fallback match: company + name
  if ($fn !== '' || $ln !== '') {
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE company_id = :cid AND first_name = :fn AND last_name = :ln LIMIT 1');
    $stmt->execute([':cid'=>$companyId, ':fn'=>$fn, ':ln'=>$ln]);
    $cid = $stmt->fetchColumn();
    if ($cid) {
      $stmt = $pdo->prepare("UPDATE contacts SET
        email_personal = COALESCE(NULLIF(:e, ''), email_personal),
        position = COALESCE(NULLIF(:pos, ''), position),
        phone_direct = COALESCE(NULLIF(:pd, ''), phone_direct),
        phone_ext = COALESCE(NULLIF(:pe, ''), phone_ext),
        salutation = COALESCE(NULLIF(:sal, ''), salutation)
        WHERE id = :id");
      $stmt->execute([':e'=>$email, ':pos'=>$pos, ':pd'=>$pd, ':pe'=>$pe, ':sal'=>$sal, ':id'=>$cid]);
      return (int)$cid;
    }
  }
  // insert new
  $stmt = $pdo->prepare('INSERT INTO contacts (company_id, salutation, first_name, last_name, position, email_personal, phone_direct, phone_ext)
                         VALUES (:company_id,:salutation,:first_name,:last_name,:position,:email,:phone_direct,:phone_ext)');
  $stmt->execute([
    ':company_id'=>$companyId, ':salutation'=>$sal, ':first_name'=>$fn, ':last_name'=>$ln,
    ':position'=>$pos, ':email'=>$email ?: null, ':phone_direct'=>$pd ?: null, ':phone_ext'=>$pe ?: null,
  ]);
  return (int)$pdo->lastInsertId();
}

function make_contacts_from_row(PDO $pdo, int $companyId, array $r): int {
  $count = 0;
  $roles = [
    ['pc_sal','pc_fn','pc_ln','Primärkontakt'],
    ['gf1_sal','gf1_fn','gf1_ln','Geschäftsführer:in'],
    ['gf2_sal','gf2_fn','gf2_ln','Geschäftsführer:in'],
    ['gf3_sal','gf3_fn','gf3_ln','Geschäftsführer:in'],
    ['pk1_sal','pk1_fn','pk1_ln','Prokurist:in'],
    ['pk2_sal','pk2_fn','pk2_ln','Prokurist:in'],
    ['pk3_sal','pk3_fn','pk3_ln','Prokurist:in'],
    ['ih1_sal','ih1_fn','ih1_ln','Inhaber:in'],
    ['ih2_sal','ih2_fn','ih2_ln','Inhaber:in'],
    ['ih3_sal','ih3_fn','ih3_ln','Inhaber:in'],
  ];
  foreach ($roles as [$salK,$fnK,$lnK,$pos]) {
    $fn  = s_trim($r[$fnK] ?? null);
    $ln  = s_trim($r[$lnK] ?? null);
    $sal = s_trim($r[$salK] ?? null);

    if ($fn!=='' || $ln!=='') {
      upsert_contact($pdo, $companyId, [
        'salutation'=>$sal, 'first_name'=>$fn, 'last_name'=>$ln,
        'position'=>$pos, 'email_personal'=>'', 'phone_direct'=>'', 'phone_ext'=>''
      ]);
      $count++;
    }
  }
  return $count;
}

// --- Controller ---
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

          // Vorab-Stichprobe (zur Heuristik „updated“)
          $existing = null; $beforeCompanyId = null;
          if (!empty($r['external_id'])) {
            $chk = $pdo->prepare('SELECT * FROM companies WHERE external_id=:x LIMIT 1');
            $chk->execute([':x'=>$r['external_id']]); $existing = $chk->fetch();
          } else {
            $chk = $pdo->prepare('SELECT * FROM companies WHERE name=:n AND (zip=:z OR city=:c) LIMIT 1');
            $chk->execute([':n'=>$r['company_name'], ':z'=>$r['zip'] ?? null, ':c'=>$r['city'] ?? null]);
            $existing = $chk->fetch();
          }
          $beforeCompanyId = $existing['id'] ?? null;

          // Company upsert
          $cid = upsert_company($pdo, $r);
          if ($beforeCompanyId) {
            // Heuristik: wenn in importierter Zeile neue Werte vorhanden sind → updated
            $fields = ['street','zip','city','state','website','email_general','phone_general','industry','business_purpose','legal_form','register_court','register_number','employees','revenue','size_class','external_id'];
            $didUpdate = false;
            foreach ($fields as $f) {
              if (isset($r[$f]) && $r[$f] !== '' && (empty($existing[$f]) || (string)$existing[$f] !== (string)$r[$f])) { $didUpdate = true; break; }
            }
            if ($didUpdate) { $result['updated_companies']++; }
          } else { $result['inserted_companies']++; }

          // Contacts aus den Personenfeldern erzeugen
          $beforeContacts = 0; $afterContacts = 0;
          // (kleine Zählung nur für Statistik)
          $beforeContactsStmt = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE company_id=:cid');
          $beforeContactsStmt->execute([':cid'=>$cid]);
          $beforeContacts = (int)$beforeContactsStmt->fetchColumn();

          $made = make_contacts_from_row($pdo, $cid, $r);

          $afterContactsStmt = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE company_id=:cid');
          $afterContactsStmt->execute([':cid'=>$cid]);
          $afterContacts = (int)$afterContactsStmt->fetchColumn();

          // grobe Statistik
          if ($afterContacts > $beforeContacts) {
            $result['inserted_contacts'] += ($afterContacts - $beforeContacts);
          } else {
            // falls upsert vorhandene aktualisiert hat
            $result['updated_contacts'] += $made;
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
  <title>CSV-Import · Aquise Backend</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<?php include __DIR__.'/main_menu.php'; ?>
<main class="container py-4">
  <div class="row g-3">
    <div class="col-12 col-xl-9">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h4 mb-3">CSV-Import: Firmen & Kontakte</h1>
          <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
          <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

          <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="col-12">
              <label for="csv" class="form-label">CSV-Datei</label>
              <input type="file" class="form-control" id="csv" name="csv" accept=".csv,text/csv" required>
              <div class="form-text">Unterstützte Trennzeichen: Semikolon, Komma, Tab. Zeichensatz: UTF-8 empfohlen.</div>
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
              <a href="#beispiel" class="btn btn-outline-secondary">CSV-Beispiel ansehen</a>
            </div>
          </form>

          <?php
