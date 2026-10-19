# Final Plan Phase 02 — SKP & Conversion

**Status: Final Plan Phase 02 telah disetujui dan disimpan sebagai dokumentasi repository pada branch `feature/pak-phase-02`; implementasi belum dimulai.** Checkpoint dokumentasi ini tidak mengubah source aplikasi, migration, dependency implementasi, atau deployment.

## 1. Baseline, tujuan, dan boundary

### Baseline yang diterima

Baseline Phase 01 pada commit **252661a** telah berhasil diuji di environment lokal:

**137 tests, 1176 assertions, 3 skipped, 0 failed.**

Kegagalan membaca `vendor/autoload.php` hanya terjadi pada environment Plan Mode/audit. Itu merupakan keterbatasan environment audit, **bukan kegagalan aplikasi atau baseline**.

Seluruh checkpoint Phase 02 menggunakan branch `feature/pak-phase-02`.

### Tujuan Phase 02

Tim SDM dapat:

- Mencatat SKP official dan provisional beserta bukti dan revisinya.
- Memilih sumber dan satu rule set secara eksplisit.
- Menghasilkan segment konversi yang tidak overlap.
- Menghitung AK Konversi secara eksak di server.
- Menyimpan preview, provenance, calculation trace, dan issue review.
- Membandingkan run lama dengan run pengganti tanpa kehilangan histori.

**Boundary: hasil AK Konversi baru.** Penjumlahan detail dalam satu conversion run bukan Akumulasi AK lama dan baru.

### Out of scope

Akumulasi, AK Integrasi/Dasar, ledger saldo, Penetapan PAK, KP/KJ, Ukom, perubahan administratif SK/TMT, KGB, portal pegawai, pengajuan, general warning engine, generator dokumen PAK, TTE, penomoran, dan integrasi BKN.

Bukti SKP private termasuk scope. Dokumen PAK keluaran tidak termasuk.

Phase 02 tidak memperkenalkan status `FINAL`, `COMPLETED`, `APPROVED`, atau `PAK_ESTABLISHED` pada conversion run. Tidak mengisi `pak_cases.finalized_at/by` atau mengubah case menjadi hasil penetapan.

## 2. Arsitektur dan kontrak domain

### Foundation yang digunakan kembali

- Satu panel internal `/admin`, workspace PAK `/admin/pak`.
- `people.id` sebagai identitas canonical.
- Histori jabatan verified, master JF, dan mapping jenjang.
- Snapshot pegawai pada case.
- `PakRuleSet` dan penguncian permanen `first_used_at`.
- Authorization granular, registry workspace, akun aktif, dan ownership query.
- Transaksi, row lock, `lock_version`, fingerprint, dan audit append-only.
- Private local storage.

Tidak membuat panel `/pak`, global PersonPolicy khusus PAK, atau kalkulasi dalam Filament.

### SKP, sumber, dan provenance

Pisahkan tiga konsep:

| Konsep | Nilai |
|---|---|
| Jenis evaluasi | `Q1`, `Q2`, `Q3`, `Q4`, `ANNUAL`, `CUSTOM_PERIOD` |
| Sifat sumber | `OFFICIAL`, `PROVISIONAL` |
| Status revisi sumber | `CURRENT`, `SUPERSEDED` |

`CUSTOM_PERIOD` digunakan untuk provisional dengan rentang khusus; bukan jenis SKP resmi baru.

Simpan person, coverage sumber, predikat asli, referensi bukti, actor/time, alasan provisional, dan hubungan revisi. Coverage sumber tidak ditimpa oleh requested conversion range.

Kontrak provenance:

- **OFFICIAL wajib memiliki source/reference identifier yang tidak kosong.**
- Evidence file boleh menyusul apabila workflow bisnis mengizinkan; ketiadaan file tidak membolehkan official reference kosong.
- **PROVISIONAL wajib memiliki reason, actor, timestamp**, serta permission `pak.skp.provisional` selain `pak.skp.manage`.
- Actor dan timestamp berasal dari server.
- Penambahan evidence setelah evaluasi digunakan run mengikuti mekanisme revisi, bukan perubahan versi lama.

