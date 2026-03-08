<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../lib/tcpdf/tcpdf.php';

requireAdmin();

$conn = getConnection();

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$report_type = isset($_GET['type']) ? $_GET['type'] : 'all'; // all, new, active, expired

// Month names in Indonesian
$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Build query based on report type
$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date = date('Y-m-t', strtotime($start_date));

if ($report_type === 'new') {
    // New members registered in the selected month
    $query = "
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.created_at,
               m.start_date, m.end_date, m.package_type, m.status, m.price, m.payment_method,
               DATEDIFF(m.end_date, CURDATE()) as days_remaining
        FROM users u
        LEFT JOIN memberships m ON u.id = m.user_id
        WHERE u.role = 'user' 
        AND u.created_at >= '$start_date' 
        AND u.created_at <= '$end_date 23:59:59'
        ORDER BY u.created_at DESC
    ";
    $report_title = "Laporan Member Baru";
} elseif ($report_type === 'active') {
    // Active members
    $query = "
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.created_at,
               m.start_date, m.end_date, m.package_type, m.status, m.price, m.payment_method,
               DATEDIFF(m.end_date, CURDATE()) as days_remaining
        FROM users u
        LEFT JOIN memberships m ON u.id = m.user_id
        WHERE u.role = 'user' AND m.status = 'active' AND m.end_date >= CURDATE()
        ORDER BY u.full_name ASC
    ";
    $report_title = "Laporan Member Aktif";
} elseif ($report_type === 'expired') {
    // Expired members
    $query = "
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.created_at,
               m.start_date, m.end_date, m.package_type, m.status, m.price, m.payment_method,
               DATEDIFF(m.end_date, CURDATE()) as days_remaining
        FROM users u
        LEFT JOIN memberships m ON u.id = m.user_id
        WHERE u.role = 'user' AND (m.status = 'expired' OR m.end_date < CURDATE())
        ORDER BY m.end_date DESC
    ";
    $report_title = "Laporan Member Expired";
} else {
    // All members
    $query = "
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.created_at,
               m.start_date, m.end_date, m.package_type, m.status, m.price, m.payment_method,
               DATEDIFF(m.end_date, CURDATE()) as days_remaining
        FROM users u
        LEFT JOIN memberships m ON u.id = m.user_id
        WHERE u.role = 'user'
        ORDER BY u.full_name ASC
    ";
    $report_title = "Laporan Semua Member";
}

$members = $conn->query($query);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
        SUM(CASE WHEN u.created_at >= '$start_date' AND u.created_at <= '$end_date 23:59:59' THEN 1 ELSE 0 END) as new_members,
        SUM(CASE WHEN m.status = 'active' AND m.end_date >= CURDATE() THEN 1 ELSE 0 END) as active_members,
        SUM(CASE WHEN m.status = 'expired' OR m.end_date < CURDATE() THEN 1 ELSE 0 END) as expired_members,
        SUM(CASE WHEN u.created_at >= '$start_date' AND u.created_at <= '$end_date 23:59:59' THEN m.price ELSE 0 END) as revenue_new_members
    FROM users u
    LEFT JOIN memberships m ON u.id = m.user_id
    WHERE u.role = 'user'
";
$stats = $conn->query($stats_query)->fetch_assoc();

// Package type labels
$packageLabels = [
    '1_month' => '1 Bulan',
    '3_months' => '3 Bulan',
    '6_months' => '6 Bulan',
    '12_months' => '12 Bulan'
];

