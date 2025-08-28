
<?php // public/logout.php
include __DIR__.'/partials/header.php';
session_destroy();
flash('success', 'Abgemeldet.');
header('Location: /index.php'); exit;
?>