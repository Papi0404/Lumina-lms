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
 * Universal Dedicated CBT Exam Player & Proctoring Room.
 *
 * Distraction-free exam interface with automatic anti-cheat lockdown,
 * question palette, countdown clock, doubt flagging, and real-time proctor beacon.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/cbt_engine.php');

use local_lumina_cbt\cbt_engine;

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$session = cbt_engine::start_or_get_session((int)$course->id, (int)$cm->id, (int)$USER->id);

// Load questions (from Quiz, H5P, or fallback)
$rawquestions = cbt_engine::get_exam_questions((int)$cm->id);

// Decode server-persisted answers if any
$serversavedanswers = [];
$serversaveddoubtful = [];
if (!empty($session->adminreviewnotes) && $session->status === 'in_progress') {
    $decoded = json_decode($session->adminreviewnotes, true);
    if (!empty($decoded['progress'])) {
        $serversavedanswers = $decoded['answers'] ?? [];
        $serversaveddoubtful = $decoded['doubtful'] ?? [];
    }
}

// Omit correct_index from client-side payload for anti-cheat security
$clientquestions = [];
foreach ($rawquestions as $q) {
    $clientquestions[] = [
        'id'       => (int)$q['id'],
        'number'   => (int)$q['number'],
        'question' => $q['question'],
        'options'  => $q['options'],
        'maxmark'  => (float)($q['maxmark'] ?? 1.0),
    ];
}

