> **Historical / superseded (internal panel only):** Desain dua panel /admin + /pak, PakPanelProvider, dan permission Super Admin sebelum corrective workspace di dokumen ini tidak lagi berlaku. Kontrak aktif: [Internal Module Workspace Architecture](../internal-workspaces.md). Hasil test lama tetap dipertahankan sebagai histori; schema/domain dan pending business rules tidak berubah.

# PLAN Phase 01 — PAK Foundation
**Status: approved for implementation, terbatas pada Phase 01.**

Tiga penyesuaian terakhir sudah dimasukkan: akses referensi DUK published yang terbatas, tanggal awal rule set draft nullable, serta penggantian `case_type` menjadi `purpose_type`. Keputusan dan batas scope lainnya tetap dipertahankan.

## 1. Current Repository Assessment

Pemeriksaan dilakukan terhadap migration, model, domain service/query/import, Filament, provider, route, permission, test, dokumentasi SDM lama, dan spesifikasi PAK.

Tidak ada kode, migration, konfigurasi, permission, atau database yang diubah. `PLAN.md` tidak ditemukan pada lokasi yang diperiksa; dokumen ini menjadi plan final pengganti dalam percakapan.

Schema yang dinilai berasal dari migration repository, bukan verifikasi database deployment. Test belum dijalankan karena melakukan migration/seeding dan penulisan file pengujian.

| Area | Kondisi aktual |
|---|---|
| Stack | Laravel 12.69.2, Filament 3.3.55, Spatie Permission 6.25.0, PhpSpreadsheet 5.10.0; requirement PHP `^8.2`. |
| Identity | `people.id` sebagai identitas canonical; NIP nullable unik dan `person_key` unik. |
| DUK | Snapshot bulanan terkait person, reporting period, dan import batch. |
| Reimport | Snapshot periode diganti dalam transaksi; ID snapshot tidak stabil. |
| Identity fallback | Berdasarkan NIP atau normalized name; rekonsiliasi perubahan identitas belum tersedia. |
| Histori | Belum ada histori verified SK/TMT, JF, dan jenjang. |
| Peta Jabatan | Publication/version independen dari periode DUK melalui `position_datasets` dan import batch. |
| Publik | `/` dan `/dashboard`, controller/Blade/query agregat. |
| Admin | Panel `/admin`, guard `web`; akses panel hanya memeriksa `period.view`. |
| Role | Super Admin, Admin SDM, Internal Viewer; permission seeder memakai `syncPermissions()`. |
| User | Belum berelasi dengan Person; UI melarang delete, tetapi belum ada deaktivasi akun. |
| Struktur | Model terpusat; domain service/query terpisah. |
| Storage/test | Private local storage; PHPUnit/Livewire, SQLite in-memory, fake storage, workbook opsional. |

### Finding / Conflict / Decision

- **Peta Jabatan:** koreksi langsung spesifikasi teknis PAK yang masih menganggapnya mengikuti bulan DUK. Decision log hanya pelengkap.
- **Dokumentasi SDM lama:** historical context; tidak mengalahkan source code/migration.
- **Snapshot DUK:** referensi kondisi bulanan, bukan bukti TMT atau histori keputusan.
- **Discovery Filament:** resource PAK harus berada di namespace/folder terpisah dari discovery admin.
- **Draft parsial:** rule FK dan atribut snapshot yang belum diketahui boleh null.
- **Audit:** perlu mencakup perubahan sebelum case dibuat.
- **Seeder:** izin PAK eksplisit tidak boleh terhapus ketika baseline SDM di-seed ulang.
- **Organisasi:** current/default profile tidak dianggap sumber historis.
- **Authorization Person:** tidak membuat global PAK-only `PersonPolicy`.
- **Retensi actor:** tambahkan deaktivasi akun agar FK `RESTRICT` memiliki mekanisme operasional.
- **Akses referensi DUK:** izin PAK tersendiri, bukan pemberian akses administratif SDM.

## 2. Reusable Existing Components

