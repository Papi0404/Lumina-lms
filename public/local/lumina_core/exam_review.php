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
 * Admin Audit & Real-time Review Center for Proctored Exams.
 *
 * Captures all violations (starting from 1 warning), keyboard attempts, gestures, and live logs.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/exam_monitor.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

use local_lumina_core\exam_monitor;

require_login();

$systemcontext = context_system::instance();
$roleinfo = theme_lumina_role_resolver::resolve_role($USER);

// Only Admin or Exam Reviewers allowed
if (!$roleinfo['is_admin'] && !has_capability('local/lumina_core:reviewexams', $systemcontext)) {
    throw new moodle_exception('nopermissions', 'error', '', 'Audit Peninjauan Ujian');
}

$action    = optional_param('action', '', PARAM_ALPHA);
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$decision  = optional_param('decision', '', PARAM_ALPHA);
$notes     = optional_param('notes', '', PARAM_TEXT);

$notice = '';
$noticetype = 'info';

if ($action === 'resolve' && $sessionid > 0 && confirm_sesskey()) {
    if (in_array($decision, ['approved', 'invalidated', 'reset'], true)) {
        exam_monitor::resolve_session($sessionid, $USER->id, $decision, $notes);
        if ($decision === 'reset') {
            $notice = 'Sesi ujian siswa berhasil di-ACC & di-reset! Peringatan dikembalikan ke 0/3 dan siswa dapat mengerjakan kembali.';
            $noticetype = 'success';
        } else if ($decision === 'approved') {
            $notice = 'Sesi ujian telah disetujui (Approved) dan nilai dipulihkan!';
            $noticetype = 'success';
        } else {
            $notice = 'Sesi ujian telah dibatalkan (Invalidated) karena pelanggaran integritas!';
            $noticetype = 'danger';
        }
    }
}

// Fetch all sessions with 1+ warnings or violations
$reviewlist = exam_monitor::get_sessions_for_review();

// Real-time live feed of recent 20 events
$livefeed = exam_monitor::get_live_audit_feed(0);

$PAGE->set_url('/local/lumina_core/exam_review.php');
$PAGE->set_context($systemcontext);
$PAGE->set_title('Pusat Audit Integritas Ujian Real-Time');
$PAGE->set_heading('Pusat Audit Integritas & Anti-Curang CBT');

echo $OUTPUT->header();
?>

