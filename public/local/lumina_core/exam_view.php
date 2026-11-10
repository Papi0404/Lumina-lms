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
 * Anti-Cheat Fullscreen Exam Proctoring View for H5P and Interactive Quizzes.
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

$PAGE->set_url('/local/lumina_core/exam_view.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_title('Ujian Terpantau: ' . format_string($cm->name));
$PAGE->set_pagelayout('popup'); // Clean distraction-free layout

echo $OUTPUT->header();
?>

<div class="lumina-exam-guard-viewport" id="examGuardViewport">
    <!-- Top Proctoring Status Bar -->
    <div class="exam-proctor-bar d-flex align-items-center justify-content-between px-4 py-2 bg-dark text-white shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-danger rounded-pill px-3 py-1 font-semibold d-inline-flex align-items-center gap-1">
                <span class="spinner-grow spinner-grow-sm text-light" style="width: 8px; height: 8px;"></span>
                LIVE PROCTORING AKTIF
            </span>
            <span class="fw-bold small"><?php echo format_string($cm->name); ?></span>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="warning-meter d-flex align-items-center gap-1">
                <span class="text-white-50 small me-1">Peringatan:</span>
                <span id="warnDot1" class="warn-dot <?php echo $session->warningcount >= 1 ? 'active' : ''; ?>"></span>
                <span id="warnDot2" class="warn-dot <?php echo $session->warningcount >= 2 ? 'active' : ''; ?>"></span>
                <span id="warnDot3" class="warn-dot <?php echo $session->warningcount >= 3 ? 'active danger' : ''; ?>"></span>
                <span id="warnCountBadge" class="badge bg-secondary ms-1"><?php echo $session->warningcount; ?> / 3</span>
            </div>
            <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-3 py-1" onclick="toggleFullscreen()">
                <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">fullscreen</span>
                Layar Penuh
            </button>
        </div>
    </div>

    <!-- Floating Warning Toast Banner (Non-blocking) -->
    <div id="examWarningToast" class="exam-warning-toast" style="display: none;">
        <div class="d-flex align-items-center gap-3">
            <span class="material-symbols-outlined fs-2 text-warning">warning</span>
            <div>
                <h6 class="mb-0 fw-bold" id="examWarningTitle" style="color: #F59E0B;">PERINGATAN 1 / 3</h6>
                <p class="mb-0 small" id="examWarningDesc">Terdeteksi meninggalkan layar ujian.</p>
            </div>
        </div>
    </div>

    <!-- Active Exam Container -->
    <div class="exam-content-box p-3" style="min-height: calc(100vh - 60px);">
        <iframe id="examFrame" src="<?php echo $CFG->wwwroot; ?>/mod/<?php echo $cm->modname; ?>/view.php?id=<?php echo $cm->id; ?>" width="100%" height="800" frameborder="0"></iframe>
    </div>

    <!-- Suspension Overlay (Triggered at Warning 3) -->
    <div id="suspensionOverlay" class="exam-suspension-overlay <?php echo ($session->status === 'under_review') ? 'show' : ''; ?>">
        <div class="antigravity-glass-card rounded-3xl p-5 text-center bg-white shadow-lg" style="max-width: 600px; margin: 80px auto;">
            <div class="rounded-circle bg-danger bg-opacity-15 text-danger d-inline-flex p-3 mb-3">
                <span class="material-symbols-outlined" style="font-size: 48px;">gavel</span>
            </div>
            <h3 class="fw-bold text-danger mb-2">Ujian Ditangguhkan!</h3>
            <p class="text-dark fw-semibold mb-2">
                Sesi ujian Anda telah dikunci oleh sistem karena mencapai batas toleransi 3 peringatan pelanggaran.
            </p>
            <p class="text-muted small mb-4">
                Nilai dan progres pengerjaan Anda saat ini <strong>ditahan dalam antrean peninjauan Administrator</strong>. Silakan hubungi pengawas ujian atau administrator sistem sekolah untuk mengajukan verifikasi manual.
            </p>
            <div class="alert alert-warning rounded-2xl small mb-4 text-start">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-warning">warning</span>
                    <strong>Status Sesi: Under Review</strong>
                </div>
                <span>ID Sesi: #<?php echo $session->id; ?> &bull; Waktu: <?php echo userdate(time(), '%d %b %Y, %H:%M WIB'); ?></span>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-primary rounded-pill px-5 py-2 font-semibold shadow-xs">
                Kembali ke Dashboard Siswa
            </a>
        </div>
    </div>
</div>

<style>
.exam-proctor-bar {
    position: sticky;
    top: 0;
    z-index: 1050;
    background: #111827 !important;
}
.warn-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #4B5563;
    display: inline-block;
    transition: all 0.3s;
}
.warn-dot.active {
    background: #F59E0B;
    box-shadow: 0 0 6px #F59E0B;
}
.warn-dot.active.danger {
    background: #EF4444;
    box-shadow: 0 0 8px #EF4444;
}
.exam-warning-toast {
    position: fixed;
    top: 60px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99998;
    background: #1E1B4B;
    color: #FFFFFF;
    border: 2px solid #F59E0B;
    border-radius: 1rem;
    padding: 1rem 1.75rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 20px rgba(245, 158, 11, 0.4);
    animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    max-width: 90%;
}
@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, -20px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}
.exam-suspension-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(17, 24, 39, 0.95);
    z-index: 99999;
    backdrop-filter: blur(8px);
}
.exam-suspension-overlay.show {
    display: block;
}
</style>

