<?php
// =================================================================
// File: config/session.php
// Deskripsi: Konfigurasi dan fungsi manajemen sesi pengguna
// Menggunakan tabel Indonesia: pengguna, keanggotaan, absensi, dll
// =================================================================

// Mulai sesi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Cek apakah pengguna sudah masuk/login
 * @return bool True jika sudah login
 */
function sudahMasuk() {
    return isset($_SESSION['id_pengguna']) && isset($_SESSION['peran']);
}

/**
 * Cek apakah pengguna adalah admin
 * @return bool True jika admin
 */
function adalahAdmin() {
    return sudahMasuk() && $_SESSION['peran'] === 'admin';
}

/**
 * Cek apakah pengguna adalah member
 * @return bool True jika member
 */
function adalahMember() {
    return sudahMasuk() && $_SESSION['peran'] === 'user';
}

/**
 * Redirect ke halaman login jika belum masuk
 */
function wajibMasuk() {
    if (!sudahMasuk()) {
        header('Location: ../login.php');
        exit();
    }
}

/**
 * Redirect jika bukan admin
 */
function wajibAdmin() {
    wajibMasuk();
    if (!adalahAdmin()) {
        header('Location: ../member/dashboard.php');
        exit();
    }
}

/**
 * Redirect jika bukan member
 */
function wajibMember() {
    wajibMasuk();
    if (!adalahMember()) {
        header('Location: ../admin/dashboard.php');
        exit();
    }
}

/**
 * Fungsi untuk keluar/logout dari sistem
 */
function keluar() {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// =================================================================
// Alias untuk backward compatibility
// =================================================================
function isLoggedIn() { return sudahMasuk(); }
function isAdmin() { return adalahAdmin(); }
function isMember() { return adalahMember(); }
function requireLogin() { wajibMasuk(); }
function requireAdmin() { wajibAdmin(); }
function requireMember() { wajibMember(); }
function logout() { keluar(); }
?>
