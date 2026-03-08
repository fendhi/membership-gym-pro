<?php
// =================================================================
// File: config/session_hosting.php
// Deskripsi: Versi untuk hosting (tanpa prefix /gym/)
// =================================================================

// Mulai sesi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deteksi base path
function getBasePath() {
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    // Jika di dalam folder gym, gunakan /gym
    if (strpos($scriptPath, '/gym') !== false) {
        return '/gym';
    }
    // Jika di root, gunakan string kosong
    return '';
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
        $base = getBasePath();
        header('Location: ' . $base . '/login.php');
        exit();
    }
}

/**
 * Redirect jika bukan admin
 */
function wajibAdmin() {
    wajibMasuk();
    if (!adalahAdmin()) {
        $base = getBasePath();
        header('Location: ' . $base . '/member/dashboard.php');
        exit();
    }
}

/**
 * Redirect jika bukan member
 */
function wajibMember() {
    wajibMasuk();
    if (!adalahMember()) {
        $base = getBasePath();
        header('Location: ' . $base . '/admin/dashboard.php');
        exit();
    }
}

/**
 * Fungsi untuk keluar/logout dari sistem
 */
function keluar() {
    session_destroy();
    $base = getBasePath();
    header('Location: ' . $base . '/login.php');
    exit();
}

// Alias untuk backward compatibility
function isLoggedIn() { return sudahMasuk(); }
function isAdmin() { return adalahAdmin(); }
function isMember() { return adalahMember(); }
function requireLogin() { wajibMasuk(); }
function requireAdmin() { wajibAdmin(); }
function requireMember() { wajibMember(); }
function logout() { keluar(); }
?>