Contoh annual untuk subset:

- Jenis sumber tetap `ANNUAL`.
- Coverage asli tetap Januari–Desember 2023.
- Requested range November–Desember 2023 disimpan terpisah.
- Penggunaannya menghasilkan `ANNUAL_SUBSET_REQUIRES_REVIEW`.
- Preview diperbolehkan; tidak dianggap sebagai pengesahan aturan annual untuk subset.

### Correction chain PerformanceEvaluation

Koreksi evaluasi menggunakan `previous_version_id` nullable sebagai self-reference.

- Hanya evaluasi berstatus `CURRENT` yang boleh direvisi.
- Service mengunci row sumber, lalu memeriksa ulang status `CURRENT` dalam transaksi.
- Satu source revision hanya boleh memiliki satu correction successor.
- Terapkan **unique constraint pada `previous_version_id`**; nilai null diperbolehkan untuk sumber awal yang independen.
- Create successor, perubahan predecessor menjadi `SUPERSEDED`, dan audit dilakukan atomik.
- Dua concurrent revisions terhadap sumber yang sama: hanya satu boleh berhasil. Request lainnya ditolak setelah status diperiksa ulang atau constraint mendeteksi konflik.
- Kegagalan transaksi tidak mengubah evaluasi lama atau meninggalkan successor parsial.

Immutability:

- Koreksi membuat row baru dengan alasan dan hubungan ke versi sebelumnya.
- Setelah digunakan conversion run, isi evaluasi dan evidence versi tersebut immutable.
- File lama tidak ditimpa, diganti, atau dihapus; revisi evidence menggunakan objek private baru.
- Metadata supersession hanya berubah melalui service terotorisasi dan diaudit.
- Official tidak otomatis menghapus provisional atau mengubah hasil lama.
- Penggantian penuh boleh menandai provisional `SUPERSEDED`.

**Partial replacement coverage bukan correction chain.** Sumber pengganti sebagian tetap boleh coexist dengan sumber lama, tidak mengisi `previous_version_id` sebagai hubungan koreksi sebagian, dan tidak menandai seluruh coverage lama telah tergantikan. Hubungan penggunaan sumber parsial ditelusuri melalui segment dan audit yang relevan.

### Pemilihan sumber dan periode

- Operator memilih sumber secara eksplisit; tidak berdasarkan record terbaru.
- Evaluasi Akhir resmi untuk tahun penuh mengikuti `BR-SKP-002`.
- Annual dan triwulan dapat coexist sebagai sumber, tetapi bulan yang sama hanya dipilih sekali dalam run.
- Provisional memerlukan alasan, actor, waktu, dan permission tambahan.
- Sumber harus milik person case dan mencakup segment yang dihitung.
- Gap tidak diisi dengan nilai nol atau predikat sebelumnya.
- Tidak menerapkan batas universal rolling 36 bulan.

**Definisi full-month deterministik:**

- Awal setiap segment harus hari pertama bulan.
- Akhir setiap segment harus hari terakhir bulan.
- Jumlah bulan dihitung secara inklusif dari pasangan tahun/bulan, bukan selisih hari.
- Pergantian histori pada hari pertama bulan dapat memecah segment; histori sebelumnya harus mencakup sampai akhir bulan sebelumnya.
- Boundary tanggal lain menghasilkan `PARTIAL_MONTH_REQUIRES_REVIEW`.
- Segment pecahan bulan tidak dihitung otomatis.

Segmentasi mempertimbangkan batas tahun, coverage sumber, requested range, dan histori jabatan.

### Histori jabatan/JF

