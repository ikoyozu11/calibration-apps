# Instruments Desk — Email Notification Activation Runbook

**Versi:** 1.0
**Tanggal:** 9 November 2026
**Status:** **Implementation Complete — Pending Controlled Activation**
**Cakupan:** Calibration Due Email Notification, Laravel 13 / Livewire 4 / PostgreSQL
**Lingkungan yang diizinkan untuk pengujian:** `calibration_staging_db` pada `127.0.0.1:5432`

> **PENTING — Dokumen prosedur, bukan izin eksekusi.** Menjalankan preview, mengirim email nyata, menulis notification history, dan mengaktifkan scheduler memerlukan izin eksplisit sesuai tahapnya. Jangan menafsirkan keberadaan perintah di dokumen ini sebagai otorisasi untuk menjalankannya.

## 1. Ringkasan keputusan

Fitur reminder sudah diimplementasikan. Pengiriman Gmail SMTP mandiri berhasil, termasuk email uji terpisah yang **dikonfirmasi diterima** di kedua mailbox perusahaan. Integrasi Laravel telah diperiksa dan diuji secara offline. Perlindungan CLI staging-only, preflight, preview read-only, dan diagnosis eligibility pada staging juga telah diperiksa.

**Belum dilakukan:** pengiriman reminder nyata melalui command Laravel ketika ada item eligible, konfigurasi secret untuk layanan terjadwal, dan aktivasi scheduler. Fitur tetap **disabled** secara default. **Jangan mengaktifkan scheduler sekarang.**

### Bukti kesiapan terakhir

| Fase | Hasil |
|---|---|
| 5E-1 | Gmail SMTP self-test diterima di mailbox pengirim; TLS/auth/SMTP accepted pada helper mandiri |
| 5E-2 | Email uji terpisah diterima di `service.malang@vision-teknik.com` dan `Indra_Tri_Sutanto@bat.com` |
| 5F | Integrasi Laravel tersedia; offline 9 tests / 53 assertions; tidak perlu perubahan source |
| 5H | Staging-only CLI guard diperiksa; offline 22 tests / 110 assertions |
| 5H-1 | Command `calibration:preview-due-reminders` ditambahkan; offline 24 tests / 107 assertions |
| 5I-B | Preflight PASS; 2 recipients terkonfigurasi; preview read-only 0 eligible / 0 expected emails |
| 5J | Diagnosis PostgreSQL READ ONLY PASS; 10 sessions, 4 Completed, 4 latest Completed dengan due date valid; tidak ada milestone cocok pada 2026-11-09 |

**Interpretasi:** angka `0` pada 9 November 2026 merupakan hasil bisnis yang valid, bukan bukti kegagalan pengiriman. Empat due date valid tidak masuk milestone/catch-up saat itu; 340 dari 344 pasangan Instrument–Type belum memiliki kalibrasi Completed. Pengiriman reminder end-to-end melalui Laravel **belum teruji** karena tidak ada item eligible.

## 2. Perilaku fungsional yang harus dipertahankan

- Pengingat untuk **2 bulan, 1 bulan, 7 hari, 3 hari, dan hari jatuh tempo**, termasuk aturan catch-up 3 hari yang telah diimplementasikan.
- Sumber due date: `calibration.calibration_due` pada **latest Completed calibration** per pasangan Instrument–Instrument Type; urutan `calibration_date DESC`, `completed_at DESC`, `calibration_id DESC`.
- Latest Completed dengan due date `NULL` tidak boleh digantikan sesi lebih lama hanya demi mendapatkan due date.
- INTERNAL dan EXTERNAL termasuk; tidak membuat due date dari interval.
- Satu email rekap harian per penerima jika ada item yang eligible; penerima diproses secara **terpisah**, tanpa CC/BCC.
- Deduplication mempertimbangkan `instrument_type_id`, snapshot due date, milestone, dan recipient.
- History, retry definitif maksimum 3, `partial_failure`, dan `delivery_unknown` **tidak boleh diubah** dalam prosedur aktivasi.
- Hasil preview bersifat indikatif pada saat evaluasi: status/data dapat berubah sebelum dispatch. Preview tidak mengubah `processing` stale menjadi `delivery_unknown`.

## 3. Batas keamanan mutlak

