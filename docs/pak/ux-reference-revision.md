# Phase 01 — Revisi tiga area UI sesuai referensi

Perubahan terbatas pada Modul Aplikasi, Beranda PAK dan Detail Riwayat Aktivitas. Tidak commit/push/deploy; tidak menjalankan migrate/seed terhadap database aplikasi.

## Hasil

- Portal: panel utama dengan identitas BBPMP Jawa Timur / Portal Kepegawaian, Selamat Datang dan deskripsi sesuai permintaan, dua card berizin dengan icon/CTA Buka Modul ↗, footer institusi. Menu akun existing dipindahkan ke header portal; tidak membuat notification palsu. Seluruh card satu tautan, keyboard-accessible, responsive dan dark-mode styled.
- Beranda: subjudul sesuai permintaan, ringkasan dua kolom (satu kolom mobile), icon, keterangan ringkas serta panel Persiapan Data PAK. Query dan gate metrik unchanged; Perlu Pemeriksaan/PAK Ditetapkan tetap —. Deskripsi Data Pegawai tetap menegaskan jumlah bukan ukuran verifikasi/kelengkapan.
- Detail: judul Detail Aktivitas dan tombol Tutup; metadata Waktu Aktivitas/Pengguna/Jenis Aktivitas/Objek Data; rincian sebelum/sesudah, Alasan Perubahan dan narasi. Status akun dari payload menjadi Aktif/Tidak Aktif; tidak mengambil status akun terkini sebagai histori. Data hilang tidak diasumsikan: Tidak tercatat; null: —. Payload nested diuraikan menjadi field; field unchanged tidak ditampilkan sebagai perubahan. JSON tersedia hanya dalam Lihat data teknis, tertutup default dan tetap escaped.
- Formatter ActivityDetailPresenter hanya presentation; tidak menambah atau mengubah event/service/policy. Copy portal yang menyebut konversi/akumulasi/penetapan tidak mengimplementasikan fitur tersebut.

## File modified pada revisi ini

- app/Filament/Pages/ModuleSelector.php
- app/Filament/Pak/Pages/Dashboard.php
- app/Filament/Pak/Resources/PakEventResource.php
- docs/pak/internal-workspaces.md
- docs/pak/test-cases.md
- resources/views/filament/internal/modules.blade.php
- resources/views/filament/pak/audit-detail.blade.php
- resources/views/filament/pak/dashboard.blade.php
- tests/Feature/Pak/PresentationPolishTest.php

## File added

- app/Filament/Pak/Support/ActivityDetailPresenter.php
- tests/Feature/Pak/ActivityDetailPresentationTest.php
- docs/pak/ux-reference-revision.md (laporan ini)

Inventaris relatif terhadap awal revisi ini, bukan seluruh uncommitted polish sebelumnya. Tidak ada file removed.

## Verifikasi

| Suite | Passed | Skipped | Failed/error | Assertions |
| --- | ---: | ---: | ---: | ---: |
| Hasil terakhir sebelum revisi | 131 | 3 | 0 | 1.125 |
| Targeted UI/workspace | 25 | 0 | 0 | 365 |
| Seluruh Phase 01 | 62 | 0 | 0 | 592 |
| Existing SDM | 72 | 3 | 0 | 584 |
| Full PHPUnit final | **134** | **3** | **0** | **1.176** |

Full final: 137 total, 02:38.390, peak memory 334 MB. Dibanding hasil terakhir: +3 passed dan +51 assertions; skip unchanged. Semua failure awal akibat compiled view lama sudah terselesaikan pada rerun, tidak ada final failure/error.

Commands: targeted PresentationPolishTest + ActivityDetailPresentationTest + InternalWorkspaceTest; php vendor/bin/phpunit tests/Feature/Pak --do-not-cache-result; php vendor/bin/phpunit --do-not-cache-result --display-skipped. Bukti JUnit: storage/logs/ux-reference-targeted.xml, ux-reference-pak.xml dan ux-reference-final.xml.

114 file protected dibandingkan SHA-256 awal revisi dan unchanged: database/migration/seeder, domain service, model, policy, middleware, route/config/bootstrap dan module resolver. Pint --test keenam file PHP yang berubah/ditambah pada revisi ini lulus; git diff --check lulus (hanya notices line-ending).

Test menggunakan SQLite :memory: terisolasi. Cache Blade lama sempat menyebabkan assertion membaca tampilan sebelumnya; php artisan view:clear dijalankan lalu test diulang. Cache view boleh terbentuk ulang, tanpa perubahan data aplikasi.

Skip existing tetap:
- ActualDukWorkbookTest::test_september_workbook_preview_and_commit_in_isolated_database — workbook SDM_DUK_WORKBOOK belum tersedia.
- ImportTemplateTest::test_user_testing_workbooks_when_available — workbook lokal opsional belum tersedia.
- PositionDocumentationTest::test_actual_position_workbook_retains_all_24_columns — workbook SDM_POSITION_WORKBOOK belum tersedia.

## Manual UAT

Implementasi mengikuti gambar referensi; belum dilakukan visual browser UAT atau klaim pixel-identical. Periksa portal desktop/mobile, dark/light, card click/focus, menu akun/logout; dashboard 2×2 dan placeholder; modal deactivate/reactivate, alasan panjang, scroll nested changes, disclosure teknis dan tombol Tutup. Semua business rule PENDING tetap tidak diputuskan. Tidak ada Phase 02/KGB/portal pegawai.
