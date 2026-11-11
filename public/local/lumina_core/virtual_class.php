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
 * Lumina LMS Virtual Class Hub: Zoom & Google Meet Management.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/virtual_class_manager.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

use local_lumina_core\virtual_class_manager;

require_login();

$action   = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);

$roleinfo = theme_lumina_role_resolver::resolve_role($USER);
$isadmin   = $roleinfo['is_admin'];
$isteacher = $roleinfo['is_teacher'];
$isstudent = $roleinfo['is_student'];

// Handle POST actions for Teachers / Admins.
if (!empty($action) && confirm_sesskey()) {
    if (!$isteacher && !$isadmin) {
        throw new \moodle_exception('nopermissions', 'error', '', 'kelola kelas virtual');
    }

    if ($courseid > 0) {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if ($context && ($isadmin || is_siteadmin($USER->id) || has_capability('moodle/course:update', $context) || has_capability('moodle/course:manageactivities', $context))) {
            if ($action === 'save') {
                $meeturl   = required_param('meet_url', PARAM_RAW_TRIMMED);
                $meettitle = optional_param('meet_title', '', PARAM_TEXT);
                virtual_class_manager::set_course_meeting($courseid, $meeturl, $meettitle, $USER->id);
                \core\notification::success('Tautan kelas virtual berhasil disimpan dan dipublikasikan ke mahasiswa!');
            } else if ($action === 'clear') {
                virtual_class_manager::clear_course_meeting($courseid);
                \core\notification::info('Sesi tatap muka kelas virtual ini telah diakhiri.');
            }
        }
    }

    redirect(new \moodle_url('/local/lumina_core/virtual_class.php'));
}

$PAGE->set_url(new \moodle_url('/local/lumina_core/virtual_class.php'));
$PAGE->set_context(\context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Kelas Virtual (Zoom & Google Meet) • Lumina LMS');
$PAGE->set_heading('Kelas Virtual Tatap Muka');

echo $OUTPUT->header();
?>

<style>
/* Lumina Clean Virtual Class Styling */
.lumina-virtual-container {
    max-width: 1140px;
    margin: 0 auto;
    padding: 1rem 0 3rem 0;
}

.virtual-page-header {
    margin-bottom: 2rem;
}

.meet-course-card {
    background: #FFFFFF !important;
    border: 1px solid #E2E8F0 !important;
    border-radius: 20px !important;
    padding: 1.75rem !important;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04) !important;
    transition: all 0.25s ease !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
}

.meet-course-card:hover {
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.08) !important;
    transform: translateY(-2px);
}

.meet-course-card.is-active {
    border-color: #34D399 !important;
    background: linear-gradient(180deg, #FFFFFF 0%, #F0FDF4 100%) !important;
}

.live-pulse-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    background: #10B981;
    color: #FFFFFF;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.35rem 0.85rem;
    border-radius: 9999px;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
}

.live-dot {
    width: 7px;
    height: 7px;
    background: #FFFFFF;
    border-radius: 50%;
    animation: live-pulse 1.4s infinite;
}

@keyframes live-pulse {
    0% { transform: scale(0.9); opacity: 0.7; }
    50% { transform: scale(1.4); opacity: 1; }
    100% { transform: scale(0.9); opacity: 0.7; }
}

.platform-brand-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.3rem 0.75rem;
    border-radius: 9999px;
    border: 1px solid transparent;
}

.join-meeting-btn {
    border-radius: 9999px !important;
    padding: 0.8rem 1.5rem !important;
    font-size: 0.95rem !important;
    font-weight: 600 !important;
    width: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0.6rem !important;
    text-decoration: none !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25) !important;
}

.join-meeting-btn:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35) !important;
}

