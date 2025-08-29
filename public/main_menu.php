<?php
// main_menu.php – Bootstrap 5 Navbar für Aquise Backend
// Einbinden auf Seiten oberhalb des <main>-Bereichs:
//   <?php include __DIR__.'/main_menu.php'; 
// Erwartet: bestehende Session (optional), $_SESSION['user'] mit ['username','full_name']

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = basename($path);
$active = function(array $names) use ($base){ return in_array($base, $names, true) ? 'active' : ''; };
$userLabel = htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '');
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/index.php">Aquise</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?= $active(['index.php','dashboard.php']) ?>" href="/public/dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $active(['companies.php','company_edit.php']) ?>" href="/public/companies.php">Firmen</a></li>
        <li class="nav-item"><a class="nav-link <?= $active(['contacts.php','contact_edit.php']) ?>" href="/public/contacts.php">Kontakte</a></li>
        <li class="nav-item"><a class="nav-link <?= $active(['dashboard.php']) ?>" href="/public/campaigns.php">Kampagnen</a></li>
        <li class="nav-item"><a class="nav-link <?= $active(['contact_import.php']) ?>" href="/public/contact_import.php">Import (CSV)</a></li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <?php if($userLabel): ?><span class="navbar-text text-white">👤 <?= $userLabel ?></span><?php endif; ?>
        <a class="btn btn-warning btn-sm" href="/public/logout.php">Logout</a>
      </div>
    </div>
  </div>
</nav>
