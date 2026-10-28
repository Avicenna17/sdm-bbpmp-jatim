# Final Plan Phase 02 — SKP & Conversion

**Status: Final Plan Phase 02 telah disetujui dan disimpan sebagai dokumentasi repository pada branch `feature/pak-phase-02`; implementasi belum dimulai.** Checkpoint dokumentasi ini tidak mengubah source aplikasi, migration, dependency implementasi, atau deployment.

## 1. Baseline, tujuan, dan boundary

### Pembaruan keputusan client — 23 Oktober 2026

Checkpoint dokumentasi ini menyinkronkan BR-SKP-002/004/005/006/009/010, BR-JAB-001, BR-REGIME-001, BR-DATA-001, BR-REF-001, dan BR-KONV-004 pada `../business-rules.md`. Keputusan client menggantikan ketentuan lama yang bertentangan tentang pemilihan sumber, jenjang, TMT tengah bulan, dan rounding. Arsitektur, chain revisi, tabel, permission, checkpoint, satu rule set eksplisit, dan batas Phase 02 lainnya tetap. Tidak ada implementasi/source yang dibuat.

### Baseline yang diterima

Baseline Phase 01 pada commit **252661a** telah berhasil diuji di environment lokal:

**137 tests, 1176 assertions, 3 skipped, 0 failed.**

Kegagalan membaca `vendor/autoload.php` hanya terjadi pada environment Plan Mode/audit. Itu merupakan keterbatasan environment audit, **bukan kegagalan aplikasi atau baseline**.

Seluruh checkpoint Phase 02 menggunakan branch `feature/pak-phase-02`.

### Tujuan Phase 02

Tim SDM dapat:

- Mencatat SKP resmi triwulan/Evaluasi Akhir beserta bukti dan revisinya; tidak membuat provisional source terpisah.
- Meninjau sumber yang di-resolve sesuai prioritas bisnis client dan memilih satu rule set secara eksplisit.
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
| Sifat sumber | `OFFICIAL`; `PROVISIONAL` hanya konsep historis desain, bukan sumber baru Phase 02 |
| Status revisi sumber | `CURRENT`, `SUPERSEDED` |

`CUSTOM_PERIOD` merupakan konsep historis desain provisional, bukan jenis SKP resmi atau jalur input provisional baru Phase 02. Struktur arsitektur dan retensi bukti historis tidak dihapus.

Simpan person, coverage sumber, predikat asli, referensi bukti, actor/time, alasan provisional, dan hubungan revisi. Coverage sumber tidak ditimpa oleh requested conversion range.

Kontrak provenance:

- **OFFICIAL wajib memiliki source/reference identifier yang tidak kosong.**
- Evidence file boleh menyusul apabila workflow bisnis mengizinkan; ketiadaan file tidak membolehkan official reference kosong.
- Metadata provisional historis tetap memuat reason, actor, timestamp; permission `pak.skp.provisional` dipertahankan dalam baseline tanpa mengaktifkan input provisional baru.
- Actor dan timestamp berasal dari server.
- Penambahan evidence setelah evaluasi digunakan run mengikuti mekanisme revisi, bukan perubahan versi lama.

Fallback SKP sudah RESOLVED: annual jika tersedia (termasuk subset), selain itu triwulan terakhir relevan; Jan–Okt memakai Q3. Tidak ada provisional source terpisah. Jika sumber relevan belum tersedia, review tanpa membuat predikat sendiri. Permission/konsep historis bukan izin membuka alur provisional baru.

BR-SKP-008 tetap PENDING BUSINESS DECISION hanya untuk kebijakan refresh/revision ketika calculation/draft berbasis triwulan sudah tersimpan lalu annual tersedia. Tidak ada auto-update calculation lama, penggantian sumber snapshot, atau successor otomatis. Bukti sumber saat calculation tetap dicatat; mekanisme teknis revision chain tidak menetapkan kebijakan bisnis tersebut.

Contoh annual untuk subset:

- Jenis sumber tetap `ANNUAL`.
- Coverage asli tetap Januari–Desember 2023.
- Requested range November–Desember 2023 disimpan terpisah.
- Evaluasi Akhir menjadi sumber utama, termasuk untuk subset tahun, sesuai BR-SKP-002 yang telah dikonfirmasi client.
- Tidak menghasilkan `ANNUAL_SUBSET_REQUIRES_REVIEW` hanya karena rentang konversi merupakan subset coverage annual.

### Correction chain PerformanceEvaluation

Lifecycle evaluasi minimal adalah `CURRENT` dan `SUPERSEDED`. Koreksi evaluasi menggunakan `previous_version_id` nullable sebagai self-reference yang menunjuk predecessor, bukan successor. Actor supersession disimpan sebagai `superseded_by_user_id`, bukan field ambigu `superseded_by`; successor ditelusuri melalui reverse relation `previous_version_id`.