- `Person`, `User`, guard `web`, dan Spatie Permission.
- `PersonnelSnapshot`/`ReportingPeriod` sebagai referensi DUK.
- `PositionDataset` dan published batch/version untuk integrasi berikutnya.
- Pola authorization, validation exception, transaksi, locking, dan preview existing.
- Pola versioning template sebagai referensi desain; tabelnya tidak dipakai untuk rule PAK.
- Form/table/login Filament.
- Private storage untuk fase dokumen berikutnya.
- PHPUnit/Livewire dan regression suite existing.

Importer tidak diperluas menjadi importer histori pada Phase 01.

## 3. Gaps for PAK Foundation

Fondasi baru mencakup:

1. Pemetaan manual User–Person.
2. Profil tambahan/current metadata.
3. Kandidat DUK yang terpisah dari histori verified.
4. Histori pangkat/golongan dan jabatan utama/definitif.
5. Master kategori, jenjang, JF, dan mapping.
6. Metadata versioned rule set.
7. Draft case, snapshot, dan audit.
8. Panel PAK, permission, serta authorization terisolasi.
9. Query referensi DUK published dengan whitelist.
10. Deaktivasi/retensi akun dan pengujian regresi.

**Tidak termasuk:** SKP, tabel angka aturan, engine, konversi, AK Integrasi, ledger, akumulasi, penetapan, eligibility, KP/KJ, Ukom, balance cycle, portal, pengajuan, warning engine, dan dokumen.

## 4. Proposed Phase 01 Architecture

### Pemisahan data

| Lapisan | Makna |
|---|---|
| Kandidat/referensi DUK | Boleh incomplete; prefill/pembanding, bukan histori verified. |
| Verified history | TMT nyata, sumber, actor, waktu verifikasi; dasar pangkat/jabatan pada tanggal acuan. |
| Case snapshot | Salinan data/provenance untuk sebuah draft; tidak berubah otomatis saat sumber berubah. |

Tidak membuat tabel kandidat tersendiri. Kandidat form tidak disimpan sebagai row histori. Referensi kandidat yang disimpan pada snapshot harus dilabeli belum terverifikasi dan tidak mengisi field histori canonical secara diam-diam.

### Pembagian tanggung jawab

- Filament: input, pembanding, preview, pemanggilan service.
- Domain service: authorization, validasi, transaksi, locking, audit.
- Query: referensi published, histori per tanggal, ownership.
- Model: relationship/casts.
- Database: FK, uniqueness, index, retensi.

### Kontrak referensi DUK PAK

`EmployeeReferenceQuery` menjadi satu-satunya jalur referensi DUK untuk UI/service PAK.

Prasyarat:

- Actor aktif.
- Memiliki `pak.access` dan `pak.employee.view`.
- Tidak memerlukan atau memberikan `period.view` maupun `personnel.view`.

Batas query:

- Hanya snapshot dengan reporting period berstatus `published`.
- Dapat membaca arsip published yang dipilih eksplisit.
- Pemilihan referensi case tidak boleh melewati tanggal acuan data pegawai.
- Validasi person, periode, dan snapshot dilakukan di server.
- Referensi yang hilang karena reimport ditolak dan harus dimuat ulang.
- Pencarian dibatasi, dipaginasi, dan tidak menyediakan bulk download.

Whitelist data:

| Kelompok | Field |
|---|---|
| Referensi internal | Snapshot ID, person ID, reporting period ID, import batch ID |
| Provenance | Bulan periode, waktu publikasi, revision periode |
| Identitas | `name_at_period`, `nip_at_period` |
| Kandidat kepegawaian | `rank_name`, `grade_code`, `position_name`, `gender`, `placement_current` |

Gunakan explicit select dan DTO/array whitelist, bukan serialisasi model lengkap. `placement_current` ditampilkan sebagai penempatan sumber, bukan unit organisasi historis otomatis.

Tidak mengembalikan `raw_payload`, `extra_data`, keterangan NIP, metadata/file import, uploader, atau atribut lain di luar whitelist. Penambahan field kemudian memerlukan kebutuhan eksplisit dan test.

Akses ini **read-only**: tidak memberikan import, publish, edit DUK, export SDM, akses `/admin`, atau download file sumber.

