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
 * Dedicated CBT Exam & Anti-Cheat Live Proctoring View for Lumina LMS.
 *
 * Implements:
 * 1. Strict Automatic Fullscreen Enforcement (Exit Lockout Overlay).
 * 2. Complete CBT Question Palette (1-20 jump grid, Answered/Doubtful/Unanswered states).
 * 3. Doubtful ("Tandai Ragu-ragu") Flagging per question.
 * 4. Pure CBT Exam mode (No instant "Periksa" / answer reveal).
 * 5. Complete AI chatbot suppression during exam.
 * 6. Live Proctoring 3-Warning Protocol & Admin Review Suspension.
 * 7. Final Exam Submission, Score calculation, and Result Certificate.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/exam_monitor.php');

use local_lumina_core\exam_monitor;

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$session = exam_monitor::start_or_get_session($course->id, $cm->id, $USER->id);

// Load the 20 CBT questions
$rawquestions = exam_monitor::get_exam_questions($cm->id);

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

// Prepare client-safe questions (omit correct_index from client JS)
$clientquestions = [];
foreach ($rawquestions as $q) {
    $clientquestions[] = [
        'id'       => (int)$q['id'],
        'number'   => (int)$q['number'],
        'question' => $q['question'],
        'options'  => $q['options'],
    ];
}

$PAGE->set_url('/local/lumina_core/exam_view.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_title('CBT Ujian Terpantau: ' . format_string($cm->name));
$PAGE->set_pagelayout('popup');

echo $OUTPUT->header();

// 1. If Exam is already completed or approved: Student cannot take it again without Admin Reset
if ($session->status === 'completed' || $session->status === 'approved') {
    ?>
    <style>
    .activity-navigation, #region-main > .activity-navigation, .urlselect, #luminaAiFab, .lumina-ai-fab {
        display: none !important;
    }
    .cbt-terminal-wrap {
        min-height: 85vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background: #F8FAFC;
    }
    .cbt-terminal-card {
        background: #FFFFFF;
        border-radius: 24px;
        max-width: 600px;
        width: 100%;
        padding: 2.75rem 2.25rem;
        text-align: center;
        box-shadow: 0 20px 45px -15px rgba(15, 23, 42, 0.1);
    }
    </style>
    <div class="cbt-terminal-wrap">
        <div class="cbt-terminal-card border border-success border-opacity-50">
            <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 52px;">task_alt</span>
            </div>
            <h3 class="fw-bold text-dark mb-2">Ujian Telah Selesai Dikerjakan</h3>
            <p class="text-secondary mb-3">
                Lembar jawaban untuk <strong><?php echo format_string($cm->name); ?></strong> telah berhasil dikumpulkan.
            </p>

            <div class="alert alert-light border rounded-2xl p-3 mb-3 text-start small" style="background: #F8FAFC;">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Status Sesi:</span>
                    <span class="fw-bold text-success">Selesai &amp; Tersimpan</span>
                </div>
                <?php if (!is_null($session->score)): ?>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Nilai Evaluasi:</span>
                    <span class="fw-bold text-primary"><?php echo number_format($session->score, 1); ?> / 100</span>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Waktu Pengumpulan:</span>
                    <span class="fw-semibold text-dark"><?php echo $session->timefinish ? userdate($session->timefinish, '%d %b %Y, %H:%M WIB') : '-'; ?></span>
                </div>
            </div>

            <p class="text-muted small mb-4">
                Sesuai kebijakan akademik, ujian CBT hanya dapat dikerjakan satu kali dan tidak dapat diulang kembali kecuali Administrator telah menyetujui (ACC / Reset) sesi Anda.
            </p>

            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-primary rounded-pill py-2-5 px-4 font-semibold w-100 shadow-xs">
                ← Kembali ke Dashboard Siswa
            </a>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// 2. If Exam is Suspended (under_review) or Invalidated: Locked out until Admin ACC / Reset
if ($session->status === 'under_review' || $session->status === 'invalidated') {
    ?>
    <style>
    .activity-navigation, #region-main > .activity-navigation, .urlselect, #luminaAiFab, .lumina-ai-fab {
        display: none !important;
    }
    .cbt-terminal-wrap {
        min-height: 85vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background: #F8FAFC;
    }
    .cbt-terminal-card {
        background: #FFFFFF;
        border-radius: 24px;
        max-width: 600px;
        width: 100%;
        padding: 2.75rem 2.25rem;
        text-align: center;
        box-shadow: 0 20px 45px -15px rgba(15, 23, 42, 0.1);
    }
    </style>
    <div class="cbt-terminal-wrap">
        <div class="cbt-terminal-card border border-danger border-opacity-50">
            <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 52px;">gavel</span>
            </div>
            <h3 class="fw-bold text-danger mb-2">Sesi Ujian Terkunci!</h3>
            <p class="text-dark fw-semibold mb-3">
                Ujian <strong><?php echo format_string($cm->name); ?></strong> telah dikunci oleh sistem pengawasan anti-curang.
            </p>

            <div class="alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-25 rounded-2xl p-3 mb-3 text-start small">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Pelanggaran Terdeteksi:</span>
                    <span class="fw-bold text-danger">3 / 3 Peringatan Terlampaui</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Kondisi Sesi:</span>
                    <span class="badge bg-danger rounded-pill px-2-5 py-0-5 font-semibold">Terkunci / Ditangguhkan</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Izin Pengerjaan:</span>
                    <span class="fw-semibold text-danger">Menunggu ACC / Reset dari Admin</span>
                </div>
            </div>

            <p class="text-muted small mb-4">
                Anda terdeteksi keluar dari mode layar penuh atau berpindah jendela browser melebihi batas toleransi. Sesuai protokol ujian terintegrasi, ujian ini tidak dapat dikerjakan lagi sampai Administrator menyetujui (ACC) atau me-reset sesi ujian Anda.
            </p>

            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-outline-danger rounded-pill py-2-5 px-4 font-semibold w-100 shadow-xs">
                ← Kembali ke Dashboard Siswa
            </a>
        </div>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}
