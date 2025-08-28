
<?php // public/index.php
require_once __DIR__.'/includes/mysql.php';
include __DIR__.'/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-md-8 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h3 mb-3">Willkommen im Aquise-Backend</h1>
        <p class="text-muted">Verwalten Sie Kampagnen, Kontakte und Telefonskripte. Bitte melden Sie sich an, um fortzufahren.</p>
        <div class="d-grid gap-2">
          <a class="btn btn-primary btn-lg" href="/login.php">Jetzt anmelden</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/partials/footer.php'; ?>