- Resolver membaca seluruh histori verified yang relevan dengan rentang, bukan hanya jabatan pada tanggal akhir case.
- Gunakan histori yang tidak superseded dan simpan ID serta salinan atributnya pada hasil.
- Histori hilang atau ambigu menghasilkan issue terstruktur.
- Non-JF tanpa dasar koefisien sah tidak diberi mapping JF otomatis.
- Master yang kemudian dinonaktifkan tidak menghapus histori verified atau hasil lama.
- Tidak mengubah histori jabatan sebagai efek samping perhitungan.

### Rule set dan penguncian

Satu case/run menggunakan **satu rule set eksplisit**.

- `RuleResolver` hanya mencari child predicate/coefficient rule dalam versi yang telah dipilih.
- Tidak memilih versi berdasarkan tanggal, versi terbaru, atau prioritas.
- Validity digunakan untuk pemeriksaan coverage, bukan pemilihan otomatis.
- Validity yang belum cukup atau perubahan aturan yang tidak tercakup menghasilkan `RULE_VALIDITY_REQUIRES_REVIEW`.
- Setiap line menyimpan provenance rule untuk ekspansi masa depan; seluruh line Phase 02 tetap wajib memakai versi run yang sama.
- Faktor predikat disimpan sebagai faktor decimal, misalnya `1.0000`, dengan nama `predicate_factor`.
- Koefisien berasal dari jenjang hasil resolusi server dan child rule terpilih.

**`first_used_at` tidak boleh dikosongkan atau dibuka kembali.** Seluruh child rule mengikuti lock parent. Untuk melengkapi predicate/coefficient pada versi terkunci, buat atau copy versi baru.

Perubahan rule set case:

- Hanya melalui domain service.
- Memerlukan authorization, alasan, optimistic version, transaksi, dan audit.
- Mengunci versi baru ketika mulai digunakan.
- Tidak mengubah rule set, snapshot, maupun angka pada run lama.
- Run terbaru yang tidak lagi sesuai konteks case diberi kebutuhan review; run pengganti harus dibuat eksplisit.

### Status run dan review issues

`pak_conversion_runs.status` menggunakan:

| Status | Makna |
|---|---|
| `PREVIEW` | Hasil pratinjau tersimpan tanpa issue khusus run yang belum terselesaikan |
| `REQUIRES_REVIEW` | Ada issue sumber, histori, aturan, coverage, atau perubahan dependency |
| `SUPERSEDED` | Run telah digantikan oleh run baru secara eksplisit |

Semua status tersebut merupakan **status kalkulasi/review**, bukan business finalization.

- `PREVIEW` tetap bukan hasil resmi meskipun angka lengkap.
- Pending rounding produksi merupakan pembatas global `PREVIEW_ONLY`; tidak perlu membuat setiap run berstatus review semata-mata karena pembatas global tersebut.
- Angka, input snapshot, dan calculation trace run lama immutable.
- Perubahan status/review metadata dibatasi service dan diaudit; bukan izin mengedit hasil.
- Penyelesaian issue menghasilkan run baru, bukan menghapus issue dari histori run lama.

Review issue berupa struktur yang memuat `code`, lokasi/rentang terdampak, referensi terkait, pesan, serta apakah issue menghalangi kalkulasi numerik.

Minimal kode:

| Kode | Dampak |
|---|---|
| `ANNUAL_SUBSET_REQUIRES_REVIEW` | Preview numerik boleh |
| `PARTIAL_MONTH_REQUIRES_REVIEW` | Segment terdampak tidak dihitung |
| `RULE_VALIDITY_REQUIRES_REVIEW` | Preview hanya jika input numerik tersedia |
| `MISSING_POSITION_HISTORY` | Segment terdampak tidak dihitung |
| `AMBIGUOUS_POSITION_HISTORY` | Segment terdampak tidak dihitung |
| `MISSING_PREDICATE_RULE` | Segment terdampak tidak dihitung |
| `MISSING_COEFFICIENT_RULE` | Segment terdampak tidak dihitung |