### Kontrak case

- Input tujuan pemeriksaan menggunakan **`purpose_type`**.
- Nilai Phase 01: `ROUTINE`, `KP`, `KJ`, `KP_KJ`.
- Tujuan pemeriksaan berbeda dari mekanisme appointment/transfer dan hasil eligibility.
- Tidak membuat field `case_type`, alias, atau fallback dengan arti lama.

## 5. Database Changes

### Tipe extensible

`source_type`, `purpose_type`, status case/rule, jenis jabatan, sumber histori, dan event/subject type memakai **VARCHAR + PHP enum/application validation**.

Tidak memakai database ENUM atau CHECK yang mengunci daftar nilai Phase 01.

### Migration

| Urutan | Suffix |
|---|---|
| 01 | `add_person_id_to_users` |
| 02 | `add_is_active_to_users` |
| 03 | `create_employee_profiles` |
| 04 | `create_functional_categories_and_levels` |
| 05 | `create_functional_positions_and_position_levels` |
| 06 | `create_employee_rank_histories` |
| 07 | `create_employee_position_histories` |
| 08 | `create_pak_rule_sets` |
| 09 | `create_pak_cases` |
| 10 | `create_pak_case_employee_snapshots` |
| 11 | `create_pak_events` |

Total: 11 tabel baru dan dua perluasan additive pada `users`. Tidak ada backfill otomatis identity, histori, profil, atau assignment role.

### User

- `person_id` nullable unique, FK ke people, `RESTRICT`.
- `is_active` boolean default true.
- Akun existing tetap aktif dan belum terpetakan.
- Deaktivasi mempertahankan relasi, permission assignment, serta audit.

### Employee Profile

- Person unique.
- Karpeg, tempat/tanggal lahir, gender.
- `institution_name`, `work_unit_name` sebagai **current/default metadata**.
- Extra data nonkritis, source reference, verifikasi, actor, timestamps.

Current/default organisasi boleh menjadi saran, tetapi tidak menjadi fallback historis case. Nama/NIP tetap identity existing; pangkat/jabatan canonical berasal dari histori.

### Master JF

- `functional_categories`: code unique, name, urutan tampilan, active flag.
- `functional_levels`: FK kategori, code unique, name, urutan, active flag.
- `functional_positions`: code nullable unique, name, active flag.
- `functional_position_levels`: pasangan FK unique dan active flag.

Seed kategori Keahlian/Keterampilan dan delapan jenjang spesifikasi. Urutan tidak menyatakan aturan kenaikan. Daftar JF/mapping tidak ditebak dari DUK.

### Histori pangkat

- Person, `rank_name`, `grade_code`.
- `tmt_start` wajib; `tmt_end` nullable.
- SK number/date nullable.
- Source/reference, verified actor/time, creator.
- Supersedes reference/time, timestamps.
- Index person/TMT awal.

Setiap row merupakan histori verified. Tanpa TMT nyata, data tetap kandidat.

### Histori jabatan

- Person, nama/jenis jabatan.
- FK JF/jenjang nullable.
- TMT, SK, sumber, verifikasi, koreksi, actor, timestamps.

Hanya **jabatan utama/definitif yang relevan sebagai dasar PAK**. JF membutuhkan mapping valid; non-JF tidak diberi jenjang otomatis. Plt/Plh/rangkap/simultan belum dimodelkan.

### Temporal histori

- Rentang inklusif; akhir tidak sebelum awal.
- Non-overlap per person untuk histori pangkat dan, secara terpisah, jabatan utama.
- Row superseded tidak menentukan keadaan efektif.
- Lock person sebelum validasi/write.
- Tidak otomatis menutup histori menggunakan tanggal perkiraan.
- Koreksi mempertahankan versi lama dan mencatat alasan.
- Tidak menjalankan workflow SK/TMT atau saldo AK.

### `pak_rule_sets`

Field:

- Code, name, version.
- **`valid_from` nullable, `valid_until` nullable.**
- Reference, status, actor, timestamps.
- Unique `(code, version)`; index code/tanggal awal.

Perilaku:

- Status Phase 01 `DRAFT`.
- Tanggal berlaku boleh keduanya kosong atau baru salah satunya diketahui.
- Jika keduanya terisi: **`valid_until >= valid_from`**.
- Tidak menebak tanggal yang belum terverifikasi.
- Tidak membuat resolver, larangan overlap, atau aktivasi/publikasi.
- Kewajiban tanggal awal pada rule aktif/published ditentukan fase berikutnya.
- Versi terpakai case immutable; revisi melalui versi baru.
- Tidak seed angka/tanggal regulasi yang diasumsikan.

### `pak_cases`

- Person wajib.
- Case number nullable, tanpa penomoran otomatis.
- Source `ADMIN_MANUAL`.
- **`purpose_type`: `ROUTINE`, `KP`, `KJ`, `KP_KJ`.**
- Period start/end, employee data as-of.
- Status `DRAFT`.
- Rule set FK nullable.
- Actor, notes, timestamps, lock version.
- Finalized actor/time nullable dan tidak dapat diisi melalui fungsi Phase 01.
- Index person/status dan status/akhir periode.

Person tidak dapat diganti setelah create. Tidak ada batas universal 36 bulan atau transisi ready/final. Tidak ada appointment/transfer/submission.

### Snapshot case

Satu row per case, memuat:

- Identitas dan atribut profil.
- Pangkat/golongan/TMT.
- Jabatan utama/jenis/TMT.
- ID serta salinan kode/nama JF, kategori, jenjang.
- Instansi/unit historis.
- Captured actor/time, schema version.
- Payload provenance, konteks organisasi bertanggal, dan kekurangan.
- Timestamps.

Instansi/unit historis berasal dari sumber bertanggal relevan yang diverifikasi, dengan reference dan tanggal/rentang berlaku. Jika belum tersedia, nilainya null. Tidak menganggap current profile atau penempatan DUK otomatis sebagai sumber historis organisasi.

Tidak ada FK wajib ke snapshot DUK. Nilai sumber disalin; ID DUK hanya provenance.

### Audit

- Case nullable, subject type/ID, event code.
- Actor, whitelist payload, occurred/created time.
- Append-only tanpa updated time.
- Index case/waktu dan subjek/waktu.

Mencakup akun, profil, histori, master, rule, case, dan refresh. Actor FK `RESTRICT`; tidak menyimpan raw workbook/dokumen lengkap pada payload.

### FK dan rollback

- Person/actor/master/rule/case terpakai dipertahankan melalui `RESTRICT`.
- Tidak ada cascade dari DUK ke PAK.
- Tidak mengubah migration lama.
- Rollback migration hanya untuk DB kosong/terisolasi; database berisi memakai restore atau forward fix.

## 6. Model & Relationship Changes

```text
User belongsTo Person
Person hasOne User dan EmployeeProfile
Person hasMany RankHistory, PositionHistory, PakCase

FunctionalCategory hasMany FunctionalLevel
FunctionalPosition belongsToMany FunctionalLevel
FunctionalPositionLevel belongsTo JF dan Level

PakRuleSet hasMany PakCase
PakCase belongsTo Person dan PakRuleSet
PakCase hasOne EmployeeSnapshot
PakCase hasMany PakEvent
```

Histori memiliki self-reference koreksi; actor memakai FK eksplisit.

### Account mapping

Action khusus berizin `user.manage`, alasan wajib, locking, uniqueness, audit. Bind/unbind/relink tidak memindahkan kepemilikan case/histori. `person_id` tidak dibuka pada mass assignment umum.

### Snapshot builder

1. Identitas dari DUK published terpilih; fallback Person diberi provenance canonical-current, bukan klaim historis verified.
2. Atribut tambahan dari profil verified.
3. Pangkat/jabatan dari verified history sesuai tanggal acuan.
4. Organisasi dari sumber bertanggal relevan.
5. Data belum tersedia tetap null.

Case/snapshot/event dibuat atomik. Referensi DUK divalidasi ulang dengan locking periode saat capture. Refresh eksplisit memakai preview, alasan, dan lock version.

