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
 * Attendance Management and Student Tracking Page for Lumina LMS.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/attendance_manager.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

use local_lumina_core\attendance_manager;

$id = required_param('id', PARAM_INT); // Course ID.
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($course->id);
$roleinfo = theme_lumina_role_resolver::resolve_role($USER);
$canmanage = $roleinfo['is_admin'] || has_capability('moodle/course:update', $context) || has_capability('local/lumina_core:manageattendance', $context);

$PAGE->set_url('/local/lumina_core/attendance.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_title('Presensi Kehadiran Kelas - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// Ensure default session exists if teacher/admin is accessing.
if ($canmanage) {
    $currentsession = attendance_manager::ensure_default_session($course->id, $USER->id);
    if (!$sessionid) {
        $sessionid = $currentsession->id;
    }
}

$notice = '';
$noticetype = 'info';

// Handle Actions (Create Session / Save Roster)
if ($canmanage && data_submitted() && confirm_sesskey()) {
    if ($action === 'create_session') {
        $name = required_param('session_name', PARAM_TEXT);
        $desc = optional_param('session_desc', '', PARAM_TEXT);
        $date = optional_param('session_date', '', PARAM_TEXT);

        $sessiondate = $date ? strtotime($date) : time();
        $newsess = new stdClass();
        $newsess->courseid = $course->id;
        $newsess->teacherid = $USER->id;
        $newsess->name = clean_text($name);
        $newsess->description = clean_text($desc);
        $newsess->sessiondate = $sessiondate;
        $newsess->timestart = $sessiondate;
        $newsess->timeend = $sessiondate + 7200;
        $newsess->timecreated = time();
        $newsess->timemodified = time();

        $sessionid = $DB->insert_record('local_lumina_att_sessions', $newsess);
        $notice = 'Sesi pertemuan baru berhasil dibuat!';
        $noticetype = 'success';
    } else if ($action === 'save_roster' && $sessionid) {
        $statuses = optional_param_array('status', [], PARAM_ALPHA);
        $remarks = optional_param_array('remarks', [], PARAM_TEXT);

        $records = [];
        foreach ($statuses as $uid => $st) {
            $records[] = [
                'userid'  => (int)$uid,
                'status'  => $st,
                'remarks' => $remarks[$uid] ?? '',
            ];
        }

        attendance_manager::save_roster($sessionid, $course->id, $USER->id, $records);
        $notice = 'Data presensi berhasil disimpan ke database!';
        $noticetype = 'success';
    }
}

// Fetch Sessions
$sessions = attendance_manager::get_course_sessions($course->id);
if ($sessionid && !isset($sessions[$sessionid])) {
    $sessionid = !empty($sessions) ? reset($sessions)->id : 0;
}

echo $OUTPUT->header();
?>

<div class="lumina-attendance-container py-3">
    <!-- Breadcrumb & Hero Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-primary" style="font-size: 32px;">groups</span>
                Daftar Kelas & Presensi Kehadiran
            </h2>
            <span class="text-muted small">
                Mata Kuliah: <strong><?php echo format_string($course->fullname); ?></strong> (<?php echo format_string($course->shortname); ?>)
            </span>
        </div>

        <?php if ($canmanage): ?>
        <div>
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" data-bs-toggle="modal" data-bs-target="#createSessionModal">
                <span class="material-symbols-outlined" style="font-size: 18px;">add_circle</span>
                Buat Sesi Pertemuan Baru
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($notice): ?>
    <div class="alert alert-<?php echo $noticetype; ?> rounded-2xl alert-dismissible fade show mb-4 shadow-xs" role="alert">
        <div class="d-flex align-items-center gap-2">
            <span class="material-symbols-outlined">check_circle</span>
            <strong><?php echo $notice; ?></strong>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($canmanage): ?>
        <!-- ================================================================= -->
        <!-- GURU / ADMIN VIEW: SESSION SELECTOR & ROSTER MANAGEMENT           -->
        <!-- ================================================================= -->
        <div class="row g-4">
            <!-- Sidebar: Sessions List -->
            <div class="col-lg-4">
                <div class="antigravity-glass-card rounded-3xl p-4 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <span class="fw-bold">Sesi Pertemuan</span>
                        <span class="badge bg-primary bg-opacity-10 text-primary border rounded-pill px-2 py-0-5 font-semibold" style="font-size: 0.72rem;">
                            <?php echo count($sessions); ?> Sesi
                        </span>
                    </div>

                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($sessions as $s): ?>
                            <?php $isactive = ($s->id == $sessionid); ?>
                            <a href="?id=<?php echo $course->id; ?>&sessionid=<?php echo $s->id; ?>" class="p-3 rounded-2xl border text-decoration-none transition-all <?php echo $isactive ? 'bg-primary text-white shadow-xs' : 'bg-white text-dark hover-bg-light'; ?>">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="font-bold small <?php echo $isactive ? 'text-white' : 'text-dark'; ?>">
                                        <?php echo format_string($s->name); ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-2 <?php echo $isactive ? 'text-white-50' : 'text-muted'; ?> small" style="font-size: 0.75rem;">
                                    <span class="material-symbols-outlined" style="font-size: 14px;">calendar_today</span>
                                    <span><?php echo userdate($s->sessiondate, '%d %b %Y'); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>

                        <?php if (empty($sessions)): ?>
                            <p class="text-muted small text-center py-4">Belum ada sesi pertemuan yang dibuat.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main: Active Session Student Roster -->
            <div class="col-lg-8">
                <div class="antigravity-glass-card rounded-3xl p-4">
                    <?php if ($sessionid && isset($sessions[$sessionid])): ?>
                        <?php
                        $activesess = $sessions[$sessionid];
                        $roster = attendance_manager::get_session_roster($course->id, $sessionid);
                        ?>
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-0"><?php echo format_string($activesess->name); ?></h4>
                                <span class="text-muted small">
                                    Tanggal: <?php echo userdate($activesess->sessiondate, '%A, %d %B %Y'); ?> &bull; Total: <strong><?php echo count($roster); ?> Siswa</strong>
                                </span>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 font-semibold" onclick="markAllPresent()">
                                <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -3px;">done_all</span>
                                Tandai Semua Hadir
                            </button>
                        </div>

                        <form method="post" action="attendance.php?id=<?php echo $course->id; ?>&sessionid=<?php echo $sessionid; ?>&action=save_roster">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                            <div class="table-responsive mb-4">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr style="font-size: 0.8rem;">
                                            <th>Mahasiswa / Siswa</th>
                                            <th class="text-center" style="width: 320px;">Status Presensi</th>
                                            <th>Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($roster as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.85rem;">
                                                        <?php echo strtoupper(substr($row['fullname'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <span class="fw-semibold d-block text-dark" style="font-size: 0.88rem;"><?php echo $row['fullname']; ?></span>
                                                        <span class="text-muted small" style="font-size: 0.75rem;"><?php echo $row['email']; ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group w-100" role="group" aria-label="Status Presensi">
                                                    <input type="radio" class="btn-check" name="status[<?php echo $row['userid']; ?>]" id="pres_<?php echo $row['userid']; ?>_present" value="present" <?php echo $row['is_present'] ? 'checked' : ''; ?>>
                                                    <label class="btn btn-outline-success btn-sm py-1 font-semibold" for="pres_<?php echo $row['userid']; ?>_present">Hadir</label>

                                                    <input type="radio" class="btn-check" name="status[<?php echo $row['userid']; ?>]" id="pres_<?php echo $row['userid']; ?>_sick" value="sick" <?php echo $row['is_sick'] ? 'checked' : ''; ?>>
                                                    <label class="btn btn-outline-warning btn-sm py-1 font-semibold" for="pres_<?php echo $row['userid']; ?>_sick">Sakit</label>

                                                    <input type="radio" class="btn-check" name="status[<?php echo $row['userid']; ?>]" id="pres_<?php echo $row['userid']; ?>_permitted" value="permitted" <?php echo $row['is_permitted'] ? 'checked' : ''; ?>>
                                                    <label class="btn btn-outline-info btn-sm py-1 font-semibold" for="pres_<?php echo $row['userid']; ?>_permitted">Izin</label>

                                                    <input type="radio" class="btn-check" name="status[<?php echo $row['userid']; ?>]" id="pres_<?php echo $row['userid']; ?>_absent" value="absent" <?php echo $row['is_absent'] ? 'checked' : ''; ?>>
                                                    <label class="btn btn-outline-danger btn-sm py-1 font-semibold" for="pres_<?php echo $row['userid']; ?>_absent">Alpa</label>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="remarks[<?php echo $row['userid']; ?>]" value="<?php echo s($row['remarks']); ?>" class="form-control form-control-sm rounded-pill px-3" placeholder="Keterangan opsional...">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>

                                        <?php if (empty($roster)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">
                                                Belum ada siswa yang terdaftar di kelas ini.
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 py-2 font-semibold shadow-xs">
                                    <span class="material-symbols-outlined" style="font-size: 18px; vertical-align: -3px;">save</span>
                                    Simpan Perubahan Presensi
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <span class="material-symbols-outlined text-muted" style="font-size: 48px;">event_busy</span>
                            <h5 class="fw-bold mt-2">Pilih atau Buat Sesi Pertemuan</h5>
                            <p class="text-muted small">Silakan pilih sesi di sebelah kiri atau klik tombol "Buat Sesi Baru".</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Buat Sesi Baru -->
        <div class="modal fade" id="createSessionModal" tabindex="-1" aria-labelledby="createSessionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-3xl border-0 shadow">
                    <form method="post" action="attendance.php?id=<?php echo $course->id; ?>&action=create_session">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <div class="modal-header border-bottom px-4 pt-4">
                            <h5 class="modal-title fw-bold" id="createSessionModalLabel">Buat Sesi Pertemuan Baru</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted">Judul Pertemuan</label>
                                <input type="text" name="session_name" class="form-control rounded-2xl" placeholder="Contoh: Pertemuan 02: Praktikum Database" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted">Tanggal Pertemuan</label>
                                <input type="date" name="session_date" class="form-control rounded-2xl" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted">Deskripsi Singkat / Topik Bahasan</label>
                                <textarea name="session_desc" class="form-control rounded-2xl" rows="3" placeholder="Pokok bahasan yang diajarkan pada sesi ini..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-top px-4 pb-4">
                            <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Simpan Sesi</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function markAllPresent() {
            document.querySelectorAll('input[type="radio"][value="present"]').forEach(function(radio) {
                radio.checked = true;
            });
        }
        </script>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- MURID / SISWA VIEW: PERSONAL ATTENDANCE SUMMARY & LOGS            -->
        <!-- ================================================================= -->
        <?php
        $studentsummary = attendance_manager::get_student_summary($course->id, $USER->id);
        ?>
        <div class="row g-4 mb-4">
            <!-- Student KPI Card -->
            <div class="col-md-3 col-sm-6">
                <div class="antigravity-glass-card rounded-3xl p-4 text-center">
                    <span class="text-muted small font-semibold text-uppercase d-block mb-1">Tingkat Kehadiran</span>
                    <h2 class="fw-bold text-primary mb-0"><?php echo $studentsummary['percent']; ?>%</h2>
                    <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 mt-2 font-semibold">
                        <?php echo $studentsummary['percent'] >= 75 ? 'Memenuhi Syarat Ujian' : 'Di Bawah Standar (<75%)'; ?>
                    </span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="antigravity-glass-card rounded-3xl p-4 text-center">
                    <span class="text-muted small font-semibold text-uppercase d-block mb-1">Total Pertemuan</span>
                    <h2 class="fw-bold text-dark mb-0"><?php echo $studentsummary['total_sessions']; ?></h2>
                    <span class="text-muted small mt-2 d-block">Sesi terjadwal semester ini</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="antigravity-glass-card rounded-3xl p-4 text-center">
                    <span class="text-muted small font-semibold text-uppercase d-block mb-1">Hadir Tepat Waktu</span>
                    <h2 class="fw-bold text-success mb-0"><?php echo $studentsummary['present_count']; ?></h2>
                    <span class="text-success small mt-2 d-block font-semibold">Tercatat aktif di kelas</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="antigravity-glass-card rounded-3xl p-4 text-center">
                    <span class="text-muted small font-semibold text-uppercase d-block mb-1">Izin / Sakit / Alpa</span>
                    <h2 class="fw-bold text-warning mb-0">
                        <?php echo $studentsummary['sick_count'] + $studentsummary['permit_count']; ?>
                        <span class="text-danger small" style="font-size: 1rem;">(<?php echo $studentsummary['absent_count']; ?> Alpa)</span>
                    </h2>
                    <span class="text-muted small mt-2 d-block">Riwayat ketidakhadiran</span>
                </div>
            </div>
        </div>

        <!-- Student Attendance Detail Table -->
        <div class="antigravity-glass-card rounded-3xl p-4">
            <h5 class="fw-bold mb-3 pb-2 border-bottom">Riwayat Kehadiran Personal Anda</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr style="font-size: 0.8rem;">
                            <th>Sesi Pertemuan</th>
                            <th>Tanggal</th>
                            <th>Status Kehadiran</th>
                            <th>Keterangan / Catatan Dosen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentsummary['records'] as $rec): ?>
                        <tr>
                            <td class="fw-semibold text-dark"><?php echo $rec['session_name']; ?></td>
                            <td class="text-muted small"><?php echo $rec['date_str']; ?></td>
                            <td>
                                <span class="badge rounded-pill px-3 py-1 font-semibold <?php echo $rec['badge_class']; ?>">
                                    <?php echo $rec['status_label']; ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?php echo $rec['remarks'] ? s($rec['remarks']) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($studentsummary['records'])): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                Belum ada riwayat presensi tercatat untuk Anda di kelas ini.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();