<script>
(function() {
    const sessionId = <?php echo (int)$session->id; ?>;
    const sesskey = '<?php echo sesskey(); ?>';
    const ajaxUrl = '<?php echo $CFG->wwwroot; ?>/local/lumina_core/ajax_exam.php';
    let currentWarnCount = <?php echo (int)$session->warningcount; ?>;
    let isSuspended = <?php echo ($session->status === 'under_review') ? 'true' : 'false'; ?>;
    let cooldown = false;
    let toastTimeout = null;

    function showWarningToast(level, message) {
        const toast = document.getElementById('examWarningToast');
        const title = document.getElementById('examWarningTitle');
        const desc = document.getElementById('examWarningDesc');
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
        if (isSuspended || cooldown) return;
        cooldown = true;
        setTimeout(() => { cooldown = false; }, 2500); // 2.5s cooldown to prevent flood

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
                    document.getElementById('suspensionOverlay').classList.add('show');
                } else {
                    showWarningToast(data.warning_level, data.message);
                }
            }
        })
        .catch(err => console.error('Violation beacon failed:', err));
    }

    function updateWarningUI(count) {
        const d1 = document.getElementById('warnDot1');
        const d2 = document.getElementById('warnDot2');
        const d3 = document.getElementById('warnDot3');
        const b = document.getElementById('warnCountBadge');

        if (d1) d1.classList.toggle('active', count >= 1);
        if (d2) d2.classList.toggle('active', count >= 2);
        if (d3) {
            d3.classList.toggle('active', count >= 3);
            d3.classList.toggle('danger', count >= 3);
        }
        if (b) b.textContent = count + ' / 3';
    }

    // 1. Detect Window Blur (Switching Application / Alt+Tab / Developer Tools)
    window.addEventListener('blur', function() {
        if (document.activeElement && document.activeElement === document.getElementById('examFrame')) {
            return; // Normal student interaction inside exam iframe
        }
        reportViolation('blur', 'Jendela browser kehilangan fokus / berpindah aplikasi');
    });

    // 2. Detect Tab Visibility Change (Tab switching or browser minimize)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            reportViolation('visibility_hidden', 'Tab disembunyikan atau berpindah tab peramban');
        }
    });

    // 3. Detect Cursor Leaving Viewport Boundary
    document.addEventListener('mouseleave', function(e) {
        if (e.clientY <= 0 || e.clientX <= 0 || (e.clientX >= window.innerWidth) || (e.clientY >= window.innerHeight)) {
            reportViolation('mouseleave', 'Kursor keluar dari batas layar peramban');
        }
    });

    // 4. Prevent Right-Click & Copy Attempt
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });
    document.addEventListener('copy', function(e) {
        e.preventDefault();
        reportViolation('clipboard_copy', 'Percobaan menyalin teks / pintasan copy-paste');
    });

    // 5. Fullscreen Enforcement
    window.toggleFullscreen = function() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => console.log(err));
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    };

    document.addEventListener('fullscreenchange', function() {
        if (!document.fullscreenElement && !isSuspended) {
            reportViolation('fullscreen_exit', 'Siswa keluar dari mode fullscreen (layar penuh)');
        }
    });

    // Request fullscreen on first click
    document.addEventListener('click', function firstClick() {
        if (!document.fullscreenElement && !isSuspended) {
            document.documentElement.requestFullscreen().catch(err => {});
        }
        document.removeEventListener('click', firstClick);
    }, { once: true });
})();
</script>

<?php
echo $OUTPUT->footer();