### Retensi akun

- Nonaktif menolak login dan akses request terlindungi berikutnya.
- Berlaku pada kedua panel, Livewire, export, dan template.
- Rotate remember token; jangan mengandalkan penghapusan session row saja.
- Pertahankan user/person link/roles/audit.
- Reaktivasi eksplisit; seeder tidak mengaktifkan kembali.
- Larang self-deactivate dan deaktivasi admin aktif terakhir yang dapat mengakses serta mengelola user/role.
- Actor service harus aktif.
- Tidak hard-delete akun yang menjadi actor histori/audit.

## 7. Filament Multi-Panel Plan

- Tambah `PakPanelProvider`, ID/path `pak`, guard `web`.
- Admin tetap default.
- Discovery hanya namespace PAK.
- Session/CSRF/auth dan pemeriksaan akun aktif diterapkan, termasuk request Livewire.

Menu foundation:

- Beranda.
- Data Pegawai PAK beserta referensi DUK read-only dan histori.
- Master kategori/jenjang/JF/mapping.
- Metadata rule set.
- Draft case.
- Audit read-only.

### Authorization Person

Tidak membuat global PAK-only `PersonPolicy`.

`PakEmployeeAuthorization` digunakan oleh resource/service PAK; query, lookup, dropdown, dan relation action ikut dibatasi. Policy didaftarkan hanya untuk model PAK baru.

### Batas akses

| Permukaan | Ketentuan |
|---|---|
| Publik | Agregat SDM saja. |
| `/admin` | Active + `period.view`, aksi sesuai izin lama. |
| `/pak` | Active + `pak.access`, aksi sesuai permission PAK. |
| Referensi DUK di `/pak` | Tambahan `pak.employee.view`, published-only, whitelist, read-only. |
| `/pegawai` | Belum dibuat. |

Akses referensi DUK tidak memberikan route/resource/admin permission SDM. Guest mendapat login panel yang tepat; panel asing ditolak.

## 8. Roles & Permissions Plan

Permission:

- `pak.access`
- `pak.employee.view`, `pak.employee.manage`
- `pak.master.view`, `pak.master.manage`
- `pak.rule-set.view`, `pak.rule-set.manage`
- `pak.case.view`, `pak.case.create`, `pak.case.update`
- `pak.audit.view`

**`pak.employee.view` secara eksplisit mencakup pembacaan terbatas DUK published melalui query PAK khusus.** Tidak mencakup export, import, publish, atau perubahan SDM.

Admin PAK mendapat permission foundation tanpa izin SDM otomatis. Pegawai disiapkan tanpa akses administratif. Super Admin dan role lama tidak mendapat PAK otomatis.

Query ownership disiapkan dan diuji tanpa portal.

Seeder idempotent, mempertahankan izin `pak.*` eksplisit dan status inactive; tidak mengembalikan izin yang dicabut atau memberi role ke akun existing. Cache permission direset.

## 9. Domain / Folder Structure

Di bawah root `C:/laragon/www/sdm-bbpmp-jatim/`:

```text
app/Domain/Pak/{Authorization,DTOs,Enums,Queries,Services}
app/Domain/Users
app/Models
app/Policies/Pak
app/Http/Middleware
app/Filament/Pak/{Pages,Resources}
app/Providers/Filament
database/{migrations,seeders,factories}
resources/views/filament/pak
tests/Feature/Pak
docs/pak
```

Tidak membuat calculator/placeholder engine. Tidak memindahkan komponen existing.

## 10. Files to Add

Dalam root repository tersebut:

- Model untuk sebelas tabel foundation.
- `PakEmployeeAuthorization`.
- Service mapping akun, profil, histori, master, rule metadata, case, snapshot, audit.
- `EmployeeReferenceQuery`, `EmployeeHistoryQuery`, `PakCaseQuery`.
- DTO `DukReferenceData`, `CreatePakCaseData`, `EmployeeSnapshotData`, `HistoricalOrganizationContext`.
- Enum aplikasi termasuk **`PakPurposeType`**; tidak membuat `PakCaseType`.
- `AccountStatusService`, `EnsureUserIsActive`.
- Policy model baru PAK, tanpa PersonPolicy.
- PakPanelProvider, dashboard, resource/page/relation manager foundation.
- View pembanding/snapshot yang diperlukan.
- Sebelas migration, dua seeder foundation, factory dan test.
- Dokumentasi teknis foundation dan decision log.

