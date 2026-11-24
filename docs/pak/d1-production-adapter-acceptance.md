# D1 — Production Client Adapter Acceptance

**Status:** BLOCKED / DEFERRED
**Tanggal keputusan:** 24 November 2026
**Branch:** `feature/pak-phase-02`

## 1. Tujuan

Checkpoint ini mendokumentasikan hasil verifikasi production client adapter untuk sumber Evaluasi Akhir SKP dari Aplikasi Pengelolaan Kinerja Kemendikdasmen.

Tujuannya adalah memastikan bahwa production adapter hanya diimplementasikan apabila struktur sumber nyata dan stable source record identifier memenuhi contract Phase 02A.2.

## 2. Sumber Data Resmi

Sumber data operasional berasal dari halaman Monitoring Evaluasi Akhir SKP pada Aplikasi Pengelolaan Kinerja.

Mekanisme export yang diketahui dan digunakan oleh admin pengelola saat ini adalah:

- PDF
- Excel

Tidak terdapat mekanisme export lain yang diketahui oleh admin pengelola. Mekanisme API atau layanan data resmi lain juga belum diketahui atau belum dapat dikonfirmasi.

## 3. Representative Workbook Evidence

Representative workbook tahun 2024 dan 2025 telah diverifikasi.

Struktur export Excel konsisten menggunakan tujuh kolom:

1. No
2. NIP
3. Nama
4. Jabatan
5. Durasi Jabatan
6. Status
7. Predikat Kinerja

Audit workbook tidak menemukan stable source record identifier pada:

- visible columns;
- hidden columns;
- hidden worksheets;
- defined names;
- workbook connections;
- custom properties;
- external links;
- hyperlinks;
- comments/notes;
- formulas.

Dengan demikian, stable source record identifier tidak tersedia atau tidak dapat dibuktikan dari official Excel export.

## 4. Source-System Identifier Evidence

Inspection pada aplikasi sumber menunjukkan bahwa action Evaluasi menggunakan identifier internal pada handler:

`dialog_print_eval(<ID>)`

dan action dokumen evaluasi menggunakan identifier yang sama:

`dialog_print_dok_eval(<ID>)`

Identifier tersebut telah diuji pada record evaluasi yang sama dan menghasilkan nilai yang tetap setelah:

- page reload;
- berpindah menu dan kembali;
- logout dan login kembali.

Uji terhadap satu pegawai yang memiliki lebih dari satu record evaluasi juga menunjukkan bahwa masing-masing record memiliki identifier yang berbeda.

Kesimpulan:

**Stable evaluation record identifier tersedia pada source application dan dibedakan per record evaluasi.**

## 5. Gap

Stable evaluation identifier yang tersedia pada source application **tidak disertakan dalam official Excel export**.

Dengan demikian:

`source application → stable identifier available`

tetapi:

`official workbook export → stable identifier unavailable`

Production workbook adapter tidak mempunyai source evidence yang cukup untuk mengisi `source_record_identifier` sesuai contract existing.

## 6. Decision

Production client workbook adapter **tidak diimplementasikan pada checkpoint ini**.

Tidak diperbolehkan membentuk `source_record_identifier` menggunakan:

- nomor urut / Excel row;
- NIP;
- nama;
- kombinasi NIP, jabatan, dan periode;
- filename;
- import order;
- batch ID;
- predicate;
- generated UUID;
- content hash;
- identifier sintetis lainnya.

Tidak dilakukan scraping UI atau integrasi terhadap endpoint/API yang belum menjadi interface resmi dan terdukung.

Existing `SkpSourceAdapter`, source DTO, normalization, fingerprint, matching, preparation, commit, dan synthetic test foundation tetap dipertahankan tanpa perubahan contract.

## 7. Acceptance Status

| Gate | Status |
|---|---|
| Representative workbook 2024 | PASS |
| Representative workbook 2025 | PASS |
| Workbook structure | PASS |
| Source-system stable identifier | PASS |
| Identifier reload stability | PASS |
| Identifier session stability | PASS |
| Distinct evaluation identity | PASS |
| Stable identifier in official Excel export | MISSING |
| Production workbook adapter acceptance | BLOCKED |
| Production implementation | HOLD |
| D1 Final | BLOCKED / DEFERRED |

## 8. Reopen Condition

D1 hanya dibuka kembali apabila salah satu kondisi berikut terpenuhi:

1. tersedia official export yang menyertakan stable evaluation identifier;
2. tersedia API atau layanan data resmi dan terdukung yang menyediakan stable evaluation identifier; atau
3. terdapat approved architecture/domain decision yang secara eksplisit mengubah existing identity contract.

Sebelum salah satu kondisi tersebut terpenuhi, tidak dilakukan production adapter implementation atau synthetic identity workaround.

## 9. Scope Consequence

Blocker berasal dari external input contract dan bukan defect pada existing Phase 02A.2 foundation.

Pekerjaan Phase 02 lain yang tidak bergantung pada production client adapter dapat tetap dilanjutkan sesuai scope dan gate masing-masing.