$PAGE->set_url('/local/lumina_cbt/exam_view.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_title('CBT Ujian Terpantau: ' . format_string($cm->name));
$PAGE->set_pagelayout('base');

$ismanager = has_capability('moodle/course:manageactivities', $context) || is_siteadmin();

// Teacher Self-Reset Simulation Handler
if ($ismanager && optional_param('resetsim', 0, PARAM_INT)) {
    $sessiontable = cbt_engine::get_session_table();
    $warningtable = cbt_engine::get_warnings_table();
    $DB->update_record($sessiontable, [
        'id'               => $session->id,
        'status'           => 'in_progress',
        'warningcount'     => 0,
        'score'            => null,
        'timefinish'       => null,
        'adminreviewnotes' => '',
    ]);
    $DB->delete_records($warningtable, ['sessionid' => $session->id]);
    redirect(new moodle_url('/local/lumina_cbt/exam_view.php', ['cmid' => $cmid]));
}

echo $OUTPUT->header();

// State A: Exam already submitted / completed
if ($session->status === 'completed' || $session->status === 'approved') {
    ?>
    <div style="min-height: 80vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; background: #F8FAFC; padding: 2rem;">
        <div style="max-width: 580px; width: 100%; background: #fff; border-radius: 20px; padding: 2.5rem; text-align: center; box-shadow: 0 12px 32px rgba(15,23,42,0.08); border: 1px solid #E2E8F0;">
            <div style="width: 68px; height: 68px; border-radius: 50%; background: #DCFCE7; color: #16A34A; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 1.25rem;">
                ✓
            </div>
            <h2 style="font-weight: 700; color: #0F172A; margin-bottom: 0.5rem;">Ujian Telah Diselesaikan</h2>
            <p style="color: #64748B; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;">
                Jawaban Anda telah berhasil dikumpulkan dan diproses oleh sistem CBT Terpantau.
            </p>
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14px; padding: 1.25rem; margin-bottom: 1.75rem;">
                <div style="font-size: 0.85rem; color: #64748B; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Nilai Akhir Ujian</div>
                <div style="font-size: 2.75rem; font-weight: 800; color: #4338CA; line-height: 1.2; margin: 0.25rem 0;">
                    <?php echo number_format((float)($session->score ?? 0), 2); ?>
                </div>
                <div style="font-size: 0.85rem; color: #10B981; font-weight: 600;">Status: Selesai &amp; Nilai Masuk Gradebook</div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" style="background: #4338CA; color: #fff; padding: 10px 22px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    Kembali ke Kelas
                </a>
                <?php if ($ismanager): ?>
                <a href="<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/exam_view.php?cmid=<?php echo $cmid; ?>&resetsim=1" style="background: #F1F5F9; color: #334155; padding: 10px 22px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem; border: 1px solid #CBD5E1;">
                    🔄 Reset Simulasi (Pengajar)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// State B1: Exam Disqualified / Invalidated by Admin
if ($session->status === 'invalidated') {
    ?>
    <div style="min-height: 80vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; background: #0B132B; padding: 2rem; color: #fff;">
        <div style="max-width: 540px; width: 100%; background: #1E293B; border-radius: 20px; padding: 2.5rem; text-align: center; box-shadow: 0 20px 48px rgba(0,0,0,0.6); border: 2px solid #EF4444;">
            <div style="width: 76px; height: 76px; border-radius: 50%; background: rgba(239,68,68,0.15); color: #EF4444; display: inline-flex; align-items: center; justify-content: center; font-size: 38px; margin-bottom: 1.25rem; border: 1px solid rgba(239,68,68,0.3);">
                🚫
            </div>
            <h2 style="font-weight: 800; color: #F8FAFC; margin-bottom: 0.75rem;">Akses Ujian Dibekukan</h2>
            <div style="background: rgba(239,68,68,0.1); border-left: 4px solid #EF4444; padding: 14px 18px; border-radius: 8px; text-align: left; color: #FCA5A5; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem;">
                Anda ujiannya telah dibekukan karna melanggar integritas, silahkan hubungi admin.
            </div>
            <p style="color: #94A3B8; font-size: 0.9rem; line-height: 1.5; margin-bottom: 1.75rem;">
                Sesi ujian Anda telah dibatalkan secara permanen oleh pengawas dan nilai didiskualifikasi (0.00).
            </p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" style="background: #EF4444; color: #fff; padding: 12px 28px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 0.95rem; box-shadow: 0 4px 14px rgba(239,68,68,0.4);">
                    Kembali ke Beranda Kelas
                </a>
                <?php if ($ismanager): ?>
                <a href="<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/exam_view.php?cmid=<?php echo $cmid; ?>&resetsim=1" style="background: #334155; color: #F1F5F9; padding: 12px 22px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    🔄 Reset Simulasi (Pengajar)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// State B2: Exam Locked Out / Suspended (3-strikes reached)
if ($session->status === 'under_review') {
    ?>
    <div style="min-height: 80vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; background: #0F172A; padding: 2rem; color: #fff;">
        <div style="max-width: 540px; width: 100%; background: #1E293B; border-radius: 20px; padding: 2.5rem; text-align: center; box-shadow: 0 20px 48px rgba(0,0,0,0.5); border: 1px solid rgba(239,68,68,0.4);">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: rgba(239,68,68,0.15); color: #EF4444; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 1.25rem;">
                🔒
            </div>
            <h2 style="font-weight: 700; color: #F8FAFC; margin-bottom: 0.75rem;">Sesi Ujian Ditangguhkan</h2>
            <p style="color: #94A3B8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem;">
                Sesi ujian Anda telah dikunci oleh sistem proctoring karena terdeteksi <?php echo (int)$session->warningcount; ?> kali pelanggaran (beralih jendela / keluar fullscreen / pintasan tombol).
            </p>
            <div style="background: #0F172A; border: 1px solid #334155; border-radius: 12px; padding: 1rem; color: #CBD5E1; font-size: 0.88rem; margin-bottom: 1.75rem;">
                Silakan hubungi Pengawas Ujian di ruangan Anda untuk memverifikasi dan membuka kunci (Unlock) ujian.
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <button onclick="window.location.reload();" style="background: #4338CA; color: #fff; border: none; padding: 10px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                    Periksa Status Buka Kunci
                </button>
                <?php if ($ismanager): ?>
                <a href="<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/exam_view.php?cmid=<?php echo $cmid; ?>&resetsim=1" style="background: #334155; color: #F1F5F9; padding: 10px 22px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    🔄 Reset Simulasi (Pengajar)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// State B2: No extracted multiple choice questions (Direct to native proctored runner)
if (empty($clientquestions)) {
    $nativeurl = $cm->url ?: (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]));
    ?>
    <div style="min-height: 80vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, sans-serif; background: #0F172A; padding: 2rem; color: #fff;">
        <div style="max-width: 580px; width: 100%; background: #1E293B; border-radius: 20px; padding: 2.5rem; text-align: center; box-shadow: 0 20px 48px rgba(0,0,0,0.5); border: 1px solid rgba(99,102,241,0.3);">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: rgba(99,102,241,0.15); color: #818CF8; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 1.25rem;">
                🛡️
            </div>
            <h2 style="font-weight: 700; color: #F8FAFC; margin-bottom: 0.75rem;">Aktivitas Ujian Interaktif</h2>
            <p style="color: #94A3B8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem;">
                Aktivitas <strong><?php echo s($cm->name); ?></strong> dikonfigurasi melalui modul <code><?php echo s($cm->modname); ?></code>. Seluruh pengerjaan akan tetap diawasi secara langsung oleh Proctoring &amp; Real-time Audit Anti-Curang.
            </p>
            <div style="background: #0F172A; border: 1px solid #334155; border-radius: 12px; padding: 1rem; color: #CBD5E1; font-size: 0.88rem; margin-bottom: 1.75rem;">
                Perlindungan anti-curang (kunci layar, deteksi tab baru, larangan copy-paste) tetap aktif mengamankan sesi Anda.
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo $nativeurl->out(false); ?>" style="background: #4F46E5; color: #fff; padding: 12px 28px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 0.95rem; box-shadow: 0 4px 14px rgba(79,70,229,0.4);">
                    Buka Halaman Ujian Terpantau 🚀
                </a>
                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" style="background: #334155; color: #F1F5F9; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    Kembali ke Kelas
                </a>
            </div>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// State C: Active In-Progress CBT Room
?>
<style>
/* Reset & Modern Minimalist Exam Canvas */
#page-header, .navbar, .drawer, #nav-drawer, footer, #page-footer {
    display: none !important;
}
html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: #0B132B !important;
    color: #1E293B;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    user-select: none;
    -webkit-user-select: none;
    overflow-x: hidden;
}

.cbt-container {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: #F8FAFC;
}

/* CBT Top Bar */
.cbt-header {
    background: #0F172A;
    color: #fff;
    padding: 0.75rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    z-index: 100;
}
.cbt-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}
.cbt-logo-badge {
    width: 36px;
    height: 36px;
    background: #4338CA;
    color: #fff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.1rem;
}
.cbt-title {
    font-weight: 700;
    font-size: 1rem;
    color: #F8FAFC;
}
.cbt-course-sub {
    font-size: 0.75rem;
    color: #94A3B8;
}

