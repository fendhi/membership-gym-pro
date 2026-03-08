<?php
// =================================================================
// File: admin/report_finance_excel.php
// Deskripsi: Export Laporan Keuangan ke Excel
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

// Query data keuangan dari financial_transactions (data permanen)
$query_keuangan = "
    SELECT 
        id,
        transaction_date as tanggal,
        member_name as nama_member,
        member_username as username,
        package_type,
        amount as jumlah,
        payment_method,
        CASE transaction_type WHEN 'new_membership' THEN 'Membership Baru' ELSE 'Perpanjangan' END as tipe
    FROM financial_transactions
    WHERE DATE(transaction_date) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'
    ORDER BY transaction_date DESC, id DESC
";
$dataKeuangan = $koneksi->query($query_keuangan);

// Hitung total
$totalPendapatan = $koneksi->query("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'")->fetch_assoc()['total'];

// Hitung per metode
$query_per_metode = "SELECT payment_method, SUM(amount) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal' AND '$tanggal_akhir' GROUP BY payment_method";
$perMetode = $koneksi->query($query_per_metode);
$dataPerMetode = [];
while ($row = $perMetode->fetch_assoc()) {
    $dataPerMetode[$row['payment_method']] = $row['total'];
}

// Hitung per tipe
$totalMembershipBaru = $koneksi->query("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal' AND '$tanggal_akhir' AND transaction_type = 'new_membership'")->fetch_assoc()['total'];
$totalPerpanjangan = $koneksi->query("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal' AND '$tanggal_akhir' AND transaction_type = 'extension'")->fetch_assoc()['total'];

closeConnection($koneksi);

// Set header untuk download Excel
$filename = 'Laporan_Keuangan_AturGym_' . $namaBulan[$bulan] . '_' . $tahun . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Output Excel dengan HTML Table (compatible dengan Excel)
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!--[if gte mso 9]>
    <xml>
        <x:ExcelWorkbook>
            <x:ExcelWorksheets>
                <x:ExcelWorksheet>
                    <x:Name>Laporan Keuangan</x:Name>
                    <x:WorksheetOptions>
                        <x:DisplayGridlines/>
                    </x:WorksheetOptions>
                </x:ExcelWorksheet>
            </x:ExcelWorksheets>
        </x:ExcelWorkbook>
    </xml>
    <![endif]-->
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 8px; }
        th { background-color: #4CAF50; color: white; font-weight: bold; }
        .header { font-size: 18px; font-weight: bold; }
        .subheader { font-size: 14px; color: #666; }
        .total-row { background-color: #e8f5e9; font-weight: bold; }
        .currency { text-align: right; }
        .summary-label { background-color: #f5f5f5; font-weight: bold; }
        .summary-value { background-color: #fff; text-align: right; }
    </style>
</head>
<body>
    <!-- Header Laporan -->
    <table>
        <tr>
            <td colspan="7" class="header">LAPORAN KEUANGAN ATURGYM</td>
        </tr>
        <tr>
            <td colspan="7" class="subheader">Periode: <?php echo $namaBulan[$bulan] . ' ' . $tahun; ?></td>
        </tr>
        <tr>
            <td colspan="7" class="subheader">Dicetak: <?php echo date('d F Y H:i'); ?></td>
        </tr>
        <tr><td colspan="7"></td></tr>
    </table>

    <!-- Ringkasan -->
    <table>
        <tr>
            <th colspan="4">RINGKASAN PENDAPATAN</th>
        </tr>
        <tr>
            <td class="summary-label">Total Pendapatan</td>
            <td class="summary-value">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></td>
            <td class="summary-label">Jumlah Transaksi</td>
            <td class="summary-value"><?php echo $dataKeuangan->num_rows; ?></td>
        </tr>
        <tr>
            <td class="summary-label">Membership Baru</td>
            <td class="summary-value">Rp <?php echo number_format($totalMembershipBaru, 0, ',', '.'); ?></td>
            <td class="summary-label">Perpanjangan</td>
            <td class="summary-value">Rp <?php echo number_format($totalPerpanjangan, 0, ',', '.'); ?></td>
        </tr>
        <tr><td colspan="4"></td></tr>
        <tr>
            <th colspan="4">PENDAPATAN PER METODE PEMBAYARAN</th>
        </tr>
        <tr>
            <td class="summary-label">Cash</td>
            <td class="summary-value">Rp <?php echo number_format($dataPerMetode['cash'] ?? 0, 0, ',', '.'); ?></td>
            <td class="summary-label">Transfer Bank</td>
            <td class="summary-value">Rp <?php echo number_format($dataPerMetode['transfer'] ?? 0, 0, ',', '.'); ?></td>
        </tr>
        <tr>
            <td class="summary-label">QRIS</td>
            <td class="summary-value">Rp <?php echo number_format($dataPerMetode['qris'] ?? 0, 0, ',', '.'); ?></td>
            <td colspan="2"></td>
        </tr>
    </table>

    <br><br>

    <!-- Detail Transaksi -->
    <table>
        <tr>
            <th>No</th>
            <th>Tanggal</th>
            <th>Nama Member</th>
            <th>Username</th>
            <th>Tipe Transaksi</th>
            <th>Paket</th>
            <th>Metode Pembayaran</th>
            <th>Jumlah (Rp)</th>
        </tr>
        <?php 
        $no = 1;
        $dataKeuangan->data_seek(0);
        while ($data = $dataKeuangan->fetch_assoc()): 
        ?>
        <tr>
            <td style="text-align: center;"><?php echo $no++; ?></td>
            <td><?php echo date('d/m/Y H:i', strtotime($data['tanggal'])); ?></td>
            <td><?php echo $data['nama_member']; ?></td>
            <td><?php echo $data['username']; ?></td>
            <td><?php echo $data['tipe']; ?></td>
            <td><?php echo str_replace('_', ' ', ucwords($data['package_type'], '_')); ?></td>
            <td><?php echo $labelMetode[$data['payment_method']] ?? $data['payment_method']; ?></td>
            <td class="currency"><?php echo number_format($data['jumlah'], 0, ',', '.'); ?></td>
        </tr>
        <?php endwhile; ?>
        <?php if ($dataKeuangan->num_rows == 0): ?>
        <tr>
            <td colspan="8" style="text-align: center;">Tidak ada transaksi di bulan ini</td>
        </tr>
        <?php else: ?>
        <tr class="total-row">
            <td colspan="7" style="text-align: right; font-weight: bold;">TOTAL:</td>
            <td class="currency" style="font-weight: bold;">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <br><br>
    <table>
        <tr>
            <td>Catatan: Laporan ini digenerate secara otomatis oleh sistem AturGym</td>
        </tr>
    </table>
</body>
</html>
