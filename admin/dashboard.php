<?php
// =================================================================
// File: admin/dashboard.php
// Deskripsi: Dashboard Admin untuk Manajemen Gym
// Fitur: Kelola member, perpanjang membership, catat absensi, 
//        verifikasi pembayaran, cetak laporan
// =================================================================

require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

// Koneksi database
$koneksi = getConnection();

// Cek pesan dari session (PRG pattern)
$pesan_sukses = null;
$pesan_error = null;
if (isset($_SESSION['pesan_sukses'])) {
    $pesan_sukses = $_SESSION['pesan_sukses'];
    unset($_SESSION['pesan_sukses']);
}
if (isset($_SESSION['pesan_error'])) {
    $pesan_error = $_SESSION['pesan_error'];
    unset($_SESSION['pesan_error']);
}

// Cek apakah ada tagihan WhatsApp yang perlu dikirim (bisa multiple)
$daftar_tagihan_wa = [];
if (isset($_SESSION['daftar_tagihan_wa'])) {
    $daftar_tagihan_wa = $_SESSION['daftar_tagihan_wa'];
    unset($_SESSION['daftar_tagihan_wa']);
}

// Paket membership yang valid
$paketValid = ['1_month', '3_months', '6_months', '12_months'];

// Daftar harga paket
$daftarHargaPaket = [
    '1_month' => 250000,
    '3_months' => 650000,
    '6_months' => 1200000,
    '12_months' => 2200000,
];

// Metode pembayaran yang tersedia
$metodePembayaran = [
    'cash' => 'Cash',
    'transfer' => 'Transfer Bank',
    'qris' => 'QRIS',
];

// Informasi Rekening Bank
$daftarRekening = [
    [
        'bank' => 'BCA',
        'no_rek' => '1234567890',
        'atas_nama' => 'AturGym Indonesia'
    ],
    [
        'bank' => 'Mandiri',
        'no_rek' => '0987654321',
        'atas_nama' => 'AturGym Indonesia'
    ],
    [
        'bank' => 'BNI',
        'no_rek' => '1122334455',
        'atas_nama' => 'AturGym Indonesia'
    ]
];

// Path gambar QRIS (letakkan file QRIS di folder assets/images/)
$gambarQris = 'assets/images/qris.png';

// Fungsi helper untuk menghitung jumlah hari dari paket (1 bulan = 28 hari)
if (!function_exists('hitungHariPaket')) {
    function hitungHariPaket(string $paket): int {
        switch ($paket) {
            case '3_months':
                return 84;  // 3 x 28 hari
            case '6_months':
                return 168; // 6 x 28 hari
            case '12_months':
                return 336; // 12 x 28 hari
            default:
                return 28;  // 1 bulan = 28 hari
        }
    }
}

// Fungsi helper untuk mendapatkan harga paket
if (!function_exists('ambilHargaPaket')) {
    function ambilHargaPaket(array $daftarHarga, string $paket): float {
        return isset($daftarHarga[$paket]) ? (float) $daftarHarga[$paket] : 0.0;
    }
}

// Fungsi helper untuk format mata uang
if (!function_exists('formatRupiah')) {
    function formatRupiah(float $jumlah): string {
        return 'Rp ' . number_format($jumlah, 0, ',', '.');
    }
}

// Fungsi helper untuk format nomor WhatsApp
if (!function_exists('formatNomorWA')) {
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
}

