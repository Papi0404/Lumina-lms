<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Indonesian strings for local_lumina_cbt.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Lumina CBT & Anti-Cheat Proctoring Suite';
$string['cbt_proctoring'] = 'Pengawasan CBT & Audit Anti-Curang';
$string['monitor_dashboard'] = 'Pemantauan Ujian Live (Real-Time)';
$string['review_queue'] = 'Antrean Audit & Peninjauan Pengawas';
$string['settings'] = 'Pengaturan Pengawasan CBT';

// Capabilities
$string['lumina_cbt:monitor'] = 'Memantau aktivitas ujian dan pelanggaran secara langsung';
$string['lumina_cbt:review'] = 'Membuka kunci, menyetujui, me-reset, atau mendiskualifikasi ujian siswa';
$string['lumina_cbt:takeexam'] = 'Mengerjakan ujian Computer-Based Test dengan proteksi proctoring';
$string['lumina_cbt:configure'] = 'Mengonfigurasi pengaturan plugin Lumina CBT';

// General Settings
$string['universal_mode'] = 'Penegakan Otomatis CBT Universal';
$string['universal_mode_desc'] = 'Jika diaktifkan, setiap kuis (mod_quiz), H5P, atau penugasan ujian akan otomatis terkunci dalam mode CBT anti-curang tanpa bisa membuka tab lain.';
$string['max_warnings'] = 'Batas Peringatan Sebelum Terkunci';
$string['max_warnings_desc'] = 'Jumlah peringatan pelanggaran yang diizinkan sebelum sesi ujian murid otomatis ditangguhkan dan terkunci (standar: 3).';
$string['enable_fullscreen'] = 'Wajib Layar Penuh (Fullscreen)';
$string['enable_fullscreen_desc'] = 'Mewajibkan peserta ujian masuk dan bertahan di mode fullscreen sepanjang pengerjaan ujian.';
$string['block_clipboard'] = 'Blokir Copy, Cut & Paste';
$string['block_clipboard_desc'] = 'Menonaktifkan operasi papan klip dan klik kanan selama ujian berlangsung.';
$string['block_touchpad_gestures'] = 'Cegah Gesture Touchpad';
$string['block_touchpad_gestures_desc'] = 'Mendeteksi dan mengintersep sapuan 3 jari / 4 jari dan gesture minimalkan jendela pada touchpad.';
$string['block_keyboard'] = 'Kunci Keyboard (Mode Pilihan Ganda)';
$string['block_keyboard_desc'] = 'Hanya izinkan navigasi mouse untuk soal pilihan ganda guna mencegah jalan pintas curang.';
$string['suppress_ai'] = 'Bungkam Widget AI & Chatbot';
$string['suppress_ai_desc'] = 'Otomatis menyembunyikan dan menonaktifkan asisten AI selama ujian berlangsung.';
$string['sound_alerts'] = 'Bunyi Notifikasi Pelanggaran';
$string['sound_alerts_desc'] = 'Membunyikan nada dering peringatan di dasbor pengawas setiap kali siswa terdeteksi melakukan pelanggaran.';

// Infraction Event Types
$string['event_keyboard_attempt'] = 'Papan Ketik Ditekan (Diblokir)';
$string['event_three_finger_gesture'] = 'Gesture 3 Jari Touchpad';
$string['event_three_finger_gesture_or_minimize'] = 'Percobaan Minimize Layar';
$string['event_trackpad_gesture'] = 'Gesture Trackpad / Pinch Zoom';
$string['event_fullscreen_exit'] = 'Keluar Layar Penuh (Fullscreen)';
$string['event_blur'] = 'Pindah Jendela / Alt+Tab';
$string['event_visibility_hidden'] = 'Pindah Tab Browser';
$string['event_mouseleave'] = 'Kursor Keluar Batas Layar';
$string['event_clipboard_copy'] = 'Percobaan Menyalin / Copy Teks';
$string['event_devtools'] = 'Percobaan Buka DevTools (F12)';
$string['event_exam_submitted'] = 'Ujian Dikumpulkan';
$string['event_exam_invalidated'] = 'Ujian Didiskualifikasi Pengawas';
$string['event_exam_reset'] = 'Sesi Di-ACC & Di-reset (0/3)';
$string['event_exam_approved'] = 'Sesi Ujian Disetujui Pengawas';