### Koreksi spesifikasi

- `database-pak.md`: publication/version Peta Jabatan independen; entitas dataset/batch; nullable validity draft; `purpose_type`.
- `prd-pak.md`: referensi Peta Jabatan published independen dan batas referensi DUK PAK.
- `business-rules.md`: klarifikasi teknis sumber published tanpa mengubah rule PENDING.
- `workflow-pak.md`: provenance published batch/version; tujuan pemeriksaan dibedakan dari mekanisme/hasil.
- `test-cases.md`: isolasi sumber published, whitelist DUK, nullable validity, dan terminology purpose.

Seluruh referensi `case_type` dengan makna tujuan pemeriksaan diganti secara konsisten pada schema, model, DTO, enum, form, query, fixture, test, dan dokumentasi. Belum ada data existing PAK yang perlu dimigrasikan/di-rename.

Dokumentasi SDM lama tetap historical context.

## 11. Existing Files to Modify

- [User.php](C:/laragon/www/sdm-bbpmp-jatim/app/Models/User.php): person relation, active cast/check, panel-specific access.
- [Person.php](C:/laragon/www/sdm-bbpmp-jatim/app/Models/Person.php): relationship foundation.
- [bootstrap/providers.php](C:/laragon/www/sdm-bbpmp-jatim/bootstrap/providers.php): provider PAK.
- [AppServiceProvider.php](C:/laragon/www/sdm-bbpmp-jatim/app/Providers/AppServiceProvider.php): policy model PAK baru.
- [UserResource.php](C:/laragon/www/sdm-bbpmp-jatim/app/Filament/Resources/UserResource.php): mapping dan deactivate/reactivate.
- [Login.php](C:/laragon/www/sdm-bbpmp-jatim/app/Filament/Auth/Login.php): active credential check.
- [AdminPanelProvider.php](C:/laragon/www/sdm-bbpmp-jatim/app/Providers/Filament/AdminPanelProvider.php): persistent active-account middleware.
- [routes/web.php](C:/laragon/www/sdm-bbpmp-jatim/routes/web.php): active middleware pada authenticated export/template.
- [DatabaseSeeder.php](C:/laragon/www/sdm-bbpmp-jatim/database/seeders/DatabaseSeeder.php): seeder foundation dan preservasi izin eksplisit.
- [README.md](C:/laragon/www/sdm-bbpmp-jatim/README.md): setup, retensi, assignment, dan verifikasi.

Importer, publisher, dashboard query, template import, serta migration existing tetap.

## 12. Tests to Add

### Foundation

- Mapping nullable/unique/FK/manual, concurrency, alasan dan audit.
- Deaktivasi login/sesi lama/remember/Livewire/export/template; reaktivasi; self/last-admin guard; retensi actor.
- Kandidat incomplete tidak menghasilkan histori verified tanpa TMT nyata.
- Profil/current metadata tidak mengubah DUK atau otomatis menjadi organisasi historis.
- Histori verified: tanggal, overlap, mapping JF, koreksi dan provenance.
- Batas jabatan utama; Plt/Plh/rangkap tidak dipaksakan.
- Master seed idempotent, mapping unique, deactivate dan historical references.
- Snapshot stability, refresh eksplisit, stale form, atomic failure.
- Ownership dan isolasi authorization Person.
- Seeder permission/status preservation.
- Fresh/upgrade migration, FK/unique, concurrency MySQL.
- Public/export regression dan konsistensi dokumentasi.

### Tambahan khusus tiga penyesuaian

**Referensi DUK**