Tambahkan kode teknis `SOURCE_COVERAGE_GAP`, `SOURCE_CHANGED_REQUIRES_REVIEW`, dan `CASE_CONTEXT_CHANGED_REQUIRES_REVIEW`.

Overlap pilihan merupakan validation error yang menolak penyimpanan, bukan hasil numerik yang boleh diteruskan. Run review dengan segment tak terhitung boleh menyimpan diagnostics; total keseluruhan harus `null`, bukan nol atau subtotal yang dilabeli total.

### Replacement chain PakConversionRun

- `revision_number` dibuat server, bukan diterima sebagai keputusan client.
- Case dikunci **sebelum** menentukan latest run dan revision number berikutnya.
- Run pertama tidak memiliki predecessor.
- Setelah case memiliki run, replacement hanya boleh terhadap **latest non-superseded run** pada case yang sama.
- Service memeriksa ulang predecessor dalam transaksi; stale predecessor ditolak.
- Satu predecessor hanya boleh memiliki satu successor.
- Terapkan **unique constraint pada `predecessor_run_id`** nullable, selain unique case + revision number.
- Create successor beserta lines, mark predecessor `SUPERSEDED`, audit, dan peningkatan `lock_version` dilakukan atomik.
- Dua concurrent replacements tidak boleh membentuk cabang successor.
- Failure tidak boleh mengubah status, angka, snapshot, atau metadata old run.

### Kalkulasi server-authoritative

Browser hanya mengirim pilihan sumber, requested range, identitas case/predecessor, expected version, fingerprint, dan alasan yang relevan.

Browser tidak authoritative untuk:

- `predicate_factor`
- `annual_coefficient`
- `month_count`
- Resolved functional level
- Nilai AK tiap line
- Total AK
- Status dan issue hasil resolusi
- `revision_number`

Field turunan tidak diterima sebagai input penyimpanan. Server membaca ulang dependency, melakukan segmentasi, resolve rule/histori, dan menghitung ulang sebelum save.

Pada transaksi save wajib berlaku:

`pak_conversion_runs.pak_rule_set_id = pak_cases.pak_rule_set_id`

Perbandingan menggunakan case yang telah dikunci dan dibaca ulang dalam transaksi. Rule set case harus tersedia dan referensinya sah. Case tanpa pilihan rule set dapat dipreview untuk diagnostics, tetapi belum dapat menyimpan run.

Jika case, predecessor, pilihan rule, atau dependency berubah sejak preview, save ditolak dan pengguna diminta mempreview ulang.

### Precision dan presentation

Gunakan exact rational arithmetic melalui `brick/math`, dengan input decimal string.

`AK = month_count / 12 × predicate_factor × annual_coefficient`

- Simpan numerator/denominator eksak untuk line dan total.
- Total eksak merupakan jumlah nilai eksak.
- Profil tampilan berversi: **PREVIEW_ONLY, tiga desimal, half-up**.
- Nilai tampilan tidak dipakai sebagai dasar rekalkulasi.
- Jangan memakai PHP float atau angka browser sebagai sumber authoritative.
- Jelaskan potensi selisih antara jumlah baris bulat dan pembulatan total eksak.
- Kebijakan preview tidak menetapkan `BR-KONV-004`.

Golden Nurmaulida tetap numeric fixture: koefisien 25 berasal dari bukti contoh, bukan mapping JF rekaan.

## 3. Proposed data, services, permissions, dan UI

### Tables dan migrations yang diusulkan

Lima tabel baru, masing-masing melalui migration additive:

| Tabel | Isi utama |
|---|---|
| `performance_evaluations` | Person, coverage, jenis/sifat sumber, predikat, `previous_version_id`, evidence private, actor/time |
| `performance_predicate_rules` | Rule set, kode/label predikat, faktor decimal |
| `ak_coefficient_rules` | Rule set, jenjang, koefisien tahunan decimal |
| `pak_conversion_runs` | Case, revision number, `predecessor_run_id`, rule set, status, input snapshot, issues, fingerprint, total eksak/preview nullable |
| `pak_conversion_periods` | Run, sumber, histori, range, bulan, rule snapshot, hasil eksak/preview nullable, trace |

