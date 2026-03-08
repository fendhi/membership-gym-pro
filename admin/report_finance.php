<?php
// =================================================================
// File: admin/report_finance.php
// Deskripsi: Laporan Keuangan Admin - Pendapatan dari membership
// =================================================================

require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$koneksi = getConnection();

// Filter tanggal
$bulan = isset($_GET['bulan']) ? intval($_GET['bulan']) : intval(date('m'));
$tahun = isset($_GET['tahun']) ? intval($_GET['tahun']) : intval(date('Y'));

// Format untuk query
$tanggal_awal = sprintf('%04d-%02d-01', $tahun, $bulan);
$tanggal_akhir = date('Y-m-t', strtotime($tanggal_awal));

// Query pendapatan dari membership baru
$query_membership = "
    SELECT 
        m.id,
        m.created_at as tanggal,
        u.full_name as nama_member,
        u.username,
        m.package_type,
        m.price as jumlah,
        m.payment_method,
        'Membership Baru' as tipe
    FROM memberships m
    JOIN users u ON m.user_id = u.id
    WHERE DATE(m.created_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
";

// Query pendapatan dari perpanjangan (yang sudah diapprove)
$query_perpanjangan = "
    SELECT 
        er.id,
        er.verified_at as tanggal,
        u.full_name as nama_member,
        u.username,
        er.package_type,
        er.amount as jumlah,
        er.payment_method,
        'Perpanjangan' as tipe
    FROM extension_requests er
    JOIN users u ON er.user_id = u.id
    WHERE er.status = 'approved'
    AND DATE(er.verified_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
";

// Gabungkan kedua query
$query_all = "
    ($query_membership)
    UNION ALL
    ($query_perpanjangan)
    ORDER BY tanggal DESC
";

$dataKeuangan = $koneksi->query($query_all);

// Hitung total pendapatan bulan ini
$query_total = "
    SELECT 
        COALESCE(SUM(jumlah), 0) as total
    FROM (
        SELECT price as jumlah FROM memberships WHERE DATE(created_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
        UNION ALL
        SELECT amount as jumlah FROM extension_requests WHERE status = 'approved' AND DATE(verified_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
    ) as combined
";
$totalPendapatan = $koneksi->query($query_total)->fetch_assoc()['total'];

// Hitung per metode pembayaran
$query_per_metode = "
    SELECT 
        payment_method,
        SUM(jumlah) as total
    FROM (
        SELECT payment_method, price as jumlah FROM memberships WHERE DATE(created_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
        UNION ALL
        SELECT payment_method, amount as jumlah FROM extension_requests WHERE status = 'approved' AND DATE(verified_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
    ) as combined
    GROUP BY payment_method
";
$perMetode = $koneksi->query($query_per_metode);
$dataPerMetode = [];
while ($row = $perMetode->fetch_assoc()) {
    $dataPerMetode[$row['payment_method']] = $row['total'];
}

// Hitung per tipe transaksi
$query_membership_total = "SELECT COALESCE(SUM(price), 0) as total FROM memberships WHERE DATE(created_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
$totalMembershipBaru = $koneksi->query($query_membership_total)->fetch_assoc()['total'];

$query_perpanjangan_total = "SELECT COALESCE(SUM(amount), 0) as total FROM extension_requests WHERE status = 'approved' AND DATE(verified_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
$totalPerpanjangan = $koneksi->query($query_perpanjangan_total)->fetch_assoc()['total'];

// Hitung jumlah transaksi
$query_count = "
    SELECT COUNT(*) as total FROM (
        SELECT id FROM memberships WHERE DATE(created_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
        UNION ALL
        SELECT id FROM extension_requests WHERE status = 'approved' AND DATE(verified_at) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
    ) as combined
";
$jumlahTransaksi = $koneksi->query($query_count)->fetch_assoc()['total'];

// Data untuk grafik bulanan (12 bulan terakhir)
$dataGrafik = [];
for ($i = 11; $i >= 0; $i--) {
    $bln = date('Y-m', strtotime("-$i months"));
    $awal = $bln . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    
    $q = "
        SELECT COALESCE(SUM(jumlah), 0) as total FROM (
            SELECT price as jumlah FROM memberships WHERE DATE(created_at) BETWEEN '$awal' AND '$akhir'
            UNION ALL
            SELECT amount as jumlah FROM extension_requests WHERE status = 'approved' AND DATE(verified_at) BETWEEN '$awal' AND '$akhir'
        ) as combined
    ";
    $total = $koneksi->query($q)->fetch_assoc()['total'];
    $dataGrafik[] = [
        'bulan' => date('M Y', strtotime($awal)),
        'total' => floatval($total)
    ];
}

closeConnection($koneksi);

// Nama bulan Indonesia
$namaBulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Label metode pembayaran
$labelMetode = [
    'cash' => 'Cash',
    'transfer' => 'Transfer Bank',
    'qris' => 'QRIS'
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan - AturGym Admin</title>
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
                        <i class="fas fa-chart-pie text-2xl text-green-400"></i>
                        <span class="text-xl font-bold">Laporan Keuangan</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="report_finance_excel.php?bulan=<?php echo $bulan; ?>&tahun=<?php echo $tahun; ?>" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-file-excel mr-2"></i>Unduh Excel
                    </a>
                    <a href="../logout.php" class="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>Keluar
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <!-- Filter -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20 mb-8">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-gray-300 text-sm font-medium mb-2">Bulan</label>
                    <select name="bulan" class="px-4 py-2 bg-white/10 border border-white/30 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-green-400">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == $bulan ? 'selected' : ''; ?> class="bg-gray-800">
                            <?php echo $namaBulan[$i]; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-300 text-sm font-medium mb-2">Tahun</label>
                    <select name="tahun" class="px-4 py-2 bg-white/10 border border-white/30 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-green-400">
                        <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == $tahun ? 'selected' : ''; ?> class="bg-gray-800">
                            <?php echo $i; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-filter mr-2"></i>Filter
                </button>
            </form>
        </div>

        <!-- Ringkasan -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-gradient-to-br from-green-600 to-green-800 rounded-2xl p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-200 text-sm">Total Pendapatan</p>
                        <p class="text-3xl font-bold text-white mt-1">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></p>
                    </div>
                    <div class="bg-white/20 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-2xl text-white"></i>
                    </div>
                </div>
                <p class="text-green-200 text-sm mt-2"><?php echo $namaBulan[$bulan] . ' ' . $tahun; ?></p>
            </div>

            <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-200 text-sm">Membership Baru</p>
                        <p class="text-3xl font-bold text-white mt-1">Rp <?php echo number_format($totalMembershipBaru, 0, ',', '.'); ?></p>
                    </div>
                    <div class="bg-white/20 p-3 rounded-full">
                        <i class="fas fa-user-plus text-2xl text-white"></i>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-purple-600 to-purple-800 rounded-2xl p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-200 text-sm">Perpanjangan</p>
                        <p class="text-3xl font-bold text-white mt-1">Rp <?php echo number_format($totalPerpanjangan, 0, ',', '.'); ?></p>
                    </div>
                    <div class="bg-white/20 p-3 rounded-full">
                        <i class="fas fa-redo text-2xl text-white"></i>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-orange-600 to-orange-800 rounded-2xl p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-200 text-sm">Jumlah Transaksi</p>
                        <p class="text-3xl font-bold text-white mt-1"><?php echo $jumlahTransaksi; ?></p>
                    </div>
                    <div class="bg-white/20 p-3 rounded-full">
                        <i class="fas fa-receipt text-2xl text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pendapatan per Metode -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <?php 
            $iconMetode = ['cash' => 'fa-money-bill', 'transfer' => 'fa-university', 'qris' => 'fa-qrcode'];
            $warnaMetode = ['cash' => 'from-yellow-600 to-yellow-800', 'transfer' => 'from-cyan-600 to-cyan-800', 'qris' => 'from-pink-600 to-pink-800'];
            foreach (['cash', 'transfer', 'qris'] as $metode): 
                $total = $dataPerMetode[$metode] ?? 0;
            ?>
            <div class="bg-gradient-to-br <?php echo $warnaMetode[$metode]; ?> rounded-xl p-4 shadow-xl">
                <div class="flex items-center space-x-3">
                    <div class="bg-white/20 p-2 rounded-full">
                        <i class="fas <?php echo $iconMetode[$metode]; ?> text-white"></i>
                    </div>
                    <div>
                        <p class="text-white/80 text-xs"><?php echo $labelMetode[$metode]; ?></p>
                        <p class="text-xl font-bold text-white">Rp <?php echo number_format($total, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Grafik Pendapatan -->
            <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-chart-line mr-2 text-green-400"></i>Tren Pendapatan 12 Bulan</h3>
                <div class="h-64">
                    <canvas id="chartPendapatan"></canvas>
                </div>
            </div>

            <!-- Grafik Pie Metode Pembayaran -->
            <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-6 border border-white/20">
                <h3 class="text-xl font-bold text-white mb-4"><i class="fas fa-chart-pie mr-2 text-green-400"></i>Distribusi Metode Pembayaran</h3>
                <div class="h-64">
                    <canvas id="chartMetode"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabel Detail Transaksi -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl overflow-hidden border border-white/20">
            <div class="bg-gradient-to-r from-green-700 to-green-800 px-6 py-4 flex items-center justify-between">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-list mr-2"></i>Detail Transaksi</h2>
                <span class="text-green-200 text-sm"><?php echo $namaBulan[$bulan] . ' ' . $tahun; ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-white/5">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">No</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tanggal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Tipe</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Paket</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase">Metode</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php 
                        $no = 1;
                        $dataKeuangan->data_seek(0);
                        while ($data = $dataKeuangan->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4 text-white"><?php echo $no++; ?></td>
                            <td class="px-6 py-4 text-white"><?php echo date('d/m/Y H:i', strtotime($data['tanggal'])); ?></td>
                            <td class="px-6 py-4">
                                <div class="text-white font-medium"><?php echo $data['nama_member']; ?></div>
                                <div class="text-gray-400 text-xs">@<?php echo $data['username']; ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $data['tipe'] == 'Membership Baru' ? 'bg-blue-500/30 text-blue-300' : 'bg-purple-500/30 text-purple-300'; ?>">
                                    <?php echo $data['tipe']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 bg-gray-500/30 text-gray-200 rounded-full text-xs">
                                    <?php echo str_replace('_', ' ', ucwords($data['package_type'], '_')); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-300"><?php echo $labelMetode[$data['payment_method']] ?? $data['payment_method']; ?></td>
                            <td class="px-6 py-4 text-right text-green-400 font-semibold">Rp <?php echo number_format($data['jumlah'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($dataKeuangan->num_rows == 0): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                <i class="fas fa-inbox text-4xl mb-3"></i>
                                <p>Tidak ada transaksi di bulan ini</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($dataKeuangan->num_rows > 0): ?>
                    <tfoot class="bg-white/5">
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-right text-white font-bold">Total:</td>
                            <td class="px-6 py-4 text-right text-green-400 font-bold text-lg">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Chart Pendapatan Bulanan
        const ctxLine = document.getElementById('chartPendapatan').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($dataGrafik, 'bulan')); ?>,
                datasets: [{
                    label: 'Pendapatan',
                    data: <?php echo json_encode(array_column($dataGrafik, 'total')); ?>,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgb(34, 197, 94)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        ticks: { color: 'rgba(255,255,255,0.7)' }
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        ticks: {
                            color: 'rgba(255,255,255,0.7)',
                            callback: function(value) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                            }
                        }
                    }
                }
            }
        });

        // Chart Pie Metode Pembayaran
        const ctxPie = document.getElementById('chartMetode').getContext('2d');
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Transfer Bank', 'QRIS'],
                datasets: [{
                    data: [
                        <?php echo $dataPerMetode['cash'] ?? 0; ?>,
                        <?php echo $dataPerMetode['transfer'] ?? 0; ?>,
                        <?php echo $dataPerMetode['qris'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(234, 179, 8, 0.8)',
                        'rgba(6, 182, 212, 0.8)',
                        'rgba(236, 72, 153, 0.8)'
                    ],
                    borderColor: [
                        'rgb(234, 179, 8)',
                        'rgb(6, 182, 212)',
                        'rgb(236, 72, 153)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: 'rgba(255,255,255,0.7)' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': Rp ' + context.parsed.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
