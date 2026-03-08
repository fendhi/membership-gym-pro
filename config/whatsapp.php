<?php
// =================================================================
// File: config/whatsapp.php
// Deskripsi: Konfigurasi WhatsApp API untuk notifikasi
// =================================================================

// Nomor WhatsApp Admin (format: 628xxxxx tanpa + atau spasi)
// Jika kosong, sistem akan mengambil dari database (tabel users dengan role='admin')
define('WA_ADMIN_NUMBER', ''); // Kosongkan untuk pakai nomor dari database

// Pilih provider API WhatsApp yang digunakan
// Opsi: 'fonnte', 'wablas', 'whatsapp_business', 'none'
define('WA_PROVIDER', 'fonnte');

// API Key untuk masing-masing provider
define('FONNTE_API_KEY', 'YOUR_FONNTE_API_KEY');      // Daftar di https://fonnte.com
define('WABLAS_API_KEY', 'YOUR_WABLAS_API_KEY');      // Daftar di https://wablas.com

// WhatsApp Business Cloud API (Meta)
define('WA_BUSINESS_TOKEN', 'YOUR_WA_BUSINESS_TOKEN');
define('WA_BUSINESS_PHONE_ID', 'YOUR_PHONE_NUMBER_ID');

/**
 * Fungsi utama untuk mengirim pesan WhatsApp
 * 
 * @param string $nomor Nomor tujuan (format: 628xxxxx)
 * @param string $pesan Isi pesan
 * @return array ['success' => bool, 'message' => string]
 */
function kirimWhatsApp($nomor, $pesan) {
    switch (WA_PROVIDER) {
        case 'fonnte':
            return kirimViaFonnte($nomor, $pesan);
        case 'wablas':
            return kirimViaWablas($nomor, $pesan);
        case 'whatsapp_business':
            return kirimViaWABusiness($nomor, $pesan);
        default:
            // Jika tidak ada provider, log pesan saja
            error_log("WhatsApp Notification to $nomor: $pesan");
            return ['success' => false, 'message' => 'No WhatsApp provider configured'];
    }
}

/**
 * Kirim via Fonnte API
 * Dokumentasi: https://fonnte.com/api
 */
function kirimViaFonnte($nomor, $pesan) {
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => [
            'target' => $nomor,
            'message' => $pesan,
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . FONNTE_API_KEY
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        error_log('Fonnte API Error: ' . $err);
        return ['success' => false, 'message' => $err];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode == 200 && isset($result['status']) && $result['status'] == true) {
        return ['success' => true, 'message' => 'Pesan terkirim'];
    }
    
    return ['success' => false, 'message' => $result['reason'] ?? 'Unknown error'];
}

/**
 * Kirim via Wablas API
 * Dokumentasi: https://wablas.com/documentation
 */
function kirimViaWablas($nomor, $pesan) {
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://pati.wablas.com/api/send-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode([
            'phone' => $nomor,
            'message' => $pesan,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . WABLAS_API_KEY,
            'Content-Type: application/json'
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        error_log('Wablas API Error: ' . $err);
        return ['success' => false, 'message' => $err];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode == 200 && isset($result['status']) && $result['status'] == true) {
        return ['success' => true, 'message' => 'Pesan terkirim'];
    }
    
    return ['success' => false, 'message' => $result['message'] ?? 'Unknown error'];
}

/**
 * Kirim via WhatsApp Business Cloud API (Meta)
 * Dokumentasi: https://developers.facebook.com/docs/whatsapp/cloud-api
 */
function kirimViaWABusiness($nomor, $pesan) {
    $curl = curl_init();
    
    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $nomor,
        'type' => 'text',
        'text' => [
            'body' => $pesan
        ]
    ];
    
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://graph.facebook.com/v17.0/' . WA_BUSINESS_PHONE_ID . '/messages',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WA_BUSINESS_TOKEN,
            'Content-Type: application/json'
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        error_log('WA Business API Error: ' . $err);
        return ['success' => false, 'message' => $err];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode == 200 && isset($result['messages'])) {
        return ['success' => true, 'message' => 'Pesan terkirim'];
    }
    
    return ['success' => false, 'message' => $result['error']['message'] ?? 'Unknown error'];
}

/**
 * Kirim notifikasi pembayaran baru ke admin
 * @param mysqli $koneksi Koneksi database (opsional, untuk ambil nomor dari DB)
 * @param array $dataMember Data member yang mengirim pembayaran
 * @param string $paket Paket yang dipilih
 * @param float $jumlah Jumlah pembayaran
 * @param string $metode Metode pembayaran
 */
function notifikasiPembayaranBaru($koneksi, $dataMember, $paket, $jumlah, $metode) {
    // Ambil nomor admin
    $nomorAdmin = WA_ADMIN_NUMBER;
    
    // Jika tidak di-set di config, ambil dari database
    if (empty($nomorAdmin) && $koneksi) {
        $result = $koneksi->query("SELECT phone FROM users WHERE role = 'admin' AND phone IS NOT NULL AND phone != '' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $nomorAdmin = formatNomorWA($row['phone']);
        }
    }
    
    if (empty($nomorAdmin)) {
        error_log('WhatsApp Notification: No admin phone number configured');
        return ['success' => false, 'message' => 'No admin phone number'];
    }
    
    $pesan = "🔔 *NOTIFIKASI PEMBAYARAN BARU*\n\n";
    $pesan .= "👤 Member: " . $dataMember['full_name'] . "\n";
    $pesan .= "📧 Username: " . $dataMember['username'] . "\n";
    $pesan .= "📦 Paket: " . str_replace('_', ' ', ucwords($paket, '_')) . "\n";
    $pesan .= "💰 Jumlah: Rp " . number_format($jumlah, 0, ',', '.') . "\n";
    $pesan .= "💳 Metode: " . ucfirst($metode) . "\n\n";
    $pesan .= "📋 Silakan verifikasi di dashboard admin.";
    
    return kirimWhatsApp($nomorAdmin, $pesan);
}

/**
 * Format nomor telepon ke format WhatsApp (628xxx)
 */
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

/**
 * Kirim notifikasi status verifikasi ke member
 */
function notifikasiStatusVerifikasi($nomorMember, $status, $paket, $catatan = '') {
    // Format nomor member
    $nomorMember = formatNomorWA($nomorMember);
    
    if (empty($nomorMember)) {
        return ['success' => false, 'message' => 'No member phone number'];
    }
    
    if ($status === 'approved') {
        $pesan = "✅ *PEMBAYARAN DISETUJUI*\n\n";
        $pesan .= "Perpanjangan membership Anda telah disetujui!\n";
        $pesan .= "📦 Paket: " . str_replace('_', ' ', ucwords($paket, '_')) . "\n\n";
        $pesan .= "Terima kasih telah menjadi member AturGym! 💪";
    } else {
        $pesan = "❌ *PEMBAYARAN DITOLAK*\n\n";
        $pesan .= "Maaf, permintaan perpanjangan Anda ditolak.\n";
        if ($catatan) {
            $pesan .= "📝 Alasan: " . $catatan . "\n\n";
        }
        $pesan .= "Silakan hubungi admin untuk informasi lebih lanjut.";
    }
    
    return kirimWhatsApp($nomorMember, $pesan);
}