Constraint utama:

- FK memakai `RESTRICT`.
- Unique rule set + predicate code; rule set + functional level.
- Unique `performance_evaluations.previous_version_id`, nullable.
- Unique `pak_conversion_runs.predecessor_run_id`, nullable.
- Unique case + revision number; run + line number.
- Status memakai VARCHAR dan enum aplikasi.
- Run menyimpan actor/time dan profil presentation.
- Tidak membuat FK ke snapshot DUK sebagai identitas jangka panjang.
- Tidak ada backfill SKP/JF dari DUK.
- Tidak mengubah migration lama.
- Tidak seed regulasi aktif yang belum disahkan.

Unique constraints menjadi pengaman database; validasi domain tetap wajib memastikan person/case yang sama, predecessor yang sah, serta lifecycle yang benar.

### Services dan interfaces

Di `app/Domain/Pak/`:

- `PerformanceEvaluationService`: create, revise, supersede, dan locking correction chain.
- `PerformanceEvidenceService`: bukti private immutable dan download terotorisasi.
- `PerformancePeriodResolver`: coverage, segmentasi, bulan utuh, overlap.
- `ConversionRuleService`: child rules dan copy versi.
- `RuleResolver`: lookup dalam versi eksplisit.
- `ConversionCalculator`: aritmetika eksak.
- `ConversionRoundingPolicy`: presentation `PREVIEW_ONLY`.
- `ConversionService`: preview, server recalculation, save, dan replacement chain.
- `ConversionReviewService`: issue dependency dan status review.
- `ConversionRunQuery`: hasil, ownership boundary, latest run, metric review.

Kontrak utama:

- `preview(...)`: read-only; menghasilkan segment, hasil/diagnostics, issue, dan fingerprint.
- `savePreview(...)`: authorize, lock case, validasi predecessor/version, resolve ulang, hitung ulang, cocokkan fingerprint, lalu simpan atomik.
- Revisi run memerlukan alasan dan hubungan predecessor.
- Run, lines, supersession metadata, audit, dan peningkatan case `lock_version` berada dalam satu transaksi.
- Tetapkan urutan lock konsisten pada jalur terkait; uji MySQL terhadap concurrent save, koreksi sumber, dan perubahan rule case.
- Kegagalan audit atau penyimpanan tidak boleh meninggalkan hasil sebagian atau mengubah predecessor.

### Permissions dan seeder

| Operasi | Permission |
|---|---|
| Baca SKP/evidence | `pak.skp.view` dan akses person yang relevan |
| Catat/revisi OFFICIAL | `pak.skp.manage` |
| Catat/revisi PROVISIONAL | `pak.skp.manage` + `pak.skp.provisional` |
| Lihat hasil konversi | `pak.conversion.view` + `pak.case.view` |
| Preview/save konversi | `pak.conversion.calculate`, `pak.conversion.view`, `pak.case.view`, `pak.case.update`, `pak.skp.view`, `pak.employee.view`, `pak.rule-set.view` |
| Ubah rule case | `pak.case.update` + `pak.rule-set.view` serta dependency read permissions |
| Kelola child rule | `pak.rule-set.manage` + `pak.rule-set.view` |

Seluruhnya tetap mensyaratkan akun aktif dan `pak.access`. Policy, query, action, service, Livewire, dan download harus menegakkan batas tersebut.

**Baseline permission seeder eksplisit:**

Super Admin dan Admin PAK masing-masing memperoleh kelima permission:

1. `pak.skp.view`
2. `pak.skp.manage`
3. `pak.skp.provisional`
4. `pak.conversion.view`
5. `pak.conversion.calculate`

Ketentuan seeder:

- Additive dan idempotent, termasuk untuk kedua role baseline yang sudah ada.
- Admin SDM dan Pegawai tidak memperoleh kelima permission tersebut secara otomatis.
- Custom roles dan permissions existing tidak dikurangi.
- Jangan memakai `syncPermissions()` untuk mereset role existing.
- Tidak mengubah assignment user, status akun, atau mapping person.
- Tidak membuat global Super Admin bypass.

### Filament, evidence, dan dashboard

Tambahkan resource SKP serta halaman konversi pada case. Alur: input sumber → pilih rule/sumber → preview segment dan issue → simpan preview → bandingkan revisi.

Evidence:

- Private local storage; nama objek acak dan checksum.
- Default teknis PDF/JPEG/PNG, maksimal 10 MiB.
- Download melalui policy record; bukan path bebas atau public URL.
- Official reference wajib tidak kosong meskipun file belum tersedia.
- File terpakai tidak ditimpa; evidence baru mengikuti revisi evaluasi.
- Kegagalan transaksi hanya boleh membersihkan objek upload baru yang belum direferensikan.
- Tidak ada purge historis otomatis.

Dashboard **Perlu Pemeriksaan**:

- Menghitung satu latest run per case berdasarkan revision number.
- Hanya latest run berstatus `REQUIRES_REVIEW` yang dihitung.
- Run superseded dan issue historis tidak menggandakan count.
- Query dijalankan hanya jika pengguna memiliki `pak.case.view` dan `pak.conversion.view`.
- Tanpa izin tampil `—`; hasil berizin kosong tampil `0`.
- Tautan menuju daftar case/run yang membutuhkan review.
- Ini metric review konversi, bukan eligibility atau general warning engine.

**PAK Ditetapkan tetap placeholder dan out of scope.**

### Audit dan privacy

Perluas subject/event audit untuk pencatatan dan koreksi SKP, supersession, child rule, penyimpanan run, replacement run, serta perubahan review status.

Audit event hanya menyimpan business metadata yang diperlukan:

- IDs record terkait.
- Actor dan timestamp.
- Reason.
- Source/reference identifier.
- Status dan issue codes.
- Nilai bisnis sebelum/sesudah yang diperlukan untuk menjelaskan tindakan.

Jangan menyalin ke audit payload:

- Binary evidence atau isi file.
- Seluruh employee snapshot.
- Credential, secret, atau token.
- Storage path sensitif.
- Identitas pribadi lengkap yang tidak diperlukan, termasuk NIP lengkap.

Snapshot kalkulasi tetap berada pada run dengan akses terotorisasi. Payload audit dibentuk melalui whitelist eksplisit, bukan serialisasi model/request secara keseluruhan.

### Files terdampak

Berkas baru mencakup lima model/policies, lima migration, DTO/enums, services/queries di atas, resource/pages SKP dan konversi, controller evidence, views, serta test/fixtures.

Berkas existing yang direncanakan berubah:

- `Person`, `PakCase`, `PakRuleSet`: relationships.
- `PakCaseService`: perubahan konteks/rule case yang diaudit dan review dependency.
- `PakRuleSetService`: copy versi lengkap dan penjagaan lock.
- `EmployeeHistoryQuery`: query rentang.
- `EmployeeHistoryService`: penandaan review run terdampak koreksi histori.
- `PakAuditService`, resource/presenter aktivitas: subject/event baru dan payload whitelist.
- `PakCaseResource`, halaman case, `PakRuleSetResource`: workflow preview.
- Dashboard PAK dan view metric.
- `PakPermissionSeeder`, `AppServiceProvider`, `InternalModuleAccess`: akses dan registry.
- `routes/web.php`: private evidence download.
- `composer.json`/lockfile: deklarasi langsung dependency matematika.
- `phpunit.xml`: suite Unit.
- Dokumentasi PAK terkait dan plan Phase 02.

Importer, publisher, public dashboard, dan semantik data SDM tetap dipertahankan.

## 4. Checkpoint implementasi, tests, dan UAT