.meeting-info-box {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
</style>

<div class="lumina-virtual-container">

    <!-- Header Section (Clean Lumina Standard) -->
    <div class="virtual-page-header d-flex align-items-center justify-content-between flex-wrap gap-3 pb-3 border-bottom">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2" style="font-size: 1.6rem;">
                <span class="material-symbols-outlined text-primary" style="font-size: 32px;">video_camera_front</span>
                Kelas Virtual &amp; Sesi Tatap Muka
            </h2>
            <span class="text-muted small">
                <?php if ($isteacher || $isadmin): ?>
                    Kelola tautan Google Meet atau Zoom untuk kelas Anda. Mahasiswa terdaftar akan langsung melihat tautan untuk bergabung.
                <?php else: ?>
                    Bergabung ke sesi perkuliahan tatap muka daring terjadwal bersama dosen pengampu Anda.
                <?php endif; ?>
            </span>
        </div>

        <div>
            <div class="d-flex align-items-center gap-2 bg-light border px-3 py-1-5 rounded-pill small">
                <span class="text-muted">Platform:</span>
                <span class="badge bg-white text-danger border rounded-pill px-2 py-0-5 d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-video"></i> Google Meet
                </span>
                <span class="badge bg-white text-primary border rounded-pill px-2 py-0-5 d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-video"></i> Zoom
                </span>
            </div>
        </div>
    </div>

    <!-- MAIN BODY -->
    <?php if ($isteacher || $isadmin): ?>
        <!-- ============================================================= -->
        <!-- TEACHER VIEW                                                  -->
        <!-- ============================================================= -->
        <?php
        $teachercourses = virtual_class_manager::get_teacher_course_meetings($USER->id);
        ?>
        <div class="mb-4">
            <h4 class="h5 fw-bold mb-1">Daftar Kelas yang Anda Ampu</h4>
            <p class="text-muted small mb-0">Masukkan tautan rapat dan agenda pertemuan. Tautan hanya akan muncul untuk siswa di kelas tersebut.</p>
        </div>

        <?php if (empty($teachercourses)): ?>
            <div class="p-5 text-center bg-white rounded-3xl border">
                <span class="material-symbols-outlined text-muted" style="font-size: 48px;">school</span>
                <h5 class="mt-3 fw-bold">Belum Ada Kelas yang Diampu</h5>
                <p class="text-muted small">Anda belum ditugaskan sebagai pengajar pada kelas mana pun.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($teachercourses as $tc): ?>
                    <div class="col-lg-6">
                        <div class="meet-course-card <?php echo $tc['has_meeting'] ? 'is-active' : ''; ?>">
                            <div>
                                <!-- Card Header -->
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2 py-0-5 font-semibold" style="font-size: 0.72rem;">
                                            <?php echo s($tc['course_shortname']); ?>
                                        </span>
                                        <h5 class="fw-bold mt-2 mb-1" style="font-size: 1.15rem;">
                                            <?php echo s($tc['course_fullname']); ?>
                                        </h5>
                                        <span class="text-muted small">
                                            <i class="fa-solid fa-users me-1 text-primary"></i><?php echo $tc['students_count']; ?> Siswa Terdaftar
                                        </span>
                                    </div>
                                    <div>
                                        <?php if ($tc['has_meeting']): ?>
                                            <span class="live-pulse-badge">
                                                <span class="live-dot"></span> Sesi Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border rounded-pill px-2-5 py-1">
                                                Belum Ada Sesi
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Active Session Details (if present) -->
                                <?php if ($tc['has_meeting']): ?>
                                    <div class="meeting-info-box border-success bg-white">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="platform-brand-pill <?php echo $tc['badge_class']; ?>">
                                                <span class="material-symbols-outlined" style="font-size: 15px;"><?php echo $tc['icon']; ?></span>
                                                <?php echo s($tc['platform']); ?>
                                            </span>
                                            <span class="text-muted small" style="font-size: 0.75rem;">
                                                Diperbarui <?php echo s($tc['updated_ago']); ?>
                                            </span>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">
                                            <?php echo s($tc['meet_title']); ?>
                                        </h6>
                                        <a href="<?php echo s($tc['meet_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-truncate d-block small text-primary mb-3">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><?php echo s($tc['meet_url']); ?>
                                        </a>
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo s($tc['meet_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success rounded-pill px-3 py-1-5 text-white font-semibold d-inline-flex align-items-center gap-1 shadow-xs">
                                                <span class="material-symbols-outlined" style="font-size: 16px;">launch</span> Buka Sesi Langsung
                                            </a>
                                            <form method="post" action="<?php echo (new \moodle_url('/local/lumina_core/virtual_class.php'))->out(false); ?>" class="d-inline" onsubmit="return confirm('Akhiri dan hapus sesi kelas virtual ini? Mahasiswa tidak akan melihat tautan ini lagi.');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="clear">
                                                <input type="hidden" name="courseid" value="<?php echo $tc['courseid']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1-5">
                                                    Akhiri Sesi
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Input Form to Set or Update URL -->
                                <form method="post" action="<?php echo (new \moodle_url('/local/lumina_core/virtual_class.php'))->out(false); ?>" class="mt-3 pt-3 border-top">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="save">
                                    <input type="hidden" name="courseid" value="<?php echo $tc['courseid']; ?>">

                                    <div class="mb-2">
                                        <label class="form-label small fw-bold text-muted mb-1">Tautan Rapat (Google Meet / Zoom URL):</label>
                                        <input type="url" name="meet_url" class="form-control form-control-sm rounded-xl" placeholder="https://meet.google.com/... atau https://zoom.us/j/..." value="<?php echo s($tc['meet_url']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-muted mb-1">Topik / Agenda Pertemuan (Opsional):</label>
                                        <input type="text" name="meet_title" class="form-control form-control-sm rounded-xl" placeholder="cth: Pertemuan 01 - Pengantar Materi" value="<?php echo s($tc['meet_title']); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4 py-2 w-100 font-semibold shadow-xs">
                                        <i class="fa-solid fa-floppy-disk me-1"></i> <?php echo $tc['has_meeting'] ? 'Perbarui Tautan Sesi' : 'Simpan & Publikasikan Sesi'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ============================================================= -->
        <!-- STUDENT VIEW                                                  -->
        <!-- ============================================================= -->
        <?php
        $studentmeetings = virtual_class_manager::get_student_active_meetings($USER->id);
        ?>
        <div class="mb-4">
            <h4 class="h5 fw-bold mb-1">Sesi Tatap Muka Terjadwal</h4>
            <p class="text-muted small mb-0">Hanya menampilkan ruang tatap muka dari mata kuliah yang Anda ikuti.</p>
        </div>

        <?php if (!empty($studentmeetings)): ?>
            <div class="row g-4 mb-4">
                <?php foreach ($studentmeetings as $sm): ?>
                    <div class="col-lg-6">
                        <div class="meet-course-card is-active shadow-sm">
                            <!-- Card Content (Top) -->
                            <div class="mb-3">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border rounded-pill px-2 py-0-5 font-semibold" style="font-size: 0.72rem;">
                                            <?php echo s($sm['course_shortname']); ?>
                                        </span>
                                        <h5 class="fw-bold mt-2 mb-1" style="font-size: 1.25rem;">
                                            <?php echo s($sm['course_fullname']); ?>
                                        </h5>
                                        <?php if (!empty($sm['teacher_name'])): ?>
                                            <span class="text-muted small d-inline-flex align-items-center gap-1">
                                                <i class="fa-solid fa-chalkboard-user text-primary"></i> Dosen: <strong><?php echo s($sm['teacher_name']); ?></strong>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="live-pulse-badge">
                                        <span class="live-dot"></span> LIVE SEKARANG
                                    </span>
                                </div>

                                <div class="meeting-info-box">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="platform-brand-pill <?php echo $sm['badge_class']; ?>">
                                            <span class="material-symbols-outlined" style="font-size: 15px;"><?php echo $sm['icon']; ?></span>
                                            <?php echo s($sm['platform']); ?>
                                        </span>
                                        <span class="text-muted small">
                                            Aktif sejak <?php echo s($sm['updated_ago']); ?>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-dark mt-2 mb-0" style="font-size: 1rem;">
                                        <?php echo s($sm['title']); ?>
                                    </h6>
                                </div>
                            </div>

                            <!-- Button (Bottom - 100% Full Width) -->
                            <div class="pt-2 border-top">
                                <a href="<?php echo s($sm['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success text-white join-meeting-btn">
                                    <span class="material-symbols-outlined" style="font-size: 20px;">video_camera_front</span>
                                    <span>Buka <?php echo s($sm['platform']); ?> &amp; Masuk Kelas</span>
                                    <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-5 text-center bg-white rounded-3xl border shadow-xs">
                <span class="material-symbols-outlined text-muted" style="font-size: 56px;">videocam_off</span>
                <h5 class="mt-3 fw-bold">Belum Ada Sesi Kelas Daring yang Sedang Berlangsung</h5>
                <p class="text-muted small" style="max-width: 520px; margin: 0 auto;">
                    Dosen pengampu mata kuliah Anda belum membuka ruang Google Meet atau Zoom saat ini. Tautan tatap muka akan otomatis tersedia di sini begitu sesi dimulai oleh dosen.
                </p>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
