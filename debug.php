<?php
// =================================================================
// File: debug.php
// Deskripsi: File untuk debugging masalah di hosting
// HAPUS FILE INI SETELAH SELESAI DEBUGGING!
// =================================================================

echo "<h1>🔧 Debug Info - Gym Management System</h1>";
echo "<hr>";

// 0. List semua file di root
echo "<h2>0. Semua File di Root Directory</h2>";
$root_files = scandir(__DIR__);
echo "<p><strong>Path:</strong> " . __DIR__ . "</p>";
echo "<ul>";
foreach ($root_files as $f) {
    if ($f != '.' && $f != '..') {
        $is_dir = is_dir(__DIR__ . '/' . $f) ? ' 📁' : '';
        echo "<li>" . htmlspecialchars($f) . $is_dir . "</li>";
    }
}
echo "</ul>";

// 1. Info PHP
echo "<h2>1. PHP Info</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "</p>";
echo "<p><strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "</p>";
echo "<p><strong>Current Script:</strong> " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "</p>";

// 2. Error Reporting
echo "<h2>2. Error Reporting</h2>";
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
echo "<p><strong>Error reporting:</strong> Enabled (E_ALL)</p>";

// 3. Cek file-file penting
echo "<h2>3. Cek File Penting</h2>";
$files_to_check = [
    'config/database.php',
    'config/session.php',
    'login.php',
    'index.php',
    'admin/dashboard.php',
    'member/dashboard.php'
];

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>File</th><th>Status</th><th>Size</th></tr>";
foreach ($files_to_check as $file) {
    $full_path = __DIR__ . '/' . $file;
    $exists = file_exists($full_path);
    $size = $exists ? filesize($full_path) . ' bytes' : '-';
    $status = $exists ? '✅ Ada' : '❌ Tidak Ada';
    echo "<tr><td>$file</td><td>$status</td><td>$size</td></tr>";
}
echo "</table>";

// 4. Cek koneksi database
echo "<h2>4. Test Koneksi Database</h2>";

