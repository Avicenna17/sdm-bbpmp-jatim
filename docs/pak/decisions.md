# Keputusan teknis Phase 01

Plan approved tetap menjadi acuan. Tidak ada business rule PENDING yang diubah menjadi keputusan final.

1. Koreksi Peta Jabatan diterapkan langsung pada database-pak.md dan klarifikasi spesifikasi terkait: published version berasal dari position_datasets/import_batches, independen dari bulan reporting_periods DUK.
2. UI histori menggunakan action modal pada EmployeeResource + viewer read-only, bukan relation-manager terpisah. Service/authorization/retensi identik dengan plan; tidak ada CRUD Person global.
3. Master identity (code/category/pasangan mapping) tidak diubah lewat edit, termasuk sebelum terpakai; buat master/mapping baru. Label dan is_active tetap dapat diedit. Ini menghindari repurposing ID dan menjaga label snapshot.
4. Tanggal kontrak case/histori/rule/organisasi diterima dalam format ISO YYYY-MM-DD. whereDate digunakan untuk rentang inklusif agar hasil konsisten pada DATE MySQL dan serialisasi date Eloquent di SQLite.
5. Bukti organisasi tanpa akhir hanya dianggap point-in-time. Operator dapat memasukkan rentang nyata dari bukti, bukan tanggal perkiraan; data tanpa bukti tetap null.
6. Retensi actor menggunakan RESTRICT + deaktivasi akun. Authorization Person terisolasi pada PakEmployeeAuthorization/resource/service, tanpa global PersonPolicy.
7. Factory Person disediakan; fixture PAK menggunakan helper sintetis PakTestCase dan service domain agar test tidak melewati validasi/audit.
8. No database ENUM/CHECK daftar status. Rule DRAFT nullable validity; tujuan case memakai purpose_type, sesuai tiga koreksi final.
9. Database/schema aplikasi tidak dimigrasikan saat implementasi. Fresh/upgrade/constraint diuji di SQLite in-memory; MySQL concurrency dan UAT dicatat terpisah sebagai verifikasi penerapan.
