# PAK Foundation — Phase 01

Implementasi mengikuti plans/phase-01-foundation.md; migration adalah source of truth schema aktual. Dokumen database lintas fase bukan daftar fitur yang sudah tersedia.

## Batas domain dan data

- Identity canonical tetap people.id. users.person_id nullable, unique, FK RESTRICT, diubah hanya melalui action user.manage dengan alasan/audit. Bind/unbind tidak memindahkan case.
- Profil menyimpan data tambahan terverifikasi dan metadata instansi/unit terkini/default. Organisasi historis snapshot membutuhkan sumber bertanggal/rentang yang mencakup tanggal acuan. Bila akhir kosong, sumber dianggap pernyataan pada satu tanggal; tidak diekstrapolasi tanpa batas.
- Kandidat DUK published boleh parsial. Prefill hanya menyalin pangkat/golongan/nama jabatan; tidak menebak TMT, SK, JF, jenjang, maupun instansi/unit historis.
- Histori verified manual mensyaratkan TMT nyata dan referensi sumber. Pangkat dan jabatan utama masing-masing non-overlap inklusif. Koreksi membuat row baru dan supersedes row lama, tidak menghapusnya. Jabatan simultan/Plt/Plh/rangkap belum dimodelkan.
- Kategori/jenjang di-seed tanpa angka aturan; daftar JF dan pasangan JF–jenjang harus diverifikasi manual. Mapping nonaktif tidak dapat dipakai untuk histori baru. Pembuatan/aktivasi mapping mensyaratkan JF, jenjang, dan kategori parent aktif; mapping nonaktif tetap dapat dipertahankan. Nama master yang disalin ke snapshot tidak berubah otomatis.
- Rule set hanya metadata DRAFT, unique code/version, tanggal nullable. Jika keduanya diisi, akhir >= awal. Overlap versi diperbolehkan. Rule yang pernah direferensikan case immutable permanen melalui first_used_at, meskipun semua case berpindah ke versi lain. Marker ditetapkan atomik bersama transaksi case dan tidak dihapus; buat versi baru untuk revisi. Tidak ada resolver/aktivasi.
- Case selalu ADMIN_MANUAL/DRAFT, purpose ROUTINE/KP/KJ/KP_KJ. Purpose bukan mekanisme pengangkatan ataupun eligibility. Periode tidak dipaksa 36 bulan. Data parsial/rule null diperbolehkan. Person tidak dapat diganti.
- Snapshot memakai verified history per tanggal, profil verified, referensi published terpilih, dan sumber organisasi bertanggal. Identitas fallback Person dilabeli canonical-current, belum diverifikasi historis. Payload menyimpan provenance/missing fields; bukan warning engine.
- Case/snapshot/audit atomik. Snapshot tidak memiliki FK ke ID DUK yang dapat diganti saat reimport. Refresh eksplisit memerlukan alasan, preview, fingerprint sumber dan lock version. Audit refresh mencatat before/after case serta snapshot.
- Semua tipe/status extensible memakai VARCHAR dan validasi PHP. Tidak ada database ENUM/CHECK daftar nilai Phase 01.

## Panel dan akses

Satu internal panel /admin dan login /admin/login; workspace SDM memerlukan period.view, PAK memerlukan pak.access, selalu akun aktif. Resource/action/service tetap memerlukan permission granular. Route PAK /admin/pak/...; namespace App\Filament\Pak dipertahankan. Tidak ada global PersonPolicy PAK. Lihat [arsitektur workspace](internal-workspaces.md) untuk landing, intended URL, dan navigation scoping.

Permission foundation: pak.access, pak.employee.view/manage, pak.master.view/manage, pak.rule-set.view/manage, pak.case.view/create/update, pak.audit.view. Admin PAK memperoleh 11 permission saat role pertama dibuat; Pegawai kosong dan belum memiliki portal. Super Admin diberi seluruh permission web pak.* secara eksplisit dan idempotent. Tidak ada assignment akun otomatis atau bypass Super Admin. Pegawai bukan deny-rule jika user juga mempunyai permission internal.

EmployeeReferenceQuery adalah jalur DUK PAK: akun aktif + pak.access + pak.employee.view, published-only, person/periode/tanggal tervalidasi, 20 row per halaman. Field dibatasi DTO DukReferenceData; raw_payload, extra_data, file/path, dan kolom lain tidak dikembalikan. Akses ini tidak memberikan period.view/import/publish/edit/export SDM.

## Retensi akun dan audit

Deactivate/reactivate melalui AccountStatusService memerlukan user.manage/alasan. Self-deactivate dan admin pengelola aktif terakhir ditolak. Remember token dirotasi. Middleware aktif persisten pada panel internal/Livewire dan route download SDM memutus sesi nonaktif pada request berikutnya; login inactive ditolak. Relasi, role, serta audit tetap disimpan.

Actor FK memakai RESTRICT. Jangan hard-delete akun actor. PakEvent append-only melalui model/service dan UI read-only; akses SQL administrator tetap merupakan batas kepercayaan operasional (tidak ada trigger database). Jangan mengubah audit menggunakan bulk query langsung. Backup dan kontrol akses DB tetap diperlukan.

## Migration dan penerapan

11 migration additive menambah 11 tabel (dua migration masing-masing membuat dua tabel) serta person_id/is_active pada users. Tidak mengubah migration existing, importer, publisher, Peta Jabatan maupun query dashboard publik.

Rollback down tersedia hanya untuk database uji/kosong. Untuk database berisi histori gunakan backup/restore terencana atau forward fix. Tidak menjalankan migrate:fresh/rollback di database aplikasi.

Sebelum penerapan: backup dan uji restore, verifikasi pending migrations dan data SDM, jalankan suite, uji MySQL 8 staging termasuk concurrent mapping/history/rule use/refresh. Setelah migrasi/seeding di staging, assign role eksplisit dan lakukan checklist manual pada laporan implementasi. Tidak ada deployment/commit/push dalam pekerjaan ini.

Koreksi review Phase 01: create/refresh/preview case yang memberikan pak_rule_set_id wajib memiliki pak.rule-set.view di service, termasuk ketika ID dikirim manual. Lihat [hasil corrective review](corrective-review-phase-01.md).
