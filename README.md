# Gym Management System

Website manajemen gym dengan 2 role: Admin dan Member/User

## Fitur

### Admin
- ✅ Dashboard dengan statistik lengkap
- ✅ Tambah member baru (set harga & metode pembayaran)
- ✅ Perpanjang membership member (catat harga & metode)
- ✅ Catat absensi member
- ✅ Lihat daftar semua member
- ✅ Lihat riwayat absensi

- ✅ Lihat identitas member
- ✅ Lihat status membership & masa aktif
- ✅ Perpanjang membership sendiri (pilih harga & metode bayar)
- ✅ Lihat riwayat absensi
- ✅ Lihat riwayat perpanjangan

## Teknologi
- HTML5
- Tailwind CSS (via CDN)
- PHP Native
- MySQL (XAMPP)
- Font Awesome Icons

## Instalasi

### 1. Persiapan Database
1. Jalankan XAMPP (Apache & MySQL)
2. Buka phpMyAdmin (http://localhost/phpmyadmin)
3. Import file `database.sql` atau jalankan query di dalamnya
4. Database `gym_management` akan otomatis terbuat beserta data sample

### 2. Konfigurasi
1. Copy folder `gym` ke folder `htdocs` XAMPP
2. Pastikan path-nya: `C:\xampp\htdocs\gym\`
3. Edit file `config/database.php` jika perlu (default sudah sesuai XAMPP)

### 3. Akses Website
1. Buka browser
2. Akses: `http://localhost/gym/`
3. Anda akan otomatis diarahkan ke halaman login

## Login Credentials

### Admin
- Username: `admin`
- Password: `admin123`

### Member/User (Sample)
- Username: `user1` | Password: `user123`
- Username: `user2` | Password: `user123`
- Username: `user3` | Password: `user123`

## Struktur Folder
```
gym/
├── config/
│   ├── database.php      # Konfigurasi database
│   └── session.php       # Manajemen session & autentikasi
├── admin/
│   ├── dashboard.php     # Dashboard admin (lengkap dengan semua fitur)
│   └── index.php         # Alternative dashboard admin
├── member/
│   └── dashboard.php     # Dashboard member (single page)
├── database.sql          # File SQL untuk setup database
├── login.php             # Halaman login
├── logout.php            # Logout handler
├── index.php             # Redirect ke login
└── README.md             # Dokumentasi ini
```

## Database Tables

### users
Menyimpan data user (admin & member)

### memberships
Menyimpan data membership member

### attendance
Menyimpan riwayat absensi

### membership_history
Menyimpan riwayat perpanjangan membership

## Fitur Responsive
- ✅ Mobile friendly
- ✅ Tablet optimized
- ✅ Desktop ready
- ✅ Menggunakan Tailwind CSS grid system

## Catatan
- Password di-hash menggunakan MD5
- Session management untuk keamanan
- Automatic redirect berdasarkan role
- Validasi form di sisi server
- UI modern dengan gradien dan backdrop blur
- Animasi hover dan transition yang smooth

## Support
Jika ada pertanyaan atau masalah, silakan hubungi developer.

---
© 2025 AturGym Management System
# membership-gym-pro