- Hanya evaluasi berstatus `CURRENT` yang boleh direvisi.
- Service mengunci row sumber, lalu memeriksa ulang status `CURRENT` dalam transaksi.
- Identity-defining fields immutable sepanjang ordinary correction chain: `person_id`, `source_kind`, `performance_year`, `evaluation_type`, `source_reference`, dan `source_record_identifier`. Successor ordinary correction WAJIB memiliki `source_identity_fingerprint` yang sama dengan predecessor. `PerformanceEvaluationService` memvalidasi kesamaan field identity dan fingerprint di dalam transaksi setelah predecessor dikunci, sebelum perubahan lifecycle apa pun.
- Ordinary correction hanya mengubah content/domain fields yang diizinkan; `content_fingerprint` berubah hanya bila canonical content berubah. Jika identity-defining field harus berubah, tolak ordinary correction dan tahan sebagai identity correction/manual review di luar ordinary 02A correction flow sampai workflow/business rule tersebut disetujui. Jangan diam-diam membentuk source identity baru melalui revision chain.
- Setelah predecessor CURRENT dikunci dan source identity divalidasi tetap sama, hitung canonical content calon successor melalui `SkpFingerprintSerializer`. Jika `content_fingerprint` sama dengan predecessor, tolak sebagai `NO_CHANGES` / no-op correction sebelum perubahan lifecycle apa pun: predecessor tetap CURRENT, tidak ada successor, `current_source_identity_fingerprint` tidak berubah, dan tidak dibuat correction audit seolah-olah terjadi perubahan data. Request dapat mengembalikan validation/domain message bahwa tidak ada perubahan data yang perlu disimpan. Revision reason saja tidak cukup untuk membuat successor jika canonical domain content tidak berubah.
- Satu source revision hanya boleh memiliki satu correction successor.
- Terapkan **unique constraint pada `previous_version_id`**; nilai null diperbolehkan untuk sumber awal yang independen.
- Dalam satu transaksi: lock predecessor `CURRENT`, periksa ulang status, validasi source identity tetap sama dan canonical content berbeda (lolos guard `NO_CHANGES`), ubah predecessor menjadi `SUPERSEDED` dan set `current_source_identity_fingerprint` menjadi `NULL`, lalu create successor `CURRENT` dengan `previous_version_id` menunjuk predecessor dan `current_source_identity_fingerprint` sama dengan `source_identity_fingerprint` successor. Simpan actor, timestamp, reason, dan audit sebelum commit.
- Dua concurrent revisions terhadap sumber yang sama: hanya satu boleh berhasil. Request lainnya ditolak setelah status diperiksa ulang atau constraint mendeteksi konflik.
- Kegagalan transaksi tidak mengubah evaluasi lama atau meninggalkan successor parsial.

Immutability:

- Koreksi membuat row baru dengan alasan dan hubungan ke versi sebelumnya.
- Setelah digunakan conversion run, isi evaluasi dan evidence versi tersebut immutable.
- File lama tidak ditimpa, diganti, atau dihapus; revisi evidence menggunakan objek private baru.
- Metadata supersession hanya berubah melalui service terotorisasi dan diaudit.
- Official tidak otomatis menghapus provisional atau mengubah hasil lama.
- Supersession sumber historis mengikuti correction chain; kedatangan annual tidak otomatis menandai run triwulan lama superseded (BR-SKP-008).

**Partial replacement coverage bukan correction chain.** Sumber pengganti sebagian tetap boleh coexist dengan sumber lama, tidak mengisi `previous_version_id` sebagai hubungan koreksi sebagian, dan tidak menandai seluruh coverage lama telah tergantikan. Hubungan penggunaan sumber parsial ditelusuri melalui segment dan audit yang relevan.

### Phase 02A — source identity, content identity, dan import concurrency

`SkpFingerprintSerializer` terpusat menjadi satu-satunya jalur pembentukan canonical `source_identity_fingerprint` dan `content_fingerprint`: SHA-256, lowercase hexadecimal, panjang 64 karakter (`CHAR(64)`). Keduanya NOT NULL pada domain PerformanceEvaluation yang valid; nullable source/content fingerprint hanya berlaku pada `pak_skp_import_rows` yang gagal sebelum identity/content dapat dibentuk. `current_source_identity_fingerprint` BUKAN hash independen: itu lifecycle guard/projection yang menyalin `source_identity_fingerprint` ketika CURRENT dan menjadi `NULL` ketika SUPERSEDED. Tidak boleh ada re-hash berbeda untuk current fingerprint. Adapter, service lain, dan client tidak membentuk source/content fingerprint dengan jalur alternatif.

Identitas sumber terpisah dari isi sumber. `source_identity_fingerprint` adalah SHA-256 canonical payload dengan field berurutan:

1. Schema version `skp-source-v1`.
2. `person_id` sebagai canonical identity dari `people.id`.
3. `source_kind`.
4. `performance_year`.
5. `evaluation_type`.
6. `source_reference`.
7. `source_record_identifier`, nullable.

`source_record_identifier` merupakan identifier stabil dari sumber. Jika satu referensi memuat beberapa record untuk person/tahun/jenis evaluasi yang sama, adapter harus membuktikan identifier pembeda dari struktur sumber. Jika tidak tersedia, tahan import sebagai identity ambiguity; jangan menciptakan identifier dari predikat atau urutan baris.

`content_fingerprint` adalah SHA-256 canonical payload dengan schema version `skp-content-v1`, lalu field identity di atas (termasuk versi identity), diikuti field berurutan:

- `predicate_code`, `predicate_label_original`.
- `coverage_start`, `coverage_end`.
- `source_date`.
- `source_position_name`, `source_functional_level_label`.
- `source_position_tmt`.
- `source_sk_number`, `source_sk_date`.

Kedua payload memakai schema version, fixed fields/fixed ordering, UTF-8, null eksplisit, tanggal ISO `YYYY-MM-DD`, dan deterministic string normalization:

- Stable source identifiers di-trim pada tepi; leading zero dan kapitalisasi dipertahankan. Identifier tidak dibentuk dari Excel row number, workbook name, batch ID, database ID, import order, atau content hash. `person_id` tetap menjadi komponen canonical employee identity, bukan pengganti identifier record sumber.
- Descriptive/domain text yang masuk content fingerprint di-trim pada tepi dan repeated whitespace di-collapse secara deterministik. Raw/original value tetap disimpan sebagai provenance. Perubahan kosmetik whitespace saja tidak menghasilkan false conflict.
- Content identity tidak memasukkan batch ID, nama/checksum workbook, lokasi cell, urutan baris, actor, timestamp import, status correction, atau link histori hasil verifikasi internal. Metadata provenance dan lampiran disimpan terpisah.

Klasifikasi import setelah seluruh validasi lain lolos:

| Source identity | Content identity | Hasil |
|---|---|---|
| Sama | Sama | Duplicate/idempotent; referensikan evaluasi existing dan catat provenance occurrence import |
| Sama | Berbeda | `SOURCE_CONTENT_CONFLICT`; tidak overwrite atau auto-correction |
| Berbeda | Apa pun | Evidence independen yang sah; bukan otomatis correction |