Seluruh checkpoint tetap di branch **`feature/pak-phase-02`**. Checkpoint bukan izin untuk mulai implementasi sekarang.

| Checkpoint | Deliverable dan gate |
|---|---|
| **02A — SKP/source** | Model sumber, coverage, correction chain, provenance, official/provisional authorization, kontrak evidence immutable; tests lifecycle |
| **02B — Rule + calculator** | Child rules, permanent lock, explicit lookup, exact arithmetic, presentation policy; golden numeric tests |
| **02C — Run/segmentation** | Full-month resolver, issues, statuses, replacement chain, server-authoritative save, equality rule case/run; integration tests |
| **02D — UI/evidence/audit** | Filament, private upload/download, audit privacy, comparison, dashboard review; Livewire dan authorization tests |
| **02E — Regression/MySQL/UAT** | Full suite, upgrade/fresh schema di DB terisolasi, MySQL races, UAT, dokumentasi hasil |

### Test wajib

**Calculation dan segmentasi**

- Golden Nurmaulida: **4.167, 25, 25, 20.833; total eksak 75**.
- Tidak membuat mapping JF agar koefisien golden cocok.
- TC-PAK-002/003/004/005/006/027 sesuai boundary konversi.
- Empat triwulan, annual, provisional, annual-subset.
- Bulan Februari/tahun kabisat, pergantian tahun, satu bulan, dan akhir bulan.
- Boundary histori awal bulan dapat disegmentasi; tengah bulan tidak dihitung.
- Semua review issue code, hasil nullable, dan larangan total semu.
- Browser memalsukan faktor, koefisien, bulan, jenjang, AK, total, status, dan revision number: ditolak/tidak dipercaya; hasil disusun server.
- Rule case berubah setelah preview: save ditolak.
- Case/run rule-set equality saat transaksi.
- `first_used_at` tidak dapat dibuka; child rules terkunci; copy versi berhasil.

**Correction chain SKP**

- Hanya CURRENT dapat direvisi.
- Dua concurrent revisions menghasilkan tepat satu successor.
- Unique `previous_version_id` mencegah correction branch.
- Failure meninggalkan predecessor tetap utuh.
- Partial replacement dapat coexist tanpa masuk correction chain.
- Evaluasi/evidence terpakai tidak dapat diedit atau ditimpa.
- Official tanpa source/reference identifier ditolak.
- Evidence yang menyusul tidak menghapus provenance atau mengganti versi terpakai.

**Replacement chain run**

- Revision number dibuat server setelah case dikunci.
- Replacement terhadap run selain latest non-superseded ditolak.
- Predecessor lintas-case ditolak.
- Unique `predecessor_run_id` mencegah branch.
- Concurrent replacements hanya menghasilkan satu successor yang sah.
- Run lama menjadi superseded hanya bersama keberhasilan penyimpanan successor.
- Failure tidak mengubah status, angka, snapshot, atau metadata old run.
- Run pengganti tidak mengubah angka/input run lama.

**Security, audit, dan compatibility**

- Official/provisional permission matrix dan dependency read permissions.
- Super Admin dan Admin PAK memperoleh kelima permission secara additive/idempotent.
- Admin SDM/Pegawai tidak mendapat grant otomatis; custom permissions tidak berkurang.
- Audit tidak mengandung binary evidence, seluruh employee snapshot, credential/token, atau storage path sensitif.
- Gagal audit menyebabkan rollback atomik.
- Dashboard menghitung latest run saja dan tidak query tanpa izin.
- Private evidence, lintas-person, akun inactive, direct URL dan Livewire.
- Reimport DUK dan perubahan master tidak mengubah hasil historis.
- Concurrent koreksi SKP, perubahan histori, perubahan rule, dan save run.
- Regression shared code dan seluruh baseline Phase 01/SDM.