?>

<!-- Suppress AI Floating Assistant & Chat Windows Completely -->
<style>
#luminaAiFab,
.lumina-ai-fab,
#luminaAiChatWindow,
.lumina-ai-chat-window,
.lumina-chatbot-widget,
.activity-navigation,
#region-main > .activity-navigation,
.urlselect {
    display: none !important;
    visibility: hidden !important;
    pointer-events: none !important;
}

:root {
    --cbt-primary: #4F46E5;
    --cbt-primary-dark: #3730A3;
    --cbt-warning: #F59E0B;
    --cbt-danger: #EF4444;
    --cbt-success: #10B981;
    --cbt-surface: #FFFFFF;
    --cbt-bg: #F8FAFC;
    --cbt-border: #E2E8F0;
    --cbt-text: #0F172A;
    --cbt-muted: #64748B;
}

body {
    background-color: var(--cbt-bg) !important;
    user-select: none !important;
    -webkit-user-select: none !important;
    overflow-x: hidden;
}

.cbt-viewport {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Proctoring Top Bar */
.cbt-topbar {
    position: sticky;
    top: 0;
    z-index: 1050;
    background: #0F172A;
    color: #FFFFFF;
    padding: 0.75rem 1.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.warn-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #475569;
    display: inline-block;
    transition: all 0.3s;
}
.warn-dot.active {
    background: #F59E0B;
    box-shadow: 0 0 8px #F59E0B;
}
.warn-dot.active.danger {
    background: #EF4444;
    box-shadow: 0 0 10px #EF4444;
}

/* Floating Warning Toast */
.cbt-warning-toast {
    position: fixed;
    top: 70px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99998;
    background: #1E1B4B;
    color: #FFFFFF;
    border: 2px solid #F59E0B;
    border-radius: 1rem;
    padding: 0.85rem 1.75rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 20px rgba(245, 158, 11, 0.4);
    animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    max-width: 90%;
}
@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, -20px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}

/* Main CBT Workspace */
.cbt-container {
    max-width: 1400px;
    width: 100%;
    margin: 1.5rem auto;
    padding: 0 1rem;
    flex: 1;
}

.cbt-card {
    background: #FFFFFF;
    border: 1px solid var(--cbt-border);
    border-radius: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* Option Cards */
.cbt-option-item {
    display: flex;
    align-items: center;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
    border: 2px solid #E2E8F0;
    border-radius: 1rem;
    background: #FFFFFF;
    cursor: pointer;
    transition: all 0.2s ease;
}
.cbt-option-item:hover {
    border-color: #A5B4FC;
    background: #F5F3FF;
}
.cbt-option-item.selected {
    border-color: #4F46E5;
    background: #EEF2FF;
    box-shadow: 0 0 0 1px #4F46E5;
}
.cbt-option-badge {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
    margin-right: 1rem;
    background: #F1F5F9;
    color: #475569;
    flex-shrink: 0;
    transition: all 0.2s;
}
.cbt-option-item.selected .cbt-option-badge {
    background: #4F46E5;
    color: #FFFFFF;
}

/* Question Palette Grid */
.cbt-palette-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.cbt-palette-btn {
    aspect-ratio: 1;
    border: 2px solid #E2E8F0;
    border-radius: 0.75rem;
    background: #F8FAFC;
    color: #475569;
    font-weight: 700;
    font-size: 0.95rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    transition: all 0.15s ease;
}
.cbt-palette-btn:hover {
    border-color: #6366F1;
    transform: translateY(-1px);
}
.cbt-palette-btn.active {
    outline: 3px solid #6366F1;
    outline-offset: 2px;
}
.cbt-palette-btn.answered {
    background: #4F46E5;
    color: #FFFFFF;
    border-color: #4F46E5;
}
.cbt-palette-btn.doubtful {
    background: #F59E0B;
    color: #FFFFFF;
    border-color: #F59E0B;
}
.cbt-palette-btn.doubtful::after {
    content: '🚩';
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: 8px;
}

/* Overlays */
.cbt-fullscreen-gate,
.cbt-fullscreen-lockout,
.cbt-suspension-overlay,
.cbt-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    background: rgba(15, 23, 42, 0.96);
    padding: 1.5rem;
}