- Admin PAK tanpa `period.view` dapat membaca whitelist referensi published.
- Tanpa `pak.employee.view`, query/service menolak.
- Draft/ready period ditolak meski ID dipaksakan.
- Snapshot person/periode tidak cocok ditolak.
- Raw payload, extra data, file import, dan field di luar whitelist tidak keluar.
- Tidak memperoleh akses `/admin`, import, publish, edit, atau export SDM.
- Referensi periode published arsip tetap dapat dipilih sesuai tanggal acuan.
- Reimport yang mengganti snapshot memerlukan pemuatan ulang.

**Rule metadata**

- `valid_from=null`, `valid_until=null` diterima untuk DRAFT.
- Salah satu tanggal saja terisi diterima.
- Kedua tanggal sama diterima.
- Akhir sebelum awal ditolak.
- Overlap antarversi diperbolehkan.
- Tidak ada resolver/aktivasi.

**Purpose**

- Penyimpanan/form/DTO memakai `purpose_type`.
- Empat nilai Phase 01 diterima.
- Appointment/transfer dan hasil eligibility tidak diterima sebagai purpose.
- Tidak ada kolom/alias `case_type` dengan makna lama.

TC-PAK-020/021 berlaku untuk panel; TC-PAK-019 sebatas ownership; TC-PAK-022/023 sebatas snapshot/metadata foundation. Golden calculation dan TC-PAK-013 bukan klaim kelulusan Phase 01.

Jalankan seluruh regression existing; laporkan skip workbook opsional secara eksplisit.

## 13. Backward Compatibility Risks

| Risiko | Mitigasi |
|---|---|
| Admin existing ditolak | Default active true; admin tetap `period.view`. |
| Authorization Person berubah | Tidak ada global PAK PersonPolicy. |
| Referensi DUK menjadi akses SDM luas | Query khusus, whitelist, published-only, tanpa izin/route administratif. |
| Resource PAK bocor | Discovery terpisah. |
| Seeder mengubah izin/status | Preservasi eksplisit dan rerun test. |
| Sesi inactive masih berjalan | Middleware setiap request terlindungi dan service actor check. |
| Actor tidak dapat dihapus | Deaktivasi resmi, audit tetap utuh. |
| Reimport memutus PAK | Canonical person dan snapshot independen. |
| Identity fallback berubah | Tidak auto-merge/relink. |
| Unit current dianggap historis | Sumber bertanggal atau null. |
| TMT fiktif | Kandidat tidak menjadi histori verified. |
| Purpose disamakan dengan mechanism/result | Nama `purpose_type` dan validasi khusus. |
| Tanggal regulasi dipaksakan | Nullable selama DRAFT. |
| Status sulit diperluas | VARCHAR/application enum. |
| Migration/rollback merusak data | Additive, backup, staging, restore/forward fix. |

## 14. Pending Business Decisions

Tetap tidak diasumsikan:

- `BR-SKP-008`: koreksi provisional vs resmi.
- `BR-PERIOD-001/003`: definisi teknis tiga tahun.
- `BR-APPOINT-008`: pengangkatan/perpindahan.
- `BR-KJ-005`: reuse setelah gagal Ukom.
- `BR-BAL-003`: trigger SK/TMT.
- `BR-KONV-004`: rounding.
- `BR-BAL-004`: status proposed.
- Mapping canonical JF, angka dan pengesahan regulasi.
- Rule resolver lintas versi dan kewajiban tanggal untuk active/published.
- Koreksi final/dokumen, retention dokumen, akun pimpinan.
- Rekonsiliasi identity fallback.
- Jabatan simultan/Plt/Plh/rangkap.
- Validasi mapping contoh Nurmaulida/perpindahan.

Koreksi faktual versi Peta Jabatan tidak mengubah status business rule PENDING.

## 15. Implementation Order

1. Koreksi spesifikasi teknis dan decision log.
2. Ambil baseline test pada lingkungan terisolasi.
3. Migration additive dan upgrade fixture SDM.
4. Model, relationship, taxonomy.
5. Permission, authorization terisolasi, whitelist query DUK, perlindungan seeder.
6. Account mapping dan deaktivasi.
7. Profil/kandidat/histori verified.
8. Metadata rule set dengan tanggal nullable.
9. Draft `purpose_type`, organisasi historis, snapshot, transaksi/audit.
10. Panel dan CRUD foundation.
11. Test foundation, regression SDM, MySQL staging.
12. UAT, dokumentasi/laporan Phase 01; berhenti sebelum Phase 02.