1. **Jangan pernah membuka koneksi ke production `calibration_db`**, termasuk untuk query read-only, preflight, Tinker, Artisan, SQL, atau fallback koneksi default.
2. Staging hanya `calibration_staging_db`, host `127.0.0.1`, port `5432`, dengan verifikasi `current_database()` oleh guard yang sudah ada.
3. Jangan mengubah `.env` default, jangan menulis App Password ke source, Git, argumen CLI, output terminal, atau log.
4. Jangan menjalankan migration, seeder, SQL mutasi, atau membuat/mengubah sesi kalibrasi untuk memancing reminder.
5. Jangan menjalankan `calibration:send-due-reminders`, `schedule:run`, atau scheduler worker tanpa izin eksplisit **baru**.
6. Jangan melakukan retry manual atau otomatis setelah hasil SMTP ambigu (`delivery_unknown`/timeout yang tidak pasti), sebelum meninjau history dan bukti penerimaan.
7. Jangan memakai kembali izin one-time dari fase sebelumnya; seluruh izin eksekusi terdahulu telah digunakan.

## 4. Konfigurasi runtime

Konfigurasi hanya melalui environment milik **proses terisolasi** yang akan menjalankan Artisan atau scheduler. `serve-staging.ps1` menyiapkan lingkungan HTTP staging, tetapi **terminal Artisan terpisah tidak otomatis mewarisi** environment dari server tersebut.