Hash dipakai untuk pencarian; canonical payload tetap dibandingkan sebelum menyatakan identik. Kecocokan dengan revision historis tidak mengaktifkannya kembali. Konten berbeda dari `CURRENT` tetap ditahan untuk pemeriksaan, bukan otomatis memulihkan revision lama.

Setelah ordinary correction, re-import payload predecessor dengan identity yang sama wajib diklasifikasikan terhadap CURRENT successor. Jika content berbeda dari successor, hasilnya `SOURCE_CONTENT_CONFLICT`; jika sama, duplicate/idempotent. Jangan membuat independent CURRENT baru hanya karena predecessor sudah SUPERSEDED: successor tetap menjaga source identity yang sama.

Constraints yang diusulkan pada `performance_evaluations`:

- `source_identity_fingerprint CHAR(64) NOT NULL` memiliki index **non-unique** agar revision history dipertahankan; `content_fingerprint CHAR(64) NOT NULL` wajib pada setiap evaluasi domain valid.
- Tambahkan nullable `current_source_identity_fingerprint` dengan **unique index**: untuk `CURRENT` nilainya sama dengan `source_identity_fingerprint`; untuk `SUPERSEDED` nilainya `NULL`. Ini menjamin maksimal satu CURRENT evaluation per source identity, termasuk concurrent initial import.
- Nullable unique `previous_version_id` tetap menjamin satu direct successor per predecessor.
- `current_source_identity_fingerprint CHAR(64)` nullable dan UNIQUE merupakan lifecycle projection, bukan hash independen. Supersession tidak menghapus historical `source_identity_fingerprint` atau `content_fingerprint`.
- Provenance row import menyimpan `source_identity_fingerprint` dan `content_fingerprint` secara terpisah dengan index pencarian; tidak menjadikan keduanya pengganti identifier sumber asli.

Jika concurrent initial import terkena unique conflict pada current source identity, service melakukan rollback yang diperlukan, lalu re-query dalam transaksi/read context yang valid dan mengklasifikasikan canonical payload sebagai duplicate atau `SOURCE_CONTENT_CONFLICT`, bukan generic failure. Penanganan ini khusus konflik identity; kegagalan database lain tidak disamarkan sebagai duplicate. Correction tetap eksplisit dan tidak berubah menjadi auto-revision akibat reimport.

### Phase 02A — private storage dan DB atomicity boundary

Private object storage tidak ikut rollback transaksi DB. Service menyimpan upload baru pada object key acak dan unik, memverifikasi checksum, lalu menjalankan transaksi metadata/domain.

Jika object baru berhasil disimpan tetapi transaksi DB gagal:

- Rollback seluruh perubahan DB dalam transaksi tersebut.
- Compensating cleanup hanya boleh menghapus object baru milik operasi gagal yang dipastikan belum memiliki referensi committed.
- Jangan menghapus object existing, object reused, atau evidence historis, termasuk workbook batch yang sudah tersimpan sebelumnya.
- Jika cleanup gagal, pertahankan object sebagai orphan baru untuk retry cleanup terkontrol; periksa ulang referensi sebelum retry. Error harus tersanitasi dan storage path tidak masuk business audit.
- Jika hasil commit belum dapat dipastikan, rekonsiliasi status DB terlebih dahulu; jangan melakukan cleanup berdasarkan asumsi rollback.

Kegagalan parsing setelah metadata batch/file berhasil committed bukan orphan: workbook dipertahankan sebagai provenance batch gagal. Tidak ada purge evidence historis. Kegagalan transaksi tidak boleh mengubah predecessor atau meninggalkan successor parsial.

### Phase 02A — adapter acceptance dan boundary

Adapter client belum accepted sampai **sanitized representative workbook dengan struktur nyata client** diverifikasi. Verifikasi mencakup sheet/header, struktur record, identitas pegawai, source reference/record identifier, tanggal, predikat, dan coverage. Fixture sintetis saja tidak memenuhi acceptance adapter; mapping workbook tidak boleh ditebak.

UI import/evidence tetap Phase 02D. Source selection dan conversion tetap pada checkpoint berikutnya; kontrak evidence/import 02A tidak memilih sumber per bulan atau menghitung AK. Explicit correction dan seluruh pending business rules tetap dipertahankan.

### Pemilihan sumber dan periode

- Server memilih sumber sesuai prioritas bisnis; operator meninjau provenance dan tidak boleh mengabaikan sumber utama dengan memilih predikat lain.
- Evaluasi Akhir resmi mengalahkan triwulan untuk tahun terkait, termasuk subset bulan (`BR-SKP-002`).
- Jika Evaluasi Akhir belum tersedia, gunakan triwulan terakhir relevan (`BR-SKP-009`): Januari–Oktober menggunakan Q3 ketika Q4/Evaluasi Akhir belum tersedia, tanpa provisional Oktober.
- Jika beberapa record annual berbeda karena perubahan jabatan, pilih record terkait jabatan dengan TMT terbesar yang berlaku pada bulan tersebut (`BR-SKP-010`); bila perubahan berulang dalam bulan sama gunakan TMT paling akhir. Bukan urutan record/ID/import/Excel. Hubungan tidak terbukti/ambigu harus direview.
- Annual dan triwulan dapat coexist sebagai sumber, tetapi bulan yang sama hanya dipilih sekali dalam run.
- Tidak menggunakan provisional source terpisah pada fallback Phase 02.
- Sumber harus milik person case; coverage asli dan rentang penerapan bisnis disimpan terpisah. Penerapan Q3 pada Januari–Oktober sah sesuai BR-SKP-009, tanpa memalsukan coverage dokumen Q3.
- Jika tidak ada sumber relevan yang dapat dipertanggungjawabkan, tampilkan gap/review; jangan mengisi nol atau membuat predikat sendiri.
- Tidak menerapkan batas universal rolling 36 bulan.

**Definisi full-month deterministik:**

