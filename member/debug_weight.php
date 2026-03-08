<?php
// =================================================================
// File: member/debug_weight.php
// Deskripsi: Debug file untuk cek masalah weight tracker
// HAPUS FILE INI SETELAH SELESAI DEBUG!
// =================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Debug Weight Tracker</h2>";
echo "<hr>";

// 1. Cek koneksi database
echo "<h3>1. Cek Koneksi Database</h3>";
try {
    require_once '../config/database.php';
    $koneksi = getConnection();
    if ($koneksi->connect_error) {
        echo "❌ <b>GAGAL:</b> " . $koneksi->connect_error . "<br>";
    } else {
        echo "✅ Koneksi database <b>BERHASIL</b><br>";
        echo "   - Server: " . $koneksi->host_info . "<br>";
    }
} catch (Exception $e) {
    echo "❌ <b>ERROR:</b> " . $e->getMessage() . "<br>";
}

echo "<hr>";

// 2. Cek session
echo "<h3>2. Cek Session</h3>";
try {
    require_once '../config/session.php';
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        echo "✅ Session aktif<br>";
    } else {
        echo "❌ Session tidak aktif<br>";
    }
    
    echo "   - Session ID: " . session_id() . "<br>";
    echo "   - id_pengguna: " . ($_SESSION['id_pengguna'] ?? '<b>TIDAK ADA</b>') . "<br>";
    echo "   - peran: " . ($_SESSION['peran'] ?? '<b>TIDAK ADA</b>') . "<br>";
    echo "   - nama_lengkap: " . ($_SESSION['nama_lengkap'] ?? '<b>TIDAK ADA</b>') . "<br>";
    
    if (!isset($_SESSION['id_pengguna'])) {
        echo "<br>⚠️ <b>Anda belum login!</b> Silakan login dulu.<br>";
    }
} catch (Exception $e) {
    echo "❌ <b>ERROR Session:</b> " . $e->getMessage() . "<br>";
}

echo "<hr>";

// 3. Cek tabel weight_logs
echo "<h3>3. Cek Tabel weight_logs</h3>";
try {
    $result = $koneksi->query("SHOW TABLES LIKE 'weight_logs'");
    if ($result->num_rows > 0) {
        echo "✅ Tabel <b>weight_logs</b> ada<br>";
        
        // Cek struktur tabel
        $struktur = $koneksi->query("DESCRIBE weight_logs");
        echo "<br><b>Struktur tabel:</b><br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $struktur->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Hitung jumlah data
        $count = $koneksi->query("SELECT COUNT(*) as total FROM weight_logs")->fetch_assoc();
        echo "<br>Total data di tabel: <b>" . $count['total'] . "</b> record<br>";
        
    } else {
        echo "❌ Tabel <b>weight_logs TIDAK ADA!</b><br>";
        echo "<br><b>Jalankan query ini di phpMyAdmin:</b><br>";
        echo "<pre style='background:#f5f5f5; padding:10px; border:1px solid #ddd;'>";
        echo "CREATE TABLE IF NOT EXISTS weight_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    weight DECIMAL(5,2) NOT NULL,
    log_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_date (user_id, log_date)
);";
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "❌ <b>ERROR:</b> " . $e->getMessage() . "<br>";
}

echo "<hr>";

// 4. Cek tabel financial_transactions
echo "<h3>4. Cek Tabel financial_transactions</h3>";
try {
    $result = $koneksi->query("SHOW TABLES LIKE 'financial_transactions'");
    if ($result->num_rows > 0) {
        echo "✅ Tabel <b>financial_transactions</b> ada<br>";
    } else {
        echo "❌ Tabel <b>financial_transactions TIDAK ADA!</b><br>";
        echo "<br><b>Jalankan query ini di phpMyAdmin:</b><br>";
        echo "<pre style='background:#f5f5f5; padding:10px; border:1px solid #ddd;'>";
        echo "CREATE TABLE IF NOT EXISTS financial_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    membership_id INT NULL,
    member_name VARCHAR(100) NOT NULL,
    member_username VARCHAR(50) NOT NULL,
    transaction_type ENUM('new_membership', 'extension') NOT NULL,
    package_type ENUM('1_month', '3_months', '6_months', '12_months') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'qris') NOT NULL,
    transaction_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type)
);";
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "❌ <b>ERROR:</b> " . $e->getMessage() . "<br>";
}

echo "<hr>";

// 5. Cek file yang diperlukan
echo "<h3>5. Cek File yang Diperlukan</h3>";
$files = [
    '../config/database.php' => 'Database config',
    '../config/session.php' => 'Session config',
    'weight_tracker.php' => 'Weight tracker',
    'weight_pdf.php' => 'Weight PDF',
    'dashboard.php' => 'Member dashboard',
];

foreach ($files as $file => $desc) {
    if (file_exists($file)) {
        echo "✅ <b>$desc</b> ($file) - Ada<br>";
    } else {
        echo "❌ <b>$desc</b> ($file) - <b>TIDAK ADA!</b><br>";
    }
}

echo "<hr>";

// 6. Info PHP
echo "<h3>6. Info Server</h3>";
echo "PHP Version: <b>" . phpversion() . "</b><br>";
echo "Server: <b>" . $_SERVER['SERVER_SOFTWARE'] . "</b><br>";
echo "Document Root: <b>" . $_SERVER['DOCUMENT_ROOT'] . "</b><br>";
echo "Script Path: <b>" . $_SERVER['SCRIPT_FILENAME'] . "</b><br>";

echo "<hr>";

// 7. Test query weight_logs
echo "<h3>7. Test Query weight_logs</h3>";
if (isset($_SESSION['id_pengguna'])) {
    try {
        $id_user = $_SESSION['id_pengguna'];
        $test = $koneksi->query("SELECT * FROM weight_logs WHERE user_id = $id_user ORDER BY log_date DESC LIMIT 5");
        
        if ($test) {
            echo "✅ Query berhasil dijalankan<br>";
            echo "Jumlah data untuk user ini: <b>" . $test->num_rows . "</b><br>";
            
            if ($test->num_rows > 0) {
                echo "<br><b>5 Data terakhir:</b><br>";
                echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
                echo "<tr><th>ID</th><th>Tanggal</th><th>Berat (kg)</th><th>Catatan</th></tr>";
                while ($row = $test->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $row['id'] . "</td>";
                    echo "<td>" . $row['log_date'] . "</td>";
                    echo "<td>" . $row['weight'] . "</td>";
                    echo "<td>" . ($row['notes'] ?? '-') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } else {
            echo "❌ Query gagal: " . $koneksi->error . "<br>";
        }
    } catch (Exception $e) {
        echo "❌ <b>ERROR:</b> " . $e->getMessage() . "<br>";
    }
} else {
    echo "⚠️ Tidak bisa test query - belum login<br>";
}

echo "<hr>";
echo "<p style='color:red;'><b>⚠️ PENTING: Hapus file ini setelah selesai debug!</b></p>";
echo "<p><a href='weight_tracker.php'>← Kembali ke Weight Tracker</a> | <a href='dashboard.php'>← Kembali ke Dashboard</a></p>";

// Tutup koneksi
if (isset($koneksi)) {
    $koneksi->close();
}
?>