| Variabel | Nilai/ketentuan |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_DATABASE` | `calibration_staging_db` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `5432` |
| `DB_URL`, `DATABASE_URL` | Kosongkan untuk menghindari override ambigu |
| `STAGING_RUNTIME_ISOLATION` | `1` |
| `SESSION_DRIVER` | `file` |
| `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp.gmail.com` |
| `MAIL_PORT` | `587` |
| `MAIL_SCHEME` | `smtp` (Laravel 13; jangan mengandalkan `MAIL_ENCRYPTION`) |
| `MAIL_USERNAME` | `instrumentsdesk.vt@gmail.com` |
| `MAIL_PASSWORD` | **Gmail App Password melalui prompt/secret injection saat runtime** |
| `MAIL_FROM_ADDRESS` | `instrumentsdesk.vt@gmail.com` |
| `MAIL_FROM_NAME` | `Instruments Desk \| Calibration Notification` |
| `CALIBRATION_DUE_NOTIFICATIONS_RECIPIENTS` | `service.malang@vision-teknik.com,Indra_Tri_Sutanto@bat.com` |
| `CALIBRATION_DUE_NOTIFICATIONS_ENABLED` | `false` untuk preview; `true` hanya dalam proses dispatch yang telah diotorisasi |

**Catatan:** Parser recipient dapat menormalisasi huruf menjadi lowercase; pada preview sebelumnya alamat kedua ditampilkan sebagai `indra_tri_sutanto@bat.com`. Pastikan jumlah recipient **2** dan alamat efektif sesuai tujuan. App Password tidak diperlukan untuk preview. Uji helper SMTP mandiri telah membuktikan STARTTLS dan verifikasi sertifikat pada helper; **jangan menganggap itu otomatis membuktikan kebijakan TLS identik pada proses Laravel**. Verifikasi kebijakan TLS/sertifikat Laravel sebelum aktivasi operasional dan jangan mengizinkan fallback plaintext.

**Config cache:** `bootstrap/cache/config.php` harus **tidak ada**. Guard menolak konfigurasi cached; jangan menjalankan `config:cache`, `config:show`, atau dump environment ketika secret aktif.

## 5. Tahap A — Preview read-only (memerlukan izin baca staging baru)

**Tujuan:** mengetahui jumlah reminder dan email potensial tanpa SMTP atau perubahan database.

**Prasyarat:** izin read-only spesifik; source/guard tetap sama; file config cache tidak ada; proses PowerShell baru dan terisolasi; database staging tersedia. Tidak perlu Gmail App Password.

Di root proyek, jalankan dalam **satu PowerShell terisolasi** setelah izin diberikan:

```powershell
$ErrorActionPreference = 'Stop'
$names = @(
    'DB_CONNECTION','DB_DATABASE','DB_HOST','DB_PORT','DB_URL','DATABASE_URL',
    'STAGING_RUNTIME_ISOLATION','SESSION_DRIVER','CACHE_STORE','QUEUE_CONNECTION',
    'CALIBRATION_DUE_NOTIFICATIONS_ENABLED',
    'CALIBRATION_DUE_NOTIFICATIONS_RECIPIENTS'
)
try {
    if (Test-Path '.\bootstrap\cache\config.php') {
        throw 'Cached Laravel configuration detected. STOP.'
    }
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_DATABASE = 'calibration_staging_db'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '5432'
    $env:DB_URL = ''
    $env:DATABASE_URL = ''
    $env:STAGING_RUNTIME_ISOLATION = '1'
    $env:SESSION_DRIVER = 'file'
    $env:CACHE_STORE = 'file'
    $env:QUEUE_CONNECTION = 'sync'
    $env:CALIBRATION_DUE_NOTIFICATIONS_ENABLED = 'false'
    $env:CALIBRATION_DUE_NOTIFICATIONS_RECIPIENTS = 'service.malang@vision-teknik.com,Indra_Tri_Sutanto@bat.com'

    php artisan staging:preflight
    if ($LASTEXITCODE -ne 0) { throw 'Preflight failed. Preview cancelled.' }

    php artisan calibration:preview-due-reminders
    if ($LASTEXITCODE -ne 0) { throw 'Preview failed. STOP.' }
}
finally {
    foreach ($name in $names) {
        Remove-Item "Env:$name" -ErrorAction SilentlyContinue
    }
}
```

> Jalankan ini hanya dalam PowerShell **khusus** yang akan ditutup setelahnya; penghapusan environment di `finally` tidak memulihkan nilai environment sebelumnya. Jangan jalankan dari terminal kerja yang menyimpan konfigurasi penting lain.

**Review wajib:** preflight PASS dan database tepat; recipient = 2; eligible per recipient; expected email count; milestone dan due-date breakdown; retry-eligible/skipped. Jika `0 eligible` atau `0 expected emails`, **hentikan proses—jangan lanjut dispatch hanya demi menguji email**. Jika hasil tak terduga, hentikan dan investigasi secara read-only dengan izin terpisah.

## 6. Tahap B — One-shot staging dispatch (BELUM DIIZINKAN)

**Tahap ini belum boleh dijalankan.** Memerlukan persetujuan eksplisit baru yang mencakup: akses baca staging, penulisan terbatas ke `calibration_due_notification_batches` dan `calibration_due_notification_items`, satu eksekusi `calibration:send-due-reminders`, dan pengiriman email nyata berisi data reminder ke **dua alamat perusahaan**. Pastikan kebijakan perusahaan memperbolehkan penggunaan akun Gmail pengirim.

Checklist operator sebelum menekan Enter:

- [ ] Ada preview terbaru yang menunjukkan item eligible dan expected emails yang masuk akal; tidak mengandalkan preview lama.
- [ ] Dua penerima disetujui untuk menerima isi reminder nyata.
- [ ] Ada persetujuan tertulis/eksplisit untuk **satu** dispatch; tidak mencakup retry.
- [ ] Staging-only CLI guards aktif; `bootstrap/cache/config.php` tidak ada.
- [ ] Kredensial DB staging tersedia melalui mekanisme yang sudah disetujui, tanpa mencetak secret.
- [ ] App Password dimasukkan dengan `Read-Host -AsSecureString` dan diteruskan hanya ke child process terisolasi; tidak dicatat di `.env`/source/log.
- [ ] Runtime `MAIL_*` sesuai tabel; TLS dan verifikasi sertifikat tidak dilemahkan.
- [ ] Preflight PASS pada **proses dispatch itu sendiri** sebelum menjalankan command.
- [ ] `CALIBRATION_DUE_NOTIFICATIONS_ENABLED=true` hanya di proses dispatch, lalu dibersihkan.
- [ ] Scheduler tidak berjalan dan tidak ada worker lain yang mengirim reminder bersamaan.

**Urutan eksekusi setelah diotorisasi:** siapkan isolated child process → inject staging env dan SMTP secret → jalankan `staging:preflight` → pastikan identitas DB → set flag enabled pada proses itu → jalankan **tepat satu kali** `php artisan calibration:send-due-reminders` → catat exit code dan ringkasan tanpa secret → tutup proses/bersihkan env. **Jangan** menggabungkan dispatch dengan automatic retry, looping, atau `schedule:run`.

**Jika output tidak jelas/timeout:** jangan rerun. Periksa status `sent`, `failed`, `delivery_unknown`, `partial_failure` dan history per recipient secara read-only dengan izin yang sesuai; konfirmasi penerimaan mailbox. SMTP `ACCEPTED` sendiri tidak membuktikan email sampai Inbox.

## 7. Tahap C — Aktivasi scheduler harian (BELUM DIIZINKAN)

Scheduler sudah terdaftar untuk **08:00 Asia/Jakarta** dengan `withoutOverlapping()`, tetapi **belum ada scheduler worker yang diaktifkan**. Aktivasi memerlukan persetujuan terpisah setelah one-shot Laravel E2E sukses.

Sebelum aktivasi, pastikan:

- [ ] Proses scheduler/cron yang dipilih hanya menjalankan environment **staging yang tervalidasi**, bukan default production.
- [ ] Secret injection untuk layanan terjadwal ditetapkan oleh operator/IT melalui secret store atau mekanisme terproteksi yang disetujui; **bukan** `.env` default, repository, atau command-line argument.
- [ ] Scheduler menggunakan konfigurasi Gmail yang benar, koneksi staging yang dikunci, dan timezone Asia/Jakarta.
- [ ] Kebijakan TLS dan verifikasi sertifikat Laravel telah diperiksa.
- [ ] Hanya **satu** scheduler aktif untuk lingkungan yang dituju; locking `withoutOverlapping()` menggunakan cache yang sesuai untuk proses scheduler.
- [ ] Monitoring hasil command, history per recipient, error delivery, dan jalur penghentian sudah ditetapkan.
- [ ] Ada persetujuan tertulis untuk jadwal harian dan penerima yang dituju.

**Jangan menjalankan `schedule:work` atau memasang cron/Task Scheduler saat ini.** Metode operasional final (Windows Task Scheduler, service, atau scheduler lain) harus dipilih sesuai deployment yang benar-benar digunakan. Jangan mengasumsikan bahwa server HTTP `serve-staging.ps1` otomatis menjalankan scheduler.

## 8. Monitoring, stop, dan pemulihan

**Periksa setelah pengiriman:** tanggal eksekusi (Asia/Jakarta), database yang tervalidasi, jumlah recipient, eligible items, jumlah batch, status per recipient, jumlah email yang diterima, dan warning; simpan ringkasan **tanpa credential** dan tanpa menyalin data kalibrasi yang tidak diperlukan.

**Jika terjadi masalah:**

1. Hentikan scheduler/worker terkait (jika sudah diaktifkan) dan pastikan proses dispatch selesai/terhenti.
2. Set feature flag `CALIBRATION_DUE_NOTIFICATIONS_ENABLED=false` untuk proses berikutnya; hentikan proses lama yang masih menyimpan flag `true`.
3. Hapus runtime secret dari lingkungan proses, bukan dengan mencetaknya.
4. Tinjau notification history per recipient sebelum memutuskan tindakan lanjut.
5. Jangan menghapus history, mereset attempts, atau mengirim ulang status ambigu tanpa persetujuan dan analisis dampak.
6. Jangan melakukan fallback ke production atau mengubah due date kalibrasi untuk memperbaiki hasil pengujian.

## 9. Kriteria selesai (go/no-go)

| Gate | Kriteria | Status 2026-11-09 |
|---|---|---|
| Implementasi | Milestone, selector, history, retry, multi-recipient, preview | **PASS** (berdasarkan laporan Codex) |
| SMTP mandiri | Gmail self-test dan kedua alamat perusahaan menerima email uji | **PASS** |
| Laravel offline | Unit/integration fake tests lulus | **PASS** |
| Staging read-only | Preflight, preview 2 recipients, eligibility diagnosis | **PASS** |
| Laravel E2E nyata | Ada reminder eligible, satu dispatch disetujui, hasil dan history diverifikasi | **PENDING** |
| Secret operasional | Penyimpanan/injeksi secret untuk layanan terjadwal dan kebijakan TLS disetujui | **PENDING** |
| Scheduler 08:00 | Persetujuan terpisah, satu worker, monitoring, stop procedure | **PENDING / OFF** |

**Keputusan saat ini: NO-GO untuk scheduler otomatis.** Tetap `enabled=false` sampai semua gate yang relevan terpenuhi. Jika tidak ada reminder eligible, tunggu milestone nyata atau minta desain pengujian terisolasi dengan otorisasi tersendiri; jangan membuat fixture pada data kalibrasi staging yang sebenarnya tanpa persetujuan eksplisit.

## 10. Referensi source yang perlu diperiksa saat aktivasi

- `scripts/serve-staging.ps1` — environment HTTP staging (tidak otomatis berlaku untuk Artisan terpisah).
- `SendCalibrationDueRemindersCommand.php` — command dispatch `calibration:send-due-reminders`.
- `PreviewCalibrationDueRemindersCommand.php` — command preview `calibration:preview-due-reminders`.
- `CalibrationDueReminderPreview.php` — perhitungan preview read-only.
- `CurrentCalibrationDueResolver.php`, `CalibrationDueMilestoneCalculator.php`, `CalibrationDueReminderSelector.php` — eligibility dan milestone.
- `CalibrationDueNotificationService.php`, `CalibrationDueRecipients.php`, `CalibrationDueReminderMail.php` — dispatch dan penerima.
- `CalibrationHttpConnection.php`, `CalibrationSessionConnection.php`, `StagingRuntimeIsolation.php` — perlindungan koneksi staging.
- `config/mail.php`, `config/calibration_due_notifications.php`, `bootstrap/app.php` — mail config, default disabled, scheduler.

---

**Penutupan fase dokumentasi:** tidak ada perubahan database, source aplikasi, pengiriman email, atau aktivasi scheduler yang dilakukan dengan menyusun dokumen ini. Dokumentasi dapat disimpan dalam repository, misalnya `docs/email-notification-activation-runbook.md`, setelah ditinjau dan disetujui oleh operator proyek.
