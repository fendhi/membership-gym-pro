<?php
require_once 'config/database.php';
require_once 'config/session.php';

$pesan_error = null;

// Redirect jika sudah masuk
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: member/dashboard.php');
    }
    exit();
}

// Proses login sebelum output HTML agar header masih bisa digunakan
if (isset($_POST['masuk'])) {
    $nama_pengguna = $_POST['nama_pengguna'];
    $kata_sandi = $_POST['kata_sandi'];
    
    $koneksi = getConnection();
    // Query ke tabel users (sesuai database.sql)
    $stmt = $koneksi->prepare("SELECT id, username, full_name, role FROM users WHERE username = ? AND password = MD5(?)");
    $stmt->bind_param("ss", $nama_pengguna, $kata_sandi);
    $stmt->execute();
    $hasil = $stmt->get_result();
    
    if ($hasil->num_rows === 1) {
        $pengguna = $hasil->fetch_assoc();
        $_SESSION['id_pengguna'] = $pengguna['id'];
        $_SESSION['nama_pengguna'] = $pengguna['username'];
        $_SESSION['nama_lengkap'] = $pengguna['full_name'];
        $_SESSION['peran'] = $pengguna['role'];
        
        $stmt->close();
        closeConnection($koneksi);

        if ($pengguna['role'] === 'admin') {
            header('Location: admin/dashboard.php');
        } else {
            header('Location: member/dashboard.php');
        }
        exit();
    } else {
        $pesan_error = "Nama pengguna atau kata sandi salah!";
    }
    
    $stmt->close();
    closeConnection($koneksi);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - Sistem Manajemen Gym</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo/Kepala -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-r from-gray-600 to-gray-800 rounded-full mb-4 shadow-lg">
                <i class="fas fa-dumbbell text-white text-3xl"></i>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2">AturGym</h1>
            <p class="text-gray-300">Sistem Manajemen Gym</p>
        </div>

        <!-- Kartu Login -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-8 border border-white/20">
            <h2 class="text-2xl font-bold text-white mb-6 text-center">Masuk ke Akun</h2>
            
            <?php if (isset($pesan_error)): ?>
            <div class="bg-gray-500/20 border border-gray-400 text-gray-200 px-4 py-3 rounded-lg mb-4 flex items-center justify-between">
                <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo $pesan_error; ?></div>
                <button type="button" onclick="this.parentElement.remove()" class="text-gray-200 hover:text-white transition ml-4">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <div>
                    <label for="nama_pengguna" class="block text-sm font-medium text-gray-200 mb-2">
                        <i class="fas fa-user mr-2"></i>Nama Pengguna
                    </label>
                    <input type="text" id="nama_pengguna" name="nama_pengguna" required
                        class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent transition"
                        placeholder="Masukkan nama pengguna">
                </div>

                <div>
                    <label for="kata_sandi" class="block text-sm font-medium text-gray-200 mb-2">
                        <i class="fas fa-lock mr-2"></i>Kata Sandi
                    </label>
                    <div class="relative">
                        <input type="password" id="kata_sandi" name="kata_sandi" required
                            class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent transition"
                            placeholder="Masukkan kata sandi">
                        <button type="button" onclick="toggleKataSandi()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                            <i class="fas fa-eye" id="ikonToggle"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="masuk"
                    class="w-full bg-gradient-to-r from-gray-700 to-gray-900 text-white py-3 rounded-lg font-semibold hover:from-gray-600 hover:to-gray-800 transform hover:scale-[1.02] transition shadow-lg">
                    <i class="fas fa-sign-in-alt mr-2"></i>Masuk
                </button>
            </form>
        </div>

        <!-- Kaki Halaman -->
        <div class="text-center mt-8 text-gray-400 text-sm">
            <p>&copy; 2025 AturGym. Hak cipta dilindungi.</p>
        </div>
    </div>

    <script>
        function toggleKataSandi() {
            const inputKataSandi = document.getElementById('kata_sandi');
            const ikonToggle = document.getElementById('ikonToggle');
            
            if (inputKataSandi.type === 'password') {
                inputKataSandi.type = 'text';
                ikonToggle.classList.remove('fa-eye');
                ikonToggle.classList.add('fa-eye-slash');
            } else {
                inputKataSandi.type = 'password';
                ikonToggle.classList.remove('fa-eye-slash');
                ikonToggle.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
