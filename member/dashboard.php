<?php
// =================================================================
// File: member/dashboard.php
// Deskripsi: Dashboard Member untuk Manajemen Gym
// Menggunakan tabel: users, memberships, attendance, extension_requests
// =================================================================

require_once '../config/database.php';
require_once '../config/session.php';
requireMember();

// Koneksi database
$koneksi = getConnection();
$id_pengguna = $_SESSION['id_pengguna'];
$pesan_sukses = null;
$pesan_error = null;

// Cek pesan sukses/error dari redirect (PRG pattern)
if (isset($_SESSION['pesan_sukses'])) {
    $pesan_sukses = $_SESSION['pesan_sukses'];
    unset($_SESSION['pesan_sukses']);
}
if (isset($_SESSION['pesan_error'])) {
    $pesan_error = $_SESSION['pesan_error'];
    unset($_SESSION['pesan_error']);
}

// Cek apakah ada redirect WhatsApp
$wa_redirect = null;
if (isset($_SESSION['wa_redirect'])) {
    $wa_redirect = $_SESSION['wa_redirect'];
    unset($_SESSION['wa_redirect']);
}

// Daftar harga paket (sesuai dengan enum di database)
$daftarHargaPaket = [
    '1_month' => 250000,
    '3_months' => 650000,
    '6_months' => 1200000,
    '12_months' => 2200000,
];

// Metode pembayaran yang tersedia (member hanya bisa transfer/qris, cash hanya admin)
$metodePembayaran = [
    'transfer' => 'Transfer Bank',
    'qris' => 'QRIS',
];

// Informasi Rekening Bank
$daftarRekening = [
    ['bank' => 'BCA', 'no_rek' => '1234567890', 'atas_nama' => 'AturGym Indonesia'],
    ['bank' => 'Mandiri', 'no_rek' => '0987654321', 'atas_nama' => 'AturGym Indonesia'],
    ['bank' => 'BNI', 'no_rek' => '1122334455', 'atas_nama' => 'AturGym Indonesia']
];

// Path gambar QRIS (letakkan file QRIS di folder assets/images/)
$gambarQris = '../assets/images/qris.png';

// Fungsi helper untuk menghitung jumlah hari dari paket (1 bulan = 28 hari)
function hitungHariPaket(string $paket): int {
    switch ($paket) {
        case '3_months': return 84;  // 3 x 28 hari
        case '6_months': return 168; // 6 x 28 hari
        case '12_months': return 336; // 12 x 28 hari
        default: return 28; // 1 bulan = 28 hari
    }
}

