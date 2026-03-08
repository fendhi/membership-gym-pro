<?php
// =================================================================
// File: member/weight_pdf.php
// Deskripsi: Generate PDF laporan berat badan member
// =================================================================

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../lib/tcpdf/tcpdf.php';
requireMember();

$koneksi = getConnection();
$id_pengguna = $_SESSION['id_pengguna'];

// Ambil data pengguna
$query_pengguna = "SELECT * FROM users WHERE id = $id_pengguna";
$dataPengguna = $koneksi->query($query_pengguna)->fetch_assoc();

// Ambil semua data berat badan
$query_data = "
    SELECT * FROM weight_logs 
    WHERE user_id = $id_pengguna 
    ORDER BY log_date DESC
";
$dataWeight = $koneksi->query($query_data);

// Hitung statistik
$query_stats = "
    SELECT 
        MIN(weight) as min_weight,
        MAX(weight) as max_weight,
        AVG(weight) as avg_weight,
        COUNT(*) as total_records,
        (SELECT weight FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date DESC LIMIT 1) as current_weight,
        (SELECT weight FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date ASC LIMIT 1) as first_weight,
        (SELECT log_date FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date ASC LIMIT 1) as first_date,
        (SELECT log_date FROM weight_logs WHERE user_id = $id_pengguna ORDER BY log_date DESC LIMIT 1) as last_date
    FROM weight_logs 
    WHERE user_id = $id_pengguna
";
$stats = $koneksi->query($query_stats)->fetch_assoc();

closeConnection($koneksi);

// Buat PDF
class MYPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 18);
        $this->Cell(0, 15, 'ATURGYM', 0, false, 'C', 0);
        $this->Ln(8);
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 10, 'Laporan Catatan Berat Badan', 0, false, 'C', 0);
        $this->Ln(15);
        $this->SetLineWidth(0.5);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(5);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Dicetak pada: ' . date('d F Y H:i') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0);
    }
}

$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document info
$pdf->SetCreator('AturGym');
$pdf->SetAuthor('AturGym');
$pdf->SetTitle('Laporan Berat Badan - ' . $dataPengguna['full_name']);
$pdf->SetSubject('Laporan Berat Badan');

// Set margins
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(TRUE, 25);

// Add page
$pdf->AddPage();

// Info Member
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Informasi Member', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 6, 'Nama', 0, 0);
$pdf->Cell(0, 6, ': ' . $dataPengguna['full_name'], 0, 1);
$pdf->Cell(40, 6, 'Username', 0, 0);
$pdf->Cell(0, 6, ': ' . $dataPengguna['username'], 0, 1);
$pdf->Cell(40, 6, 'Periode Data', 0, 0);
if ($stats['first_date'] && $stats['last_date']) {
    $pdf->Cell(0, 6, ': ' . date('d/m/Y', strtotime($stats['first_date'])) . ' - ' . date('d/m/Y', strtotime($stats['last_date'])), 0, 1);
} else {
    $pdf->Cell(0, 6, ': -', 0, 1);
}
$pdf->Ln(5);

// Statistik
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Ringkasan Statistik', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetFillColor(240, 240, 240);

// Tabel statistik
$pdf->Cell(45, 8, 'Berat Saat Ini', 1, 0, 'L', true);
$pdf->Cell(45, 8, $stats['current_weight'] ? number_format($stats['current_weight'], 1) . ' kg' : '-', 1, 0, 'C');
$pdf->Cell(45, 8, 'Berat Rata-rata', 1, 0, 'L', true);
$pdf->Cell(45, 8, $stats['avg_weight'] ? number_format($stats['avg_weight'], 1) . ' kg' : '-', 1, 1, 'C');

$pdf->Cell(45, 8, 'Berat Terendah', 1, 0, 'L', true);
$pdf->Cell(45, 8, $stats['min_weight'] ? number_format($stats['min_weight'], 1) . ' kg' : '-', 1, 0, 'C');
$pdf->Cell(45, 8, 'Berat Tertinggi', 1, 0, 'L', true);
$pdf->Cell(45, 8, $stats['max_weight'] ? number_format($stats['max_weight'], 1) . ' kg' : '-', 1, 1, 'C');

$perubahan = $stats['current_weight'] && $stats['first_weight'] ? $stats['current_weight'] - $stats['first_weight'] : 0;
$pdf->Cell(45, 8, 'Perubahan Total', 1, 0, 'L', true);
$pdf->Cell(45, 8, $perubahan != 0 ? ($perubahan > 0 ? '+' : '') . number_format($perubahan, 1) . ' kg' : '-', 1, 0, 'C');
$pdf->Cell(45, 8, 'Total Pencatatan', 1, 0, 'L', true);
$pdf->Cell(45, 8, $stats['total_records'] . ' kali', 1, 1, 'C');

$pdf->Ln(10);

// Tabel Data
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Riwayat Berat Badan', 0, 1, 'L');

// Header tabel
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(80, 80, 80);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(15, 8, 'No', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'Tanggal', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Berat (kg)', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Perubahan', 1, 0, 'C', true);
$pdf->Cell(65, 8, 'Catatan', 1, 1, 'C', true);

// Data tabel
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);
$no = 1;
$prev_weight = null;

while ($data = $dataWeight->fetch_assoc()) {
    $perubahan_row = $prev_weight !== null ? $data['weight'] - $prev_weight : 0;
    $prev_weight = $data['weight'];
    
    $fill = $no % 2 == 0;
    $pdf->SetFillColor(250, 250, 250);
    
    $pdf->Cell(15, 7, $no, 1, 0, 'C', $fill);
    $pdf->Cell(40, 7, date('d F Y', strtotime($data['log_date'])), 1, 0, 'L', $fill);
    $pdf->Cell(30, 7, number_format($data['weight'], 1), 1, 0, 'C', $fill);
    
    if ($perubahan_row != 0) {
        $pdf->Cell(30, 7, ($perubahan_row > 0 ? '+' : '') . number_format($perubahan_row, 1), 1, 0, 'C', $fill);
    } else {
        $pdf->Cell(30, 7, '-', 1, 0, 'C', $fill);
    }
    
    $catatan = $data['notes'] ?: '-';
    if (strlen($catatan) > 30) {
        $catatan = substr($catatan, 0, 27) . '...';
    }
    $pdf->Cell(65, 7, $catatan, 1, 1, 'L', $fill);
    
    $no++;
}

if ($dataWeight->num_rows == 0) {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(180, 10, 'Tidak ada data berat badan', 1, 1, 'C');
}

// Output PDF - Download langsung
$pdf->Output('Laporan_Berat_Badan_' . $dataPengguna['username'] . '_' . date('Ymd') . '.pdf', 'D');
?>
