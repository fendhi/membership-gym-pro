<?php
// =================================================================
// File: member/weight_tracker.php
// Deskripsi: Halaman pencatatan dan grafik berat badan member
// =================================================================

require_once '../config/database.php';
require_once '../config/session.php';
requireMember();

$koneksi = getConnection();
$id_pengguna = $_SESSION['id_pengguna'];
$pesan_sukses = null;
$pesan_error = null;

// Ambil data pengguna
$query_pengguna = "SELECT * FROM users WHERE id = $id_pengguna";
$dataPengguna = $koneksi->query($query_pengguna)->fetch_assoc();

// Proses simpan berat badan
if (isset($_POST['simpan_berat'])) {
    $berat = floatval($_POST['berat']);
    $tanggal = $_POST['tanggal'];
    $catatan = $koneksi->real_escape_string($_POST['catatan'] ?? '');
    
    if ($berat <= 0 || $berat > 500) {
        $pesan_error = "Berat badan tidak valid!";
    } elseif (empty($tanggal)) {
        $pesan_error = "Tanggal wajib diisi!";
    } else {
        // Cek apakah sudah ada data di tanggal tersebut
        $cek = $koneksi->prepare("SELECT id FROM weight_logs WHERE user_id = ? AND log_date = ?");
        $cek->bind_param("is", $id_pengguna, $tanggal);
        $cek->execute();
        $hasil_cek = $cek->get_result();
        
        if ($hasil_cek->num_rows > 0) {
            // Update data yang sudah ada
            $stmt = $koneksi->prepare("UPDATE weight_logs SET weight = ?, notes = ? WHERE user_id = ? AND log_date = ?");
            $stmt->bind_param("dsis", $berat, $catatan, $id_pengguna, $tanggal);
        } else {
            // Insert data baru
            $stmt = $koneksi->prepare("INSERT INTO weight_logs (user_id, weight, log_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("idss", $id_pengguna, $berat, $tanggal, $catatan);
        }
        
        if ($stmt->execute()) {
            $pesan_sukses = "Berat badan berhasil dicatat!";
        } else {
            $pesan_error = "Gagal menyimpan data berat badan.";
        }
        $stmt->close();
        $cek->close();
    }
}

// Proses hapus data
if (isset($_POST['hapus_data'])) {
    $id_hapus = intval($_POST['id_hapus']);
    $stmt = $koneksi->prepare("DELETE FROM weight_logs WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id_hapus, $id_pengguna);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $pesan_sukses = "Data berhasil dihapus!";
    } else {
        $pesan_error = "Gagal menghapus data.";
    }
    $stmt->close();
}

// Ambil data berat badan 30 hari terakhir untuk grafik
$query_grafik = "
    SELECT log_date, weight 
    FROM weight_logs 
    WHERE user_id = $id_pengguna 
    AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY log_date ASC
";
$dataGrafik = $koneksi->query($query_grafik);
$labels = [];
$values = [];
while ($row = $dataGrafik->fetch_assoc()) {
    $labels[] = date('d/m', strtotime($row['log_date']));
    $values[] = floatval($row['weight']);
}

// Ambil semua data berat badan untuk tabel
$query_semua = "
    SELECT * FROM weight_logs 
    WHERE user_id = $id_pengguna 
    ORDER BY log_date DESC
    LIMIT 50
";
$semuaData = $koneksi->query($query_semua);

// Hitung statistik
$query_stats = "
    SELECT 
        MIN(weight) as min_weight,
        MAX(weight) as max_weight,
        AVG(weight) as avg_weight,
        COUNT(*) as total_records,
        (SELECT weight FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date DESC LIMIT 1) as current_weight,
        (SELECT weight FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date ASC LIMIT 1) as first_weight
    FROM weight_logs 
    WHERE user_id = $id_pengguna
";
$stats = $koneksi->query($query_stats)->fetch_assoc();

closeConnection($koneksi);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catatan Berat Badan - AturGym</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 min-h-screen">
    <!-- Navigasi -->
    <nav class="bg-black/30 backdrop-blur-lg text-white shadow-2xl border-b border-white/10">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between py-4">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="flex items-center space-x-2 hover:text-gray-300 transition">
                        <i class="fas fa-arrow-left"></i>
                        <span>Kembali</span>
                    </a>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-weight text-2xl text-gray-400"></i>
                        <span class="text-xl font-bold">Catatan Berat Badan</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="cetak_bb.php" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-download mr-2"></i>Unduh PDF
                    </a>
                    <a href="../logout.php" class="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>Keluar
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <!-- Pesan Alert -->
        <?php if ($pesan_sukses): ?>
        <div class="bg-green-500/20 border-l-4 border-green-500 text-green-200 p-4 mb-6 rounded-lg shadow backdrop-blur-sm flex items-center justify-between">
            <div><i class="fas fa-check-circle mr-2"></i><?php echo $pesan_sukses; ?></div>
            <button type="button" onclick="this.parentElement.remove()" class="text-green-200 hover:text-white transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($pesan_error): ?>
        <div class="bg-red-500/20 border-l-4 border-red-500 text-red-200 p-4 mb-6 rounded-lg shadow backdrop-blur-sm flex items-center justify-between">
            <div><i class="fas fa-exclamation-triangle mr-2"></i><?php echo $pesan_error; ?></div>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-200 hover:text-white transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Statistik -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white/10 backdrop-blur-lg rounded-xl p-4 border border-white/20">
                <div class="text-gray-400 text-sm mb-1"><i class="fas fa-weight mr-1"></i>Berat Saat Ini</div>
                <div class="text-2xl font-bold text-white"><?php echo $stats['current_weight'] ? number_format($stats['current_weight'], 1) . ' kg' : '-'; ?></div>
            </div>
            <div class="bg-white/10 backdrop-blur-lg rounded-xl p-4 border border-white/20">
                <div class="text-gray-400 text-sm mb-1"><i class="fas fa-chart-line mr-1"></i>Perubahan</div>
                <?php 
                $perubahan = $stats['current_weight'] && $stats['first_weight'] ? $stats['current_weight'] - $stats['first_weight'] : 0;
                $warna = $perubahan < 0 ? 'text-green-400' : ($perubahan > 0 ? 'text-red-400' : 'text-white');
                ?>
                <div class="text-2xl font-bold <?php echo $warna; ?>">
                    <?php echo $perubahan != 0 ? ($perubahan > 0 ? '+' : '') . number_format($perubahan, 1) . ' kg' : '-'; ?>
                </div>
            </div>
            <div class="bg-white/10 backdrop-blur-lg rounded-xl p-4 border border-white/20">
                <div class="text-gray-400 text-sm mb-1"><i class="fas fa-arrow-down mr-1"></i>Terendah</div>
                <div class="text-2xl font-bold text-white"><?php echo $stats['min_weight'] ? number_format($stats['min_weight'], 1) . ' kg' : '-'; ?></div>
            </div>
            <div class="bg-white/10 backdrop-blur-lg rounded-xl p-4 border border-white/20">
                <div class="text-gray-400 text-sm mb-1"><i class="fas fa-arrow-up mr-1"></i>Tertinggi</div>
                <div class="text-2xl font-bold text-white"><?php echo $stats['max_weight'] ? number_format($stats['max_weight'], 1) . ' kg' : '-'; ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Form Input Berat Badan -->
            <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <div class="flex items-center mb-6">
                    <i class="fas fa-plus-circle text-3xl text-gray-400 mr-4"></i>
                    <h2 class="text-2xl font-bold text-white">Catat Berat Badan</h2>
                </div>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Tanggal</label>
                        <input type="date" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required
                            class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Berat Badan (kg)</label>
                        <input type="number" name="berat" step="0.1" min="1" max="500" placeholder="Contoh: 70.5" required
                            class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Catatan (opsional)</label>
                        <textarea name="catatan" rows="2" placeholder="Contoh: Setelah olahraga"
                            class="w-full px-4 py-3 bg-white/10 border border-white/30 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400"></textarea>
                    </div>
                    <button type="submit" name="simpan_berat" class="w-full bg-gradient-to-r from-gray-600 to-gray-700 text-white px-6 py-3 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-105 transition font-semibold">
                        <i class="fas fa-save mr-2"></i>Simpan
                    </button>
                </form>
            </div>

            <!-- Grafik Berat Badan -->
            <div class="lg:col-span-2 bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-chart-line text-3xl text-gray-400 mr-4"></i>
                        <h2 class="text-2xl font-bold text-white">Grafik Berat Badan</h2>
                    </div>
                    <span class="text-gray-400 text-sm">30 hari terakhir</span>
                </div>
                <?php if (count($values) > 0): ?>
                <div class="relative h-64">
                    <canvas id="weightChart"></canvas>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-chart-area text-6xl text-gray-600 mb-4"></i>
                    <p class="text-gray-400">Belum ada data berat badan</p>
                    <p class="text-gray-500 text-sm mt-2">Mulai catat berat badan Anda untuk melihat grafik</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabel Riwayat -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl overflow-hidden border border-white/20">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4 flex items-center justify-between">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-history mr-2"></i>Riwayat Berat Badan</h2>
                <span class="text-gray-300 text-sm"><?php echo $stats['total_records'] ?? 0; ?> data</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tanggal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Berat</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Perubahan</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Catatan</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-300 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php 
                        $prev_weight = null;
                        $semuaData->data_seek(0);
                        while ($data = $semuaData->fetch_assoc()): 
                            $perubahan = $prev_weight !== null ? $data['weight'] - $prev_weight : 0;
                            $prev_weight = $data['weight'];
                        ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-white">
                                <div class="font-medium"><?php echo date('d F Y', strtotime($data['log_date'])); ?></div>
                                <div class="text-gray-400 text-xs"><?php echo date('l', strtotime($data['log_date'])); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-xl font-bold text-white"><?php echo number_format($data['weight'], 1); ?></span>
                                <span class="text-gray-400 text-sm">kg</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($perubahan != 0): ?>
                                <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $perubahan < 0 ? 'bg-green-500/30 text-green-300' : 'bg-red-500/30 text-red-300'; ?>">
                                    <?php echo $perubahan > 0 ? '+' : ''; ?><?php echo number_format($perubahan, 1); ?> kg
                                </span>
                                <?php else: ?>
                                <span class="text-gray-500">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-gray-300 max-w-xs truncate"><?php echo $data['notes'] ?: '-'; ?></td>
                            <td class="px-6 py-4 text-center">
                                <form method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus data ini?')">
                                    <input type="hidden" name="id_hapus" value="<?php echo $data['id']; ?>">
                                    <button type="submit" name="hapus_data" class="text-red-400 hover:text-red-300 transition">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($semuaData->num_rows == 0): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                                <i class="fas fa-inbox text-4xl mb-3"></i>
                                <p>Belum ada data berat badan</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        <?php if (count($values) > 0): ?>
        const ctx = document.getElementById('weightChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Berat Badan (kg)',
                    data: <?php echo json_encode($values); ?>,
                    borderColor: 'rgb(156, 163, 175)',
                    backgroundColor: 'rgba(156, 163, 175, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgb(156, 163, 175)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' kg';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            callback: function(value) {
                                return value + ' kg';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