.cbt-gate-card {
    background: #FFFFFF;
    border-radius: 1.5rem;
    max-width: 640px;
    width: 100%;
    padding: 2.5rem;
    text-align: center;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
    animation: zoomIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes zoomIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>

<div class="cbt-viewport" id="cbtViewport">
    <!-- Top Proctoring Status Bar -->
    <header class="cbt-topbar d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-danger rounded-pill px-3 py-1 font-semibold d-inline-flex align-items-center gap-2">
                <span class="spinner-grow spinner-grow-sm text-light" style="width: 8px; height: 8px;"></span>
                LIVE PROCTORING AKTIF
            </span>
            <div class="d-none d-md-block">
                <span class="fw-bold small"><?php echo format_string($course->fullname); ?></span>
                <span class="text-white-50 mx-1">&bull;</span>
                <span class="text-white-50 small"><?php echo format_string($cm->name); ?></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <!-- Warning Meter -->
            <div class="d-flex align-items-center gap-1 bg-dark bg-opacity-50 px-3 py-1 rounded-pill border border-secondary border-opacity-25">
                <span class="text-white-50 small me-1">Peringatan:</span>
                <span id="warnDot1" class="warn-dot <?php echo $session->warningcount >= 1 ? 'active' : ''; ?>"></span>
                <span id="warnDot2" class="warn-dot <?php echo $session->warningcount >= 2 ? 'active' : ''; ?>"></span>
                <span id="warnDot3" class="warn-dot <?php echo $session->warningcount >= 3 ? 'active danger' : ''; ?>"></span>
                <span id="warnCountBadge" class="badge bg-secondary ms-1"><?php echo $session->warningcount; ?> / 3</span>
            </div>

            <!-- Fullscreen Indicator -->
            <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 rounded-pill px-3 py-1 font-semibold d-none d-sm-inline-flex align-items-center gap-1">
                <span class="material-symbols-outlined" style="font-size: 14px;">fullscreen</span>
                Layar Penuh Terkunci
            </span>

            <!-- Mouse Only Mode Badge -->
            <span class="badge bg-info bg-opacity-25 text-cyan border border-info border-opacity-50 rounded-pill px-3 py-1 font-semibold d-none d-md-inline-flex align-items-center gap-1" title="Papan ketik dinonaktifkan sistem. Hanya mouse yang diizinkan untuk navigasi dan menjawab soal.">
                <span class="material-symbols-outlined" style="font-size: 14px;">mouse</span>
                Mode Mouse Saja
            </span>

            <!-- Top Submit Button -->
            <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold" onclick="openSubmitModal()">
                Kumpulkan Ujian
            </button>
        </div>
    </header>

    <!-- Floating Non-blocking Warning Toast -->
    <div id="cbtWarningToast" class="cbt-warning-toast" style="display: none;">
        <div class="d-flex align-items-center gap-3">
            <span class="material-symbols-outlined fs-2 text-warning">warning</span>
            <div>
                <h6 class="mb-0 fw-bold" id="cbtToastTitle" style="color: #F59E0B;">PERINGATAN 1 / 3</h6>
                <p class="mb-0 small" id="cbtToastDesc">Terdeteksi meninggalkan layar ujian.</p>
            </div>
        </div>
    </div>

    <!-- Main CBT Interactive Workspace -->
    <main class="cbt-container">
        <div class="row g-4">
            <!-- Left Column: Active Question -->
            <div class="col-lg-8">
                <div class="cbt-card p-4 p-md-5 d-flex flex-column justify-content-between" style="min-height: 580px;">
                    <div>
                        <!-- Question Header -->
                        <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
                            <div>
                                <span class="badge bg-indigo-50 text-primary border border-indigo-200 px-3 py-1 rounded-pill fw-bold text-uppercase" style="letter-spacing: 0.05em;">
                                    Soal Pilihan Ganda
                                </span>
                                <h4 class="fw-bold mt-2 mb-0" id="cbtQuestionTitle">Soal No. 1 dari 20</h4>
                            </div>

                            <!-- Ragu-ragu Toggle Button -->
                            <button type="button" id="btnDoubtful" class="btn btn-outline-warning rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2" onclick="toggleDoubtful()">
                                <span id="doubtfulIcon">🚩</span>
                                <span id="doubtfulLabel">Tandai Ragu-ragu</span>
                            </button>
                        </div>

                        <!-- Question Statement Text -->
                        <div class="cbt-question-text mb-4" id="cbtQuestionBody" style="font-size: 1.15rem; font-weight: 500; color: #1E293B; line-height: 1.6;">
                            <!-- Injected dynamically -->
                        </div>

                        <!-- Options List -->
                        <div class="cbt-options-list" id="cbtOptionsContainer">
                            <!-- Injected dynamically -->
                        </div>
                    </div>

                    <!-- Question Navigation Footer -->
                    <div class="d-flex align-items-center justify-content-between pt-4 mt-4 border-top">
                        <button type="button" id="btnPrev" class="btn btn-outline-secondary rounded-pill px-4 fw-bold d-inline-flex align-items-center gap-2" onclick="navigatePrev()">
                            <span class="material-symbols-outlined" style="font-size: 18px;">arrow_back</span>
                            Sebelumnya
                        </button>

                        <div class="d-none d-md-flex align-items-center gap-3 small text-muted">
                            <span>Terjawab: <strong class="text-primary" id="recapAnswered">0</strong></span>
                            <span>&bull;</span>
                            <span>Ragu-ragu: <strong class="text-warning" id="recapDoubtful">0</strong></span>
                            <span>&bull;</span>
                            <span>Belum: <strong class="text-secondary" id="recapUnanswered">20</strong></span>
                        </div>

                        <button type="button" id="btnNext" class="btn btn-primary rounded-pill px-4 fw-bold d-inline-flex align-items-center gap-2" onclick="navigateNext()">
                            Selanjutnya
                            <span class="material-symbols-outlined" style="font-size: 18px;">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Question Palette Navigation -->
            <div class="col-lg-4">
                <div class="cbt-card p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold mb-0 text-dark">Navigasi Nomor Soal</h6>
                        <span class="badge bg-light text-dark border rounded-pill px-2 py-1 small">20 Soal</span>
                    </div>

                    <!-- Legend -->
                    <div class="d-flex flex-wrap gap-2 mb-3 pb-3 border-bottom small text-muted">
                        <div class="d-flex align-items-center gap-1">
                            <span style="width: 12px; height: 12px; border-radius: 3px; background: #4F46E5; display: inline-block;"></span>
                            <span>Sudah Dijawab</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span style="width: 12px; height: 12px; border-radius: 3px; background: #F59E0B; display: inline-block;"></span>
                            <span>Ragu-ragu</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span style="width: 12px; height: 12px; border-radius: 3px; background: #F8FAFC; border: 1px solid #CBD5E1; display: inline-block;"></span>
                            <span>Belum Dijawab</span>
                        </div>
                    </div>

                    <!-- Grid of 20 Question Buttons -->
                    <div class="cbt-palette-grid mb-4" id="cbtPaletteGrid">
                        <!-- 20 buttons generated via JS -->
                    </div>

                    <!-- Final Submission Button -->
                    <button type="button" class="btn btn-primary w-100 py-3 rounded-2xl fw-bold d-flex align-items-center justify-content-center gap-2 shadow-sm" onclick="openSubmitModal()">
                        <span class="material-symbols-outlined">assignment_turned_in</span>
                        Kumpulkan Lembar Ujian
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- 1. Mandatory Fullscreen Gate on Start (Always Full Screen) -->
    <div id="cbtFullscreenGate" class="cbt-fullscreen-gate" style="display: <?php echo ($session->status === 'in_progress') ? 'flex' : 'none'; ?>;">
        <div class="cbt-gate-card">
            <div class="rounded-circle bg-indigo-50 text-primary d-inline-flex p-3 mb-3" style="background: #EEF2FF; color: #4F46E5;">
                <span class="material-symbols-outlined" style="font-size: 52px;">shield</span>
            </div>
            <h3 class="fw-bold text-dark mb-2">Pusat Ujian Terpantau CBT</h3>
            <p class="text-secondary mb-4">
                Ujian <strong><?php echo format_string($cm->name); ?></strong> dilindungi sistem pengawasan ketat anti-curang. Anda <strong>wajib berada dalam mode Layar Penuh (Fullscreen)</strong> sepanjang pengerjaan ujian.
            </p>

            <div class="alert alert-light border rounded-2xl text-start small mb-4 p-3" style="background: #F8FAFC;">
                <div class="fw-bold mb-2 text-dark">Protokol Keamanan &amp; Audit Ujian:</div>
                <ul class="mb-0 ps-3 text-muted">
                    <li><strong>Mode Mouse Saja:</strong> Seluruh tombol keyboard (papan ketik) dinonaktifkan sistem hingga Anda mengumpulkan ujian. Gunakan mouse untuk menjawab dan navigasi.</li>
                    <li><strong>Anti-Minimize &amp; Gesture 3 Jari:</strong> Dilarang menggunakan swipe 3 jari pada touchpad atau meminimalkan layar. Pelanggaran langsung dicatat secara real-time pada audit pengawas.</li>
                    <li><strong>Layar Penuh Wajib:</strong> Anda dilarang keluar dari mode Layar Penuh (Esc, F11, dll). Keluar layar penuh akan langsung mengunci tampilan soal.</li>
                    <li><strong>Audit Real-Time:</strong> Setiap pelanggaran (walau hanya 1 kali) tercatat ke log sistem. Batas maksimal <strong>3 peringatan</strong> sebelum ujian ditangguhkan ke Administrator.</li>
                </ul>
            </div>

            <button type="button" class="btn btn-primary w-100 py-3 rounded-2xl fw-bold fs-5 shadow-sm" onclick="startExamInFullscreen()">
                Masuk Layar Penuh & Mulai Ujian
            </button>
        </div>
    </div>

    <!-- 2. Strict Fullscreen Exit Lockout Overlay -->
    <div id="fullscreenLockOverlay" class="cbt-fullscreen-lockout" style="display: none;">
        <div class="cbt-gate-card border border-warning" style="border-width: 2px !important;">
            <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 52px;">screen_lock_rotation</span>
            </div>
            <h3 class="fw-bold text-danger mb-2">LAYAR PENUH TERKUNCI!</h3>
            <p class="text-dark fw-semibold mb-2">
                Anda terdeteksi keluar dari mode Layar Penuh.
            </p>
            <p class="text-muted small mb-4">
                Sistem pengawasan live proctoring mencatat kejadian ini sebagai pelanggaran pengawasan. Anda tidak dapat melihat atau melanjutkan soal di luar mode Layar Penuh.
            </p>

            <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold" id="lockoutWarnBadge">
                    Peringatan Terdeteksi
                </span>
            </div>

            <button type="button" class="btn btn-warning w-100 py-3 rounded-2xl fw-bold fs-5 text-dark shadow-sm" onclick="resumeFullscreen()">
                Masuk Kembali ke Layar Penuh
            </button>
        </div>
    </div>

    <!-- 3. Suspension Overlay (Warning 3 Reached) -->
    <div id="suspensionOverlay" class="cbt-suspension-overlay" style="display: <?php echo ($session->status === 'under_review') ? 'flex' : 'none'; ?>;">
        <div class="cbt-gate-card border border-danger">
            <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 52px;">gavel</span>
            </div>
            <h3 class="fw-bold text-danger mb-2">Ujian Ditangguhkan!</h3>
            <p class="text-dark fw-semibold mb-2">
                Sesi ujian Anda telah dikunci oleh sistem karena mencapai batas toleransi 3 peringatan pelanggaran.
            </p>
            <p class="text-muted small mb-4">
                Nilai dan lembar jawaban Anda saat ini <strong>ditahan dalam antrean peninjauan Administrator</strong>. Silakan hubungi pengawas ujian atau administrator sistem untuk mengajukan verifikasi manual.
            </p>
            <div class="alert alert-warning rounded-2xl small mb-4 text-start">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-warning" style="font-size: 18px;">warning</span>
                    <strong>Status Sesi: Under Review (#<?php echo $session->id; ?>)</strong>
                </div>
                <span>Waktu Penangguhan: <?php echo userdate(time(), '%d %b %Y, %H:%M WIB'); ?></span>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-primary w-100 py-3 rounded-2xl fw-bold">
                Kembali ke Dashboard Siswa
            </a>
        </div>
    </div>

    <!-- 4. Submit Confirmation Modal -->
    <div id="submitModal" class="cbt-modal-backdrop" style="display: none;">
        <div class="cbt-gate-card">
            <div class="rounded-circle bg-indigo-50 text-primary d-inline-flex p-3 mb-3" style="background: #EEF2FF; color: #4F46E5;">
                <span class="material-symbols-outlined" style="font-size: 48px;">fact_check</span>
            </div>
            <h3 class="fw-bold text-dark mb-2">Konfirmasi Pengumpulan</h3>
            <p class="text-secondary small mb-4">
                Harap periksa kembali ringkasan lembar jawaban Anda sebelum menyelesaikan ujian secara permanen.
            </p>

            <div class="row g-2 mb-4">
                <div class="col-4">
                    <div class="p-3 bg-light rounded-2xl border text-center">
                        <div class="text-muted small">Terjawab</div>
                        <h4 class="fw-bold text-primary mb-0" id="modalAnswered">0</h4>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 bg-light rounded-2xl border text-center">
                        <div class="text-muted small">Ragu-ragu</div>
                        <h4 class="fw-bold text-warning mb-0" id="modalDoubtful">0</h4>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 bg-light rounded-2xl border text-center">
                        <div class="text-muted small">Belum Dijawab</div>
                        <h4 class="fw-bold text-secondary mb-0" id="modalUnanswered">20</h4>
                    </div>
                </div>
            </div>

            <div id="modalWarningAlert" class="alert alert-warning rounded-2xl small mb-4 text-start" style="display: none;">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-warning" style="font-size: 18px;">warning</span>
                    <strong>Perhatian!</strong>
                </div>
                <span id="modalWarningText">Masih ada soal yang belum dijawab atau ditandai ragu-ragu.</span>
            </div>

            <div class="d-flex gap-3">
                <button type="button" class="btn btn-outline-secondary w-50 py-3 rounded-2xl fw-bold" onclick="closeSubmitModal()">
                    Periksa Kembali
                </button>
                <button type="button" class="btn btn-primary w-50 py-3 rounded-2xl fw-bold" id="btnConfirmSubmit" onclick="submitExamFinal()">
                    Ya, Kumpulkan
                </button>
            </div>
        </div>
    </div>

    <!-- 5. Completion Result Screen -->
    <div id="completionScreen" class="cbt-fullscreen-gate" style="display: <?php echo ($session->status === 'completed') ? 'flex' : 'none'; ?>;">
        <div class="cbt-gate-card">
            <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 56px;">verified</span>
            </div>
            <h3 class="fw-bold text-dark mb-1">Ujian Berhasil Dikumpulkan!</h3>
            <p class="text-secondary small mb-4">
                Terima kasih, lembar jawaban Anda telah diterima dan dinilai secara otomatis oleh sistem Lumina CBT.
            </p>

            <div class="p-4 bg-light rounded-3xl border mb-4 text-center">
                <span class="text-muted small text-uppercase fw-bold" style="letter-spacing: 0.05em;">Nilai Akhir Ujian</span>
                <h1 class="display-4 fw-extrabold text-primary mb-1" id="resultScore">
                    <?php echo ($session->score !== null) ? number_format($session->score, 1) : '0.0'; ?>
                </h1>
                <span class="badge bg-success bg-opacity-15 text-success rounded-pill px-3 py-1 font-semibold">
                    Lulus Evaluasi CBT
                </span>
            </div>

            <div class="row g-2 mb-4 text-start small">
                <div class="col-6">
                    <div class="p-2 border rounded-xl bg-white">
                        <span class="text-muted">Integritas Pengawasan:</span>
                        <div class="fw-bold text-success" id="resultIntegrity">Aman & Terverifikasi</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-2 border rounded-xl bg-white">
                        <span class="text-muted">Total Pelanggaran:</span>
                        <div class="fw-bold text-dark" id="resultViolations"><?php echo $session->warningcount; ?> / 3 Peringatan</div>
                    </div>
                </div>
            </div>

            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-primary w-100 py-3 rounded-2xl fw-bold">
                Kembali ke Dashboard Utama
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    // Server-provided data
    const sessionId = <?php echo (int)$session->id; ?>;
    const userId = <?php echo (int)$USER->id; ?>;
    const sesskey = '<?php echo sesskey(); ?>';
    const ajaxUrl = '<?php echo $CFG->wwwroot; ?>/local/lumina_core/ajax_exam.php';
    const questions = <?php echo json_encode($clientquestions, JSON_UNESCAPED_UNICODE); ?>;
    const totalQuestions = questions.length || 20;

    let currentWarnCount = <?php echo (int)$session->warningcount; ?>;
    let isSuspended = <?php echo ($session->status === 'under_review') ? 'true' : 'false'; ?>;
    let isCompleted = <?php echo ($session->status === 'completed') ? 'true' : 'false'; ?>;
    let hasStarted = isCompleted || isSuspended;

    let currentIndex = 0; // 0-based question index
    let userAnswers = <?php echo json_encode($serversavedanswers, JSON_FORCE_OBJECT); ?> || {};
    let doubtfulMap = <?php echo json_encode($serversaveddoubtful, JSON_FORCE_OBJECT); ?> || {};

    // Load persisted local answers if any (overrides if newer)
    const storageKey = 'lumina_cbt_' + sessionId + '_' + userId;
    try {
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (parsed.answers) userAnswers = Object.assign({}, userAnswers, parsed.answers);
            if (parsed.doubtful) doubtfulMap = Object.assign({}, doubtfulMap, parsed.doubtful);
        }
    } catch(e) {}

    let syncTimer = null;
    function syncProgressToServer() {
        if (isSuspended || isCompleted) return;
        if (syncTimer) clearTimeout(syncTimer);
        syncTimer = setTimeout(() => {
            const formData = new FormData();
            formData.append('sessionid', sessionId);
            formData.append('action', 'save_progress');
            formData.append('answers', JSON.stringify(userAnswers));
            formData.append('doubtful', JSON.stringify(doubtfulMap));
            formData.append('sesskey', sesskey);
            fetch(ajaxUrl, { method: 'POST', body: formData }).catch(err => console.log('Auto-save heartbeat error:', err));
        }, 500);
    }

    function saveStateLocally() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                answers: userAnswers,
                doubtful: doubtfulMap,
                updatedAt: Date.now()
            }));
        } catch(e) {}
        syncProgressToServer();
    }

    // Initialize Question Palette
    function initPalette() {
        const grid = document.getElementById('cbtPaletteGrid');
        if (!grid) return;
        grid.innerHTML = '';

        for (let i = 1; i <= totalQuestions; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cbt-palette-btn';
            btn.id = 'paletteBtn_' + i;
            btn.textContent = i;
            btn.onclick = () => jumpToQuestion(i - 1);
            grid.appendChild(btn);
        }
        updateAllPaletteStates();
    }

    // Update single button state
    function updatePaletteState(num) {
        const btn = document.getElementById('paletteBtn_' + num);
        if (!btn) return;

        btn.classList.toggle('active', (num - 1) === currentIndex);

        const isAns = (userAnswers[num] !== undefined && userAnswers[num] !== null);
        const isDoubt = (doubtfulMap[num] === true);

        btn.classList.toggle('doubtful', isDoubt);
        btn.classList.toggle('answered', isAns && !isDoubt);
    }

    function updateAllPaletteStates() {
        for (let i = 1; i <= totalQuestions; i++) {
            updatePaletteState(i);
        }
        updateRecapStats();
    }

    function updateRecapStats() {
        let ansCount = 0;
        let doubtCount = 0;

        for (let i = 1; i <= totalQuestions; i++) {
            if (doubtfulMap[i]) {
                doubtCount++;
            }
            if (userAnswers[i] !== undefined && userAnswers[i] !== null) {
                ansCount++;
            }
        }
        const unansCount = Math.max(0, totalQuestions - ansCount);

        document.getElementById('recapAnswered').textContent = ansCount;
        document.getElementById('recapDoubtful').textContent = doubtCount;
        document.getElementById('recapUnanswered').textContent = unansCount;
    }

    // Render current question
    function renderQuestion(index) {
        if (index < 0 || index >= totalQuestions) return;
        currentIndex = index;
        const q = questions[index];
        const qNum = index + 1;

        // Title & Navigation buttons
        document.getElementById('cbtQuestionTitle').textContent = 'Soal No. ' + qNum + ' dari ' + totalQuestions;
        document.getElementById('btnPrev').disabled = (index === 0);

        const btnNext = document.getElementById('btnNext');
        if (index === totalQuestions - 1) {
            btnNext.innerHTML = 'Kumpulkan <span class="material-symbols-outlined" style="font-size: 18px;">check</span>';
            btnNext.onclick = openSubmitModal;
        } else {
            btnNext.innerHTML = 'Selanjutnya <span class="material-symbols-outlined" style="font-size: 18px;">arrow_forward</span>';
            btnNext.onclick = navigateNext;
        }

        // Doubtful button
        const isDoubt = doubtfulMap[qNum] === true;
        const btnDoubtful = document.getElementById('btnDoubtful');
        if (isDoubt) {
            btnDoubtful.classList.remove('btn-outline-warning');
            btnDoubtful.classList.add('btn-warning');
            document.getElementById('doubtfulLabel').textContent = 'Ragu-ragu (Ditandai)';
        } else {
            btnDoubtful.classList.remove('btn-warning');
            btnDoubtful.classList.add('btn-outline-warning');
            document.getElementById('doubtfulLabel').textContent = 'Tandai Ragu-ragu';
        }

        // Question text
        document.getElementById('cbtQuestionBody').innerHTML = q.question;

        // Render options (Pure CBT: NO CHECK / PERIKSA OPTION)
        const optContainer = document.getElementById('cbtOptionsContainer');
        optContainer.innerHTML = '';

        const selectedOpt = userAnswers[qNum];

        q.options.forEach((opt, optIdx) => {
            const item = document.createElement('div');
            item.className = 'cbt-option-item' + (selectedOpt === opt.index ? ' selected' : '');
            item.onclick = () => selectOption(qNum, opt.index);

            const badge = document.createElement('div');
            badge.className = 'cbt-option-badge';
            badge.textContent = opt.label || String.fromCharCode(65 + optIdx);

            const text = document.createElement('div');
            text.className = 'fw-medium text-dark flex-grow-1';
            text.textContent = opt.text;

            item.appendChild(badge);
            item.appendChild(text);
            optContainer.appendChild(item);
        });

        updateAllPaletteStates();
    }

    function selectOption(qNum, optIndex) {
        if (isSuspended || isCompleted) return;
        userAnswers[qNum] = optIndex;
        saveStateLocally();
        renderQuestion(currentIndex);
    }

    window.toggleDoubtful = function() {
        if (isSuspended || isCompleted) return;
        const qNum = currentIndex + 1;
        doubtfulMap[qNum] = !doubtfulMap[qNum];
        saveStateLocally();
        renderQuestion(currentIndex);
    };

    window.navigatePrev = function() {
        if (currentIndex > 0) {
            renderQuestion(currentIndex - 1);
        }
    };

    window.navigateNext = function() {
        if (currentIndex < totalQuestions - 1) {
            renderQuestion(currentIndex + 1);
        }
    };

    window.jumpToQuestion = function(targetIdx) {
        renderQuestion(targetIdx);
    };

    // Submit Modal
    window.openSubmitModal = function() {
        if (isSuspended || isCompleted) return;

        let ansCount = 0;
        let doubtCount = 0;
        for (let i = 1; i <= totalQuestions; i++) {
            if (doubtfulMap[i]) doubtCount++;
            if (userAnswers[i] !== undefined && userAnswers[i] !== null) ansCount++;
        }
        const unansCount = Math.max(0, totalQuestions - ansCount);

        document.getElementById('modalAnswered').textContent = ansCount;
        document.getElementById('modalDoubtful').textContent = doubtCount;
        document.getElementById('modalUnanswered').textContent = unansCount;

        const warnAlert = document.getElementById('modalWarningAlert');
        const warnText = document.getElementById('modalWarningText');

        if (unansCount > 0 || doubtCount > 0) {
            warnAlert.style.display = 'block';
            warnText.textContent = `Perhatian: Ada ${unansCount} soal belum dijawab dan ${doubtCount} soal ditandai ragu-ragu.`;
        } else {
            warnAlert.style.display = 'none';
        }

        document.getElementById('submitModal').style.display = 'flex';
    };

    window.closeSubmitModal = function() {
        document.getElementById('submitModal').style.display = 'none';
    };

    window.submitExamFinal = function() {
        const btn = document.getElementById('btnConfirmSubmit');
        btn.disabled = true;
        btn.textContent = 'Memproses...';

        const formData = new FormData();
        formData.append('sessionid', sessionId);
        formData.append('action', 'submit_exam');
        formData.append('answers', JSON.stringify(userAnswers));
        formData.append('sesskey', sesskey);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                isCompleted = true;
                closeSubmitModal();

                // Show completion screen
                document.getElementById('resultScore').textContent = Number(data.score).toFixed(1);
                document.getElementById('resultViolations').textContent = `${data.warning_count} / 3 Peringatan`;
                document.getElementById('completionScreen').style.display = 'flex';

                // Clear temporary local storage
                try { localStorage.removeItem(storageKey); } catch(e) {}
            } else {
                alert('Gagal mengumpulkan ujian: ' + (data.error || 'Terjadi kesalahan sistem'));
                btn.disabled = false;
                btn.textContent = 'Ya, Kumpulkan';
            }
        })
        .catch(err => {
            console.error('Submit error:', err);
            alert('Koneksi terputus saat mengumpulkan ujian. Silakan coba kembali.');
            btn.disabled = false;
            btn.textContent = 'Ya, Kumpulkan';
        });
    };

    // PROCTORING & STRICT FULLSCREEN ENFORCEMENT
    let cooldown = false;
    let toastTimeout = null;

    function showWarningToast(level, message) {
        const toast = document.getElementById('cbtWarningToast');
        const title = document.getElementById('cbtToastTitle');
        const desc = document.getElementById('cbtToastDesc');
        if (!toast) return;

        title.textContent = 'PERINGATAN ' + level + ' / 3: TERDETEKSI PELANGGARAN';
        title.style.color = (level >= 2) ? '#EF4444' : '#F59E0B';
        desc.textContent = message;
        toast.style.borderColor = (level >= 2) ? '#EF4444' : '#F59E0B';
        toast.style.display = 'block';

        if (toastTimeout) clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
            toast.style.display = 'none';
        }, 5000);
    }

    function reportViolation(eventType, meta) {
        if (isSuspended || isCompleted || cooldown) return;
        cooldown = true;
        setTimeout(() => { cooldown = false; }, 2500);

        const formData = new FormData();
        formData.append('sessionid', sessionId);
        formData.append('eventtype', eventType);
        formData.append('metadata', meta || '');
        formData.append('sesskey', sesskey);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentWarnCount = data.warning_level;
                updateWarningUI(data.warning_level);

                if (data.is_suspended) {
                    isSuspended = true;
                    document.getElementById('suspensionOverlay').style.display = 'flex';
                    document.getElementById('fullscreenLockOverlay').style.display = 'none';
                    const mainEl = document.getElementById('cbtExamMain');
                    if (mainEl) mainEl.style.display = 'none';
                } else {
                    showWarningToast(data.warning_level, data.message);
                }
            }
        })
        .catch(err => console.error('Violation report failed:', err));
    }

    function updateWarningUI(count) {
        const d1 = document.getElementById('warnDot1');
        const d2 = document.getElementById('warnDot2');
        const d3 = document.getElementById('warnDot3');
        const b = document.getElementById('warnCountBadge');
        const lb = document.getElementById('lockoutWarnBadge');

        if (d1) d1.classList.toggle('active', count >= 1);
        if (d2) d2.classList.toggle('active', count >= 2);
        if (d3) {
            d3.classList.toggle('active', count >= 3);
            d3.classList.toggle('danger', count >= 3);
        }
        if (b) b.textContent = count + ' / 3';
        if (lb) lb.textContent = 'Peringatan ' + count + ' / 3 Terdeteksi';
    }

    function requestBrowserFullscreen() {
        const el = document.documentElement;
        if (el.requestFullscreen) {
            return el.requestFullscreen();
        } else if (el.webkitRequestFullscreen) {
            return el.webkitRequestFullscreen();
        } else if (el.mozRequestFullScreen) {
            return el.mozRequestFullScreen();
        } else if (el.msRequestFullscreen) {
            return el.msRequestFullscreen();
        }
        return Promise.resolve();
    }

    function getFullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
    }

    // Always Fullscreen Entry
    window.startExamInFullscreen = function() {
        requestBrowserFullscreen()
            .then(() => {
                hasStarted = true;
                const gate = document.getElementById('cbtFullscreenGate');
                if (gate) gate.style.display = 'none';
            })
            .catch(err => {
                console.log('Fullscreen request notice:', err);
                hasStarted = true;
                const gate = document.getElementById('cbtFullscreenGate');
                if (gate) gate.style.display = 'none';
            });
    };

    window.resumeFullscreen = function() {
        requestBrowserFullscreen()
            .then(() => {
                const lock = document.getElementById('fullscreenLockOverlay');
                if (lock) lock.style.display = 'none';
            })
            .catch(err => {
                console.log('Resume fullscreen notice:', err);
                const lock = document.getElementById('fullscreenLockOverlay');
                if (lock) lock.style.display = 'none';
            });
    };

    // =========================================================================
    // 1. STRICT KEYBOARD DISABLE (MOUSE ONLY MODE - NO KEYBOARD ALLOWED)
    // =========================================================================
    function blockKeyboardInput(e) {
        if (isCompleted) return; // Allow keyboard after exam submission

        // Instantly suppress and cancel keyboard event across capturing phase
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();

        // Warn and log to real-time proctor audit
        if (hasStarted && !isSuspended && !isCompleted) {
            const pressedKey = e.key || e.code || 'Tombol';
            showWarningToast(currentWarnCount, 'Papan ketik (Keyboard) dinonaktifkan! Ujian ini hanya menggunakan mouse untuk menjawab.');
            reportViolation('keyboard_attempt', 'Menekan tombol keyboard: ' + pressedKey);
        }
        return false;
    }

    // Intercept in capturing phase (true) to block BEFORE any browser default or input handling
    window.addEventListener('keydown', blockKeyboardInput, true);
    window.addEventListener('keyup', blockKeyboardInput, true);
    window.addEventListener('keypress', blockKeyboardInput, true);

    // =========================================================================
    // 2. ANTI-MINIMIZE & MULTI-TOUCH / 3-FINGER GESTURE DETECTION
    // =========================================================================
    function handleMultiTouchGesture(e) {
        if (isCompleted) return;
        if (e.touches && e.touches.length >= 3) {
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();

            if (hasStarted && !isSuspended && !isCompleted) {
                showWarningToast(currentWarnCount, 'Gesture 3 jari terdeteksi & dilarang! Tetap fokus pada layar ujian.');
                reportViolation('three_finger_gesture', 'Percobaan gesture 3 jari / multi-touch untuk meminimalkan layar');
            }
        }
    }
    window.addEventListener('touchstart', handleMultiTouchGesture, { passive: false, capture: true });
    window.addEventListener('touchmove', handleMultiTouchGesture, { passive: false, capture: true });

    // Trackpad pinch-to-zoom (wheel + ctrlKey)
    window.addEventListener('wheel', function(e) {
        if (e.ctrlKey) {
            e.preventDefault();
            if (hasStarted && !isSuspended && !isCompleted) {
                reportViolation('trackpad_gesture', 'Percobaan zoom / pinch pada trackpad');
            }
        }
    }, { passive: false, capture: true });

    // Webkit gestures
    window.addEventListener('gesturestart', function(e) {
        e.preventDefault();
        if (hasStarted && !isSuspended && !isCompleted) {
            reportViolation('trackpad_gesture', 'Percobaan gesture swipe/pinch trackpad');
        }
    }, { passive: false, capture: true });
    window.addEventListener('gesturechange', function(e) { e.preventDefault(); }, { passive: false, capture: true });
    window.addEventListener('gestureend', function(e) { e.preventDefault(); }, { passive: false, capture: true });

    // Fullscreen Change Listener: Wajib Layar Penuh
    function handleFullscreenChange() {
        if (!hasStarted || isSuspended || isCompleted) return;

        if (!getFullscreenElement()) {
            // Student exited fullscreen -> Lock screen immediately & record violation!
            const lock = document.getElementById('fullscreenLockOverlay');
            if (lock) lock.style.display = 'flex';
            reportViolation('fullscreen_exit', 'Terdeteksi meminimalkan jendela / keluar fullscreen (Gesture 3 jari / Esc / Minimize)');
        } else {
            // Student entered fullscreen -> Hide lockout overlay
            const lock = document.getElementById('fullscreenLockOverlay');
            if (lock) lock.style.display = 'none';
        }
    }

    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    document.addEventListener('mozfullscreenchange', handleFullscreenChange);

    // 1. Detect Window Blur / Alt+Tab / Trackpad 3-Finger Minimize
    window.addEventListener('blur', function() {
        if (!hasStarted || isSuspended || isCompleted) return;
        const lock = document.getElementById('fullscreenLockOverlay');
        if (lock && !getFullscreenElement()) {
            lock.style.display = 'flex';
        }
        reportViolation('blur', 'Jendela diminimalkan atau beralih aplikasi (Gesture 3 jari / Alt+Tab / Task switch)');
    });

    // 2. Detect Tab Visibility Change
    document.addEventListener('visibilitychange', function() {
        if (!hasStarted || isSuspended || isCompleted) return;
        if (document.hidden) {
            reportViolation('visibility_hidden', 'Tab disembunyikan atau berpindah tab');
        }
    });

    // 3. Detect Cursor Leaving Viewport Boundary
    document.addEventListener('mouseleave', function(e) {
        if (!hasStarted || isSuspended || isCompleted) return;
        if (e.clientY <= 0 || e.clientX <= 0 || (e.clientX >= window.innerWidth) || (e.clientY >= window.innerHeight)) {
            reportViolation('mouseleave', 'Kursor keluar dari batas layar');
        }
    });

    // 4. Prevent Right-Click & Copy Shortcut
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });
    document.addEventListener('copy', function(e) {
        e.preventDefault();
        reportViolation('clipboard_copy', 'Percobaan menyalin teks / copy-paste');
    });

    // Start App
    initPalette();
    renderQuestion(0);
})();
</script>

<?php
echo $OUTPUT->footer();