// Create new PDF document
class MYPDF extends TCPDF {
    public function Header() {
        // Logo (optional)
        // $this->Image('logo.png', 10, 10, 30, '', 'PNG');
        
        // Set font
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(102, 51, 153); // Purple
        
        // Title
        $this->Cell(0, 15, 'ATURGYM INDONESIA', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(10);
        
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, 'Jl. Fitness No. 123, Jakarta | Telp: (021) 123-4567', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(15);
        
        // Line
        $this->SetDrawColor(102, 51, 153);
        $this->SetLineWidth(0.5);
        $this->Line(10, 35, 200, 35);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages().' | Dicetak: '.date('d/m/Y H:i:s'), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Create PDF
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('AturGym Management System');
$pdf->SetAuthor('Admin AturGym');
$pdf->SetTitle($report_title . ' - ' . $monthNames[$month] . ' ' . $year);
$pdf->SetSubject('Laporan Member Gym');

// Set margins
$pdf->SetMargins(10, 40, 10);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 20);

// Add a page
$pdf->AddPage();

// Report Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(51, 51, 51);
$pdf->Cell(0, 10, $report_title, 0, 1, 'C');

$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 8, 'Periode: ' . $monthNames[$month] . ' ' . $year, 0, 1, 'C');
$pdf->Ln(5);

// Statistics Box
$pdf->SetFillColor(245, 245, 250);
$pdf->SetDrawColor(200, 200, 200);
$pdf->RoundedRect(10, $pdf->GetY(), 190, 25, 3, '1111', 'DF');

$pdf->SetY($pdf->GetY() + 5);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(51, 51, 51);

// Stats in columns
$col_width = 47.5;
$pdf->Cell($col_width, 6, 'Total Member', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Member Baru', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Member Aktif', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Member Expired', 0, 1, 'C');

$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(102, 51, 153);
$pdf->Cell($col_width, 8, $stats['total_members'], 0, 0, 'C');
$pdf->SetTextColor(46, 125, 50);
$pdf->Cell($col_width, 8, $stats['new_members'], 0, 0, 'C');
$pdf->SetTextColor(33, 150, 243);
$pdf->Cell($col_width, 8, $stats['active_members'], 0, 0, 'C');
$pdf->SetTextColor(244, 67, 54);
$pdf->Cell($col_width, 8, $stats['expired_members'], 0, 1, 'C');

$pdf->Ln(15);

// Table Header
$pdf->SetFillColor(102, 51, 153);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetDrawColor(102, 51, 153);
$pdf->SetLineWidth(0.3);
$pdf->SetFont('helvetica', 'B', 9);

// Column widths
$w = array(8, 35, 50, 25, 28, 25, 20);

$pdf->Cell($w[0], 8, 'No', 1, 0, 'C', true);
$pdf->Cell($w[1], 8, 'Nama Member', 1, 0, 'C', true);
$pdf->Cell($w[2], 8, 'Email / Phone', 1, 0, 'C', true);
$pdf->Cell($w[3], 8, 'Paket', 1, 0, 'C', true);
$pdf->Cell($w[4], 8, 'Tgl Daftar', 1, 0, 'C', true);
$pdf->Cell($w[5], 8, 'Exp Date', 1, 0, 'C', true);
$pdf->Cell($w[6], 8, 'Status', 1, 1, 'C', true);

// Table Data
$pdf->SetTextColor(51, 51, 51);
$pdf->SetFont('helvetica', '', 8);
$fill = false;
$no = 1;

while ($member = $members->fetch_assoc()) {
    // Set fill color for alternate rows
    if ($fill) {
        $pdf->SetFillColor(250, 250, 255);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    
    // Check if we need a new page
    if ($pdf->GetY() > 260) {
        $pdf->AddPage();
        
        // Repeat header
        $pdf->SetFillColor(102, 51, 153);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        
        $pdf->Cell($w[0], 8, 'No', 1, 0, 'C', true);
        $pdf->Cell($w[1], 8, 'Nama Member', 1, 0, 'C', true);
        $pdf->Cell($w[2], 8, 'Email / Phone', 1, 0, 'C', true);
        $pdf->Cell($w[3], 8, 'Paket', 1, 0, 'C', true);
        $pdf->Cell($w[4], 8, 'Tgl Daftar', 1, 0, 'C', true);
        $pdf->Cell($w[5], 8, 'Exp Date', 1, 0, 'C', true);
        $pdf->Cell($w[6], 8, 'Status', 1, 1, 'C', true);
        
        $pdf->SetTextColor(51, 51, 51);
        $pdf->SetFont('helvetica', '', 8);
    }
    
    // Set fill color again after page break check
    if ($fill) {
        $pdf->SetFillColor(250, 250, 255);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    
    // Determine status
    $status_text = 'N/A';
    if ($member['end_date']) {
        if ($member['days_remaining'] > 0) {
            $status_text = 'Aktif';
        } else {
            $status_text = 'Expired';
        }
    }
    
    // Package label
    $package_text = isset($packageLabels[$member['package_type']]) ? $packageLabels[$member['package_type']] : '-';
    
    // Contact info (email or phone)
    $contact = $member['email'] ?: ($member['phone'] ?: '-');
    if (strlen($contact) > 25) {
        $contact = substr($contact, 0, 22) . '...';
    }
    
    // Name (truncate if too long)
    $name = $member['full_name'];
    if (strlen($name) > 18) {
        $name = substr($name, 0, 15) . '...';
    }
    
    $pdf->Cell($w[0], 7, $no, 1, 0, 'C', $fill);
    $pdf->Cell($w[1], 7, $name, 1, 0, 'L', $fill);
    $pdf->Cell($w[2], 7, $contact, 1, 0, 'L', $fill);
    $pdf->Cell($w[3], 7, $package_text, 1, 0, 'C', $fill);
    $pdf->Cell($w[4], 7, $member['created_at'] ? date('d/m/Y', strtotime($member['created_at'])) : '-', 1, 0, 'C', $fill);
    $pdf->Cell($w[5], 7, $member['end_date'] ? date('d/m/Y', strtotime($member['end_date'])) : '-', 1, 0, 'C', $fill);
    
    // Status with color
    if ($status_text === 'Aktif') {
        $pdf->SetTextColor(46, 125, 50);
    } elseif ($status_text === 'Expired') {
        $pdf->SetTextColor(244, 67, 54);
    } else {
        $pdf->SetTextColor(128, 128, 128);
    }
    $pdf->Cell($w[6], 7, $status_text, 1, 1, 'C', $fill);
    $pdf->SetTextColor(51, 51, 51);
    
    $fill = !$fill;
    $no++;
}

// Summary section
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(51, 51, 51);
$pdf->Cell(0, 8, 'RINGKASAN', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(60, 7, 'Total Data Ditampilkan:', 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, ($no - 1) . ' member', 0, 1, 'L');

if ($report_type === 'new') {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(60, 7, 'Pendapatan Member Baru:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(46, 125, 50);
    $pdf->Cell(0, 7, 'Rp ' . number_format($stats['revenue_new_members'], 0, ',', '.'), 0, 1, 'L');
}

// Signature section
$pdf->Ln(20);
$pdf->SetTextColor(51, 51, 51);
$pdf->SetFont('helvetica', '', 10);

$pdf->Cell(130, 7, '', 0, 0, 'L');
$pdf->Cell(50, 7, 'Jakarta, ' . date('d ') . $monthNames[(int)date('m')] . date(' Y'), 0, 1, 'C');

$pdf->Cell(130, 7, '', 0, 0, 'L');
$pdf->Cell(50, 7, 'Admin AturGym', 0, 1, 'C');

$pdf->Ln(15);

$pdf->Cell(130, 7, '', 0, 0, 'L');
$pdf->Cell(50, 7, '(________________________)', 0, 1, 'C');

closeConnection($conn);

// Output PDF
$filename = 'Laporan_Member_' . $monthNames[$month] . '_' . $year . '_' . date('YmdHis') . '.pdf';
$pdf->Output($filename, 'D'); // D = Download, I = Inline view
exit;
?>