// =================================================================
// PROSES FORM POST
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ---------------------------------------------------------
    // TAMBAH MEMBER BARU
    // ---------------------------------------------------------
    if (isset($_POST['add_member'])) {
        $namaPengguna = trim($_POST['username'] ?? '');
        $kataSandi = trim($_POST['password'] ?? '');
        $namaLengkap = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telepon = trim($_POST['phone'] ?? '');
        $paket = $_POST['package'] ?? '';
        $inputHarga = $_POST['price'] ?? null;
        $harga = $inputHarga !== null && $inputHarga !== '' ? (float)$inputHarga : ambilHargaPaket($daftarHargaPaket, $paket);
        $metode_bayar = $_POST['payment_method'] ?? '';

        // Validasi input
        if ($namaPengguna === '' || $kataSandi === '' || $namaLengkap === '') {
            $_SESSION['pesan_error'] = 'Username, password, dan nama lengkap wajib diisi.';
            closeConnection($koneksi);
            header('Location: dashboard.php#tambah-member');
            exit();
        } elseif (!in_array($paket, $paketValid, true)) {
            $_SESSION['pesan_error'] = 'Paket membership tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#tambah-member');
            exit();
        } elseif ($harga <= 0) {
            $_SESSION['pesan_error'] = 'Harga membership harus lebih dari 0.';
            closeConnection($koneksi);
            header('Location: dashboard.php#tambah-member');
            exit();
        } elseif (!array_key_exists($metode_bayar, $metodePembayaran)) {
            $_SESSION['pesan_error'] = 'Metode pembayaran tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#tambah-member');
            exit();
        } else {
            // Cek apakah username sudah digunakan
            $cekStmt = $koneksi->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $cekStmt->bind_param("s", $namaPengguna);
            $cekStmt->execute();
            $cekStmt->store_result();

            if ($cekStmt->num_rows > 0) {
                $_SESSION['pesan_error'] = 'Username sudah digunakan, pilih username lain.';
                $cekStmt->close();
                closeConnection($koneksi);
                header('Location: dashboard.php#tambah-member');
                exit();
            } else {
                // Hitung tanggal mulai dan berakhir (1 bulan = 28 hari)
                $tanggal_mulai = date('Y-m-d');
                $jumlahHari = hitungHariPaket($paket);
                $tanggal_berakhir = date('Y-m-d', strtotime($tanggal_mulai . " +$jumlahHari days"));

                try {
                    $koneksi->begin_transaction();

                    // Insert user baru
                    $stmtUser = $koneksi->prepare("INSERT INTO users (username, password, full_name, email, phone, role) VALUES (?, MD5(?), ?, ?, ?, 'user')");
                    $stmtUser->bind_param("sssss", $namaPengguna, $kataSandi, $namaLengkap, $email, $telepon);

                    if (!$stmtUser->execute()) {
                        throw new Exception($stmtUser->error);
                    }

                    $id_user = $stmtUser->insert_id;

                    // Insert membership
                    $stmtMembership = $koneksi->prepare("INSERT INTO memberships (user_id, start_date, end_date, package_type, status, price, payment_method) VALUES (?, ?, ?, ?, 'active', ?, ?)");
                    $stmtMembership->bind_param("isssds", $id_user, $tanggal_mulai, $tanggal_berakhir, $paket, $harga, $metode_bayar);

                    if (!$stmtMembership->execute()) {
                        throw new Exception($stmtMembership->error);
                    }

                    $id_membership = $stmtMembership->insert_id;

                    // Insert riwayat membership
                    $stmtRiwayat = $koneksi->prepare("INSERT INTO membership_history (membership_id, extended_by, extension_date, previous_end_date, new_end_date, package_type, amount, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $id_admin = $_SESSION['id_pengguna'];
                    $stmtRiwayat->bind_param(
                        "iissssds",
                        $id_membership,
                        $id_admin,
                        $tanggal_mulai,
                        $tanggal_mulai,
                        $tanggal_berakhir,
                        $paket,
                        $harga,
                        $metode_bayar
                    );

                    if (!$stmtRiwayat->execute()) {
                        throw new Exception($stmtRiwayat->error);
                    }

                    // Insert ke financial_transactions (data permanen untuk laporan keuangan)
                    $stmtFinance = $koneksi->prepare("INSERT INTO financial_transactions (user_id, membership_id, member_name, member_username, transaction_type, package_type, amount, payment_method, transaction_date) VALUES (?, ?, ?, ?, 'new_membership', ?, ?, ?, CURDATE())");
                    $stmtFinance->bind_param("iisssds", $id_user, $id_membership, $namaLengkap, $namaPengguna, $paket, $harga, $metode_bayar);
                    if (!$stmtFinance->execute()) {
                        throw new Exception($stmtFinance->error);
                    }

                    $koneksi->commit();
                    
                    // Buat link WhatsApp untuk kirim info akun ke member baru
                    if (!empty($telepon)) {
                        $nomorMember = formatNomorWA($telepon);
                        
                        // Jika pembayaran CASH, kirim info akun saja tanpa tagihan
                        if ($metode_bayar === 'cash') {
                            $pesanWA = "Halo *" . urlencode($namaLengkap) . "*,%0A%0A";
                            $pesanWA .= "Selamat datang di *AturGym*! 🏋️%0A%0A";
                            $pesanWA .= "Akun Anda telah berhasil dibuat:%0A";
                            $pesanWA .= "👤 Username: " . urlencode($namaPengguna) . "%0A";
                            $pesanWA .= "🔑 Password: " . urlencode($kataSandi) . "%0A%0A";
                            $pesanWA .= "📋 *DETAIL MEMBERSHIP*%0A";
                            $pesanWA .= "📦 Paket: " . urlencode(str_replace('_', ' ', ucwords($paket, '_'))) . "%0A";
                            $pesanWA .= "📅 Mulai: " . date('d/m/Y', strtotime($tanggal_mulai)) . "%0A";
                            $pesanWA .= "📅 Berakhir: " . date('d/m/Y', strtotime($tanggal_berakhir)) . "%0A%0A";
                            $pesanWA .= "Terima kasih telah bergabung! 💪";
                        } else {
                            // Jika pembayaran TRANSFER/QRIS, kirim info akun + tagihan
                            $pesanWA = "Halo *" . urlencode($namaLengkap) . "*,%0A%0A";
                            $pesanWA .= "Selamat datang di *AturGym*! 🏋️%0A%0A";
                            $pesanWA .= "Akun Anda telah berhasil dibuat:%0A";
                            $pesanWA .= "👤 Username: " . urlencode($namaPengguna) . "%0A";
                            $pesanWA .= "🔑 Password: " . urlencode($kataSandi) . "%0A%0A";
                            $pesanWA .= "📋 *DETAIL MEMBERSHIP*%0A";
                            $pesanWA .= "📦 Paket: " . urlencode(str_replace('_', ' ', ucwords($paket, '_'))) . "%0A";
                            $pesanWA .= "📅 Mulai: " . date('d/m/Y', strtotime($tanggal_mulai)) . "%0A";
                            $pesanWA .= "📅 Berakhir: " . date('d/m/Y', strtotime($tanggal_berakhir)) . "%0A%0A";
                            $pesanWA .= "💰 *TAGIHAN*%0A";
                            $pesanWA .= "Total: *Rp " . number_format($harga, 0, ',', '.') . "*%0A";
                            $pesanWA .= "Metode: " . ucfirst($metode_bayar) . "%0A%0A";
                            
                            // Jika TRANSFER, tambahkan daftar rekening
                            if ($metode_bayar === 'transfer') {
                                $pesanWA .= "🏦 *REKENING PEMBAYARAN*%0A";
                                foreach ($daftarRekening as $rek) {
                                    $pesanWA .= "• " . $rek['bank'] . ": " . $rek['no_rek'] . "%0A";
                                    $pesanWA .= "  a.n " . $rek['atas_nama'] . "%0A";
                                }
                                $pesanWA .= "%0A";
                            }
                            
                            $pesanWA .= "Terima kasih telah bergabung! 💪";
                        }
                        
                        // Simpan ke array tagihan
                        if (!isset($_SESSION['daftar_tagihan_wa'])) {
                            $_SESSION['daftar_tagihan_wa'] = [];
                        }
                        $_SESSION['daftar_tagihan_wa'][] = [
                            'nama' => $namaLengkap,
                            'telepon' => $telepon,
                            'tipe' => 'Member Baru',
                            'paket' => str_replace('_', ' ', ucwords($paket, '_')),
                            'harga' => $metode_bayar === 'cash' ? 0 : $harga,
                            'link' => "https://wa.me/" . $nomorMember . "?text=" . $pesanWA
                        ];
                    }
                    
                    $_SESSION['pesan_sukses'] = 'Member ' . htmlspecialchars($namaLengkap, ENT_QUOTES) . ' berhasil ditambahkan!';
                } catch (Exception $e) {
                    $koneksi->rollback();
                    $_SESSION['pesan_error'] = 'Gagal menambahkan member: ' . $e->getMessage();
                } finally {
                    if (isset($stmtRiwayat) && $stmtRiwayat instanceof mysqli_stmt) {
                        $stmtRiwayat->close();
                    }
                    if (isset($stmtMembership) && $stmtMembership instanceof mysqli_stmt) {
                        $stmtMembership->close();
                    }
                    if (isset($stmtUser) && $stmtUser instanceof mysqli_stmt) {
                        $stmtUser->close();
                    }
                }
            }

            $cekStmt->close();
        }
        closeConnection($koneksi);
        header('Location: dashboard.php#tambah-member');
        exit();
    }    // ---------------------------------------------------------
    // HAPUS MEMBER
    // ---------------------------------------------------------
    elseif (isset($_POST['delete_members'])) {
        $memberTerpilih = $_POST['selected_members'] ?? [];

        if (!is_array($memberTerpilih) || count($memberTerpilih) === 0) {
            $_SESSION['pesan_error'] = 'Pilih setidaknya satu member untuk dihapus.';
        } else {
            $daftarId = array_unique(array_map('intval', $memberTerpilih));
            $daftarId = array_values(array_filter($daftarId, static function ($id) {
                return $id > 0;
            }));

            if (empty($daftarId)) {
                $_SESSION['pesan_error'] = 'Pilihan member tidak valid.';
            } else {
                try {
                    $koneksi->begin_transaction();

                    $stmtHapus = $koneksi->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
                    $jumlahTerhapus = 0;

                    foreach ($daftarId as $idMember) {
                        $stmtHapus->bind_param("i", $idMember);
                        $stmtHapus->execute();
                        $jumlahTerhapus += $stmtHapus->affected_rows;
                    }

                    if ($jumlahTerhapus === 0) {
                        $koneksi->rollback();
                        $_SESSION['pesan_error'] = 'Tidak ada member yang dihapus. Pastikan member valid.';
                    } else {
                        $koneksi->commit();
                        $_SESSION['pesan_sukses'] = $jumlahTerhapus . ' member berhasil dihapus.';
                    }

                    $stmtHapus->close();
                } catch (Exception $e) {
                    $koneksi->rollback();
                    $_SESSION['pesan_error'] = 'Gagal menghapus member: ' . $e->getMessage();
                }
            }
        }
        closeConnection($koneksi);
        header('Location: dashboard.php#dashboard');
        exit();
    }    // ---------------------------------------------------------
    // PERPANJANG MEMBERSHIP
    // ---------------------------------------------------------
    elseif (isset($_POST['extend_membership'])) {
        $id_membership = (int)($_POST['membership_id'] ?? 0);
        $paket = $_POST['extend_package'] ?? '';
        $inputHarga = $_POST['extend_price'] ?? null;
        $harga = $inputHarga !== null && $inputHarga !== '' ? (float)$inputHarga : ambilHargaPaket($daftarHargaPaket, $paket);
        $metode_bayar = $_POST['extend_payment_method'] ?? '';

        // Validasi input
        if ($id_membership <= 0) {
            $_SESSION['pesan_error'] = 'Membership tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#perpanjang-member');
            exit();
        } elseif (!in_array($paket, $paketValid, true)) {
            $_SESSION['pesan_error'] = 'Paket perpanjangan tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#perpanjang-member');
            exit();
        } elseif ($harga <= 0) {
            $_SESSION['pesan_error'] = 'Harga perpanjangan harus lebih dari 0.';
            closeConnection($koneksi);
            header('Location: dashboard.php#perpanjang-member');
            exit();
        } elseif (!array_key_exists($metode_bayar, $metodePembayaran)) {
            $_SESSION['pesan_error'] = 'Metode pembayaran tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#perpanjang-member');
            exit();
        } else {
            // Ambil data membership saat ini
            $stmt = $koneksi->prepare("SELECT id, end_date FROM memberships WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $id_membership);
            $stmt->execute();
            $dataMembership = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$dataMembership) {
                $_SESSION['pesan_error'] = 'Data membership tidak ditemukan.';
                closeConnection($koneksi);
                header('Location: dashboard.php#perpanjang-member');
                exit();
            } else {
                $tanggal_berakhir_lama = $dataMembership['end_date'];
                $tanggal_dasar = max(date('Y-m-d'), $tanggal_berakhir_lama);
                $jumlahHari = hitungHariPaket($paket);
                $tanggal_berakhir_baru = date('Y-m-d', strtotime($tanggal_dasar . " +$jumlahHari days"));

                // Update membership
                $stmtUpdate = $koneksi->prepare("UPDATE memberships SET end_date = ?, package_type = ?, status = 'active', price = ?, payment_method = ? WHERE id = ?");
                $stmtUpdate->bind_param("ssdsi", $tanggal_berakhir_baru, $paket, $harga, $metode_bayar, $id_membership);
                $stmtUpdate->execute();
                $stmtUpdate->close();

                // Simpan ke riwayat
                $stmtRiwayat = $koneksi->prepare("INSERT INTO membership_history (membership_id, extended_by, extension_date, previous_end_date, new_end_date, package_type, amount, payment_method) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)");
                $id_admin = $_SESSION['id_pengguna'];
                $stmtRiwayat->bind_param("iisssds", $id_membership, $id_admin, $tanggal_berakhir_lama, $tanggal_berakhir_baru, $paket, $harga, $metode_bayar);
                $stmtRiwayat->execute();
                $stmtRiwayat->close();

                // Ambil data member untuk kirim WhatsApp
                $stmtMember = $koneksi->prepare("SELECT u.full_name, u.username, u.phone FROM users u JOIN memberships m ON u.id = m.user_id WHERE m.id = ?");
                $stmtMember->bind_param("i", $id_membership);
                $stmtMember->execute();
                $dataMember = $stmtMember->get_result()->fetch_assoc();
                $stmtMember->close();

                // Insert ke financial_transactions (data permanen untuk laporan keuangan)
                if ($dataMember) {
                    $stmtFinance = $koneksi->prepare("INSERT INTO financial_transactions (user_id, membership_id, member_name, member_username, transaction_type, package_type, amount, payment_method, transaction_date) VALUES ((SELECT user_id FROM memberships WHERE id = ?), ?, ?, ?, 'extension', ?, ?, ?, CURDATE())");
                    $stmtFinance->bind_param("iisssds", $id_membership, $id_membership, $dataMember['full_name'], $dataMember['username'], $paket, $harga, $metode_bayar);
                    $stmtFinance->execute();
                    $stmtFinance->close();
                }
                
                // Buat link WhatsApp untuk kirim tagihan perpanjangan
                if ($dataMember && !empty($dataMember['phone'])) {
                    $nomorMember = formatNomorWA($dataMember['phone']);
                    $pesanWA = "Halo *" . urlencode($dataMember['full_name']) . "*,%0A%0A";
                    $pesanWA .= "📋 *TAGIHAN PERPANJANGAN MEMBERSHIP*%0A%0A";
                    $pesanWA .= "📦 Paket: " . urlencode(str_replace('_', ' ', ucwords($paket, '_'))) . "%0A";
                    $pesanWA .= "📅 Berlaku sampai: " . date('d/m/Y', strtotime($tanggal_berakhir_baru)) . "%0A%0A";
                    $pesanWA .= "💰 *TOTAL TAGIHAN*%0A";
                    $pesanWA .= "*Rp " . number_format($harga, 0, ',', '.') . "*%0A";
                    $pesanWA .= "Metode: " . ucfirst($metode_bayar) . "%0A%0A";
                    $pesanWA .= "Silakan lakukan pembayaran. Terima kasih! 🙏";
                    
                    // Simpan ke array tagihan
                    if (!isset($_SESSION['daftar_tagihan_wa'])) {
                        $_SESSION['daftar_tagihan_wa'] = [];
                    }
                    $_SESSION['daftar_tagihan_wa'][] = [
                        'nama' => $dataMember['full_name'],
                        'telepon' => $dataMember['phone'],
                        'tipe' => 'Perpanjangan',
                        'paket' => str_replace('_', ' ', ucwords($paket, '_')),
                        'harga' => $harga,
                        'link' => "https://wa.me/" . $nomorMember . "?text=" . $pesanWA
                    ];
                }

                $_SESSION['pesan_sukses'] = 'Membership berhasil diperpanjang!';
                closeConnection($koneksi);
                header('Location: dashboard.php#perpanjang-member');
                exit();
            }
        }
    }    // ---------------------------------------------------------
    // CATAT ABSENSI
    // ---------------------------------------------------------
    elseif (isset($_POST['add_attendance'])) {
        $memberTerpilih = $_POST['attendance_members'] ?? [];
        $id_admin = $_SESSION['id_pengguna'];

        if (!is_array($memberTerpilih) || count($memberTerpilih) === 0) {
            $_SESSION['pesan_error'] = 'Pilih setidaknya satu member untuk diabsen.';
        } else {
            $jumlahBerhasil = 0;
            // Gunakan waktu WIB (UTC+7) untuk absensi
            $waktu_wib = gmdate('Y-m-d H:i:s', time() + (7 * 3600));
            $stmtAbsensi = $koneksi->prepare("INSERT INTO attendance (user_id, check_in, notes, created_by) VALUES (?, ?, '', ?)");
            
            foreach ($memberTerpilih as $id_user) {
                $id_user = (int)$id_user;
                if ($id_user > 0) {
                    $stmtAbsensi->bind_param("isi", $id_user, $waktu_wib, $id_admin);
                    if ($stmtAbsensi->execute()) {
                        $jumlahBerhasil++;
                    }
                }
            }
            
            $stmtAbsensi->close();
            
            if ($jumlahBerhasil > 0) {
                $_SESSION['pesan_sukses'] = $jumlahBerhasil . ' member berhasil diabsen!';
            } else {
                $_SESSION['pesan_error'] = 'Gagal mencatat absensi.';
            }
        }
        closeConnection($koneksi);
        header('Location: dashboard.php#catat-absensi');
        exit();
    }    // ---------------------------------------------------------
    // UPDATE DATA MEMBER
    // ---------------------------------------------------------
    elseif (isset($_POST['update_member'])) {
        $id_member = (int)($_POST['member_id'] ?? 0);
        $namaLengkap = trim($_POST['edit_full_name'] ?? '');
        $email = trim($_POST['edit_email'] ?? '');
        $telepon = trim($_POST['edit_phone'] ?? '');

        if ($id_member <= 0) {
            $_SESSION['pesan_error'] = 'Member tidak valid.';
        } elseif ($namaLengkap === '') {
            $_SESSION['pesan_error'] = 'Nama lengkap wajib diisi.';
        } else {
            $stmtUpdate = $koneksi->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ? AND role = 'user'");
            $stmtUpdate->bind_param("sssi", $namaLengkap, $email, $telepon, $id_member);

            if ($stmtUpdate->execute()) {
                if ($stmtUpdate->affected_rows > 0) {
                    $_SESSION['pesan_sukses'] = 'Data member berhasil diperbarui!';
                } else {
                    $_SESSION['pesan_error'] = 'Tidak ada perubahan data atau member tidak ditemukan.';
                }
            } else {
                $_SESSION['pesan_error'] = 'Gagal memperbarui data member: ' . $stmtUpdate->error;
            }

            $stmtUpdate->close();
        }
        closeConnection($koneksi);
        header('Location: dashboard.php#dashboard');
        exit();
    }    // ---------------------------------------------------------
    // VERIFIKASI PEMBAYARAN
    // ---------------------------------------------------------
    elseif (isset($_POST['verify_payment'])) {
        // Proses verifikasi pembayaran
        $id_permintaan = (int)($_POST['request_id'] ?? 0);
        $aksi = $_POST['verification_action'] ?? '';
        $catatan_admin = trim($_POST['admin_notes'] ?? '');
        $id_admin = $_SESSION['id_pengguna'];

        if ($id_permintaan <= 0) {
            $_SESSION['pesan_error'] = 'Request tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#verifikasi-pembayaran');
            exit();
        } elseif (!in_array($aksi, ['approve', 'reject'])) {
            $_SESSION['pesan_error'] = 'Aksi tidak valid.';
            closeConnection($koneksi);
            header('Location: dashboard.php#verifikasi-pembayaran');
            exit();
        } else {
            // Ambil detail permintaan beserta data member
            $stmt = $koneksi->prepare("SELECT er.*, m.end_date as current_end_date, u.full_name, u.username FROM extension_requests er JOIN memberships m ON er.membership_id = m.id JOIN users u ON er.user_id = u.id WHERE er.id = ? AND er.status = 'pending'");
            $stmt->bind_param("i", $id_permintaan);
            $stmt->execute();
            $dataPermintaan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$dataPermintaan) {
                $_SESSION['pesan_error'] = 'Request tidak ditemukan atau sudah diproses.';
            } else {
                if ($aksi === 'approve') {
                    // Setujui: Update membership dan tandai request sebagai disetujui
                    $tanggal_berakhir_lama = $dataPermintaan['current_end_date'];
                    $tanggal_dasar = max(date('Y-m-d'), $tanggal_berakhir_lama);
                    $jumlahHari = hitungHariPaket($dataPermintaan['package_type']);
                    $tanggal_berakhir_baru = date('Y-m-d', strtotime($tanggal_dasar . " +$jumlahHari days"));

                    $koneksi->begin_transaction();
                    try {
                        // Update membership
                        $stmtUpdate = $koneksi->prepare("UPDATE memberships SET end_date = ?, package_type = ?, status = 'active', price = ?, payment_method = ? WHERE id = ?");
                        $stmtUpdate->bind_param("ssdsi", $tanggal_berakhir_baru, $dataPermintaan['package_type'], $dataPermintaan['amount'], $dataPermintaan['payment_method'], $dataPermintaan['membership_id']);
                        $stmtUpdate->execute();
                        $stmtUpdate->close();

                        // Simpan ke riwayat membership
                        $stmtRiwayat = $koneksi->prepare("INSERT INTO membership_history (membership_id, extended_by, extension_date, previous_end_date, new_end_date, package_type, amount, payment_method) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)");
                        $stmtRiwayat->bind_param("iisssds", $dataPermintaan['membership_id'], $dataPermintaan['user_id'], $tanggal_berakhir_lama, $tanggal_berakhir_baru, $dataPermintaan['package_type'], $dataPermintaan['amount'], $dataPermintaan['payment_method']);
                        $stmtRiwayat->execute();
                        $stmtRiwayat->close();

                        // Insert ke financial_transactions (data permanen untuk laporan keuangan)
                        $stmtFinance = $koneksi->prepare("INSERT INTO financial_transactions (user_id, membership_id, member_name, member_username, transaction_type, package_type, amount, payment_method, transaction_date) VALUES (?, ?, ?, ?, 'extension', ?, ?, ?, CURDATE())");
                        $stmtFinance->bind_param("iisssds", $dataPermintaan['user_id'], $dataPermintaan['membership_id'], $dataPermintaan['full_name'], $dataPermintaan['username'], $dataPermintaan['package_type'], $dataPermintaan['amount'], $dataPermintaan['payment_method']);
                        $stmtFinance->execute();
                        $stmtFinance->close();

                        // Update status permintaan
                        $stmtReq = $koneksi->prepare("UPDATE extension_requests SET status = 'approved', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
                        $stmtReq->bind_param("sii", $catatan_admin, $id_admin, $id_permintaan);
                        $stmtReq->execute();
                        $stmtReq->close();

                        $koneksi->commit();
                        $_SESSION['pesan_sukses'] = 'Pembayaran berhasil diverifikasi dan membership diperpanjang!';
                    } catch (Exception $e) {
                        $koneksi->rollback();
                        $_SESSION['pesan_error'] = 'Gagal memverifikasi pembayaran: ' . $e->getMessage();
                    }
                } else {
                    // Tolak: Tandai permintaan sebagai ditolak
                    $stmtReq = $koneksi->prepare("UPDATE extension_requests SET status = 'rejected', admin_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?");
                    $stmtReq->bind_param("sii", $catatan_admin, $id_admin, $id_permintaan);
                    if ($stmtReq->execute()) {
                        $_SESSION['pesan_sukses'] = 'Permintaan perpanjangan telah ditolak.';
                    } else {
                        $_SESSION['pesan_error'] = 'Gagal menolak permintaan.';
                    }
                    $stmtReq->close();
                }
            }
            closeConnection($koneksi);
            header('Location: dashboard.php#verifikasi-pembayaran');
            exit();
        }
    }
}