- Awal setiap segment harus hari pertama bulan.
- Akhir setiap segment harus hari terakhir bulan.
- Jumlah bulan dihitung secara inklusif dari pasangan tahun/bulan, bukan selisih hari.
- Pilih jabatan/jenjang dengan TMT terbesar yang berlaku per bulan (BR-JAB-001); beberapa perubahan dalam bulan sama memakai TMT paling akhir bulan itu. Bulan terdahulu tidak memakai jenjang yang baru berlaku bulan berikutnya.
- TMT pada awal, tengah, atau akhir bulan memperlakukan bulan perubahan sebagai satu bulan penuh dengan jabatan/jenjang terbaru, tanpa prorata hari. Tanggal TMT asli tetap disimpan.
- `PARTIAL_MONTH_REQUIRES_REVIEW` hanya berlaku untuk requested range dengan batas hari parsial yang belum diputuskan, bukan untuk TMT tengah/akhir bulan yang telah dikonfirmasi client.

Segmentasi mempertimbangkan batas tahun, rentang penerapan sumber, requested range, dan hasil resolusi jabatan/jenjang per bulan. Antarbulan dapat memakai koefisien berbeda; tidak ada prorata hari di dalam satu bulan atau overlap bulan.

### Histori jabatan/JF

- Resolver membaca histori verified dan memilih TMT JABATAN -> SK terbesar yang berlaku pada setiap bulan. Bila perubahan berulang dalam bulan sama, gunakan TMT paling akhir. Urutan record, ID database, tanggal import, dan urutan Excel bukan dasar resolusi.
- Gunakan histori yang tidak superseded; simpan ID, TMT asli, referensi SK, sumber dan salinan atributnya. Koefisien mengikuti jenjang terpilih per bulan; TMT tengah/akhir bulan berlaku untuk satu bulan penuh tanpa mengubah hasil bulan sebelumnya.
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
- Rounding BR-KONV-004 telah disahkan client. Label `PREVIEW_ONLY` tetap membatasi lifecycle hasil Phase 02, bukan menandakan rounding masih pending; tidak memperkenalkan business finalization.
- Angka, input snapshot, dan calculation trace run lama immutable.
- Perubahan status/review metadata dibatasi service dan diaudit; bukan izin mengedit hasil.
- Penyelesaian issue menghasilkan run baru, bukan menghapus issue dari histori run lama.

Review issue berupa struktur yang memuat `code`, lokasi/rentang terdampak, referensi terkait, pesan, serta apakah issue menghalangi kalkulasi numerik.

Minimal kode:

| Kode | Dampak |
|---|---|
| `ANNUAL_SUBSET_REQUIRES_REVIEW` | Kode historis desain; tidak diterbitkan untuk annual subset yang sah menurut BR-SKP-002 |
| `PARTIAL_MONTH_REQUIRES_REVIEW` | Requested range parsial di luar keputusan TMT; tidak berlaku hanya karena TMT tengah/akhir bulan |
| `RULE_VALIDITY_REQUIRES_REVIEW` | Safe diagnostics boleh ditampilkan pada preview tanpa kalkulasi numerik untuk seluruh request; exact total NULL. Authoritative diagnostic save sebagai REQUIRES_REVIEW hanya jika seluruh save gate lainnya berhasil. |
| `LEGACY_REGIME_REQUIRES_REVIEW` | Authoritative TMT Jabatan SK < 2023-01-01 evidence: affected line non-numeric, exact line result/complete total NULL; conditional diagnostic save; stop current-regime child lookup. Detail mengikuti OD-12 Phase 02C. |
| `REGIME_EVIDENCE_REQUIRES_REVIEW` | OD-13: position/history resolved tetapi documentary TMT Jabatan SK proof tidak cukup; bukan LEGACY/CURRENT, affected line non-numeric dan complete total NULL; conditional diagnostic save, no child lookup. |
| `MISSING_POSITION_HISTORY` | Segment terdampak tidak dihitung |
| `AMBIGUOUS_POSITION_HISTORY` | Segment terdampak tidak dihitung |
| `PREDICATE_RULE_NOT_FOUND` | Setelah validity lolos, exact predicate code tidak memiliki matching child rule; line terdampak diagnostic, complete total NULL |
| `COEFFICIENT_RULE_NOT_FOUND` | Setelah validity lolos, canonical functional_level_id tersedia tetapi exact coefficient rule tidak ditemukan; line terdampak diagnostic, complete total NULL |
| `MISSING_FUNCTIONAL_LEVEL` | Position resolution sukses, tetapi selected authoritative position tidak menyediakan canonical functional_level_id untuk coefficient lookup; line terdampak diagnostic, complete total NULL. Gap/overlap tetap memakai position issue codes. |