## 16. Phase 01 Acceptance Criteria

- [ ] Spesifikasi PAK tidak lagi menganggap Peta Jabatan mengikuti bulan DUK.
- [ ] SDM existing dan public dashboard tetap berfungsi.
- [ ] Panel/permission PAK eksplisit dan terpisah.
- [ ] Referensi DUK published dapat dibaca dengan `pak.employee.view` melalui whitelist, tanpa izin administratif SDM.
- [ ] Tidak ada global PersonPolicy khusus PAK.
- [ ] User–Person nullable/unique/manual dan auditable.
- [ ] Deaktivasi menghentikan akses tanpa menghapus actor/histori.
- [ ] Kandidat tanpa TMT tidak menjadi histori verified.
- [ ] Histori jabatan hanya jabatan utama/definitif.
- [ ] Koreksi histori mempertahankan versi lama.
- [ ] Organisasi current/default tidak menjadi sumber historis otomatis.
- [ ] Master JF tersedia.
- [ ] Rule DRAFT menerima validity yang belum lengkap; pasangan tanggal tetap divalidasi.
- [ ] Tidak ada resolver, overlap prohibition, atau aktivasi rule.
- [ ] Case menggunakan `purpose_type`, terpisah dari mechanism dan eligibility.
- [ ] Draft incomplete dan rule nullable didukung.
- [ ] Snapshot stabil; refresh eksplisit dan auditable.
- [ ] Status extensible memakai VARCHAR/application validation.
- [ ] Test/regression lulus dengan skip/failure existing dilaporkan.
- [ ] Tidak ada fitur Phase 02 atau berikutnya.
- [ ] Semua PENDING tetap tidak diasumsikan.

## 17. Verification Checklist

### Sebelum penerapan

- [ ] Backup/restore database dan private storage.
- [ ] Cocokkan applied migrations dengan repository.
- [ ] Pastikan DB test/staging terisolasi.
- [ ] Catat row, permission, status akun, pointer publication/version.
- [ ] Review schema additive, string statuses, nullable validity, dan `purpose_type`.
- [ ] Review koreksi langsung dokumentasi.

### Otomatis

- [ ] Jalankan test PAK dan seluruh regression existing.
- [ ] Fresh/upgrade migration serta MySQL constraint/concurrency.
- [ ] Seeder rerun tanpa perubahan izin/status tak diminta.
- [ ] Panel discovery dan isolasi Person authorization.
- [ ] Whitelist/published-only DUK serta penolakan akses administratif SDM.
- [ ] Nullable validity dan validasi pasangan tanggal.
- [ ] Tidak ada penggunaan `case_type` untuk tujuan pemeriksaan.
- [ ] Inactive login/sesi/Livewire/export/template.
- [ ] Catat workbook test yang dilewati.

### Manual

- [ ] Admin SDM tidak otomatis mengakses PAK; Admin PAK tidak otomatis mengakses admin.
- [ ] Admin PAK dapat memilih referensi published tanpa permission SDM.
- [ ] Tidak tersedia edit/import/publish/export dari referensi DUK PAK.
- [ ] Mapping, deaktivasi, dan retensi audit bekerja.
- [ ] Prefill tanpa TMT tetap kandidat.
- [ ] Unit historis tanpa sumber tetap kosong.
- [ ] Rule draft dapat disimpan tanpa tanggal berlaku.
- [ ] Tujuan pemeriksaan tampil sebagai Routine/KP/KJ/KP_KJ, bukan mekanisme atau hasil.
- [ ] Reimport tidak mengubah snapshot case.
- [ ] Refresh membutuhkan tindakan eksplisit.
- [ ] Tidak ada kalkulasi, workflow KP/KJ, portal, atau dokumen.

**Persetujuan implementasi telah diberikan untuk plan ini. Pelaksanaan tetap terbatas pada Phase 01; belum dijalankan selama sesi berada dalam Plan Mode.**