SQLite untuk suite rutin; MySQL staging wajib untuk locking/concurrency dan constraint chain. Jalankan suite setelah setiap checkpoint sesuai perubahan, lalu seluruh suite pada 02E.

### UAT dan acceptance

Tim SDM memverifikasi sumber, provenance, revisi, annual-subset, provisional, segmentasi, golden numerik, trace, tampilan pending, private evidence, dan metric review.

UAT juga memeriksa SDM-only, PAK-only, mixed permissions, inactive account, dua workspace, dan operasi SDM existing.

Acceptance teknis mensyaratkan kalkulasi eksak benar, tidak double-count, correction/replacement chain tidak bercabang, histori stabil, authorization/audit lulus, dan tidak ada regression. Hasil hanya berstatus preview/review; tidak ada klaim business finalization.

## 5. Business rules, pending decisions, dan risks

### Kontrak yang sudah ditetapkan

Mengikuti status pada dokumen repository:

- `BR-TERM-001/002`: pemisahan istilah.
- `BR-ACTOR-001`: pengelolaan oleh Tim SDM.
- `BR-SKP-001–007`: evaluasi, sumber, segmentasi, provisional, dan retensi.
- `BR-PERIOD-002`: explicit date range.
- `BR-PRED-002`, `BR-KOEF-002/003`: versioning, master lookup, snapshot.
- `BR-KONV-003/005`: precision dan banyak detail.
- `BR-CASE-001–003`: case, identity, snapshot.
- `BR-AUDIT-001–003`: provenance dan audit.

Keputusan teknis correction/replacement chain, permission baseline, dan audit privacy dicatat sebagai kontrak Phase 02. Tidak mengubah status regulasi atau rule pending.

### PENDING BUSINESS DECISION

| Area | Batas Phase 02 |
|---|---|
| `BR-KONV-004` | Three-decimal half-up hanya `PREVIEW_ONLY` |
| Faktor, koefisien, formula berstatus REFERENCE | Tidak otomatis menjadi master produksi yang disahkan |
| Annual untuk subset tahun | Preview dengan issue review |
| Dasar koefisien Nurmaulida | Numeric fixture saja |
| Pecahan bulan | Tidak dihitung otomatis |
| Lintas versi regulasi | Tidak ada automatic resolver |
| `BR-PERIOD-003` | Tidak memaksakan rolling 36 bulan |
| `BR-SKP-008` | Tidak mengimplementasikan koreksi administratif PAK final |
| `BR-APPOINT-008`, `BR-KJ-005`, `BR-BAL-003/004` | Tidak disentuh |
| Retensi evidence | Tidak ada penghapusan otomatis |

### Risks dan mitigasi

- **Preview disalahartikan sebagai penetapan:** status terbatas, label permanen, tanpa finalization action.
- **Rule lama terkunci sebelum child rule tersedia:** copy versi baru; tidak membuka lock.
- **Data histori tidak lengkap:** issue eksplisit dan hasil nullable.
- **Hasil usang setelah sumber berubah:** review dependency tanpa mengubah angka lama.
- **Correction/replacement chain bercabang:** row lock, validasi CURRENT/latest, unique successor reference, dan transaksi atomik.
- **Race saat save:** server recalculation, transaksi, lock, fingerprint, optimistic version.
- **Akses bocor melalui evidence/metric/audit:** policy record, dependency permission, query guard, dan whitelist payload.
- **Perubahan shared service merusak SDM:** perubahan terbatas dan regression wajib.
- **Keterbatasan runtime audit:** tidak dianggap sebagai kegagalan baseline; validasi implementasi dilakukan pada environment test yang berfungsi.

**Tidak ada pertanyaan klarifikasi yang blocking untuk review terakhir.** Keputusan lain tetap dipertahankan. Pending business decisions tidak diselesaikan melalui asumsi.

Pekerjaan berhenti pada final documentation checkpoint.
Final Plan Phase 02 telah disimpan sebagai dokumentasi repository.
Implementasi Phase 02 belum dimulai.