<div class="lumina-exam-review-container py-3">
    <!-- Header with Live Pulse -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-danger" style="font-size: 32px;">verified_user</span>
                Pusat Audit Integritas Ujian (Live Proctoring)
            </h2>
            <span class="text-muted small">
                Sistem audit real-time: mendeteksi setiap pelanggaran (mulai dari 1 peringatan, percobaan keyboard, gesture 3 jari, hingga perpindahan tab/jendela).
            </span>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 rounded-pill px-3 py-2 font-semibold d-inline-flex align-items-center gap-2">
                <span class="spinner-grow spinner-grow-sm text-success" style="width: 8px; height: 8px;"></span>
                Live Audit Aktif (Up to Date)
            </span>
            <a href="exam_review.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1-5 font-semibold d-inline-flex align-items-center gap-1">
                <span class="material-symbols-outlined" style="font-size: 16px;">refresh</span>
                Segarkan
            </a>
        </div>
    </div>

    <!-- Live Toast Alert for Instant Violations -->
    <div id="liveViolationAlert" class="alert alert-danger rounded-2xl mb-4 shadow-xs" style="display: none;"></div>

    <?php if ($notice): ?>
    <div class="alert alert-<?php echo $noticetype; ?> rounded-2xl alert-dismissible fade show mb-4 shadow-xs" role="alert">
        <div class="d-flex align-items-center gap-2">
            <span class="material-symbols-outlined">info</span>
            <strong><?php echo $notice; ?></strong>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-2xl" style="background: linear-gradient(135deg, #FEF2F2 0%, #FFFFFF 100%); border: 1px solid #FECACA !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold">Ujian Ditangguhkan (3/3)</span>
                        <h3 class="fw-bold text-danger mb-0 mt-1">
                            <?php echo count(array_filter($reviewlist, fn($x) => $x['warning_count'] >= 3 || $x['status'] === 'under_review')); ?>
                        </h3>
                    </div>
                    <div class="rounded-circle bg-danger text-white p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <span class="material-symbols-outlined">gavel</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-2xl" style="background: linear-gradient(135deg, #FFFBEB 0%, #FFFFFF 100%); border: 1px solid #FDE68A !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold">Terindikasi Pelanggaran (1-2)</span>
                        <h3 class="fw-bold text-warning mb-0 mt-1">
                            <?php echo count(array_filter($reviewlist, fn($x) => $x['warning_count'] >= 1 && $x['warning_count'] < 3 && $x['status'] !== 'under_review')); ?>
                        </h3>
                    </div>
                    <div class="rounded-circle bg-warning text-dark p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <span class="material-symbols-outlined">warning</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-2xl" style="background: linear-gradient(135deg, #F0FDF4 0%, #FFFFFF 100%); border: 1px solid #BBF7D0 !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold">Total Log Kejadian Terekam</span>
                        <h3 class="fw-bold text-success mb-0 mt-1">
                            <?php echo count($livefeed); ?>
                        </h3>
                    </div>
                    <div class="rounded-circle bg-success text-white p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <span class="material-symbols-outlined">receipt_long</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Review Queue Table -->
    <div class="antigravity-glass-card rounded-3xl p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-primary">format_list_bulleted</span>
                Daftar Sesi Ujian dengan Riwayat Pelanggaran (Mulai dari 1 Peringatan)
            </h5>
            <span class="badge bg-secondary bg-opacity-10 text-secondary border rounded-pill px-3 py-1 font-semibold">
                <?php echo count($reviewlist); ?> Siswa Terpantau
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr style="font-size: 0.8rem;">
                        <th>Mahasiswa</th>
                        <th>Kursus &amp; Materi Ujian</th>
                        <th>Status Peringatan</th>
                        <th>Pelanggaran Terakhir</th>
                        <th>Waktu Sesi</th>
                        <th class="text-end" style="min-width: 220px;">Aksi Keputusan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewlist as $item): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle <?php echo $item['status'] === 'approved' ? 'bg-success text-white' : ($item['warning_count'] >= 3 ? 'bg-danger text-white' : 'bg-warning text-dark'); ?> d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; min-width: 36px;">
                                    <?php echo strtoupper(substr($item['student_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <span class="fw-bold text-dark d-block" style="font-size: 0.88rem;"><?php echo $item['student_name']; ?></span>
                                    <span class="text-muted small" style="font-size: 0.75rem;"><?php echo $item['student_email']; ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="fw-semibold text-dark d-block"><?php echo $item['course_name']; ?></span>
                            <span class="text-muted small"><?php echo $item['activity_name']; ?></span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <?php if ($item['status'] === 'approved'): ?>
                                <span class="badge bg-success text-white rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    ✅ Sesi Lolos &amp; Disetujui (Nilai Sah: <?php echo number_format((float)($item['score'] ?? 0), 1); ?>)
                                </span>
                                <?php elseif ($item['status'] === 'invalidated'): ?>
                                <span class="badge bg-danger text-white rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    ❌ Dibatalkan / Nilai 0.0
                                </span>
                                <?php elseif ($item['warning_count'] >= 3 || $item['status'] === 'under_review'): ?>
                                <span class="badge bg-danger text-white rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    🚫 3 / 3 Ditangguhkan
                                </span>
                                <?php elseif ($item['warning_count'] === 2): ?>
                                <span class="badge bg-warning text-dark border border-warning rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    ⚠️ 2 / 3 Peringatan
                                </span>
                                <?php elseif ($item['warning_count'] === 1): ?>
                                <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    ⚠️ 1 / 3 Peringatan
                                </span>
                                <?php else: ?>
                                <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    Bersih (0/3)
                                </span>
                                <?php endif; ?>

                                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0-5 d-inline-flex align-items-center gap-1" style="font-size: 0.7rem;" onclick="openAuditModal(<?php echo $item['session_id']; ?>, '<?php echo s($item['student_name']); ?>')">
                                    <span class="material-symbols-outlined" style="font-size: 13px;">receipt_long</span>
                                    <?php echo $item['event_count']; ?> Log
                                </button>
                            </div>
                        </td>
                        <td>
                            <div style="max-width: 260px;">
                                <span class="text-dark small d-block text-truncate fw-semibold" title="<?php echo s($item['latest_violation']); ?>">
                                    <?php echo s($item['latest_violation']); ?>
                                </span>
                                <span class="text-muted" style="font-size: 0.7rem;"><?php echo $item['latest_time']; ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="text-dark small d-block"><?php echo $item['finish_time']; ?></span>
                            <span class="text-muted" style="font-size: 0.7rem;">Mulai: <?php echo $item['start_time']; ?></span>
                        </td>
                        <td class="text-end">
                            <?php if ($item['status'] === 'approved'): ?>
                            <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.74rem;">
                                    <span class="material-symbols-outlined" style="font-size: 13px; vertical-align: -2px;">verified</span>
                                    Sah (<?php echo number_format((float)($item['score'] ?? 0), 1); ?>)
                                </span>
                                <form method="post" action="exam_review.php?action=resolve" class="d-inline-flex gap-1">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="sessionid" value="<?php echo $item['session_id']; ?>">
                                    <button type="submit" name="decision" value="reset" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0-5" title="Reset Sesi" style="font-size: 0.7rem;">
                                        Reset
                                    </button>
                                    <button type="submit" name="decision" value="invalidated" class="btn btn-xs btn-outline-danger rounded-pill px-2 py-0-5" title="Batalkan jika curang" style="font-size: 0.7rem;">
                                        Batalkan
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <form method="post" action="exam_review.php?action=resolve" class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="sessionid" value="<?php echo $item['session_id']; ?>">
                                <button type="submit" name="decision" value="reset" class="btn btn-sm btn-primary text-white rounded-pill px-2-5 py-1 font-semibold shadow-xs" title="ACC & Reset Peringatan ke 0/3 agar murid bisa mengerjakan ulang" style="font-size: 0.75rem;">
                                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">restart_alt</span>
                                    ACC &amp; Reset (0/3)
                                </button>
                                <button type="submit" name="decision" value="approved" class="btn btn-sm btn-outline-success rounded-pill px-2-5 py-1 font-semibold" title="Loloskan sesi ujian" style="font-size: 0.75rem;">
                                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">check</span>
                                    Loloskan
                                </button>
                                <button type="submit" name="decision" value="invalidated" class="btn btn-sm btn-outline-danger rounded-pill px-2-5 py-1 font-semibold" title="Batalkan ujian karena kecurangan" style="font-size: 0.75rem;">
                                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">close</span>
                                    Batalkan
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($reviewlist)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <span class="material-symbols-outlined text-success" style="font-size: 48px;">task_alt</span>
                                <h5 class="fw-bold mt-2 mb-1">Semua Sesi Ujian Bersih!</h5>
                                <p class="text-muted small mb-0">Belum ada pelanggaran (peringatan 1, 2, atau 3) yang terdeteksi pada peserta ujian saat ini.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Live Audit Feed Stream -->
    <div class="antigravity-glass-card rounded-3xl p-4">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-danger">stream</span>
                Linimasa Pelanggaran Real-Time (Live Stream)
            </h5>
            <span class="text-muted small">Diperbarui otomatis secara berkala</span>
        </div>

        <div id="liveFeedContainer" style="max-height: 380px; overflow-y: auto;">
            <?php if (!empty($livefeed)): ?>
                <?php foreach ($livefeed as $ev): ?>
                <div class="p-3 mb-2 bg-light rounded-3 border d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge <?php echo $ev['badge_class']; ?> rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                            <span class="material-symbols-outlined" style="font-size: 18px;"><?php echo $ev['icon']; ?></span>
                        </span>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-dark"><?php echo $ev['student_name']; ?></span>
                                <span class="badge bg-white text-secondary border font-monospace" style="font-size: 0.7rem;"><?php echo $ev['course']; ?></span>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size: 0.7rem;"><?php echo $ev['warning_count']; ?>/3 Peringatan</span>
                            </div>
                            <div class="text-muted small mt-1">
                                <strong><?php echo $ev['label']; ?>:</strong> <?php echo $ev['metadata']; ?>
                            </div>
                        </div>
                    </div>
                    <span class="badge bg-white text-muted border font-monospace"><?php echo $ev['time_formatted']; ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <span class="material-symbols-outlined fs-2 text-success">verified</span>
                    <p class="mb-0 mt-2 small">Belum ada peristiwa pelanggaran yang tercatat dalam linimasa live.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Detail Audit Kejadian Siswa -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; border: 1px solid #E2E8F0; box-shadow: 0 10px 40px rgba(0,0,0,0.12);">
            <div class="modal-header border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="material-symbols-outlined text-danger" style="font-size: 24px;">policy</span>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="auditModalTitle">Log Audit Integritas Ujian</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="auditModalBody">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span> Memuat riwayat audit...
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-4">
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sesskey = '<?php echo sesskey(); ?>';

    window.openAuditModal = function(sessionId, studentName) {
        document.getElementById('auditModalTitle').textContent = 'Log Audit Integritas: ' + studentName;
        const modalEl = document.getElementById('auditDetailModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        const body = document.getElementById('auditModalBody');
        body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Memuat riwayat audit...</div>';

        fetch('ajax_exam.php?action=get_audit_details&sessionid=' + sessionId + '&sesskey=' + sesskey)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.events && data.events.length) {
                    let html = '<div class="timeline">';
                    data.events.forEach((ev, idx) => {
                        html += `
                        <div class="d-flex align-items-start gap-3 p-3 mb-2 bg-light rounded-3 border">
                            <span class="badge ${ev.badge_class} rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; flex-shrink: 0;">
                                <span class="material-symbols-outlined" style="font-size: 16px;">${ev.icon}</span>
                            </span>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <span class="fw-bold text-dark">${ev.label}</span>
                                    <span class="badge bg-white text-muted border font-monospace" style="font-size: 0.725rem;">${ev.time}</span>
                                </div>
                                <div class="text-muted small mt-1">Detail: <code>${ev.metadata}</code></div>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                    body.innerHTML = html;
                } else {
                    body.innerHTML = '<div class="text-center py-4 text-muted"><span class="material-symbols-outlined text-success fs-1">check_circle</span><p class="mb-0 mt-2">Tidak ada log pelanggaran pada sesi ini.</p></div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat log audit: ' + err.message + '</div>';
            });
    };

    // Real-time live polling every 5 seconds for new violations
    let lastFeedTime = <?php echo time(); ?>;
    setInterval(function() {
        fetch('ajax_exam.php?action=get_live_feed&since=' + lastFeedTime + '&sesskey=' + sesskey)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.feed && data.feed.length > 0) {
                    lastFeedTime = data.server_time || Math.floor(Date.now() / 1000);
                    const alertEl = document.getElementById('liveViolationAlert');
                    if (alertEl) {
                        alertEl.style.display = 'block';
                        const first = data.feed[0];
                        alertEl.innerHTML = `
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <strong>⚠️ Pelanggaran Baru Terdeteksi (${data.feed.length}):</strong> ${first.student_name} (${first.course}) &mdash; <span class="badge bg-danger">${first.label}</span> &bull; <em>${first.metadata}</em>
                            </div>
                            <a href="exam_review.php" class="btn btn-sm btn-danger rounded-pill px-3 py-1 font-semibold">Segarkan Data</a>
                        </div>`;
                    }
                }
            })
            .catch(err => console.log('Live audit polling:', err));
    }, 5000);
});
</script>

<?php
echo $OUTPUT->footer();