// Proctoring Messages & Warnings
$string['warning_1'] = 'Peringatan 1 dari 3: Pelanggaran terdeteksi ({$a}). Tetap berada di dalam layar ujian!';
$string['warning_2'] = 'Peringatan 2 dari 3: Pelanggaran Kritis ({$a})! Satu pelanggaran lagi, ujian Anda akan otomatis dikunci dan dilaporkan ke pengawas!';
$string['warning_3'] = 'Peringatan 3 dari 3: Batas pelanggaran tercapai. Ujian Anda telah ditangguhkan dan dikunci untuk peninjauan Pengawas/Administrator.';
$string['locked_out_title'] = 'Ujian Ditangguhkan - Menunggu Peninjauan Pengawas';
$string['locked_out_desc'] = 'Sesi ujian Anda terkunci karena pelanggaran berulang. Silakan lapor ke pengawas ujian atau administrator di ruangan Anda untuk membuka kunci.';

// UI Navigation & Labels
$string['course_cbt_menu'] = '🛡️ Pengawasan CBT & Audit';
$string['live_feed'] = 'Live Audit Feed (Real-Time)';
$string['active_candidates'] = 'Daftar Peserta Ujian Aktif';
$string['unlocked_success'] = 'Sesi peserta berhasil dibuka kuncinya. Peringatan di-reset menjadi 0/3.';
$string['reset_success'] = 'Sesi ujian berhasil dibuka kuncinya dan di-reset menjadi 0/3.';
$string['approved_success'] = 'Sesi ujian berhasil disetujui.';
$string['invalidated_success'] = 'Sesi ujian berhasil dibatalkan dan nilai diubah menjadi 0.';
$string['restore_success'] = 'Nilai ujian berhasil dipulihkan kembali.';
$string['enter_fullscreen_btn'] = 'Masuk Layar Penuh & Mulai Ujian';
$string['submit_exam'] = 'Kumpulkan Ujian';
$string['flag_doubtful'] = 'Ragu-ragu';
$string['question_palette'] = 'Navigasi Soal';

// Per-Quiz CBT Configuration
$string['cbt_settings_header'] = 'Mode CBT Lumina (Anti-Curang)';
$string['cbt_enable'] = 'Izinkan Mode CBT';
$string['cbt_enable_help'] = 'Jika diaktifkan (Ya), ujian ini otomatis berjalan di ruang ujian CBT layar penuh dengan pengawasan anti-curang real-time (mendeteksi pindah tab, minimize, shortcut, gesture touchpad). Jika dimatikan (Tidak), ujian berjalan sebagai kuis Moodle standar biasa.';
$string['status_not_started'] = 'Belum Mengerjakan';
$string['status_in_progress'] = 'Sedang Mengerjakan';

// Foto Verifikasi & Retensi
$string['task_cleanup_photos'] = 'Pembersihan Otomatis Foto Verifikasi CBT Kadaluarsa (> 2 Minggu)';
$string['photo_retention_days'] = 'Masa Retensi Foto Verifikasi (Hari)';
$string['photo_retention_days_desc'] = 'Jumlah hari penyimpanan foto bukti dan verifikasi peserta sebelum dihapus permanen secara otomatis oleh sistem (default: 14 hari / 2 minggu).';
$string['checkpoint_photos'] = 'Otomatis Ambil 3 Foto Verifikasi Ujian';
$string['checkpoint_photos_desc'] = 'Jika diaktifkan, sistem secara otomatis mengambil foto wajah peserta pada 3 titik: saat mulai ujian, setelah 5 menit pengerjaan, dan saat pengumpulan jawaban.';


