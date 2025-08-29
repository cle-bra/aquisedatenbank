<?php
// index.php (Standalone)
// Keine Abhängigkeit zu includes/partials – reines Bootstrap-Gerüst
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function is_logged_in() { return isset($_SESSION['user']); }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="KNX Aquise Backend – Startseite">
  <title>Aquise Backend · Start</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; display: flex; flex-direction: column; }
    main { flex: 1; }
    .hero {
      background: linear-gradient(180deg, rgba(0,0,0,.6), rgba(0,0,0,.3)), url('https://images.unsplash.com/photo-1525182008055-f88b95ff7980?q=80&w=1600&auto=format&fit=crop');
      background-size: cover; background-position: center; color: #fff;
      border-radius: .75rem;
    }
  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbars" aria-controls="navbars" aria-expanded="false" aria-label="Navigation umschalten">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbars">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="/index.php">Start</a></li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <?php if (is_logged_in()): ?>
          <span class="navbar-text text-white-50">👤 <?= htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username']) ?></span>
          <a class="btn btn-outline-light btn-sm" href="/public/dashboard.php">Dashboard</a>
          <a class="btn btn-warning btn-sm" href="/logout.php">Logout</a>
        <?php else: ?>
          <a class="btn btn-outline-light btn-sm" href="/public/login.php">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<main class="container py-4">
  <div class="row g-3">
    <div class="col-12">
      <div class="p-5 hero shadow-sm">
        <div class="col-lg-8">
          <h1 class="display-6 fw-semibold">Willkommen im KNX Aquise‑Backend</h1>
          <p class="lead">Steuern Sie Telefonaquise, Kampagnen und Skripte für das KNX‑Trainingcenter. Voll responsiv auf Basis von Bootstrap 5.</p>
          <div class="d-flex flex-wrap gap-2">
            <?php if (is_logged_in()): ?>
              <a href="/public/dashboard.php" class="btn btn-primary btn-lg">Zum Dashboard</a>
              <a href="/public/logout.php" class="btn btn-outline-light btn-lg">Abmelden</a>
            <?php else: ?>
              <a href="/public/login.php" class="btn btn-primary btn-lg">Jetzt anmelden</a>
              <a href="#features" class="btn btn-outline-light btn-lg">Mehr erfahren</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12" id="features">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <h2 class="h5">Kampagnen</h2>
              <p class="text-muted mb-3">Organisieren Sie Akquise-Kampagnen wie „Berlin 2026“, planen Sie Zeiträume und tracken Sie Ergebnisse.</p>
              <a href="/public/login.php" class="btn btn-outline-primary btn-sm">Anmelden, um fortzufahren</a>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <h2 class="h5">Kontakte & Firmen</h2>
              <p class="text-muted mb-3">Pflegen Sie Firmen, Ansprechpartner und DSGVO‑Einwilligungen. Behalten Sie die Historie im Blick.</p>
              <a href="/public/login.php" class="btn btn-outline-primary btn-sm">Anmelden, um fortzufahren</a>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <h2 class="h5">Skripte & Flows</h2>
              <p class="text-muted mb-3">Erstellen Sie Fragenkataloge mit Verzweigungen und nutzen Sie sie im Dialer für konsistente Gespräche.</p>
              <a href="/public/login.php" class="btn btn-outline-primary btn-sm">Anmelden, um fortzufahren</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h5">Schnellzugriff</h2>
          <div class="row g-2">
            <div class="col-12 col-md-6 col-lg-3 d-grid">
              <a class="btn btn-outline-secondary" href="/public/login.php">Login</a>
            </div>
            <div class="col-12 col-md-6 col-lg-3 d-grid">
              <a class="btn btn-outline-secondary disabled" aria-disabled="true" href="#">Kontakt-Import (CSV)</a>
            </div>
            <div class="col-12 col-md-6 col-lg-3 d-grid">
              <a class="btn btn-outline-secondary disabled" aria-disabled="true" href="#">Dialer starten</a>
            </div>
            <div class="col-12 col-md-6 col-lg-3 d-grid">
              <a class="btn btn-outline-secondary disabled" aria-disabled="true" href="#">Reports</a>
            </div>
          </div>
          <p class="small text-muted mt-3 mb-0">Hinweis: Funktionen werden nach Anmeldung freigeschaltet.</p>
        </div>
      </div>
    </div>
  </div>
</main>

<footer class="py-4 bg-white border-top">
  <div class="container d-flex flex-column flex-md-row justify-content-between small text-muted">
    <span>© <?= date('Y') ?> KNX‑Trainingcenter · Aquise Backend</span>
    <span>Built with Bootstrap 5</span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