// Coba load config database
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    
    echo "<p><strong>DB_HOST:</strong> " . (defined('DB_HOST') ? DB_HOST : 'Tidak terdefinisi') . "</p>";
    echo "<p><strong>DB_USER:</strong> " . (defined('DB_USER') ? DB_USER : 'Tidak terdefinisi') . "</p>";
    echo "<p><strong>DB_NAME:</strong> " . (defined('DB_NAME') ? DB_NAME : 'Tidak terdefinisi') . "</p>";
    
    // Test koneksi
    echo "<h3>Test Koneksi:</h3>";
    try {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            echo "<p style='color: red;'>❌ <strong>Koneksi GAGAL:</strong> " . $conn->connect_error . "</p>";
            
            // Coba koneksi tanpa database
            echo "<h4>Mencoba koneksi tanpa database...</h4>";
            $conn2 = @new mysqli(DB_HOST, DB_USER, DB_PASS);
            if ($conn2->connect_error) {
                echo "<p style='color: red;'>❌ Koneksi ke MySQL server juga gagal: " . $conn2->connect_error . "</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Koneksi ke MySQL OK, tapi database '<strong>" . DB_NAME . "</strong>' tidak ditemukan!</p>";
                
                // List database yang ada
                echo "<h4>Database yang tersedia:</h4>";
                $result = $conn2->query("SHOW DATABASES");
                if ($result) {
                    echo "<ul>";
                    while ($row = $result->fetch_array()) {
                        echo "<li>" . $row[0] . "</li>";
                    }
                    echo "</ul>";
                }
                $conn2->close();
            }
        } else {
            echo "<p style='color: green;'>✅ <strong>Koneksi BERHASIL!</strong></p>";
            
            // Cek tabel
            echo "<h4>Tabel dalam database:</h4>";
            $result = $conn->query("SHOW TABLES");
            if ($result && $result->num_rows > 0) {
                echo "<ul>";
                while ($row = $result->fetch_array()) {
                    echo "<li>" . $row[0] . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<p style='color: orange;'>⚠️ Database kosong atau tidak ada tabel!</p>";
            }
            
            $conn->close();
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ <strong>Exception:</strong> " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ File config/database.php tidak ditemukan!</p>";
}

// 5. Cek Session
echo "<h2>5. Test Session</h2>";
session_start();
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Status:</strong> " . (session_status() === PHP_SESSION_ACTIVE ? '✅ Aktif' : '❌ Tidak Aktif') . "</p>";
echo "<p><strong>Session Save Path:</strong> " . session_save_path() . "</p>";

// Test write session
$_SESSION['debug_test'] = 'OK - ' . date('Y-m-d H:i:s');
echo "<p><strong>Session Write Test:</strong> " . ($_SESSION['debug_test'] ?? '❌ Gagal') . "</p>";

// 6. Cek Permissions
echo "<h2>6. Cek Folder Permissions</h2>";
$folders_to_check = [
    '.',
    'config',
    'uploads',
    'uploads/payment_proofs',
    'assets',
    'assets/images'
];

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Folder</th><th>Exists</th><th>Writable</th><th>Permissions</th></tr>";
foreach ($folders_to_check as $folder) {
    $full_path = __DIR__ . '/' . $folder;
    $exists = is_dir($full_path);
    $writable = $exists ? is_writable($full_path) : false;
    $perms = $exists ? substr(sprintf('%o', fileperms($full_path)), -4) : '-';
    
    $exists_status = $exists ? '✅' : '❌';
    $writable_status = $writable ? '✅' : '❌';
    
    echo "<tr><td>$folder</td><td>$exists_status</td><td>$writable_status</td><td>$perms</td></tr>";
}
echo "</table>";

// 7. PHP Extensions
echo "<h2>7. PHP Extensions yang Dibutuhkan</h2>";
$required_extensions = ['mysqli', 'pdo', 'pdo_mysql', 'gd', 'mbstring', 'json', 'session'];

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Extension</th><th>Status</th></tr>";
foreach ($required_extensions as $ext) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? '✅ Loaded' : '❌ Not Loaded';
    echo "<tr><td>$ext</td><td>$status</td></tr>";
}
echo "</table>";

// 8. Test include login.php
echo "<h2>8. Test Load Login Page</h2>";
echo "<p>Mencoba mengecek login.php...</p>";

$login_file = __DIR__ . '/login.php';
if (file_exists($login_file)) {
    echo "<p style='color: green;'>✅ login.php: File exists (" . filesize($login_file) . " bytes)</p>";
    
    // Coba baca beberapa baris pertama untuk cek syntax dasar
    $content = file_get_contents($login_file);
    if ($content !== false) {
        // Cek apakah ada opening PHP tag
        if (strpos($content, '<?php') !== false || strpos($content, '<?') !== false) {
            echo "<p style='color: green;'>✅ PHP opening tag ditemukan</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Tidak ada PHP opening tag</p>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ login.php tidak ditemukan</p>";
}

// 9. Informasi Hosting
echo "<h2>9. Server Info</h2>";
echo "<p><strong>Server Name:</strong> " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "</p>";
echo "<p><strong>HTTP Host:</strong> " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "</p>";
echo "<p><strong>Request URI:</strong> " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "</p>";
echo "<p><strong>Remote Address:</strong> " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "</p>";

// Memory & Limits
echo "<h2>10. PHP Limits</h2>";
echo "<p><strong>memory_limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "<p><strong>max_execution_time:</strong> " . ini_get('max_execution_time') . " seconds</p>";
echo "<p><strong>upload_max_filesize:</strong> " . ini_get('upload_max_filesize') . "</p>";
echo "<p><strong>post_max_size:</strong> " . ini_get('post_max_size') . "</p>";

echo "<hr>";
echo "<p style='color: red; font-weight: bold;'>⚠️ PENTING: Hapus file debug.php ini setelah selesai debugging!</p>";
echo "<p><em>Generated at: " . date('Y-m-d H:i:s') . "</em></p>";
?>
