<?php
// =================================================================
// File: config/database.php
// Deskripsi: Konfigurasi koneksi database MySQL
// =================================================================

// Konfigurasi Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gym_management1');

/**
 * Mendapatkan koneksi database
 * @return mysqli Objek koneksi database
 */
function getConnection() {
    $koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($koneksi->connect_error) {
        die("Koneksi gagal: " . $koneksi->connect_error);
    }
    
    return $koneksi;
}

/**
 * Menutup koneksi database
 * @param mysqli $koneksi Objek koneksi database
 */
function closeConnection($koneksi) {
    if ($koneksi) {
        $koneksi->close();
    }
}
?>
