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
 * Admin Audit & Review Queue for Proctored Exams.
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
    if (in_array($decision, ['approved', 'invalidated'], true)) {
        exam_monitor::resolve_session($sessionid, $USER->id, $decision, $notes);
        $notice = ($decision === 'approved')
            ? 'Sesi ujian telah disetujui (Approved) dan nilai dipulihkan!'
            : 'Sesi ujian telah dibatalkan (Invalidated) karena pelanggaran integritas!';
        $noticetype = ($decision === 'approved') ? 'success' : 'danger';
    }
}

$reviewlist = exam_monitor::get_sessions_for_review();

$PAGE->set_url('/local/lumina_core/exam_review.php');
$PAGE->set_context($systemcontext);
$PAGE->set_title('Antrean Audit & Peninjauan Ujian Anti-Curang');
$PAGE->set_heading('Pusat Audit Integritas Ujian');

echo $OUTPUT->header();
?>

<div class="lumina-exam-review-container py-3">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-danger" style="font-size: 32px;">verified_user</span>
                Pusat Audit &amp; Peninjauan Ujian (Admin)
            </h2>
            <span class="text-muted small">
                Tinjau sesi ujian siswa yang ditangguhkan sistem akibat mencapai batas 3 peringatan perpindahan layar.
            </span>
        </div>

        <div>
            <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 rounded-pill px-3 py-2 font-semibold">
                <?php echo count($reviewlist); ?> Sesi Menunggu Keputusan
            </span>
        </div>
    </div>

    <?php if ($notice): ?>
    <div class="alert alert-<?php echo $noticetype; ?> rounded-2xl alert-dismissible fade show mb-4 shadow-xs" role="alert">
        <div class="d-flex align-items-center gap-2">
            <span class="material-symbols-outlined">info</span>
            <strong><?php echo $notice; ?></strong>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Review Queue Table -->
    <div class="antigravity-glass-card rounded-3xl p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr style="font-size: 0.8rem;">
                        <th>ID &amp; Mahasiswa</th>
                        <th>Kursus &amp; Materi Ujian</th>
                        <th>Status &amp; Pelanggaran</th>
                        <th>Waktu Kejadian</th>
                        <th class="text-end" style="min-width: 200px;">Aksi Keputusan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewlist as $item): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px;">
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
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-danger bg-opacity-15 text-danger border border-danger border-opacity-25 rounded-pill px-2-5 py-1 font-semibold" style="font-size: 0.72rem;">
                                    3 Peringatan Aktif
                                </span>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border rounded-pill px-2 py-0-5" style="font-size: 0.7rem;">
                                    <?php echo $item['event_count']; ?> Log Kejadian
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="text-dark small d-block"><?php echo $item['finish_time']; ?></span>
                            <span class="text-muted small" style="font-size: 0.7rem;">Mulai: <?php echo $item['start_time']; ?></span>
                        </td>
                        <td class="text-end">
                            <form method="post" action="exam_review.php?action=resolve" class="d-inline-flex gap-2">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="sessionid" value="<?php echo $item['session_id']; ?>">
                                <button type="submit" name="decision" value="approved" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 font-semibold" title="Pulihkan dan loloskan hasil ujian">
                                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">check</span>
                                    Setujui
                                </button>
                                <button type="submit" name="decision" value="invalidated" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 font-semibold" title="Batalkan nilai siswa karena kecurangan fatal">
                                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">close</span>
                                    Batalkan
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($reviewlist)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <span class="material-symbols-outlined text-success" style="font-size: 48px;">task_alt</span>
                                <h5 class="fw-bold mt-2 mb-1">Semua Sesi Ujian Bersih!</h5>
                                <p class="text-muted small mb-0">Tidak ada ujian siswa yang sedang ditangguhkan atau menunggu peninjauan saat ini.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