// Fungsi helper untuk format mata uang
function formatRupiah(float $jumlah): string {
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

// Fungsi helper untuk format nomor WhatsApp
function formatNomorWA($nomor) {
    // Hapus karakter non-angka
    $nomor = preg_replace('/[^0-9]/', '', $nomor);
    
    // Jika diawali 0, ganti dengan 62
    if (substr($nomor, 0, 1) === '0') {
        $nomor = '62' . substr($nomor, 1);
    }
    
    // Jika tidak diawali 62, tambahkan 62
    if (substr($nomor, 0, 2) !== '62') {
        $nomor = '62' . $nomor;
    }
    
    return $nomor;
}

// =================================================================
// AMBIL DATA PENGGUNA
// =================================================================
$query_pengguna = "SELECT * FROM users WHERE id = $id_pengguna";
$dataPengguna = $koneksi->query($query_pengguna)->fetch_assoc();

// =================================================================
// AMBIL DATA MEMBERSHIP
// =================================================================
$query_membership = "
    SELECT m.*, DATEDIFF(m.end_date, CURDATE()) as days_remaining
    FROM memberships m
    WHERE m.user_id = $id_pengguna
    ORDER BY m.end_date DESC
    LIMIT 1
";
$hasil_membership = $koneksi->query($query_membership);
$dataMembership = $hasil_membership->fetch_assoc();

// =================================================================
// AMBIL RIWAYAT ABSENSI
// =================================================================
$query_absensi = "
    SELECT a.*, u.full_name as admin_name
    FROM attendance a
    LEFT JOIN users u ON a.created_by = u.id
    WHERE a.user_id = $id_pengguna
    ORDER BY a.check_in DESC
    LIMIT 10
";
$daftarAbsensi = $koneksi->query($query_absensi);

// =================================================================
// AMBIL RIWAYAT MEMBERSHIP
// =================================================================
$membership_id = $dataMembership['id'] ?? 0;
$query_riwayat = "
    SELECT mh.*, u.full_name as admin_name
    FROM membership_history mh
    LEFT JOIN users u ON mh.extended_by = u.id
    WHERE mh.membership_id = $membership_id
    ORDER BY mh.created_at DESC
    LIMIT 5
";
$riwayatMembership = $koneksi->query($query_riwayat);

// =================================================================
// AMBIL PERMINTAAN PERPANJANGAN
// =================================================================
$query_permintaan = "
    SELECT er.*, u.full_name as admin_name
    FROM extension_requests er
    LEFT JOIN users u ON er.verified_by = u.id
    WHERE er.user_id = $id_pengguna
    ORDER BY er.created_at DESC
    LIMIT 10
";
$daftarPermintaan = $koneksi->query($query_permintaan);

// =================================================================
// PROSES PERPANJANGAN MEMBERSHIP
// =================================================================
if (isset($_POST['perpanjang'])) {
    $paket = $_POST['paket'] ?? '';
    $harga = isset($daftarHargaPaket[$paket]) ? $daftarHargaPaket[$paket] : 0;
    $metode_bayar = $_POST['metode_pembayaran'] ?? '';

    // Validasi
    if (!$dataMembership) {
        $_SESSION['pesan_error'] = "Anda belum memiliki membership aktif.";
        header("Location: dashboard.php");
        exit;
    } elseif (!array_key_exists($paket, $daftarHargaPaket)) {
        $_SESSION['pesan_error'] = "Paket tidak valid.";
        header("Location: dashboard.php");
        exit;
    } elseif (!array_key_exists($metode_bayar, $metodePembayaran)) {
        $_SESSION['pesan_error'] = "Metode pembayaran tidak valid.";
        header("Location: dashboard.php");
        exit;
    } else {
        // Member harus upload bukti pembayaran (transfer/qris)
        $bukti_pembayaran = null;
        $error_upload = null;
        
        if (isset($_FILES['bukti_pembayaran']) && $_FILES['bukti_pembayaran']['error'] === UPLOAD_ERR_OK) {
            $tipe_diizinkan = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $ukuran_maks = 5 * 1024 * 1024;
            
            $tipe_file = $_FILES['bukti_pembayaran']['type'];
            $ukuran_file = $_FILES['bukti_pembayaran']['size'];
            
            if (!in_array($tipe_file, $tipe_diizinkan)) {
                $error_upload = "Format file tidak valid. Gunakan JPG, PNG, atau GIF.";
            } elseif ($ukuran_file > $ukuran_maks) {
                $error_upload = "Ukuran file terlalu besar. Maksimal 5MB.";
            } else {
                $direktori_upload = '../uploads/payment_proofs/';
                if (!is_dir($direktori_upload)) {
                    mkdir($direktori_upload, 0777, true);
                }
                
                $ekstensi = pathinfo($_FILES['bukti_pembayaran']['name'], PATHINFO_EXTENSION);
                $nama_file = 'proof_' . $id_pengguna . '_' . time() . '.' . $ekstensi;
                $path_file = $direktori_upload . $nama_file;
                
                if (move_uploaded_file($_FILES['bukti_pembayaran']['tmp_name'], $path_file)) {
                    $bukti_pembayaran = $nama_file;
                } else {
                    $error_upload = "Gagal mengupload bukti pembayaran.";
                }
            }
        } else {
            $error_upload = "Bukti pembayaran wajib diupload untuk metode Transfer/QRIS.";
        }
        
        if ($error_upload) {
            $_SESSION['pesan_error'] = $error_upload;
            closeConnection($koneksi);
            header("Location: dashboard.php");
            exit;
        }
        
        if ($bukti_pembayaran) {
            // Cek apakah sudah ada permintaan pending
            $cek_pending = $koneksi->prepare("SELECT id FROM extension_requests WHERE user_id = ? AND membership_id = ? AND status = 'pending'");
            $cek_pending->bind_param("ii", $id_pengguna, $dataMembership['id']);
            $cek_pending->execute();
            $cek_pending->store_result();
            
            if ($cek_pending->num_rows > 0) {
                $_SESSION['pesan_error'] = "Anda masih memiliki permintaan yang menunggu verifikasi.";
            } else {
                $stmt = $koneksi->prepare("INSERT INTO extension_requests (user_id, membership_id, package_type, amount, payment_method, payment_proof, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->bind_param("iisdss", $id_pengguna, $dataMembership['id'], $paket, $harga, $metode_bayar, $bukti_pembayaran);
                
if ($stmt->execute()) {
                    // Ambil nomor telepon admin dari database
                    $queryAdmin = $koneksi->query("SELECT phone FROM users WHERE role = 'admin' AND phone IS NOT NULL AND phone != '' LIMIT 1");
                    $adminPhone = '';
                    if ($queryAdmin && $rowAdmin = $queryAdmin->fetch_assoc()) {
                        $adminPhone = formatNomorWA($rowAdmin['phone']);
                    }
                    
                    // Buat pesan WhatsApp
                    $pesanWA = "Halo Admin AturGym,%0A%0A";
                    $pesanWA .= "Saya ingin mengkonfirmasi pembayaran perpanjangan membership:%0A%0A";
                    $pesanWA .= "👤 Nama: " . urlencode($dataPengguna['full_name']) . "%0A";
                    $pesanWA .= "📧 Username: " . urlencode($dataPengguna['username']) . "%0A";
                    $pesanWA .= "📦 Paket: " . urlencode(str_replace('_', ' ', ucwords($paket, '_'))) . "%0A";
                    $pesanWA .= "💰 Jumlah: Rp " . number_format($harga, 0, ',', '.') . "%0A";
                    $pesanWA .= "💳 Metode: " . ucfirst($metode_bayar) . "%0A%0A";
                    $pesanWA .= "Bukti pembayaran sudah saya upload di sistem.%0A";
                    $pesanWA .= "Mohon diverifikasi. Terima kasih! 🙏";
                    
                    // Simpan link WA ke session untuk redirect
                    if (!empty($adminPhone)) {
                        $_SESSION['wa_redirect'] = "https://wa.me/" . $adminPhone . "?text=" . $pesanWA;
                    } else {
                        // Debug: jika nomor admin tidak ditemukan
                        error_log("WhatsApp Error: Nomor admin tidak ditemukan di database");
                    }
                    
                    $_SESSION['pesan_sukses'] = "Permintaan perpanjangan berhasil dikirim! Menunggu verifikasi admin.";
                } else {
                    $_SESSION['pesan_error'] = "Gagal mengirim permintaan perpanjangan.";
                }
            }
            $cek_pending->close();
            closeConnection($koneksi);
            header("Location: dashboard.php");
            exit;
        }
    }
}

closeConnection($koneksi);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Member - AturGym</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen">
    <!-- Navigasi -->
    <nav class="bg-black/30 backdrop-blur-lg text-white shadow-2xl border-b border-white/10">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between py-4">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-dumbbell text-2xl text-gray-400"></i>
                        <span class="text-xl font-bold">AturGym</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="catatan_bb.php" class="hidden md:flex bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-500 hover:to-gray-600 px-4 py-2 rounded-lg transition items-center">
                        <i class="fas fa-weight mr-2"></i>Catatan BB
                    </a>
                    <span class="hidden md:block"><i class="fas fa-user mr-2"></i><?php echo $_SESSION['nama_lengkap']; ?></span>
                    <a href="../logout.php" class="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>Keluar
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Mobile: Tombol Catatan BB di luar navbar -->
    <div class="md:hidden container mx-auto px-4 py-3 flex justify-end">
        <a href="catatan_bb.php" class="bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-500 hover:to-gray-600 px-4 py-2 rounded-lg transition text-sm text-white">
            <i class="fas fa-weight mr-2"></i>Catatan BB
        </a>
    </div>

    <!-- Konten Utama -->
    <div class="container mx-auto px-4 py-8">
        <!-- Pesan Alert -->
        <?php if (isset($pesan_sukses)): ?>
        <div class="bg-gray-500/20 border-l-4 border-gray-500 text-gray-200 p-4 mb-6 rounded-lg shadow backdrop-blur-sm">
            <div class="flex items-center justify-between">
                <div><i class="fas fa-check-circle mr-2"></i><?php echo $pesan_sukses; ?></div>
                <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-gray-200 hover:text-white transition ml-4">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php if ($wa_redirect): ?>
            <div class="mt-3 pt-3 border-t border-gray-500/30">
                <a href="<?php echo $wa_redirect; ?>" target="_blank" class="inline-flex items-center bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fab fa-whatsapp text-xl mr-2"></i>
                    Kirim Konfirmasi ke WhatsApp Admin
                </a>
                <p class="text-gray-300 text-sm mt-2"><i class="fas fa-info-circle mr-1"></i>Klik tombol di atas untuk mengirim konfirmasi pembayaran ke admin via WhatsApp</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (isset($pesan_error)): ?>
        <div class="bg-gray-400/20 border-l-4 border-gray-400 text-gray-200 p-4 mb-6 rounded-lg shadow backdrop-blur-sm flex items-center justify-between">
            <div><i class="fas fa-exclamation-triangle mr-2"></i><?php echo $pesan_error; ?></div>
            <button type="button" onclick="this.parentElement.remove()" class="text-gray-200 hover:text-white transition ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Bagian Selamat Datang -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-gradient-to-r from-gray-600 to-gray-800 rounded-full mb-4 shadow-2xl">
                <span class="text-4xl font-bold text-white"><?php echo strtoupper(substr($dataPengguna['full_name'], 0, 1)); ?></span>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2">Selamat Datang, <?php echo $dataPengguna['full_name']; ?>!</h1>
            <p class="text-gray-300">Member sejak <?php echo date('d F Y', strtotime($dataPengguna['created_at'])); ?></p>
        </div>

        <!-- Kartu Profil & Membership -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Kartu Profil -->
            <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <div class="flex items-center mb-6">
                    <i class="fas fa-id-card text-3xl text-gray-400 mr-4"></i></i>
                    <h2 class="text-2xl font-bold text-white">Identitas Member</h2>
                </div>
                <div class="space-y-4">
                    <div class="flex items-start">
                        <div class="w-32 text-gray-400 flex items-center"><i class="fas fa-user w-6 mr-2"></i><span>Nama</span></div>
                        <div class="flex-1 text-white font-medium">: <?php echo $dataPengguna['full_name']; ?></div>
                    </div>
                    <div class="flex items-start">
                        <div class="w-32 text-gray-400 flex items-center"><i class="fas fa-fingerprint w-6 mr-2"></i><span>Username</span></div>
                        <div class="flex-1 text-white font-medium">: <?php echo $dataPengguna['username']; ?></div>
                    </div>
                    <div class="flex items-start">
                        <div class="w-32 text-gray-400 flex items-center"><i class="fas fa-envelope w-6 mr-2"></i><span>Email</span></div>
                        <div class="flex-1 text-white font-medium">: <?php echo $dataPengguna['email'] ?: '-'; ?></div>
                    </div>
                    <div class="flex items-start">
                        <div class="w-32 text-gray-400 flex items-center"><i class="fas fa-phone w-6 mr-2"></i><span>Telepon</span></div>
                        <div class="flex-1 text-white font-medium">: <?php echo $dataPengguna['phone'] ?: '-'; ?></div>
                    </div>
                </div>

                <!-- BMI Calculator -->
                <div class="mt-6 pt-6 border-t border-white/20">
                    <div class="flex items-center mb-4">
                        <i class="fas fa-calculator text-xl text-gray-400 mr-3"></i>
                        <h3 class="text-lg font-bold text-white">Kalkulator BMI</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="block text-gray-400 text-sm mb-1">Berat (kg)</label>
                            <input type="number" id="berat_badan" placeholder="60" step="0.1" min="1" max="500"
                                class="w-full px-3 py-2 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm">
                        </div>
                        <div>
                            <label class="block text-gray-400 text-sm mb-1">Tinggi (cm)</label>
                            <input type="number" id="tinggi_badan" placeholder="170" step="0.1" min="1" max="300"
                                class="w-full px-3 py-2 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm">
                        </div>
                    </div>
                    <button onclick="hitungBMI()" class="w-full bg-gradient-to-r from-gray-600 to-gray-700 text-white px-4 py-2 rounded-lg hover:from-gray-500 hover:to-gray-600 transition text-sm font-semibold">
                        <i class="fas fa-calculator mr-2"></i>Hitung BMI
                    </button>
                    
                    <!-- Hasil BMI -->
                    <div id="hasil_bmi" class="hidden mt-4 p-4 bg-white/5 rounded-lg">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-white mb-1" id="nilai_bmi">-</div>
                            <div class="text-sm text-gray-400 mb-2">Nilai BMI Anda</div>
                            <span id="kategori_bmi" class="px-4 py-1 rounded-full text-sm font-semibold"></span>
                        </div>
                        <div class="mt-4 pt-4 border-t border-white/10">
                            <div class="text-xs text-gray-400 space-y-1">
                                <div class="flex justify-between"><span>Kurus</span><span>&lt; 18.5</span></div>
                                <div class="flex justify-between"><span>Normal</span><span>18.5 - 24.9</span></div>
                                <div class="flex justify-between"><span>Gemuk</span><span>25 - 29.9</span></div>
                                <div class="flex justify-between"><span>Obesitas</span><span>≥ 30</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kartu Status Membership -->
            <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <div class="flex items-center mb-6">
                    <i class="fas fa-crown text-3xl text-gray-400 mr-4"></i></i>
                    <h2 class="text-2xl font-bold text-white">Status Membership</h2>
                </div>
                
                <?php if ($dataMembership): ?>
                <div class="space-y-4">
                    <div class="flex items-center justify-between p-4 bg-white/5 rounded-lg">
                        <span class="text-gray-300">Paket</span>
                        <span class="px-4 py-2 bg-gradient-to-r from-gray-600 to-gray-700 text-white rounded-full font-semibold">
                            <?php echo str_replace('_', ' ', ucwords($dataMembership['package_type'], '_')); ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-white/5 rounded-lg">
                        <span class="text-gray-300">Mulai</span>
                        <span class="text-white font-semibold"><?php echo date('d F Y', strtotime($dataMembership['start_date'])); ?></span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-white/5 rounded-lg">
                        <span class="text-gray-300">Berakhir</span>
                        <span class="text-white font-semibold"><?php echo date('d F Y', strtotime($dataMembership['end_date'])); ?></span>
                    </div>
                    
                    <div class="p-4 bg-gradient-to-r from-gray-600/20 to-gray-700/20 rounded-lg border border-gray-500/30">
                        <?php if ($dataMembership['days_remaining'] > 0): ?>
                        <div class="text-center">
                            <div class="text-5xl font-bold text-white mb-2"><?php echo $dataMembership['days_remaining']; ?></div>
                            <div class="text-gray-300">Hari Tersisa</div>
                            <div class="mt-3">
                                <span class="px-4 py-2 bg-gray-500/30 text-gray-200 rounded-full text-sm font-semibold">
                                    <i class="fas fa-check-circle mr-1"></i>Aktif
                                </span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-5xl text-gray-400 mb-3"></i>
                            <div class="text-gray-200 font-semibold text-lg">Membership Kadaluarsa</div>
                            <div class="text-gray-300 text-sm mt-2">Segera perpanjang membership Anda</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <button onclick="bukaModal('modalPerpanjang')" class="w-full bg-gradient-to-r from-gray-600 to-gray-700 text-white px-6 py-4 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-105 transition font-semibold">
                        <i class="fas fa-calendar-plus mr-2"></i>Perpanjang Membership
                    </button>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-times-circle text-6xl text-gray-400 mb-4"></i>
                    <p class="text-gray-300 text-lg">Anda belum memiliki membership</p>
                    <p class="text-gray-400 text-sm mt-2">Hubungi admin untuk mendaftar</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Permintaan Perpanjangan -->
        <?php if ($daftarPermintaan && $daftarPermintaan->num_rows > 0): ?>
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl overflow-hidden border border-white/20 mb-8">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-clock mr-2"></i>Status Permintaan Perpanjangan</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tanggal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Paket</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Jumlah</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Metode</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php while($permintaan = $daftarPermintaan->fetch_assoc()): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-white"><?php echo date('d/m/Y H:i', strtotime($permintaan['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 bg-gray-500/30 text-gray-200 rounded-full text-xs font-semibold">
                                    <?php echo str_replace('_', ' ', ucwords($permintaan['package_type'], '_')); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-white"><?php echo formatRupiah((float)$permintaan['amount']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300"><?php echo ucfirst($permintaan['payment_method']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($permintaan['status'] === 'pending'): ?>
                                <span class="px-3 py-1 bg-gray-400/30 text-gray-200 rounded-full text-xs font-semibold">
                                    <i class="fas fa-clock mr-1"></i>Menunggu
                                </span>
                                <?php elseif ($permintaan['status'] === 'approved'): ?>
                                <span class="px-3 py-1 bg-gray-600/30 text-gray-100 rounded-full text-xs font-semibold">
                                    <i class="fas fa-check mr-1"></i>Disetujui
                                </span>
                                <?php else: ?>
                                <span class="px-3 py-1 bg-gray-500/30 text-gray-300 rounded-full text-xs font-semibold">
                                    <i class="fas fa-times mr-1"></i>Ditolak
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Riwayat Absensi -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl overflow-hidden border border-white/20 mb-8">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-history mr-2"></i>Riwayat Absensi</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">No</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tanggal & Waktu</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Dicatat Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php 
                        $no = 1;
                        if ($daftarAbsensi->num_rows > 0):
                            while($absen = $daftarAbsensi->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300"><?php echo $no++; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-white font-medium"><?php echo date('d F Y', strtotime($absen['check_in'])); ?></div>
                                <div class="text-gray-400 text-sm"><?php echo date('H:i', strtotime($absen['check_in'])); ?> WIB</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1 text-green-600"></i>Hadir
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300"><?php echo $absen['admin_name'] ?: 'System'; ?></td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-400">
                                <i class="fas fa-inbox text-4xl mb-3 block"></i>
                                <p>Belum ada riwayat absensi</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Riwayat Membership -->
        <?php if ($dataMembership && $riwayatMembership->num_rows > 0): ?>
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl overflow-hidden border border-white/20">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-file-invoice mr-2"></i>Riwayat Perpanjangan</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tanggal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Paket</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Periode Lama</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Periode Baru</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Pembayaran</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php while($riwayat = $riwayatMembership->fetch_assoc()): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-white"><?php echo date('d/m/Y', strtotime($riwayat['extension_date'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 bg-gray-500/30 text-gray-200 rounded-full text-xs font-semibold">
                                    <?php echo str_replace('_', ' ', ucwords($riwayat['package_type'], '_')); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300"><?php echo date('d/m/Y', strtotime($riwayat['previous_end_date'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300 font-medium"><?php echo date('d/m/Y', strtotime($riwayat['new_end_date'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-300">
                                <div><?php echo formatRupiah((float)$riwayat['amount']); ?></div>
                                <div class="text-xs text-gray-400"><?php echo ucfirst($riwayat['payment_method']); ?></div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Perpanjang Membership -->
    <div id="modalPerpanjang" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50 overflow-y-auto">
        <div class="bg-white/10 backdrop-blur-xl rounded-2xl shadow-2xl max-w-md w-full border border-white/20 my-8">
            <div class="bg-gradient-to-r from-gray-600 to-gray-700 px-6 py-4 rounded-t-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-calendar-plus mr-2"></i>Perpanjang Membership</h3>
                    <button type="button" onclick="tutupModal('modalPerpanjang')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <form method="POST" id="formPerpanjang" enctype="multipart/form-data" class="p-6 max-h-[70vh] overflow-y-auto">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-200 mb-3">Pilih Paket Perpanjangan</label>
                    <div class="space-y-3">
                        <?php foreach ($daftarHargaPaket as $kode => $harga): ?>
                        <label class="flex items-center p-4 bg-white/5 rounded-lg border border-white/20 hover:bg-white/10 transition cursor-pointer">
                            <input type="radio" name="paket" value="<?php echo $kode; ?>" required class="mr-3 w-4 h-4">
                            <div class="flex-1">
                                <div class="text-white font-semibold"><?php echo str_replace('_', ' ', ucwords($kode, '_')); ?></div>
                                <div class="text-gray-400 text-sm"><?php echo formatRupiah($harga); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-200 mb-3">Metode Pembayaran</label>
                    <div class="space-y-3">
                        <?php foreach ($metodePembayaran as $kode => $nama): ?>
                        <label class="flex items-center p-4 bg-white/5 rounded-lg border border-white/20 hover:bg-white/10 transition cursor-pointer">
                            <input type="radio" name="metode_pembayaran" value="<?php echo $kode; ?>" required class="mr-3 w-4 h-4" onchange="toggleInfoPembayaran()">
                            <div class="flex-1">
                                <div class="text-white font-semibold"><?php echo $nama; ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Info Rekening Bank -->
                <div id="infoRekening" class="hidden mb-6 p-4 bg-gray-500/20 rounded-lg border border-gray-500/30">
                    <h4 class="text-white font-semibold mb-3"><i class="fas fa-university mr-2"></i>Informasi Rekening</h4>
                    <?php foreach ($daftarRekening as $rek): ?>
                    <div class="text-gray-300 text-sm mb-2">
                        <strong><?php echo $rek['bank']; ?>:</strong> <?php echo $rek['no_rek']; ?> (<?php echo $rek['atas_nama']; ?>)
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Info QRIS dengan Barcode -->
                <div id="infoQris" class="hidden mb-6 p-4 bg-gray-500/20 rounded-lg border border-gray-500/30">
                    <h4 class="text-white font-semibold mb-3"><i class="fas fa-qrcode mr-2"></i>Scan QRIS untuk Pembayaran</h4>
                    <div class="flex justify-center">
                        <div class="bg-white p-4 rounded-lg text-center">
                            <?php if (file_exists($gambarQris)): ?>
                            <img src="<?php echo $gambarQris; ?>" alt="QRIS" class="max-w-[250px] mx-auto rounded-lg shadow-md">
                            <?php else: ?>
                            <div class="bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                <i class="fas fa-qrcode text-6xl text-gray-400 mb-3"></i>
                                <p class="text-gray-500">Gambar QRIS belum tersedia</p>
                                <p class="text-xs text-gray-400 mt-2">Hubungi admin untuk informasi pembayaran</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="text-center text-gray-300 text-sm mt-3">Scan QR Code di atas menggunakan aplikasi e-wallet atau mobile banking</p>
                </div>

                <!-- Upload Bukti Pembayaran -->
                <div id="uploadBukti" class="hidden mb-6">
                    <label class="block text-sm font-medium text-gray-200 mb-2">Upload Bukti Pembayaran</label>
                    <input type="file" name="bukti_pembayaran" accept="image/*" class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white">
                    <p class="text-gray-400 text-xs mt-2">Format: JPG, PNG, GIF. Maks 5MB</p>
                </div>
                
                <button type="submit" name="perpanjang" class="w-full bg-gradient-to-r from-gray-600 to-gray-700 text-white px-6 py-4 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-105 transition font-semibold">
                    <i class="fas fa-paper-plane mr-2"></i>Kirim Permintaan
                </button>
            </form>
        </div>
    </div>

    <script>
        // Cek apakah ada redirect WhatsApp
        <?php if ($wa_redirect): ?>
        // Tampilkan modal konfirmasi untuk kirim WhatsApp ke admin
        document.addEventListener('DOMContentLoaded', function() {
            if (confirm('Permintaan berhasil dikirim! Klik OK untuk mengirim konfirmasi via WhatsApp ke admin.')) {
                window.open('<?php echo $wa_redirect; ?>', '_blank');
            }
        });
        <?php endif; ?>
        
        function bukaModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }
        
        function tutupModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
        
        function toggleInfoPembayaran() {
            const metode = document.querySelector('input[name="metode_pembayaran"]:checked');
            const infoRekening = document.getElementById('infoRekening');
            const infoQris = document.getElementById('infoQris');
            const uploadBukti = document.getElementById('uploadBukti');
            
            // Sembunyikan semua info pembayaran
            infoRekening.classList.add('hidden');
            infoQris.classList.add('hidden');
            uploadBukti.classList.add('hidden');
            
            if (metode) {
                if (metode.value === 'transfer') {
                    infoRekening.classList.remove('hidden');
                    uploadBukti.classList.remove('hidden');
                } else if (metode.value === 'qris') {
                    infoQris.classList.remove('hidden');
                    uploadBukti.classList.remove('hidden');
                }
            }
        }

        // Tutup modal saat klik di luar
        window.onclick = function(event) {
            if (event.target.classList.contains('bg-black/70')) {
                event.target.classList.add('hidden');
            }
        }

        // Fungsi Hitung BMI
        function hitungBMI() {
            const berat = parseFloat(document.getElementById('berat_badan').value);
            const tinggi = parseFloat(document.getElementById('tinggi_badan').value);
            const hasilDiv = document.getElementById('hasil_bmi');
            const nilaiBMI = document.getElementById('nilai_bmi');
            const kategoriBMI = document.getElementById('kategori_bmi');
            
            if (!berat || !tinggi || berat <= 0 || tinggi <= 0) {
                alert('Mohon masukkan berat dan tinggi badan yang valid!');
                return;
            }
            
            // Rumus BMI: berat(kg) / (tinggi(m))^2
            const tinggiMeter = tinggi / 100;
            const bmi = berat / (tinggiMeter * tinggiMeter);
            const bmiRounded = bmi.toFixed(1);
            
            nilaiBMI.textContent = bmiRounded;
            
            // Tentukan kategori
            let kategori, warna;
            if (bmi < 18.5) {
                kategori = 'Kurus';
                warna = 'bg-blue-500/50 text-blue-200';
            } else if (bmi < 25) {
                kategori = 'Normal';
                warna = 'bg-green-500/50 text-green-200';
            } else if (bmi < 30) {
                kategori = 'Gemuk';
                warna = 'bg-yellow-500/50 text-yellow-200';
            } else {
                kategori = 'Obesitas';
                warna = 'bg-red-500/50 text-red-200';
            }
            
            kategoriBMI.textContent = kategori;
            kategoriBMI.className = 'px-4 py-1 rounded-full text-sm font-semibold ' + warna;
            
            hasilDiv.classList.remove('hidden');
        }
    </script>
</body>
</html>
