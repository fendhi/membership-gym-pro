<?php
// File: logout.php
// Deskripsi: Menangani proses logout pengguna

require_once __DIR__ . '/config/session.php';

// Langsung logout ketika file ini diakses
session_destroy();
header('Location: login.php');
exit();
?>