Validity gate, precedence yang short-circuit child numeric resolution, dan outcome mengikuti [OD-11 RESOLVED dalam plan Phase 02C](phase-02c-resolution-conversion-runs.md#od-11--resolved-rule-validity-dan-child-resolvability); executable acceptance dimiliki 02C.5/TC-PAK-042. Ketentuan tersebut menjadi acuan authoritative untuk rule validity pada parent plan ini. [OD-12](phase-02c-resolution-conversion-runs.md#od-12--resolved-canonical-legacy-review-outcome) menambahkan canonical LEGACY outcome berdasarkan bukti TMT Jabatan SK, bukan labels atau automatic history.tmt_start equivalence; LEGACY tidak diterjemahkan menjadi missing rule/level karena current-rule lookup sengaja dilewati. [OD-13](phase-02c-resolution-conversion-runs.md#od-13--resolved-tmt-jabatan-sk-evidence-contract) menetapkan limited documentary equivalence untuk selected same-person verified/non-superseded OD-04 history dengan tmt_start dan nonblank sk_number/source_ref; sk_date bukan effective TMT. Evidence gate mendahului regime/suitability; insufficient proof memakai REGIME_EVIDENCE_REQUIRES_REVIEW, bukan missing rule/level.

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

Field turunan tidak diterima sebagai input penyimpanan. Server membaca ulang dependency, memvalidasi pemilihan sumber terhadap prioritas annual/triwulan dan jabatan terbaru, melakukan segmentasi, resolve rule/histori, dan menghitung ulang sebelum save. Pilihan browser tidak boleh mengalahkan sumber yang diwajibkan aturan bisnis.

Pada transaksi save wajib berlaku:

`pak_conversion_runs.pak_rule_set_id = pak_cases.pak_rule_set_id`

Perbandingan menggunakan case yang telah dikunci dan dibaca ulang dalam transaksi. Rule set case harus tersedia dan referensinya sah. Case tanpa pilihan rule set dapat dipreview untuk diagnostics, tetapi belum dapat menyimpan run.

Jika case, predecessor, pilihan rule, atau dependency berubah sejak preview, save ditolak dan pengguna diminta mempreview ulang.

### Precision dan presentation

Gunakan exact rational arithmetic melalui `brick/math`, dengan input decimal string.

**CALCULATION:** simpan/hitung presisi penuh, jangan membulatkan setiap baris, jumlahkan hasil presisi penuh, lalu rounding hanya pada output/total akhir dengan ROUND_HALF_UP maksimum tiga angka desimal.

**DISPLAY:** hapus trailing zero dan gunakan koma desimal: `100.000 -> 100`, `18.750 -> 18,75`, `12.500 -> 12,5`, `20.833 -> 20,833`. Formatting tidak mengubah nilai calculation dan tidak menjadi operand penjumlahan.

`AK = month_count / 12 × predicate_factor × annual_coefficient`

- Simpan numerator/denominator eksak untuk line dan total.
- Total eksak merupakan jumlah nilai eksak.
- BR-KONV-004 disahkan: jumlahkan nilai presisi penuh tanpa membulatkan komponen; bulatkan satu kali pada output total akhir, maksimal tiga desimal, ROUND_HALF_UP (digit berikutnya 5 menaikkan digit yang dipertahankan).
- Profil tampilan berversi tetap **PREVIEW_ONLY** untuk scope lifecycle. Gunakan koma desimal dan trim trailing zeros yang tidak signifikan; formatting baris tidak mengubah nilai tersimpan maupun operand total.
- Nilai tampilan tidak dipakai sebagai dasar rekalkulasi.
- Jangan memakai PHP float atau angka browser sebagai sumber authoritative.
- Jelaskan potensi selisih antara jumlah baris bulat dan pembulatan total eksak.
- BR-KONV-004 kini keputusan bisnis client; penetapannya tidak memperluas scope menjadi finalisasi PAK.

Contoh presentation: `100.000 -> 100`, `18.750 -> 18,75`, `12.500 -> 12,5`, `20.830 -> 20,83`, `20.833 -> 20,833`, `0.500 -> 0,5`, `0.000 -> 0`. Contoh rounding total: `18.7544 -> 18,754`, `18.7545 -> 18,755`, `18.7546 -> 18,755`. Nilai baris yang dibulatkan untuk display tidak pernah dijumlahkan sebagai total.

Golden Nurmaulida tetap numeric fixture: koefisien 25 berasal dari bukti contoh, bukan mapping JF rekaan.

### Regime TMT, personnel source, dan guidance

- BR-REGIME-001: **TMT Jabatan < 2023-01-01 = LEGACY / AK Integrasi; TMT Jabatan >= 2023-01-01 = PERFORMANCE CONVERSION / konversi predikat kinerja.** AK Integrasi adalah nilai existing, tidak dihitung ulang/dimasukkan ke total oleh Conversion Engine Phase 02. Jangan membuat calculator aturan lama; kasus tersebut ditahan untuk review.
- Ini klasifikasi bisnis saat ini, bukan asumsi regulasi tidak akan berubah. Rule/version dan provenance tetap dapat dikembangkan/diaudit; tidak ada pemilihan rule set otomatis.
- BR-DATA-001: dokumen resmi nantinya membutuhkan KARPEG/Nomor Seri Karpeg, TEMPAT LAHIR, TGL LAHIR, TMT PANGKAT/GOL, TMT JABATAN -> SK dari DUK/full personnel source. Catat sumber/batch/versi atau dokumen, field asli, tanggal acuan, dan verifikasi. TMT pangkat, TMT jabatan, tanggal SK dan nomor SK tetap terpisah. Tidak mengubah importer pada checkpoint ini.
- Default Unit Kerja: **Balai Besar Penjaminan Mutu Pendidikan Provinsi Jawa Timur**. Default Instansi: **Kementerian Pendidikan Dasar dan Menengah**. Tandai provenance sebagai default client, bukan bukti historis verified; snapshot lama tetap utuh.
- BR-REF-001: materi resmi/PPT perlu tersedia sebagai referensi Admin PAK: ketentuan AK perpindahan ke JF, konversi predikat, kebutuhan AK KP/KJ model konversi, dan tabel lain yang diidentifikasi dari sumber. Simpan metadata referensi/versi/lingkup.
- Bedakan rule engine Phase 02 (predikat, jenjang, faktor/koefisien versi eksplisit, konversi, rounding), guidance administrator, dan calculator fase berikutnya (perpindahan, AK Dasar, target KP/KJ, integrasi/akumulasi/penetapan). Tabel referensi tidak otomatis menjadi calculator aktif. Tidak ada implementasi UI guidance pada checkpoint dokumentasi ini.

## 3. Proposed data, services, permissions, dan UI

### Tables dan migrations yang diusulkan

Tujuh tabel baru, masing-masing melalui migration additive. Phase 02A mencakup `performance_evaluations` serta dua supporting tables import; empat tabel rule/conversion tetap pada checkpoint berikutnya:

| Tabel | Isi utama |
|---|---|
| `performance_evaluations` | Person, coverage, jenis/sifat sumber, predikat, `previous_version_id`, evidence private, actor/time |
| `pak_skp_import_batches` | Provenance workbook/operasi import: private storage reference, nama asli, checksum, versi adapter/schema, status, actor/time, summary/error metadata aman |
| `pak_skp_import_rows` | Occurrence per record: batch, lokasi sumber, person resolution, source identifiers, source/content fingerprint, normalized/raw provenance minimum, status/issues, evaluasi nullable |
| `performance_predicate_rules` | Rule set, kode/label predikat, faktor decimal |
| `ak_coefficient_rules` | Rule set, jenjang, koefisien tahunan decimal |
| `pak_conversion_runs` | Case, revision number, `predecessor_run_id`, rule set, status, input snapshot, issues, fingerprint, total eksak/preview nullable |
| `pak_conversion_periods` | Run, sumber, histori, range, bulan, rule snapshot, hasil eksak/preview nullable, trace |

Field fingerprint minimum pada proposed schema `performance_evaluations`:

| Field | Kontrak |
|---|---|
| `source_identity_fingerprint` | `CHAR(64) NOT NULL`, indexed non-unique |
| `content_fingerprint` | `CHAR(64) NOT NULL` |
| `current_source_identity_fingerprint` | `CHAR(64)` nullable, UNIQUE; CURRENT = source identity fingerprint, SUPERSEDED = NULL |

Historical source/content fingerprint tetap tersimpan saat SUPERSEDED. Nullable source/content fingerprint hanya berlaku pada row import yang gagal sebelum fingerprint valid dapat dibentuk, bukan pada domain PerformanceEvaluation.

Persistence import Phase 02A:

- `pak_skp_import_batches`: `id`, `disk`, `object_key` private, `original_filename`, `checksum_sha256`, `adapter_key`, `adapter_version`, `schema_version`, `status`, `created_by`, nullable `committed_by`, `created_at`, `updated_at`, nullable `committed_at`, serta `summary` dan `error_metadata` tersanitasi. Status mencerminkan lifecycle import, bukan approval/finalisasi PAK. Storage reference hanya metadata internal terotorisasi, bukan payload audit.
- `pak_skp_import_rows`: `id`, `batch_id`, `record_ordinal`, `source_sheet`, `source_row`/`source_locator`, nullable `person_id` ke `people.id`, `person_resolution_status`, nullable `match_method`, `source_reference`, nullable `source_record_identifier`, `source_identity_fingerprint`, `content_fingerprint`, `normalized_payload`, `raw_provenance` minimum, `processing_status`, structured `issue_codes`/error metadata aman, nullable `performance_evaluation_id`, dan timestamps. Field sumber/fingerprint boleh null pada row invalid jika belum dapat dibentuk; evaluasi domain tetap wajib memenuhi validasi sumber resmi.
- Duplicate, conflict, identity ambiguity, dan validation failure tetap disimpan sebagai row provenance meskipun tidak membuat evaluasi. Duplicate dapat merujuk evaluasi existing; conflict/ambiguity tidak dipaksa memiliki `performance_evaluation_id`.
- Unique `(batch_id, record_ordinal)`; index `batch_id`, `person_id`, `performance_evaluation_id`, `source_identity_fingerprint`, dan `content_fingerprint`; index batch `(status, created_at)`. Fingerprint row tidak unique karena occurrence lintas import harus dipertahankan.
- Batch/row provenance disimpan terpisah dari transaksi pembuatan/revision evaluasi. Jika transaksi domain rollback, hasil failure/classification dicatat setelah rollback dalam transaksi provenance tersendiri; tidak meninggalkan evaluasi parsial. Failed batch dan row retained tidak dianggap orphan. Objek baru tanpa referensi committed mengikuti compensating cleanup yang sudah ditetapkan.
- Raw provenance hanya field yang diperlukan untuk penelusuran, bukan dump seluruh workbook. Jangan menyalin credential, rahasia, raw payload, atau storage path sensitif ke business audit.
- Urutan migration 02A: `performance_evaluations`, `pak_skp_import_batches`, lalu `pak_skp_import_rows` agar FK tersedia; migrations rule/conversion mengikuti dependensi checkpoint berikutnya. Tidak membuat migration aktual pada documentation checkpoint ini.

### Phase 02A.1 — penegasan schema dan model invariants

- `pak_skp_import_batches.disk`: `VARCHAR(50) NOT NULL` **tanpa default**. Pemilihan disk wajib eksplisit oleh service/config pada checkpoint berikutnya; model/schema tidak mengasumsikan `local` atau disk lain.
- Hash/checksum guard: `pak_skp_import_batches.checksum_sha256` wajib lowercase hexadecimal tepat 64 karakter (`[0-9a-f]{64}`). `pak_skp_import_rows.source_identity_fingerprint` dan `content_fingerprint`, bila non-null, wajib memenuhi format yang sama. Guard ini memvalidasi format, bukan mengimplementasikan hashing/serializer pada 02A.1.
- Row dengan `processing_status` READY, IMPORTED, DUPLICATE, atau CONFLICT wajib memiliki `person_resolution_status=MATCHED`, `person_id` non-null, serta source identity dan content fingerprint non-null. IMPORTED dan DUPLICATE juga wajib memiliki `performance_evaluation_id`; CONFLICT tidak wajib menghasilkan evaluasi. Retensi row invalid/ambiguity sebelum identity/content dapat dibentuk tetap dipertahankan.
- Batch berstatus COMMITTED wajib memiliki `committed_by` dan `committed_at`. Pada status non-COMMITTED, kedua field tersebut harus NULL dan tidak boleh mengklaim metadata commit. COMMITTED berarti operasi import telah difinalisasi sesuai kontrak import, bukan seluruh row harus IMPORTED; batch COMMITTED dapat mempertahankan row DUPLICATE, CONFLICT, atau INVALID sebagai provenance. Ini bukan finalisasi bisnis PAK.
- Normal insert `PerformanceEvaluation` hanya boleh membuat CURRENT. SUPERSEDED hanya terjadi melalui transisi CURRENT → SUPERSEDED sesuai correction lifecycle yang telah ditetapkan; normal model create tidak boleh langsung membuat SUPERSEDED.
- Direct SQL/bulk query bukan jalur penulisan domain yang sah. Model guards tidak dianggap melindungi operasi yang melewati Eloquent events; `PakModel` global tidak diubah pada 02A.1. Penegasan ini tidak mengimplementasikan service, serializer, atau executable test pada documentation checkpoint.

Constraint utama:

- FK memakai `RESTRICT`.
- Unique rule set + predicate code; rule set + functional level.
- Unique `performance_evaluations.previous_version_id`, nullable.
- Index non-unique `performance_evaluations.source_identity_fingerprint`; unique nullable `current_source_identity_fingerprint` sesuai lifecycle CURRENT/SUPERSEDED.
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

- `SkpWorkbookAdapter`: membaca struktur workbook client dan menghasilkan normalized SKP source rows beserta raw provenance minimum/lokasi sumber; tidak menentukan source selection conversion dan tidak menghitung AK.
- `SkpImportService`: mengelola batch/row provenance, person/source identity resolution, canonical serialization melalui serializer terpusat, source/content fingerprint, duplicate/conflict/identity-ambiguity classification, initial-import concurrency, serta storage compensation. Delegasikan create atau explicit revision domain record ke `PerformanceEvaluationService`; import tidak melakukan auto-revision. Source resolution di sini adalah identifikasi record sumber, bukan pemilihan predikat untuk bulan konversi.
- `SkpFingerprintSerializer`: satu implementasi canonical serialization yang menghasilkan source identity dan content fingerprint dengan kontrak SHA-256 lowercase hexadecimal 64 karakter; mempertahankan seluruh normalization rules di atas. Current fingerprint hanya lifecycle projection yang menyalin source fingerprint atau NULL, bukan output hash independen.
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

`PerformanceEvaluationService` tetap menjadi pemilik lifecycle/correction evaluasi. Backend import/source foundation, adapter contract, batch/row persistence, identity/idempotency/concurrency, storage/evidence contract, dan automated domain/integration tests termasuk 02A. Filament upload/preview/commit, import history, evidence upload/download, dan presentation/audit UI tetap 02D.

Kontrak utama conversion (bukan preview import yang menyimpan batch/row provenance):

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
| Kontrak permission provisional historis; bukan alur input aktif Phase 02 | `pak.skp.manage` + `pak.skp.provisional` tetap dipertahankan |
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

Berkas baru mencakup tujuh model dan tujuh migration untuk tujuh tabel di atas, policies sesuai batas akses, DTO/enums, backend adapter/import/serializer serta services/queries di atas, resource/pages SKP dan konversi, controller evidence, views, serta test/fixtures. Resource/pages dan UI import/evidence tetap deliverable 02D; bukan implementasi 02A.

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
| **02A — SKP/source** | Backend import/source foundation, adapter contract, batch/row persistence, coverage, source lifecycle/correction, identity/idempotency/concurrency, authorization, storage/evidence contract; automated domain/integration tests |
| **02B — Rule + calculator** | Child rules, permanent lock, explicit lookup, exact arithmetic, presentation policy; golden numeric tests |
| **02C — Run/segmentation** | Full-month resolver, issues, statuses, replacement chain, server-authoritative save, equality rule case/run; integration tests |
| **02D — UI/evidence/audit** | Filament upload/preview/commit UI, import history UI, evidence upload/download UI, presentation/audit UI, comparison, dashboard review; Livewire dan authorization tests |
| **02E — Regression/MySQL/UAT** | Full suite, upgrade/fresh schema di DB terisolasi, MySQL races, UAT, dokumentasi hasil |

### Test wajib

**Phase 02A — acceptance/design cases (spesifikasi, bukan executable tests)**

Pada documentation checkpoint ini cases berikut belum executable. Seluruh acceptance/design cases Phase 02A **WAJIB menjadi executable automated tests** ketika checkpoint 02A diimplementasikan, termasuk domain/integration tests dan pengujian concurrency MySQL yang relevan.

- Batch/row persistence mempertahankan duplicate, conflict, identity ambiguity, dan validation failure tanpa mewajibkan evaluasi baru; failed batch/row tetap dapat ditelusuri setelah rollback domain.
- Source identity dan content fingerprint hanya dibentuk melalui serializer terpusat dengan output SHA-256 lowercase hexadecimal 64 karakter; current fingerprint merupakan salinan source fingerprint atau NULL, tanpa re-hash independen.
- Valid domain evaluation tidak dapat disimpan tanpa source identity atau content fingerprint.
- CURRENT selalu memiliki current fingerprint yang sama dengan source identity; SUPERSEDED selalu memiliki current fingerprint NULL, sementara historical source/content fingerprint tetap ada.
- Ordinary correction dengan source identity yang sama dan canonical content berbeda tetap dapat membuat successor sesuai correction lifecycle; perubahan terhadap masing-masing identity-defining field ditolak setelah predecessor dikunci, tanpa mengubah predecessor atau membuat successor.
- Ordinary correction mempertahankan source identity dan mengubah content fingerprint hanya ketika canonical content berubah; perubahan kosmetik whitespace tidak menghasilkan fingerprint baru.
- Ordinary correction dengan identity sama tetapi canonical content sama persis ditolak sebagai `NO_CHANGES`, termasuk bila hanya revision reason yang berubah. Predecessor tetap CURRENT, current fingerprint tetap sama, tidak ada successor atau partial lifecycle mutation, dan tidak ada correction audit seolah-olah data berubah. Perubahan kosmetik whitespace yang menghasilkan canonical content sama juga merupakan no-op.
- Setelah correction, re-import payload predecessor diklasifikasikan terhadap CURRENT successor sebagai duplicate atau `SOURCE_CONTENT_CONFLICT` sesuai canonical content, bukan membuat independent CURRENT baru.
- Source identity sama/content sama menghasilkan duplicate; identity sama/content berbeda menghasilkan `SOURCE_CONTENT_CONFLICT`; identity berbeda dapat menjadi evidence independen setelah validasi.
- Concurrent initial import identik menghasilkan tepat satu CURRENT evaluation; request yang terkena unique conflict melakukan re-query dan menjadi duplicate, bukan generic failure.
- Concurrent initial import dengan identity sama/content berbeda menghasilkan satu CURRENT dan conflict pada request lainnya; tidak overwrite atau auto-correction.
- Dua concurrent corrections terhadap predecessor yang sama hanya satu berhasil; nullable unique `previous_version_id` mencegah dua direct successors.
- Correction atomik mengosongkan current fingerprint predecessor, membuat successor CURRENT beserta predecessor link/current fingerprint, dan menyimpan actor/time/reason/audit. Failure di setiap tahap memulihkan predecessor dan tidak meninggalkan successor parsial.
- Canonical serialization konsisten untuk fixed fields/order, UTF-8, explicit null, dan tanggal ISO. Leading zero serta kapitalisasi identifier dipertahankan; row order, workbook name, batch ID, dan import order tidak membentuk identifier.
- Perbedaan surrounding/repeated whitespace pada descriptive text tidak menimbulkan false content conflict; raw/original value tetap dapat ditelusuri sebagai provenance.
- Object baru berhasil ditulis lalu DB gagal: orphan baru tanpa referensi dibersihkan; object existing/reused dan evidence historis tetap utuh.
- Cleanup pertama gagal: retry memeriksa ulang referensi dan tidak menghapus object yang telah direferensikan. Hasil commit yang belum pasti direkonsiliasi sebelum cleanup.
- Workbook batch gagal dengan metadata committed tetap dipertahankan; failure tidak mengubah file lama, evidence lama, atau predecessor.
- Adapter acceptance menggunakan sanitized representative workbook dengan struktur nyata client yang telah diverifikasi, bukan fixture sintetis saja.

**Calculation dan segmentasi**

- Golden Nurmaulida: **4.167, 25, 25, 20.833; total eksak 75**.
- Tidak membuat mapping JF agar koefisien golden cocok.
- TC-PAK-002/003/004/005/006/027 sesuai boundary konversi.
- Empat triwulan, annual, annual-subset, tanpa source provisional terpisah; bukti historis tetap dipertahankan.
- Bulan Februari/tahun kabisat, pergantian tahun, satu bulan, dan akhir bulan.
- TMT awal/tengah/akhir bulan menggunakan jabatan/jenjang terbaru untuk bulan penuh; requested range parsial lain tetap review.
- Annual tersedia mengalahkan triwulan termasuk subset; tanpa annual memakai triwulan terakhir relevan; Januari–Oktober menggunakan Q3 tanpa provisional ketika Q4/annual belum ada.
- Beberapa record annual dan histori dipilih menurut TMT terbesar yang berlaku pada bulan tersebut, termasuk beberapa perubahan dalam bulan sama; bukan urutan record/ID/import/Excel.
- Annual datang setelah run triwulan: angka dan source evidence lama tidak berubah; tidak ada auto-update/replacement. Kebijakan refresh/revision tetap pending BR-SKP-008.
- AK Integrasi existing tidak dihitung ulang atau ditambahkan engine Phase 02; klasifikasi regime menyimpan provenance TMT dan versi.
- ROUND_HALF_UP total-only, pembeda jumlah raw vs jumlah display, trim trailing zeros, serta seluruh contoh BR-KONV-004.
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

- Permission matrix sumber resmi/retensi historis dan dependency read permissions; permission provisional yang dipertahankan tidak membuka source provisional baru.
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

Tim SDM memverifikasi sumber, provenance, revisi, annual-subset, fallback tanpa provisional terpisah, segmentasi per bulan, golden numerik, trace, tampilan pending BR-SKP-008, private evidence, dan metric review.

UAT juga memeriksa SDM-only, PAK-only, mixed permissions, inactive account, dua workspace, dan operasi SDM existing.

Acceptance teknis mensyaratkan kalkulasi eksak benar, tidak double-count, correction/replacement chain tidak bercabang, histori stabil, authorization/audit lulus, dan tidak ada regression. Hasil hanya berstatus preview/review; tidak ada klaim business finalization.

## 5. Business rules, pending decisions, dan risks

### Kontrak yang sudah ditetapkan

Mengikuti status pada dokumen repository:

- `BR-TERM-001/002`: pemisahan istilah.
- `BR-ACTOR-001`: pengelolaan oleh Tim SDM.
- `BR-SKP-001–007/009/010`: evaluasi, prioritas annual/subset dan triwulan terakhir, pemilihan annual terkait jabatan terbaru, non-overlap, pengecualian provisional, dan retensi.
- `BR-PERIOD-002`: explicit date range.
- `BR-PRED-002`, `BR-KOEF-002/003`: versioning, master lookup, snapshot.
- `BR-KONV-003/004/005`: precision, ROUND_HALF_UP total-only dengan trimming, dan banyak detail.
- `BR-JAB-001`, `BR-REGIME-001`, `BR-DATA-001`, `BR-REF-001`: jenjang terbaru/bulan penuh, regime TMT, kebutuhan provenance personnel/default organisasi, dan pemisahan guidance dari engine.
- `BR-CASE-001–003`: case, identity, snapshot.
- `BR-AUDIT-001–003`: provenance dan audit.

Keputusan teknis correction/replacement chain, permission baseline, dan audit privacy tetap. Pembaruan client 23 Oktober 2026 mengunci annual subset, jabatan terbaru/TMT tengah bulan, dan BR-KONV-004; pending lain tidak berubah dan tidak ada klaim verifikasi regulasi baru.

### PENDING BUSINESS DECISION

| Area | Batas Phase 02 |
|---|---|
| Faktor, koefisien, formula berstatus REFERENCE | Tidak otomatis menjadi master produksi yang disahkan |
| Dasar koefisien Nurmaulida | Numeric fixture saja |
| Requested range dengan batas hari parsial selain akibat TMT | Belum dikunci; TMT tengah/akhir bulan sendiri sudah dihitung bulan penuh |
| Lintas versi regulasi | Tidak ada automatic resolver |
| `BR-PERIOD-003` | Tidak memaksakan rolling 36 bulan |
| `BR-SKP-008` | Kebijakan refresh/revision calculation/draft triwulan saat annual tersedia kemudian; tidak ada auto-update dan bukti sumber lama tetap |
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