// =================================================================
// AMBIL DATA STATISTIK
// =================================================================
$statistik = [];

// Total member
$hasil = $koneksi->query("SELECT COUNT(*) as total FROM users WHERE role = 'user'");
$statistik['total_member'] = $hasil->fetch_assoc()['total'];

// Membership aktif (hanya user yang MASIH ADA di tabel users dan punya membership aktif)
$hasil = $koneksi->query("
    SELECT COUNT(DISTINCT m.user_id) as total 
    FROM memberships m
    INNER JOIN users u ON m.user_id = u.id
    WHERE u.role = 'user' AND m.status = 'active'
");
$statistik['membership_aktif'] = $hasil->fetch_assoc()['total'];

// Membership expired (user yang MASIH ADA tapi membership-nya expired atau tidak punya membership aktif)
$hasil = $koneksi->query("
    SELECT COUNT(*) as total 
    FROM users u 
    WHERE u.role = 'user' 
    AND u.id NOT IN (
        SELECT DISTINCT m.user_id FROM memberships m WHERE m.status = 'active'
    )
");
$statistik['membership_expired'] = $hasil->fetch_assoc()['total'];

// Absensi hari ini (gunakan tanggal WIB)
$tanggal_wib = gmdate('Y-m-d', time() + (7 * 3600));
$hasil = $koneksi->query("SELECT COUNT(*) as total FROM attendance WHERE DATE(check_in) = '$tanggal_wib'");
$statistik['absensi_hari_ini'] = $hasil->fetch_assoc()['total'];

// Pembayaran menunggu verifikasi
$hasil = $koneksi->query("SELECT COUNT(*) as total FROM extension_requests WHERE status = 'pending'");
$statistik['pembayaran_pending'] = $hasil->fetch_assoc()['total'];

// =================================================================
// AMBIL DAFTAR NAMA UNTUK MODAL STATISTIK
// =================================================================

// Daftar semua member
$daftar_semua_member = $koneksi->query("
    SELECT u.id, u.full_name, u.username, u.phone, u.email, u.created_at
    FROM users u 
    WHERE u.role = 'user' 
    ORDER BY u.full_name ASC
");

// Daftar member aktif
$daftar_member_aktif = $koneksi->query("
    SELECT DISTINCT u.id, u.full_name, u.username, u.phone, m.end_date, 
           DATEDIFF(m.end_date, CURDATE()) as days_remaining
    FROM users u
    INNER JOIN memberships m ON u.id = m.user_id
    WHERE u.role = 'user' AND m.status = 'active'
    ORDER BY u.full_name ASC
");

// Daftar member expired
$daftar_member_expired = $koneksi->query("
    SELECT u.id, u.full_name, u.username, u.phone, 
           (SELECT MAX(m.end_date) FROM memberships m WHERE m.user_id = u.id) as last_end_date
    FROM users u 
    WHERE u.role = 'user' 
    AND u.id NOT IN (
        SELECT DISTINCT m.user_id FROM memberships m WHERE m.status = 'active'
    )
    ORDER BY u.full_name ASC
");

// Daftar absensi hari ini
$daftar_absensi_hari_ini = $koneksi->query("
    SELECT u.id, u.full_name, u.username, u.phone, a.check_in, a.notes
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE DATE(a.check_in) = '$tanggal_wib'
    ORDER BY a.check_in DESC
");

// Daftar menunggu konfirmasi
$daftar_menunggu_konfirmasi = $koneksi->query("
    SELECT er.*, u.full_name, u.username, u.phone, m.end_date as current_end_date
    FROM extension_requests er
    JOIN users u ON er.user_id = u.id
    JOIN memberships m ON er.membership_id = m.id
    WHERE er.status = 'pending'
    ORDER BY er.created_at ASC
");

// =================================================================
// AMBIL DATA PERMINTAAN PERPANJANGAN PENDING
// =================================================================
$query_permintaan_pending = "
    SELECT er.*, u.full_name, u.username, u.email, u.phone, m.end_date as current_end_date
    FROM extension_requests er
    JOIN users u ON er.user_id = u.id
    JOIN memberships m ON er.membership_id = m.id
    WHERE er.status = 'pending'
    ORDER BY er.created_at ASC
";
$daftar_permintaan_pending = $koneksi->query($query_permintaan_pending);

// =================================================================
// AMBIL SEMUA RIWAYAT PERMINTAAN PERPANJANGAN
// =================================================================
$query_semua_permintaan = "
    SELECT er.*, u.full_name, u.username, admin.full_name as admin_name
    FROM extension_requests er
    JOIN users u ON er.user_id = u.id
    LEFT JOIN users admin ON er.verified_by = admin.id
    ORDER BY er.created_at DESC
    LIMIT 20
";
$daftar_semua_permintaan = $koneksi->query($query_semua_permintaan);

// =================================================================
// AMBIL SEMUA DATA MEMBER DENGAN INFO MEMBERSHIP
// =================================================================
$query_member = "
    SELECT u.id, u.username, u.full_name, u.email, u.phone,
           m.id as membership_id, m.start_date, m.end_date, m.package_type, m.status,
           m.price, m.payment_method,
           DATEDIFF(m.end_date, CURDATE()) as days_remaining
    FROM users u
    LEFT JOIN memberships m ON u.id = m.user_id
    WHERE u.role = 'user'
    ORDER BY u.id DESC
";
$daftar_member = $koneksi->query($query_member);

// =================================================================
// AMBIL DATA ABSENSI TERAKHIR (UNTUK DASHBOARD)
// =================================================================
$query_absensi = "
    SELECT a.*, u.full_name, u.username
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    ORDER BY a.check_in DESC
    LIMIT 10
";
$absensi_terakhir = $koneksi->query($query_absensi);

// Ambil daftar member untuk dropdown perpanjang membership
$daftar_member_perpanjang = $koneksi->query("SELECT m.id, u.id as user_id, u.full_name, u.username, m.end_date FROM memberships m JOIN users u ON m.user_id = u.id WHERE u.role = 'user' ORDER BY u.full_name");

// Ambil daftar user untuk dropdown absensi
$daftar_user_absensi = $koneksi->query("SELECT id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name");

// Ambil absensi terakhir untuk section catat absensi
$absensi_terakhir_section = $koneksi->query($query_absensi);

// =================================================================
// DATA LAPORAN KEUANGAN (dari tabel financial_transactions - permanen)
// =================================================================
$bulan_keuangan = isset($_GET['bulan_keuangan']) ? intval($_GET['bulan_keuangan']) : intval(date('m'));
$tahun_keuangan = isset($_GET['tahun_keuangan']) ? intval($_GET['tahun_keuangan']) : intval(date('Y'));

$tanggal_awal_keuangan = sprintf('%04d-%02d-01', $tahun_keuangan, $bulan_keuangan);
$tanggal_akhir_keuangan = date('Y-m-t', strtotime($tanggal_awal_keuangan));

// Query data keuangan dari financial_transactions
$query_keuangan = "
    SELECT id, transaction_date as tanggal, member_name as nama_member, member_username as username, 
           package_type, amount as jumlah, payment_method, 
           CASE transaction_type WHEN 'new_membership' THEN 'Membership Baru' ELSE 'Perpanjangan' END as tipe
    FROM financial_transactions
    WHERE DATE(transaction_date) BETWEEN '$tanggal_awal_keuangan' AND '$tanggal_akhir_keuangan'
    ORDER BY transaction_date DESC, id DESC
";
$dataKeuangan = $koneksi->query($query_keuangan);

// Total pendapatan
$q_total = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal_keuangan' AND '$tanggal_akhir_keuangan'";
$totalPendapatan = $koneksi->query($q_total)->fetch_assoc()['total'];

// Per metode pembayaran
$q_metode = "SELECT payment_method, SUM(amount) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal_keuangan' AND '$tanggal_akhir_keuangan' GROUP BY payment_method";
$perMetode = $koneksi->query($q_metode);
$dataPerMetode = [];
while ($row = $perMetode->fetch_assoc()) {
    $dataPerMetode[$row['payment_method']] = $row['total'];
}

// Total per tipe
$totalMembershipBaru = $koneksi->query("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal_keuangan' AND '$tanggal_akhir_keuangan' AND transaction_type = 'new_membership'")->fetch_assoc()['total'];
$totalPerpanjangan = $koneksi->query("SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$tanggal_awal_keuangan' AND '$tanggal_akhir_keuangan' AND transaction_type = 'extension'")->fetch_assoc()['total'];

// Jumlah transaksi
$jumlahTransaksi = $dataKeuangan->num_rows;

// Data grafik 12 bulan terakhir
$dataGrafik = [];
for ($i = 11; $i >= 0; $i--) {
    $bln = date('Y-m', strtotime("-$i months"));
    $awal = $bln . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $q = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE DATE(transaction_date) BETWEEN '$awal' AND '$akhir'";
    $total = $koneksi->query($q)->fetch_assoc()['total'];
    $dataGrafik[] = ['bulan' => date('M Y', strtotime($awal)), 'total' => floatval($total)];
}

$namaBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
$labelMetode = ['cash' => 'Cash', 'transfer' => 'Transfer Bank', 'qris' => 'QRIS'];

// Tutup koneksi setelah semua query selesai
closeConnection($koneksi);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dasbor Admin - Manajemen Gym</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-gradient-to-r from-gray-800 to-gray-900 text-white shadow-lg fixed w-full z-30">
        <div class="px-4">
            <div class="flex items-center justify-between py-4">
                <div class="flex items-center space-x-4">
                    <button id="sidebarToggle" class="text-white hover:bg-white/20 p-2 rounded-lg transition">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-dumbbell text-2xl"></i>
                        <span class="text-xl font-bold">AturGym Admin</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="hidden md:block"><i class="fas fa-user-shield mr-2"></i><?php echo $_SESSION['nama_lengkap']; ?></span>
                    <a href="../logout.php?action=logout" class="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>Keluar
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <aside id="sidebar" class="fixed left-0 top-16 h-full w-64 bg-white shadow-lg transform -translate-x-full transition-transform duration-300 z-20">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-list-ul mr-2 text-gray-600"></i>Menu
            </h2>
            <nav class="space-y-2">
                <a href="#dashboard" onclick="showSection('dashboard')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition active">
                    <i class="fas fa-home w-5"></i>
                    <span>Dashboard</span>
                </a>
                <a href="#tambah-member" onclick="showSection('tambah-member')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition">
                    <i class="fas fa-user-plus w-5"></i>
                    <span>Tambah Member</span>
                </a>
                <a href="#perpanjang-member" onclick="showSection('perpanjang-member')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition">
                    <i class="fas fa-calendar-plus w-5"></i>
                    <span>Perpanjang Member</span>
                </a>
                <a href="#catat-absensi" onclick="showSection('catat-absensi')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition">
                    <i class="fas fa-clipboard-check w-5"></i>
                    <span>Catat Absensi</span>
                </a>
                <a href="#verifikasi-pembayaran" onclick="showSection('verifikasi-pembayaran')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition relative">
                    <i class="fas fa-credit-card w-5"></i>
                    <span>Verifikasi Pembayaran</span>
                    <?php if ($statistik['pembayaran_pending'] > 0): ?>
                    <span class="absolute right-2 top-1/2 -translate-y-1/2 bg-gray-700 text-white text-xs font-bold px-2 py-1 rounded-full"><?php echo $statistik['pembayaran_pending']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#laporan-keuangan" onclick="showSection('laporan-keuangan')" class="nav-link flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition">
                    <i class="fas fa-chart-pie w-5 text-gray-600"></i>
                    <span>Laporan Keuangan</span>
                </a>
            </nav>
        </div>
    </aside>

    <!-- Overlay for sidebar -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-10 hidden"></div>

    <!-- Main Content -->
    <div id="mainContent" class="pt-16 transition-all duration-300">
        <div class="px-4 py-8">
        <!-- Alerts -->
        <?php if (isset($pesan_sukses)): ?>
        <div class="bg-gray-100 border-l-4 border-gray-500 text-gray-700 p-4 mb-6 rounded-lg shadow alert-dismissible">
            <div class="flex items-center justify-between">
                <div><i class="fas fa-check-circle mr-2"></i><?php echo $pesan_sukses; ?></div>
                <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-gray-700 hover:text-gray-900 transition ml-4">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Daftar Tagihan WhatsApp -->
        <?php if (!empty($daftar_tagihan_wa)): ?>
        <div class="bg-white border-l-4 border-gray-500 p-4 mb-6 rounded-lg shadow">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-800">
                    <i class="fab fa-whatsapp text-gray-600 mr-2"></i>
                    Kirim Tagihan via WhatsApp (<?php echo count($daftar_tagihan_wa); ?> member)
                </h3>
                <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-gray-500 hover:text-gray-700 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Telepon</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tipe</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Paket</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tagihan</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($daftar_tagihan_wa as $index => $tagihan): ?>
                        <tr class="hover:bg-gray-50" id="tagihan-row-<?php echo $index; ?>">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($tagihan['nama']); ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                                <?php echo htmlspecialchars($tagihan['telepon']); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $tagihan['tipe'] === 'Member Baru' ? 'bg-gray-200 text-gray-800' : 'bg-gray-300 text-gray-800'; ?>">
                                    <?php echo $tagihan['tipe']; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                                <?php echo htmlspecialchars($tagihan['paket']); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-semibold text-gray-800">
                                Rp <?php echo number_format($tagihan['harga'], 0, ',', '.'); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <a href="<?php echo $tagihan['link']; ?>" target="_blank" 
                                   onclick="markAsSent(<?php echo $index; ?>)"
                                   class="inline-flex items-center bg-gray-600 hover:bg-gray-700 text-white px-3 py-1.5 rounded-lg transition text-sm">
                                    <i class="fab fa-whatsapp mr-1"></i>
                                    Kirim
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-200 flex justify-between items-center">
                <p class="text-gray-500 text-sm"><i class="fas fa-info-circle mr-1"></i>Klik "Kirim" untuk membuka WhatsApp dan mengirim tagihan ke masing-masing member</p>
                <button onclick="sendAllWA()" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg transition text-sm">
                    <i class="fab fa-whatsapp mr-1"></i>Kirim Semua
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (isset($pesan_error)): ?>
        <div class="bg-gray-200 border-l-4 border-gray-600 text-gray-700 p-4 mb-6 rounded-lg shadow alert-dismissible flex items-center justify-between">
            <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo $pesan_error; ?></div>
            <button type="button" onclick="this.parentElement.remove()" class="text-gray-700 hover:text-gray-900 transition ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Dashboard Section -->
        <div id="section-dashboard" class="content-section">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div onclick="openModal('modalTotalMember')" class="bg-gradient-to-br from-gray-600 to-gray-700 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition cursor-pointer">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-200 text-sm font-medium">Total Member</p>
                        <p class="text-3xl font-bold mt-2"><?php echo $statistik['total_member']; ?></p>
                    </div>
                    <div class="bg-white/20 p-4 rounded-full">
                        <i class="fas fa-users text-3xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-300 mt-2"><i class="fas fa-hand-pointer mr-1"></i>Klik untuk lihat daftar</p>
            </div>

            <div onclick="openModal('modalMemberAktif')" class="bg-gradient-to-br from-gray-700 to-gray-800 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition cursor-pointer">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-200 text-sm font-medium">Member Aktif</p>
                        <p class="text-3xl font-bold mt-2"><?php echo $statistik['membership_aktif']; ?></p>
                    </div>
                    <div class="bg-white/20 p-4 rounded-full">
                        <i class="fas fa-check-circle text-3xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-300 mt-2"><i class="fas fa-hand-pointer mr-1"></i>Klik untuk lihat daftar</p>
            </div>

            <div onclick="openModal('modalMemberExpired')" class="bg-gradient-to-br from-gray-500 to-gray-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition cursor-pointer">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-200 text-sm font-medium">Member Expired</p>
                        <p class="text-3xl font-bold mt-2"><?php echo $statistik['membership_expired']; ?></p>
                    </div>
                    <div class="bg-white/20 p-4 rounded-full">
                        <i class="fas fa-exclamation-triangle text-3xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-300 mt-2"><i class="fas fa-hand-pointer mr-1"></i>Klik untuk lihat daftar</p>
            </div>

            <div onclick="openModal('modalAbsenHariIni')" class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition cursor-pointer">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-200 text-sm font-medium">Absen Hari Ini</p>
                        <p class="text-3xl font-bold mt-2"><?php echo $statistik['absensi_hari_ini']; ?></p>
                    </div>
                    <div class="bg-white/20 p-4 rounded-full">
                        <i class="fas fa-calendar-check text-3xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-300 mt-2"><i class="fas fa-hand-pointer mr-1"></i>Klik untuk lihat daftar</p>
            </div>

            <div onclick="openModal('modalMenungguKonfirmasi')" class="bg-gradient-to-br from-gray-400 to-gray-500 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition cursor-pointer">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-100 text-sm font-medium">Menunggu Verifikasi</p>
                        <p class="text-3xl font-bold mt-2"><?php echo $statistik['pembayaran_pending']; ?></p>
                    </div>
                    <div class="bg-white/20 p-4 rounded-full">
                        <i class="fas fa-clock text-3xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-200 mt-2"><i class="fas fa-hand-pointer mr-1"></i>Klik untuk lihat daftar</p>
            </div>
        </div>

        <!-- Members Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <form method="POST" id="memberBulkForm">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                        <h2 class="text-xl font-bold text-white flex items-center"><i class="fas fa-list mr-2"></i>Daftar Member</h2>
                        <button type="button" onclick="openModal('reportModal')" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition">
                            <i class="fas fa-file-pdf"></i>
                            <span>Cetak Laporan</span>
                        </button>
                    </div>
                    <!-- Search Bar -->
                    <div class="flex items-center space-x-3">
                        <div class="flex-1 relative">
                            <input type="text" id="searchMember" placeholder="Cari member berdasarkan nama, username, email, atau telepon..." class="w-full px-4 py-2 pl-10 rounded-lg border-2 border-white/30 bg-white/10 text-white placeholder-white/70 focus:bg-white/20 focus:border-white focus:outline-none">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-white/70"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 pb-4">
                    <div id="selectionActions" class="hidden">
                        <div class="flex items-center space-x-3">
                            <span class="text-white text-sm bg-white/20 px-3 py-1 rounded-full whitespace-nowrap">
                                <i class="fas fa-check-double mr-1"></i><span id="selectionInfo">0</span> dipilih
                            </span>
                            <button type="submit" name="delete_members" id="deleteSelectedBtn" onclick="return confirm('Yakin ingin menghapus member yang dipilih?');" class="bg-gray-500/80 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap" disabled>
                                <i class="fas fa-trash-alt"></i>
                                <span>Hapus</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-3 border-b border-gray-100 flex items-center justify-between">
                    <span class="text-sm text-gray-500" id="selectionHelper">Centang member yang ingin dihapus untuk menampilkan tombol aksi.</span>
                    <span class="text-sm text-gray-600" id="searchResultInfo"></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-12">
                                    <input type="checkbox" id="selectAllMembers" class="h-4 w-4 text-gray-600 border-gray-300 rounded focus:ring-gray-500">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Telepon</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paket</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Periode</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="memberTableBody">
                            <?php while($member = $daftar_member->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50 transition member-row" data-name="<?php echo strtolower($member['full_name']); ?>" data-username="<?php echo strtolower($member['username']); ?>" data-email="<?php echo strtolower($member['email'] ?? ''); ?>" data-phone="<?php echo strtolower($member['phone'] ?? ''); ?>">
                                <td class="px-4 py-4">
                                    <input type="checkbox" name="selected_members[]" value="<?php echo $member['id']; ?>" class="member-checkbox h-4 w-4 text-gray-600 border-gray-300 rounded focus:ring-gray-500">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-semibold rounded">#<?php echo str_pad($member['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-gray-600 to-gray-700 rounded-full flex items-center justify-center text-white font-bold">
                                            <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo $member['full_name']; ?></div>
                                            <div class="text-sm text-gray-500"><?php echo $member['username']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php if($member['email']): ?>
                                            <i class="fas fa-envelope text-gray-400 mr-2"></i><?php echo $member['email']; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php if($member['phone']): ?>
                                            <i class="fas fa-phone text-gray-400 mr-2"></i><?php echo $member['phone']; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if($member['package_type']): ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                        <?php echo str_replace('_', ' ', ucwords($member['package_type'], '_')); ?>
                                    </span>
                                    <?php if(!is_null($member['price']) && $member['price'] > 0): ?>
                                    <div class="text-xs text-gray-600 mt-1 font-semibold">
                                        <?php echo formatRupiah((float)$member['price']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if(!empty($member['payment_method'])): ?>
                                    <div class="text-xs text-gray-500">
                                        <i class="fas fa-wallet mr-1"></i><?php echo ucfirst($member['payment_method']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if($member['start_date']): ?>
                                    <div class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($member['start_date'])); ?></div>
                                    <div class="text-sm text-gray-500">s/d <?php echo date('d/m/Y', strtotime($member['end_date'])); ?></div>
                                    <?php if($member['days_remaining'] > 0): ?>
                                    <div class="text-xs text-gray-600 mt-1">
                                        <i class="fas fa-clock mr-1"></i><?php echo $member['days_remaining']; ?> hari lagi
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if($member['end_date']): ?>
                                        <?php if(strtotime($member['end_date']) >= strtotime(date('Y-m-d'))): ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-700 text-white">
                                            <i class="fas fa-check-circle mr-1"></i>Aktif
                                        </span>
                                        <?php else: ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-300 text-gray-800">
                                            <i class="fas fa-times-circle mr-1"></i>Expired
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                        No Membership
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <button type="button" onclick="openEditModal(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['full_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($member['email'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($member['phone'] ?? '', ENT_QUOTES); ?>')" class="text-gray-600 hover:text-gray-900 transition">
                                        <i class="fas fa-edit mr-1"></i>Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <!-- Recent Attendance (Dashboard) -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mt-8" id="dashboard-attendance">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-history mr-2"></i>Absensi Terakhir</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Masuk</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($absensi = $absensi_terakhir->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8 bg-gradient-to-r from-gray-600 to-gray-700 rounded-full flex items-center justify-center text-white text-xs font-bold">
                                        <?php echo strtoupper(substr($absensi['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900"><?php echo $absensi['full_name']; ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $absensi['username']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <i class="fas fa-calendar mr-2 text-gray-500"></i><?php echo date('d/m/Y H:i', strtotime($absensi['check_in'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    <i class="fas fa-check-circle mr-1 text-gray-600"></i>Hadir
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div>
        <!-- End Dashboard Section -->

        <!-- Tambah Member Section -->
        <div id="section-tambah-member" class="content-section hidden">
            <div class="flex justify-center">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden max-w-4xl w-full">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <h3 class="text-2xl font-bold text-white"><i class="fas fa-user-plus mr-2"></i>Tambah Member Baru</h3>
                </div>
                <form method="POST" class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="username" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Password <span class="text-red-500">*</span></label>
                            <input type="password" name="password" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                            <input type="email" name="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Telepon</label>
                            <input type="text" name="phone" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Paket Membership <span class="text-red-500">*</span></label>
                        <select name="package" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent package-select">
                            <option value="1_month">1 Bulan - Rp 250.000</option>
                            <option value="3_months">3 Bulan - Rp 650.000</option>
                            <option value="6_months">6 Bulan - Rp 1.200.000</option>
                            <option value="12_months">12 Bulan - Rp 2.200.000</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Harga <span class="text-red-500">*</span></label>
                            <div class="flex items-center">
                                <span class="px-4 py-3 bg-gray-100 border border-r-0 border-gray-300 text-gray-600 rounded-l-lg">Rp</span>
                                <input type="number" name="price" min="0" step="1000" required class="w-full px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent price-input" placeholder="0">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Otomatis mengikuti paket, bisa disesuaikan manual.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Metode Pembayaran <span class="text-red-500">*</span></label>
                            <select name="payment_method" id="add_payment_method" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent" onchange="togglePaymentInfo('add')">
                                <?php foreach ($metodePembayaran as $kodeMetode => $labelMetode): ?>
                                <option value="<?php echo $kodeMetode; ?>"><?php echo $labelMetode; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Informasi Pembayaran Transfer -->
                    <div id="add_transfer_info" class="mb-6 hidden">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-university mr-2"></i>Informasi Rekening Bank</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php foreach ($daftarRekening as $rekening): ?>
                                <div class="bg-white p-3 rounded-lg border border-gray-100">
                                    <p class="font-bold text-gray-800"><?php echo $rekening['bank']; ?></p>
                                    <p class="text-lg font-mono text-gray-600"><?php echo $rekening['no_rek']; ?></p>
                                    <p class="text-sm text-gray-600">a.n. <?php echo $rekening['atas_nama']; ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informasi Pembayaran QRIS -->
                    <div id="add_qris_info" class="mb-6 hidden">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-qrcode mr-2"></i>Scan QRIS untuk Pembayaran</h4>
                            <div class="flex justify-center">
                                <div class="bg-white p-4 rounded-lg border border-gray-100 text-center">
                                    <?php if (file_exists($gambarQris)): ?>
                                    <img src="../<?php echo $gambarQris; ?>" alt="QRIS" class="max-w-xs mx-auto rounded-lg shadow-md" style="max-height: 300px;">
                                    <?php else: ?>
                                    <div class="bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                        <i class="fas fa-qrcode text-6xl text-gray-400 mb-3"></i>
                                        <p class="text-gray-500">Gambar QRIS belum tersedia</p>
                                        <p class="text-xs text-gray-400 mt-2">Upload file QRIS ke: assets/images/qris.png</p>
                                    </div>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-600 mt-3">Scan QR Code di atas menggunakan aplikasi e-wallet atau mobile banking</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex space-x-4">
                        <button type="submit" name="add_member" class="flex-1 bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-3 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                            <i class="fas fa-save mr-2"></i>Simpan Member
                        </button>
                        <button type="button" onclick="showSection('dashboard')" class="flex-1 bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                            <i class="fas fa-arrow-left mr-2"></i>Kembali
                        </button>
                    </div>
                </form>
            </div>
            </div>
        </div>
        <!-- End Tambah Member Section -->

        <!-- Perpanjang Member Section -->
        <div id="section-perpanjang-member" class="content-section hidden">
            <div class="flex justify-center">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden max-w-2xl w-full">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <h3 class="text-2xl font-bold text-white"><i class="fas fa-calendar-plus mr-2"></i>Perpanjang Membership</h3>
                </div>
                <form method="POST" class="p-8">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Member <span class="text-red-500">*</span></label>
                                <div class="relative mb-2">
                                    <input type="text" id="searchExtendMember" placeholder="Cari berdasarkan nama, username, atau ID..." class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm">
                                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                </div>
                                <select name="membership_id" id="extendMemberSelect" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                                    <option value="">-- Pilih Member --</option>
                                    <?php 
                                    while($m = $daftar_member_perpanjang->fetch_assoc()): 
                                    ?>
                                    <option value="<?php echo $m['id']; ?>" data-name="<?php echo strtolower($m['full_name']); ?>" data-username="<?php echo strtolower($m['username']); ?>" data-id="<?php echo $m['user_id']; ?>">
                                        #<?php echo str_pad($m['user_id'], 4, '0', STR_PAD_LEFT); ?> - <?php echo $m['full_name']; ?> (<?php echo $m['username']; ?>) - Berakhir: <?php echo date('d/m/Y', strtotime($m['end_date'])); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>Ketik nama, username atau ID untuk mencari member</p>
                            </div>
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Paket Perpanjangan <span class="text-red-500">*</span></label>
                                <select name="extend_package" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent package-select">
                                    <option value="1_month">1 Bulan - Rp 250.000</option>
                                    <option value="3_months">3 Bulan - Rp 650.000</option>
                                    <option value="6_months">6 Bulan - Rp 1.200.000</option>
                                    <option value="12_months">12 Bulan - Rp 2.200.000</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Harga Perpanjangan <span class="text-red-500">*</span></label>
                                    <div class="flex items-center">
                                        <span class="px-4 py-3 bg-gray-100 border border-r-0 border-gray-300 text-gray-600 rounded-l-lg">Rp</span>
                                        <input type="number" name="extend_price" min="0" step="1000" required class="w-full px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent price-input" placeholder="0">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Metode Pembayaran <span class="text-red-500">*</span></label>
                                    <select name="extend_payment_method" id="extend_payment_method" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent" onchange="togglePaymentInfo('extend')">
                                        <?php foreach ($metodePembayaran as $kodeMetode => $labelMetode): ?>
                                        <option value="<?php echo $kodeMetode; ?>"><?php echo $labelMetode; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Informasi Pembayaran Transfer -->
                            <div id="extend_transfer_info" class="mb-6 hidden">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-university mr-2"></i>Informasi Rekening Bank</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <?php foreach ($daftarRekening as $rekening): ?>
                                        <div class="bg-white p-3 rounded-lg border border-gray-100">
                                            <p class="font-bold text-gray-800"><?php echo $rekening['bank']; ?></p>
                                            <p class="text-lg font-mono text-gray-600"><?php echo $rekening['no_rek']; ?></p>
                                            <p class="text-sm text-gray-600">a.n. <?php echo $rekening['atas_nama']; ?></p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Informasi Pembayaran QRIS -->
                            <div id="extend_qris_info" class="mb-6 hidden">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-qrcode mr-2"></i>Scan QRIS untuk Pembayaran</h4>
                                    <div class="flex justify-center">
                                        <div class="bg-white p-4 rounded-lg border border-gray-100 text-center">
                                            <?php if (file_exists($gambarQris)): ?>
                                            <img src="../<?php echo $gambarQris; ?>" alt="QRIS" class="max-w-xs mx-auto rounded-lg shadow-md" style="max-height: 300px;">
                                            <?php else: ?>
                                            <div class="bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                                <i class="fas fa-qrcode text-6xl text-gray-400 mb-3"></i>
                                                <p class="text-gray-500">Gambar QRIS belum tersedia</p>
                                                <p class="text-xs text-gray-400 mt-2">Upload file QRIS ke: assets/images/qris.png</p>
                                            </div>
                                            <?php endif; ?>
                                            <p class="text-sm text-gray-600 mt-3">Scan QR Code di atas menggunakan aplikasi e-wallet atau mobile banking</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex space-x-4">
                                <button type="submit" name="extend_membership" class="flex-1 bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-3 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                                    <i class="fas fa-check mr-2"></i>Perpanjang
                                </button>
                                <button type="button" onclick="showSection('dashboard')" class="flex-1 bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                                    <i class="fas fa-arrow-left mr-2"></i>Kembali
                                </button>
                            </div>
                </form>
            </div>
            </div>
        </div>
        <!-- End Perpanjang Member Section -->

        <!-- Catat Absensi Section -->
        <div id="section-catat-absensi" class="content-section hidden">
            <div class="flex justify-center">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden max-w-4xl w-full">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <h3 class="text-2xl font-bold text-white"><i class="fas fa-clipboard-check mr-2"></i>Catat Absensi</h3>
                </div>
                <form method="POST" class="p-6">
                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <label class="block text-sm font-medium text-gray-700">Pilih Member untuk Diabsen</label>
                            <div class="flex items-center space-x-3">
                                <span class="text-sm text-gray-500" id="attendanceCount">0 member dipilih</span>
                                <button type="button" onclick="selectAllAttendance()" class="text-sm text-gray-600 hover:text-gray-800 underline">Pilih Semua</button>
                                <button type="button" onclick="deselectAllAttendance()" class="text-sm text-gray-600 hover:text-gray-800 underline">Hapus Semua</button>
                            </div>
                        </div>
                        <!-- Search Input for Member -->
                        <div class="relative mb-4">
                            <input type="text" id="searchAttendanceMember" placeholder="Cari berdasarkan nama, username, atau ID..." class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <!-- Member Checkbox List -->
                        <div class="border border-gray-200 rounded-lg max-h-96 overflow-y-auto" id="attendanceMemberList">
                            <?php 
                            while($u = $daftar_user_absensi->fetch_assoc()): 
                            ?>
                            <label class="attendance-member-item flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0" data-name="<?php echo strtolower($u['full_name']); ?>" data-username="<?php echo strtolower($u['username']); ?>" data-id="<?php echo $u['id']; ?>">
                                <input type="checkbox" name="attendance_members[]" value="<?php echo $u['id']; ?>" class="attendance-checkbox h-5 w-5 text-gray-600 border-gray-300 rounded focus:ring-gray-500 mr-4">
                                <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-gray-600 to-gray-700 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-gray-900">#<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?> - <?php echo $u['full_name']; ?></div>
                                    <div class="text-xs text-gray-500">@<?php echo $u['username']; ?></div>
                                </div>
                                <div class="text-gray-400">
                                    <i class="fas fa-check-circle text-xl opacity-0 check-icon"></i>
                                </div>
                            </label>
                            <?php endwhile; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1"></i>Centang member yang hadir hari ini</p>
                    </div>
                    <div class="flex space-x-4">
                        <button type="submit" name="add_attendance" class="flex-1 bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-3 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                            <i class="fas fa-check mr-2"></i>Simpan Absensi
                        </button>
                        <button type="button" onclick="showSection('dashboard')" class="flex-1 bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                            <i class="fas fa-arrow-left mr-2"></i>Kembali
                        </button>
                    </div>
                </form>
            </div>
            </div>
            <!-- Recent Attendance (Catat Absensi) -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden mt-8 max-w-4xl mx-auto" id="absensi-attendance">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <h2 class="text-xl font-bold text-white"><i class="fas fa-history mr-2"></i>Absensi Terakhir</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Masuk</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            while($item_absensi = $absensi_terakhir_section->fetch_assoc()):
                            ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 bg-gradient-to-r from-gray-600 to-gray-700 rounded-full flex items-center justify-center text-white text-xs font-bold">
                                            <?php echo strtoupper(substr($item_absensi['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900"><?php echo $item_absensi['full_name']; ?></div>
                                            <div class="text-xs text-gray-500"><?php echo $item_absensi['username']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <i class="fas fa-calendar mr-2 text-gray-500"></i><?php echo date('d/m/Y H:i', strtotime($item_absensi['check_in'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        <i class="fas fa-check-circle mr-1 text-gray-600"></i>Hadir
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- End Catat Absensi Section -->

        <!-- Verifikasi Pembayaran Section -->
        <div id="section-verifikasi-pembayaran" class="content-section hidden">
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-4"><i class="fas fa-credit-card text-gray-500 mr-2"></i>Verifikasi Pembayaran</h2>
                <p class="text-gray-600">Verifikasi bukti pembayaran dari member yang ingin memperpanjang membership.</p>
            </div>

            <!-- Pending Requests -->
            <?php if ($daftar_permintaan_pending && $daftar_permintaan_pending->num_rows > 0): ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-clock mr-2"></i>Menunggu Verifikasi (<?php echo $statistik['pembayaran_pending']; ?>)</h3>
                </div>
                <div class="divide-y divide-gray-200">
                    <?php while($permintaan = $daftar_permintaan_pending->fetch_assoc()): ?>
                    <div class="p-6">
                        <div class="flex flex-col lg:flex-row gap-6">
                            <!-- Info Member -->
                            <div class="flex-1">
                                <div class="flex items-center mb-4">
                                    <div class="h-12 w-12 bg-gradient-to-r from-gray-600 to-gray-700 rounded-full flex items-center justify-center text-white font-bold text-xl mr-4">
                                        <?php echo strtoupper(substr($permintaan['full_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <h4 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($permintaan['full_name']); ?></h4>
                                        <p class="text-sm text-gray-500">@<?php echo htmlspecialchars($permintaan['username']); ?></p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span class="text-gray-500">Email:</span>
                                        <span class="font-medium text-gray-800 ml-2"><?php echo htmlspecialchars($permintaan['email'] ?: '-'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Telepon:</span>
                                        <span class="font-medium text-gray-800 ml-2"><?php echo htmlspecialchars($permintaan['phone'] ?: '-'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Paket:</span>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-semibold ml-2">
                                            <?php echo str_replace('_', ' ', ucwords($permintaan['package_type'], '_')); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Jumlah:</span>
                                        <span class="font-bold text-green-600 ml-2"><?php echo formatRupiah((float)$permintaan['amount']); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Metode:</span>
                                        <span class="font-medium text-gray-800 ml-2"><?php echo ucfirst($permintaan['payment_method']); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Tanggal Request:</span>
                                        <span class="font-medium text-gray-800 ml-2"><?php echo date('d/m/Y H:i', strtotime($permintaan['created_at'])); ?></span>
                                    </div>
                                    <div class="col-span-2">
                                        <span class="text-gray-500">Membership Saat Ini:</span>
                                        <span class="font-medium text-gray-800 ml-2">Berakhir <?php echo date('d/m/Y', strtotime($permintaan['current_end_date'])); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Bukti Pembayaran -->
                            <div class="lg:w-64">
                                <p class="text-sm font-medium text-gray-700 mb-2">Bukti Pembayaran:</p>
                                <?php if ($permintaan['payment_proof']): ?>
                                <a href="../uploads/payment_proofs/<?php echo $permintaan['payment_proof']; ?>" target="_blank" class="block">
                                    <img src="../uploads/payment_proofs/<?php echo $permintaan['payment_proof']; ?>" alt="Bukti Pembayaran" class="w-full h-48 object-cover rounded-lg border-2 border-gray-200 hover:border-blue-500 transition cursor-pointer">
                                </a>
                                <p class="text-xs text-center text-gray-500 mt-1">Klik untuk memperbesar</p>
                                <?php else: ?>
                                <div class="w-full h-48 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <span class="text-gray-400">Tidak ada bukti</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Form Aksi -->
                            <div class="lg:w-72">
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="request_id" value="<?php echo $permintaan['id']; ?>">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Catatan Admin (opsional)</label>
                                        <textarea name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent text-sm" placeholder="Catatan untuk member..."></textarea>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button type="submit" name="verify_payment" value="1" onclick="this.form.querySelector('[name=verification_action]').value='approve'" class="flex-1 bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold transition">
                                            <i class="fas fa-check mr-1"></i>Setujui
                                        </button>
                                        <button type="submit" name="verify_payment" value="1" onclick="this.form.querySelector('[name=verification_action]').value='reject'" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-semibold transition">
                                            <i class="fas fa-times mr-1"></i>Tolak
                                        </button>
                                    </div>
                                    <input type="hidden" name="verification_action" value="">
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg p-8 text-center mb-8">
                <i class="fas fa-check-circle text-6xl text-green-400 mb-4"></i>
                <h3 class="text-xl font-bold text-gray-700 mb-2">Tidak Ada Pembayaran Pending</h3>
                <p class="text-gray-500">Semua permintaan perpanjangan sudah diverifikasi.</p>
            </div>
            <?php endif; ?>

            <!-- Riwayat Semua Permintaan -->
            <?php if ($daftar_semua_permintaan && $daftar_semua_permintaan->num_rows > 0): ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-gray-600 to-gray-700 px-6 py-4">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-history mr-2"></i>Riwayat Verifikasi</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paket</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jumlah</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Metode</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Diverifikasi</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while($riwayat = $daftar_semua_permintaan->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($riwayat['full_name']); ?></div>
                                    <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($riwayat['username']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-semibold">
                                        <?php echo str_replace('_', ' ', ucwords($riwayat['package_type'], '_')); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap font-medium"><?php echo formatRupiah((float)$riwayat['amount']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo ucfirst($riwayat['payment_method']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($riwayat['status'] === 'pending'): ?>
                                    <span class="px-2 py-1 bg-gray-200 text-gray-800 rounded-full text-xs font-semibold">
                                        <i class="fas fa-clock mr-1"></i>Pending
                                    </span>
                                    <?php elseif ($riwayat['status'] === 'approved'): ?>
                                    <span class="px-2 py-1 bg-gray-700 text-white rounded-full text-xs font-semibold">
                                        <i class="fas fa-check mr-1"></i>Disetujui
                                    </span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 bg-gray-400 text-white rounded-full text-xs font-semibold">
                                        <i class="fas fa-times mr-1"></i>Ditolak
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo $riwayat['admin_name'] ?: '-'; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo date('d/m/Y H:i', strtotime($riwayat['created_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-6">
                <button type="button" onclick="showSection('dashboard')" class="bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Beranda
                </button>
            </div>
        </div>
        <!-- End Verifikasi Pembayaran Section -->

        <!-- Laporan Keuangan Section -->
        <div id="section-laporan-keuangan" class="content-section hidden">
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-4"><i class="fas fa-chart-pie text-gray-600 mr-2"></i>Laporan Keuangan</h2>
                <p class="text-gray-600">Laporan pendapatan dari membership baru dan perpanjangan.</p>
            </div>

            <!-- Filter -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="section" value="laporan-keuangan">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Bulan</label>
                        <select name="bulan_keuangan" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == $bulan_keuangan ? 'selected' : ''; ?>>
                                <?php echo $namaBulan[$i]; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Tahun</label>
                        <select name="tahun_keuangan" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                            <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == $tahun_keuangan ? 'selected' : ''; ?>>
                                <?php echo $i; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="bg-gray-700 hover:bg-gray-800 text-white px-6 py-2 rounded-lg transition">
                        <i class="fas fa-filter mr-2"></i>Filter
                    </button>
                    <a href="report_finance_excel.php?bulan=<?php echo $bulan_keuangan; ?>&tahun=<?php echo $tahun_keuangan; ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fas fa-file-excel mr-2"></i>Unduh Excel
                    </a>
                </form>
            </div>

            <!-- Ringkasan -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-400 text-sm">Total Pendapatan</p>
                            <p class="text-2xl font-bold text-white mt-1">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-money-bill-wave text-xl text-white"></i>
                        </div>
                    </div>
                    <p class="text-gray-500 text-xs mt-2"><?php echo $namaBulan[$bulan_keuangan] . ' ' . $tahun_keuangan; ?></p>
                </div>

                <div class="bg-gradient-to-br from-gray-600 to-gray-700 rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-300 text-sm">Membership Baru</p>
                            <p class="text-2xl font-bold text-white mt-1">Rp <?php echo number_format($totalMembershipBaru, 0, ',', '.'); ?></p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-user-plus text-xl text-white"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-gray-500 to-gray-600 rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-200 text-sm">Perpanjangan</p>
                            <p class="text-2xl font-bold text-white mt-1">Rp <?php echo number_format($totalPerpanjangan, 0, ',', '.'); ?></p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-redo text-xl text-white"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-gray-400 to-gray-500 rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-100 text-sm">Jumlah Transaksi</p>
                            <p class="text-2xl font-bold text-white mt-1"><?php echo $jumlahTransaksi; ?></p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-receipt text-xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pendapatan per Metode -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="bg-gradient-to-br from-gray-700 to-gray-800 rounded-xl p-4 shadow-lg">
                    <div class="flex items-center space-x-3">
                        <div class="bg-white/20 p-2 rounded-full">
                            <i class="fas fa-money-bill text-white"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-xs">Cash</p>
                            <p class="text-lg font-bold text-white">Rp <?php echo number_format($dataPerMetode['cash'] ?? 0, 0, ',', '.'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-600 to-gray-700 rounded-xl p-4 shadow-lg">
                    <div class="flex items-center space-x-3">
                        <div class="bg-white/20 p-2 rounded-full">
                            <i class="fas fa-university text-white"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-xs">Transfer Bank</p>
                            <p class="text-lg font-bold text-white">Rp <?php echo number_format($dataPerMetode['transfer'] ?? 0, 0, ',', '.'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-gray-500 to-gray-600 rounded-xl p-4 shadow-lg">
                    <div class="flex items-center space-x-3">
                        <div class="bg-white/20 p-2 rounded-full">
                            <i class="fas fa-qrcode text-white"></i>
                        </div>
                        <div>
                            <p class="text-white/80 text-xs">QRIS</p>
                            <p class="text-lg font-bold text-white">Rp <?php echo number_format($dataPerMetode['qris'] ?? 0, 0, ',', '.'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grafik -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-chart-line mr-2 text-gray-600"></i>Tren Pendapatan 12 Bulan</h3>
                    <div class="h-64">
                        <canvas id="chartPendapatan"></canvas>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-chart-pie mr-2 text-gray-600"></i>Distribusi Metode Pembayaran</h3>
                    <div class="h-64">
                        <canvas id="chartMetode"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tabel Detail -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4 flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-list mr-2"></i>Detail Transaksi</h3>
                    <span class="text-gray-300 text-sm"><?php echo $namaBulan[$bulan_keuangan] . ' ' . $tahun_keuangan; ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paket</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Metode</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $no = 1;
                            $dataKeuangan->data_seek(0);
                            while ($data = $dataKeuangan->fetch_assoc()): 
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-800"><?php echo $no++; ?></td>
                                <td class="px-6 py-4 text-gray-800"><?php echo date('d/m/Y H:i', strtotime($data['tanggal'])); ?></td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900"><?php echo $data['nama_member']; ?></div>
                                    <div class="text-gray-500 text-xs">@<?php echo $data['username']; ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $data['tipe'] == 'Membership Baru' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-800'; ?>">
                                        <?php echo $data['tipe']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">
                                        <?php echo str_replace('_', ' ', ucwords($data['package_type'], '_')); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-600"><?php echo $labelMetode[$data['payment_method']] ?? $data['payment_method']; ?></td>
                                <td class="px-6 py-4 text-right text-gray-800 font-semibold">Rp <?php echo number_format($data['jumlah'], 0, ',', '.'); ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($jumlahTransaksi == 0): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                    <i class="fas fa-inbox text-4xl mb-3"></i>
                                    <p>Tidak ada transaksi di bulan ini</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($jumlahTransaksi > 0): ?>
                        <tfoot class="bg-gray-100">
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-right font-bold text-gray-800">Total:</td>
                                <td class="px-6 py-4 text-right text-gray-800 font-bold text-lg">Rp <?php echo number_format($totalPendapatan, 0, ',', '.'); ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="mt-6">
                <button type="button" onclick="showSection('dashboard')" class="bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali ke Beranda
                </button>
            </div>
        </div>
        <!-- End Laporan Keuangan Section -->

    </div>
    </div>

    <!-- Edit Member Modal -->
    <div id="editMemberModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4 rounded-t-2xl">
                <h3 class="text-xl font-bold text-white"><i class="fas fa-user-edit mr-2"></i>Edit Data Member</h3>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="member_id" id="edit_member_id">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nama Lengkap <span class="text-red-500">*</span></label>
                    <input type="text" name="edit_full_name" id="edit_full_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                    <input type="email" name="edit_email" id="edit_email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Telepon</label>
                    <input type="text" name="edit_phone" id="edit_phone" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                </div>
                <div class="flex space-x-3">
                    <button type="submit" name="update_member" class="flex-1 bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-3 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                        <i class="fas fa-save mr-2"></i>Simpan Perubahan
                    </button>
                    <button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-300 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold">
                        <i class="fas fa-times mr-2"></i>Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Global variables for sidebar
        let sidebar, sidebarToggle, sidebarOverlay, mainContent;
        
        // Daftar link WhatsApp untuk kirim semua
        const waLinks = <?php echo json_encode(array_column($daftar_tagihan_wa, 'link')); ?>;
        
        // Tandai tagihan sebagai sudah dikirim
        function markAsSent(index) {
            const row = document.getElementById('tagihan-row-' + index);
            if (row) {
                row.classList.add('bg-gray-100');
                const btn = row.querySelector('a');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check mr-1"></i>Terkirim';
                    btn.classList.remove('bg-gray-600', 'hover:bg-gray-700');
                    btn.classList.add('bg-gray-400', 'cursor-default');
                }
            }
        }
        
        // Kirim semua tagihan WhatsApp satu per satu
        function sendAllWA() {
            if (waLinks.length === 0) return;
            
            let currentIndex = 0;
            
            function openNext() {
                if (currentIndex < waLinks.length) {
                    window.open(waLinks[currentIndex], '_blank');
                    markAsSent(currentIndex);
                    currentIndex++;
                    // Delay 1 detik sebelum buka link berikutnya
                    if (currentIndex < waLinks.length) {
                        setTimeout(openNext, 1000);
                    }
                }
            }
            
            if (confirm('Akan membuka ' + waLinks.length + ' tab WhatsApp. Lanjutkan?')) {
                openNext();
            }
        }

        // Function to close sidebar (available globally)
        function closeSidebarGlobal() {
            if (sidebar && sidebarOverlay && mainContent) {
                sidebar.classList.add('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                mainContent.classList.remove('ml-64');
            }
        }

        // Section navigation
        function showSection(sectionName) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.add('hidden');
            });

            // Show selected section
            const targetSection = document.getElementById('section-' + sectionName);
            if (targetSection) {
                targetSection.classList.remove('hidden');
            }

            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active', 'bg-gray-100', 'text-gray-900', 'font-semibold');
            });

            const activeLink = document.querySelector(`a[href="#${sectionName}"]`);
            if (activeLink) {
                activeLink.classList.add('active', 'bg-gray-100', 'text-gray-900', 'font-semibold');
            }

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Initialize everything when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize sidebar elements
            sidebar = document.getElementById('sidebar');
            sidebarToggle = document.getElementById('sidebarToggle');
            sidebarOverlay = document.getElementById('sidebarOverlay');
            mainContent = document.getElementById('mainContent');

            // Function to toggle sidebar
            function toggleSidebar() {
                const isOpen = !sidebar.classList.contains('-translate-x-full');
                
                if (isOpen) {
                    // Close sidebar
                    sidebar.classList.add('-translate-x-full');
                    sidebarOverlay.classList.add('hidden');
                    if (mainContent) {
                        mainContent.classList.remove('ml-64');
                    }
                } else {
                    // Open sidebar
                    sidebar.classList.remove('-translate-x-full');
                    sidebarOverlay.classList.remove('hidden');
                    if (mainContent && window.innerWidth >= 1024) {
                        mainContent.classList.add('ml-64');
                    }
                }
            }

            // Function to close sidebar
            function closeSidebar() {
                sidebar.classList.add('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                if (mainContent) {
                    mainContent.classList.remove('ml-64');
                }
            }

            // Sidebar toggle - only hamburger button can close/open sidebar
            if (sidebarToggle && sidebar && sidebarOverlay) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleSidebar();
                });
            }

            // Handle window resize
            window.addEventListener('resize', function() {
                if (mainContent && !sidebar.classList.contains('-translate-x-full')) {
                    if (window.innerWidth >= 1024) {
                        mainContent.classList.add('ml-64');
                    } else {
                        mainContent.classList.remove('ml-64');
                    }
                }
            });

            // Member search functionality
            const searchInput = document.getElementById('searchMember');
            const memberRows = document.querySelectorAll('.member-row');
            const searchResultInfo = document.getElementById('searchResultInfo');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase().trim();
                    let visibleCount = 0;
                    let totalCount = memberRows.length;

                    memberRows.forEach(row => {
                        const name = row.getAttribute('data-name') || '';
                        const username = row.getAttribute('data-username') || '';
                        const email = row.getAttribute('data-email') || '';
                        const phone = row.getAttribute('data-phone') || '';

                        const matches = name.includes(searchTerm) || 
                                      username.includes(searchTerm) || 
                                      email.includes(searchTerm) || 
                                      phone.includes(searchTerm);

                        if (matches) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    if (searchResultInfo) {
                        if (searchTerm === '') {
                            searchResultInfo.textContent = '';
                        } else {
                            searchResultInfo.textContent = `Menampilkan ${visibleCount} dari ${totalCount} member`;
                        }
                    }
                });
            }

            // Checkbox and selection functionality
            const selectAllCheckbox = document.getElementById('selectAllMembers');
            const actionBar = document.getElementById('selectionActions');
            const helperText = document.getElementById('selectionHelper');
            const deleteButton = document.getElementById('deleteSelectedBtn');
            const selectionInfo = document.getElementById('selectionInfo');
            const daftarHarga = <?php echo json_encode($daftarHargaPaket); ?>;

            const sinkronisasiHarga = (selectEl, inputEl) => {
                if (!selectEl || !inputEl) return;
                const paketTerpilih = selectEl.value;
                const harga = daftarHarga[paketTerpilih] || 0;
                inputEl.value = harga > 0 ? harga : '';
            };

            // Sinkronisasi harga untuk semua select paket
            document.querySelectorAll('.package-select').forEach(selectEl => {
                const container = selectEl.closest('form');
                const priceInput = container.querySelector('.price-input');
                if (priceInput) {
                    selectEl.addEventListener('change', () => sinkronisasiHarga(selectEl, priceInput));
                    sinkronisasiHarga(selectEl, priceInput);
                }
            });

            // Fungsi untuk memperbarui status seleksi
            const updateSelectionState = () => {
                const checkboxes = document.querySelectorAll('.member-checkbox');
                const checkedCheckboxes = document.querySelectorAll('.member-checkbox:checked');
                const checkedCount = checkedCheckboxes.length;

                if (actionBar) {
                    actionBar.classList.toggle('hidden', checkedCount === 0);
                }

                if (helperText) {
                    helperText.classList.toggle('hidden', checkedCount > 0);
                }

                if (deleteButton) {
                    deleteButton.disabled = checkedCount === 0;
                    deleteButton.classList.toggle('opacity-50', checkedCount === 0);
                    deleteButton.classList.toggle('cursor-not-allowed', checkedCount === 0);
                }

                if (selectionInfo) {
                    selectionInfo.textContent = checkedCount;
                }

                if (selectAllCheckbox) {
                    if (checkboxes.length === 0) {
                        selectAllCheckbox.checked = false;
                        selectAllCheckbox.indeterminate = false;
                    } else {
                        selectAllCheckbox.checked = checkedCount > 0 && checkedCount === checkboxes.length;
                        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
                    }
                }
            };

            document.querySelectorAll('.member-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', updateSelectionState);
            });

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function () {
                    const checkedState = this.checked;
                    document.querySelectorAll('.member-checkbox').forEach(function (checkbox) {
                        checkbox.checked = checkedState;
                    });
                    updateSelectionState();
                });
            }

            updateSelectionState();
        });

        // Function to toggle payment info display
        function togglePaymentInfo(prefix) {
            const paymentMethod = document.getElementById(prefix + '_payment_method').value;
            const transferInfo = document.getElementById(prefix + '_transfer_info');
            const qrisInfo = document.getElementById(prefix + '_qris_info');
            
            // Hide all payment info
            if (transferInfo) transferInfo.classList.add('hidden');
            if (qrisInfo) qrisInfo.classList.add('hidden');
            
            // Show relevant payment info
            if (paymentMethod === 'transfer' && transferInfo) {
                transferInfo.classList.remove('hidden');
            } else if (paymentMethod === 'qris' && qrisInfo) {
                qrisInfo.classList.remove('hidden');
            }
        }

        // Modal functions (defined outside DOMContentLoaded so they can be called from onclick attributes)
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function openEditModal(id, name, email, phone) {
            document.getElementById('edit_member_id').value = id;
            document.getElementById('edit_full_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('editMemberModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editMemberModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('bg-opacity-50')) {
                event.target.classList.add('hidden');
            }
        }

        // Search functionality for Perpanjang Member dropdown
        function setupDropdownSearch(searchInputId, selectId) {
            const searchInput = document.getElementById(searchInputId);
            const selectElement = document.getElementById(selectId);
            
            if (!searchInput || !selectElement) return;
            
            // Store original options
            const originalOptions = Array.from(selectElement.options).map(opt => ({
                value: opt.value,
                text: opt.text,
                name: opt.dataset.name || '',
                username: opt.dataset.username || '',
                id: opt.dataset.id || ''
            }));
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                // Clear current options except first one
                selectElement.innerHTML = '';
                
                // Add default option
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.text = '-- Pilih Member --';
                selectElement.appendChild(defaultOption);
                
                // Filter and add matching options
                originalOptions.forEach(opt => {
                    if (opt.value === '') return; // Skip default option
                    
                    const matchesName = opt.name.includes(searchTerm);
                    const matchesUsername = opt.username.includes(searchTerm);
                    const matchesId = opt.id.includes(searchTerm) || opt.value.includes(searchTerm);
                    const matchesText = opt.text.toLowerCase().includes(searchTerm);
                    
                    if (searchTerm === '' || matchesName || matchesUsername || matchesId || matchesText) {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.text = opt.text;
                        option.dataset.name = opt.name;
                        option.dataset.username = opt.username;
                        option.dataset.id = opt.id;
                        selectElement.appendChild(option);
                    }
                });
                
                // If only one result (plus default), auto-select it
                if (selectElement.options.length === 2 && searchTerm !== '') {
                    selectElement.selectedIndex = 1;
                }
            });
        }
        
        // Initialize search for Extend Member dropdown
        document.addEventListener('DOMContentLoaded', function() {
            setupDropdownSearch('searchExtendMember', 'extendMemberSelect');
            
            // Initialize attendance checkbox search
            initAttendanceCheckboxSearch();
            
            // Initialize attendance checkbox visual feedback
            initAttendanceCheckboxVisuals();
        });

        // Search functionality for Attendance Checkbox List
        function initAttendanceCheckboxSearch() {
            const searchInput = document.getElementById('searchAttendanceMember');
            const memberItems = document.querySelectorAll('.attendance-member-item');
            
            if (!searchInput || memberItems.length === 0) return;
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                memberItems.forEach(item => {
                    const name = item.dataset.name || '';
                    const username = item.dataset.username || '';
                    const id = item.dataset.id || '';
                    
                    const matchesName = name.includes(searchTerm);
                    const matchesUsername = username.includes(searchTerm);
                    const matchesId = id.includes(searchTerm);
                    
                    if (searchTerm === '' || matchesName || matchesUsername || matchesId) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Visual feedback for checkboxes
        function initAttendanceCheckboxVisuals() {
            const checkboxes = document.querySelectorAll('.attendance-checkbox');
            
            checkboxes.forEach(checkbox => {
                // Set initial state
                updateCheckboxVisual(checkbox);
                
                // Add change listener
                checkbox.addEventListener('change', function() {
                    updateCheckboxVisual(this);
                    updateAttendanceCount();
                });
            });
            
            // Initialize count
            updateAttendanceCount();
        }

        function updateCheckboxVisual(checkbox) {
            const label = checkbox.closest('label');
            const checkIcon = label.querySelector('.check-icon');
            
            if (checkbox.checked) {
                label.classList.add('bg-gray-100');
                label.classList.remove('hover:bg-gray-50');
                checkIcon.classList.remove('opacity-0');
                checkIcon.classList.add('text-gray-600');
            } else {
                label.classList.remove('bg-gray-100');
                label.classList.add('hover:bg-gray-50');
                checkIcon.classList.add('opacity-0');
                checkIcon.classList.remove('text-gray-600');
            }
        }

        function updateAttendanceCount() {
            const checkboxes = document.querySelectorAll('.attendance-checkbox:checked');
            const countElement = document.getElementById('attendanceCount');
            
            if (countElement) {
                countElement.textContent = checkboxes.length + ' member dipilih';
            }
        }

        function selectAllAttendance() {
            const checkboxes = document.querySelectorAll('.attendance-checkbox');
            checkboxes.forEach(checkbox => {
                // Only select visible items
                const label = checkbox.closest('label');
                if (label.style.display !== 'none') {
                    checkbox.checked = true;
                    updateCheckboxVisual(checkbox);
                }
            });
            updateAttendanceCount();
        }

        function deselectAllAttendance() {
            const checkboxes = document.querySelectorAll('.attendance-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
                updateCheckboxVisual(checkbox);
            });
            updateAttendanceCount();
        }
    </script>

    <!-- Modal Cetak Laporan -->
    <div id="reportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-file-pdf mr-2"></i>Cetak Laporan Member</h3>
                    <button type="button" onclick="closeModal('reportModal')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <form action="report_members.php" method="GET" target="_blank" class="p-6">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Jenis Laporan</label>
                    <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                        <option value="all">Semua Member</option>
                        <option value="new">Member Baru (per bulan)</option>
                        <option value="active">Member Aktif</option>
                        <option value="expired">Member Expired</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bulan</label>
                        <select name="month" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                            <?php 
                            $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            $currentMonth = (int)date('m');
                            foreach ($months as $i => $monthName): 
                            ?>
                            <option value="<?php echo $i + 1; ?>" <?php echo ($i + 1 === $currentMonth) ? 'selected' : ''; ?>>
                                <?php echo $monthName; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tahun</label>
                        <select name="year" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent">
                            <?php 
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 5; $y--): 
                            ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y === $currentYear) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                    <h4 class="font-semibold text-gray-800 mb-2"><i class="fas fa-info-circle mr-1"></i>Informasi Laporan</h4>
                    <ul class="text-sm text-gray-700 space-y-1">
                        <li>• <strong>Semua Member:</strong> Menampilkan seluruh member terdaftar</li>
                        <li>• <strong>Member Baru:</strong> Member yang mendaftar di bulan terpilih</li>
                        <li>• <strong>Member Aktif:</strong> Member dengan membership masih berlaku</li>
                        <li>• <strong>Member Expired:</strong> Member dengan membership sudah habis</li>
                    </ul>
                </div>
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-3 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                        <i class="fas fa-download mr-2"></i>Unduh PDF
                    </button>
                    <button type="button" onclick="closeModal('reportModal')" class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 transition font-semibold">
                        <i class="fas fa-times mr-2"></i>Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Total Member -->
    <div id="modalTotalMember" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="bg-gradient-to-r from-gray-600 to-gray-700 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-users mr-2"></i>Total Member (<?php echo $statistik['total_member']; ?>)</h3>
                    <button type="button" onclick="closeModal('modalTotalMember')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto max-h-[60vh]">
                <table class="w-full">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Telepon</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php 
                        $no = 1;
                        $daftar_semua_member->data_seek(0);
                        while ($m = $daftar_semua_member->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $no++; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($m['username']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($m['phone'] ?? '-'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($statistik['total_member'] == 0): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">Belum ada member</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t">
                <button type="button" onclick="closeModal('modalTotalMember')" class="w-full bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition font-semibold">
                    <i class="fas fa-times mr-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Member Aktif -->
    <div id="modalMemberAktif" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-check-circle mr-2"></i>Member Aktif (<?php echo $statistik['membership_aktif']; ?>)</h3>
                    <button type="button" onclick="closeModal('modalMemberAktif')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto max-h-[60vh]">
                <table class="w-full">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Sisa Hari</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php 
                        $no = 1;
                        $daftar_member_aktif->data_seek(0);
                        while ($m = $daftar_member_aktif->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $no++; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($m['username']); ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php 
                                $days = $m['days_remaining'];
                                $colorClass = $days <= 7 ? 'bg-red-100 text-red-800' : ($days <= 14 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800');
                                ?>
                                <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $colorClass; ?>">
                                    <?php echo $days; ?> hari
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($statistik['membership_aktif'] == 0): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">Tidak ada member aktif</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t">
                <button type="button" onclick="closeModal('modalMemberAktif')" class="w-full bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition font-semibold">
                    <i class="fas fa-times mr-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Member Expired -->
    <div id="modalMemberExpired" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="bg-gradient-to-r from-gray-500 to-gray-600 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-exclamation-triangle mr-2"></i>Member Expired (<?php echo $statistik['membership_expired']; ?>)</h3>
                    <button type="button" onclick="closeModal('modalMemberExpired')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto max-h-[60vh]">
                <table class="w-full">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Telepon</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Terakhir Aktif</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php 
                        $no = 1;
                        $daftar_member_expired->data_seek(0);
                        while ($m = $daftar_member_expired->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $no++; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($m['username']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($m['phone'] ?? '-'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?php echo $m['last_end_date'] ? date('d/m/Y', strtotime($m['last_end_date'])) : '-'; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($statistik['membership_expired'] == 0): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Tidak ada member expired</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t">
                <button type="button" onclick="closeModal('modalMemberExpired')" class="w-full bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition font-semibold">
                    <i class="fas fa-times mr-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Absen Hari Ini -->
    <div id="modalAbsenHariIni" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="bg-gradient-to-r from-gray-800 to-gray-900 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-calendar-check mr-2"></i>Absen Hari Ini (<?php echo $statistik['absensi_hari_ini']; ?>)</h3>
                    <button type="button" onclick="closeModal('modalAbsenHariIni')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-300 text-sm mt-1"><?php echo date('l, d F Y'); ?></p>
            </div>
            <div class="overflow-y-auto max-h-[60vh]">
                <table class="w-full">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Jam Masuk</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php 
                        $no = 1;
                        $daftar_absensi_hari_ini->data_seek(0);
                        while ($a = $daftar_absensi_hari_ini->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $no++; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($a['full_name']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($a['username']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                    <?php echo date('H:i', strtotime($a['check_in'])); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($a['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($statistik['absensi_hari_ini'] == 0): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Belum ada absensi hari ini</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t">
                <button type="button" onclick="closeModal('modalAbsenHariIni')" class="w-full bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition font-semibold">
                    <i class="fas fa-times mr-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Menunggu Konfirmasi -->
    <div id="modalMenungguKonfirmasi" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[80vh] overflow-hidden">
            <div class="bg-gradient-to-r from-gray-400 to-gray-500 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-white"><i class="fas fa-clock mr-2"></i>Menunggu Verifikasi (<?php echo $statistik['pembayaran_pending']; ?>)</h3>
                    <button type="button" onclick="closeModal('modalMenungguKonfirmasi')" class="text-white/70 hover:text-white transition">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto max-h-[60vh]">
                <table class="w-full">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Paket</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nominal</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Metode</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php 
                        $no = 1;
                        $daftar_menunggu_konfirmasi->data_seek(0);
                        while ($r = $daftar_menunggu_konfirmasi->fetch_assoc()): 
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $no++; ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($r['full_name']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo ucwords(str_replace('_', ' ', $r['package_type'])); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600">Rp <?php echo number_format($r['amount'], 0, ',', '.'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo ucfirst($r['payment_method']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($statistik['pembayaran_pending'] == 0): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Tidak ada pembayaran menunggu verifikasi</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t flex gap-3">
                <a href="#verifikasi-pembayaran" onclick="closeModal('modalMenungguKonfirmasi'); showSection('verifikasi-pembayaran');" class="flex-1 text-center bg-gradient-to-r from-gray-700 to-gray-800 text-white px-6 py-2 rounded-lg hover:from-gray-600 hover:to-gray-700 transition font-semibold">
                    <i class="fas fa-arrow-right mr-2"></i>Ke Halaman Verifikasi
                </a>
                <button type="button" onclick="closeModal('modalMenungguKonfirmasi')" class="flex-1 bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition font-semibold">
                    <i class="fas fa-times mr-2"></i>Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Chart.js untuk Laporan Keuangan -->
    <script>
        // Initialize charts when page loads or when section is shown
        function initFinanceCharts() {
            // Chart Pendapatan Bulanan
            const ctxLine = document.getElementById('chartPendapatan');
            if (ctxLine && !ctxLine.chart) {
                ctxLine.chart = new Chart(ctxLine.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_column($dataGrafik, 'bulan')); ?>,
                        datasets: [{
                            label: 'Pendapatan',
                            data: <?php echo json_encode(array_column($dataGrafik, 'total')); ?>,
                            borderColor: 'rgb(55, 65, 81)',
                            backgroundColor: 'rgba(55, 65, 81, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgb(55, 65, 81)',
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
                            x: { grid: { color: 'rgba(0,0,0,0.1)' } },
                            y: {
                                grid: { color: 'rgba(0,0,0,0.1)' },
                                ticks: {
                                    callback: function(value) {
                                        return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Chart Pie Metode Pembayaran
            const ctxPie = document.getElementById('chartMetode');
            if (ctxPie && !ctxPie.chart) {
                ctxPie.chart = new Chart(ctxPie.getContext('2d'), {
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
                                'rgba(31, 41, 55, 0.9)',
                                'rgba(107, 114, 128, 0.9)',
                                'rgba(209, 213, 219, 0.9)'
                            ],
                            borderColor: [
                                'rgb(31, 41, 55)',
                                'rgb(107, 114, 128)',
                                'rgb(209, 213, 219)'
                            ],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' },
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
            }
        }

        // Initialize charts when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we should show finance section from URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('section') === 'laporan-keuangan' || urlParams.get('bulan_keuangan')) {
                showSection('laporan-keuangan');
            }
        });

        // Override showSection to initialize charts when finance section is shown
        const originalShowSection = window.showSection;
        window.showSection = function(sectionId) {
            if (typeof originalShowSection === 'function') {
                originalShowSection(sectionId);
            }
            if (sectionId === 'laporan-keuangan') {
                setTimeout(initFinanceCharts, 100);
            }
        };
    </script>
</body>
</html>