.cbt-timer-box {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #1E293B;
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 700;
    font-size: 1.1rem;
    color: #38BDF8;
}

/* Main Split View */
.cbt-body {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* Question Area */
.cbt-question-area {
    flex: 1;
    padding: 2rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.cbt-card {
    background: #FFFFFF;
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid #E2E8F0;
    box-shadow: 0 4px 12px rgba(15,23,42,0.03);
    margin-bottom: 1.5rem;
}

.cbt-qnum-badge {
    display: inline-block;
    padding: 4px 12px;
    background: #EEF2FF;
    color: #4338CA;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
    margin-bottom: 1rem;
}

.cbt-qtext {
    font-size: 1.125rem;
    font-weight: 600;
    color: #0F172A;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}

.cbt-option {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    border: 1.5px solid #E2E8F0;
    border-radius: 12px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.15s ease;
    background: #FFFFFF;
}
.cbt-option:hover {
    border-color: #CBD5E1;
    background: #F8FAFC;
}
.cbt-option.selected {
    border-color: #4338CA;
    background: #EEF2FF;
    color: #312E81;
}
.cbt-opt-label {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #F1F5F9;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.88rem;
    flex-shrink: 0;
}
.cbt-option.selected .cbt-opt-label {
    background: #4338CA;
    color: #FFFFFF;
}

/* Navigation Controls */
.cbt-nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-top: 1rem;
}

/* Palette Sidebar */
.cbt-palette-sidebar {
    width: 320px;
    background: #FFFFFF;
    border-left: 1px solid #E2E8F0;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.cbt-palette-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin-top: 1rem;
}
.cbt-pal-btn {
    aspect-ratio: 1;
    border-radius: 10px;
    border: 1px solid #E2E8F0;
    background: #F8FAFC;
    color: #475569;
    font-weight: 700;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.cbt-pal-btn.answered {
    background: #10B981;
    color: #fff;
    border-color: #059669;
}
.cbt-pal-btn.doubtful {
    background: #F59E0B;
    color: #fff;
    border-color: #D97706;
}
.cbt-pal-btn.active {
    outline: 2px solid #4338CA;
    outline-offset: 2px;
}
</style>

<div class="cbt-container">
    <!-- Top Header -->
    <div class="cbt-header">
        <div class="cbt-brand">
            <div class="cbt-logo-badge">🛡️</div>
            <div>
                <div class="cbt-title"><?php echo format_string($cm->name); ?></div>
                <div class="cbt-course-sub"><?php echo format_string($course->fullname); ?> &bull; <?php echo fullname($USER); ?></div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 16px;">
            <div class="cbt-timer-box">
                <span>⏱️</span>
                <span id="cbt-timer-text">45:00</span>
            </div>
            <?php if ($ismanager): ?>
            <a href="?cmid=<?php echo $cmid; ?>&resetsim=1" style="background: rgba(239,68,68,0.2); color: #FCA5A5; border: 1px solid rgba(239,68,68,0.4); padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; text-decoration: none; font-weight: 600;">
                🔄 Reset Simulasi
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Body Layout -->
    <div class="cbt-body">
        <!-- Question Column -->
        <div class="cbt-question-area">
            <div>
                <div class="cbt-card">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span class="cbt-qnum-badge" id="cbt-qnum-header">Soal Nomor 1</span>
                        <button type="button" id="cbt-doubt-btn" style="background: transparent; border: 1.5px solid #F59E0B; color: #D97706; font-weight: 600; padding: 6px 14px; border-radius: 8px; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <span id="cbt-doubt-icon">⭐</span>
                            <span id="cbt-doubt-label">Tandai Ragu-ragu</span>
                        </button>
                    </div>

                    <div class="cbt-qtext" id="cbt-qtext-box">Memuat soal ujian...</div>
                    <div id="cbt-options-box"></div>
                </div>
            </div>

            <!-- Footer Controls -->
            <div class="cbt-nav-bar">
                <button type="button" id="cbt-prev-btn" style="background: #F1F5F9; color: #334155; border: 1px solid #CBD5E1; padding: 10px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                    &larr; Sebelumnya
                </button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="cbt-next-btn" style="background: #4338CA; color: #fff; border: none; padding: 10px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                        Selanjutnya &rarr;
                    </button>
                    <button type="button" id="cbt-finish-btn" style="background: #10B981; color: #fff; border: none; padding: 10px 22px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.9rem;">
                        ✓ Kumpulkan Ujian
                    </button>
                </div>
            </div>
        </div>

        <!-- Palette Sidebar -->
        <div class="cbt-palette-sidebar">
            <div>
                <h4 style="font-weight: 700; font-size: 1rem; color: #0F172A; margin: 0 0 4px 0;">Navigasi Soal</h4>
                <div style="font-size: 0.8rem; color: #64748B;">Klik nomor untuk lompat ke soal</div>

                <div class="cbt-palette-grid" id="cbt-palette-container"></div>
            </div>

            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 12px; font-size: 0.78rem;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                    <span style="width: 14px; height: 14px; border-radius: 4px; background: #10B981; display: inline-block;"></span>
                    <span>Sudah Dijawab</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                    <span style="width: 14px; height: 14px; border-radius: 4px; background: #F59E0B; display: inline-block;"></span>
                    <span>Ragu-ragu</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="width: 14px; height: 14px; border-radius: 4px; background: #F8FAFC; border: 1px solid #CBD5E1; display: inline-block;"></span>
                    <span>Belum Dijawab</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function() {
    var questions = <?php echo json_encode($clientquestions); ?>;
    var savedAnswers = <?php echo json_encode($serversavedanswers); ?> || {};
    var savedDoubtful = <?php echo json_encode($serversaveddoubtful); ?> || {};
    var sessionId = <?php echo (int)$session->id; ?>;
    var cmid = <?php echo (int)$cmid; ?>;
    var sesskey = "<?php echo sesskey(); ?>";
    var currentIndex = 0;
    var remainingSeconds = 45 * 60; // 45 minutes default

    // Initialize state
    var answers = {};
    for (var k in savedAnswers) {
        answers[parseInt(k, 10)] = parseInt(savedAnswers[k], 10);
    }
    var doubtful = {};
    for (var d in savedDoubtful) {
        doubtful[parseInt(d, 10)] = !!savedDoubtful[d];
    }

    // Render palette
    var palContainer = document.getElementById('cbt-palette-container');
    function renderPalette() {
        palContainer.innerHTML = '';
        questions.forEach(function(q, idx) {
            var btn = document.createElement('div');
            btn.className = 'cbt-pal-btn';
            btn.innerText = q.number;

            var qnum = q.number;
            if (doubtful[qnum]) {
                btn.classList.add('doubtful');
            } else if (answers[qnum] !== undefined && answers[qnum] !== null) {
                btn.classList.add('answered');
            }

            if (idx === currentIndex) {
                btn.classList.add('active');
            }

            btn.onclick = function() {
                currentIndex = idx;
                renderQuestion();
            };
            palContainer.appendChild(btn);
        });
    }

    // Render current question
    function renderQuestion() {
        var q = questions[currentIndex];
        var pointBadge = (q.maxmark && parseFloat(q.maxmark) > 0) ? ' • Bobot: ' + q.maxmark + ' Poin' : '';
        document.getElementById('cbt-qnum-header').innerText = 'Soal Nomor ' + q.number + ' dari ' + questions.length + pointBadge;
        document.getElementById('cbt-qtext-box').innerText = q.question;

        var optBox = document.getElementById('cbt-options-box');
        optBox.innerHTML = '';

        q.options.forEach(function(opt) {
            var div = document.createElement('div');
            div.className = 'cbt-option';
            if (answers[q.number] === opt.index) {
                div.classList.add('selected');
            }

            div.innerHTML = '<span class="cbt-opt-label">' + opt.label + '</span>' +
                            '<span>' + opt.text + '</span>';

            div.onclick = function() {
                answers[q.number] = opt.index;
                renderQuestion();
                saveProgress();
            };
            optBox.appendChild(div);
        });

        // Doubt flag state
        var isDoubt = !!doubtful[q.number];
        var dBtn = document.getElementById('cbt-doubt-btn');
        var dIcon = document.getElementById('cbt-doubt-icon');
        var dLabel = document.getElementById('cbt-doubt-label');
        if (isDoubt) {
            dBtn.style.background = '#F59E0B';
            dBtn.style.color = '#fff';
            dIcon.innerText = '★';
            dLabel.innerText = 'Ragu-ragu (Aktif)';
        } else {
            dBtn.style.background = 'transparent';
            dBtn.style.color = '#D97706';
            dIcon.innerText = '☆';
            dLabel.innerText = 'Tandai Ragu-ragu';
        }

        document.getElementById('cbt-prev-btn').disabled = (currentIndex === 0);
        document.getElementById('cbt-next-btn').style.display = (currentIndex === questions.length - 1) ? 'none' : 'inline-block';

        renderPalette();
    }

    // Toggle doubtful
    document.getElementById('cbt-doubt-btn').onclick = function() {
        var qnum = questions[currentIndex].number;
        doubtful[qnum] = !doubtful[qnum];
        renderQuestion();
        saveProgress();
    };

    // Nav controls
    document.getElementById('cbt-prev-btn').onclick = function() {
        if (currentIndex > 0) {
            currentIndex--;
            renderQuestion();
        }
    };
    document.getElementById('cbt-next-btn').onclick = function() {
        if (currentIndex < questions.length - 1) {
            currentIndex++;
            renderQuestion();
        }
    };

    // Auto-save progress
    function saveProgress() {
        var form = new FormData();
        form.append('action', 'save_progress');
        form.append('session_id', sessionId);
        form.append('cmid', cmid);
        form.append('sesskey', sesskey);
        form.append('answers', JSON.stringify(answers));
        form.append('doubtful', JSON.stringify(doubtful));

        fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_events.php', {
            method: 'POST',
            body: form
        }).catch(function(e){});
    }

    // Countdown Timer
    var timerEl = document.getElementById('cbt-timer-text');
    var timerInt = setInterval(function() {
        remainingSeconds--;
        if (remainingSeconds <= 0) {
            clearInterval(timerInt);
            timerEl.innerText = '00:00';
            submitExam(true);
            return;
        }
        var m = Math.floor(remainingSeconds / 60);
        var s = remainingSeconds % 60;
        timerEl.innerText = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
    }, 1000);

    // Submit handler
    document.getElementById('cbt-finish-btn').onclick = function() {
        var answeredCount = Object.keys(answers).length;
        var unans = questions.length - answeredCount;
        var confirmMsg = 'Apakah Anda yakin ingin mengumpulkan ujian?\n\n' +
                         '• Soal terjawab: ' + answeredCount + ' dari ' + questions.length + '\n' +
                         '• Soal belum dijawab: ' + unans + '\n\n' +
                         'Setelah dikumpulkan, jawaban tidak dapat diubah kembali.';
        if (confirm(confirmMsg)) {
            submitExam(false);
        }
    };

    function submitExam(isAuto) {
        var form = new FormData();
        form.append('action', 'submit_exam');
        form.append('session_id', sessionId);
        form.append('cmid', cmid);
        form.append('sesskey', sesskey);
        form.append('answers', JSON.stringify(answers));

        fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_events.php', {
            method: 'POST',
            body: form
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success) {
                alert('Ujian berhasil dikumpulkan! Nilai Anda: ' + data.score);
                window.location.reload();
            } else {
                alert(data.message || 'Gagal mengumpulkan ujian.');
            }
        })
        .catch(function(err) {
            alert('Terjadi kesalahan jaringan saat mengumpulkan ujian.');
        });
    }

    renderQuestion();

    // Initialize Anti-Cheat Proctoring Engine
    require(['local_lumina_cbt/proctor'], function(Proctor) {
        Proctor.init({
            sessionId: sessionId,
            cmid: cmid,
            courseId: <?php echo (int)$course->id; ?>,
            sesskey: sesskey,
            maxWarnings: <?php echo (int)(get_config('local_lumina_cbt', 'max_warnings') ?: 3); ?>,
            strictFullscreen: true,
            blockKeyboard: true,
            suppressAi: true,
            enableCamera: true
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
