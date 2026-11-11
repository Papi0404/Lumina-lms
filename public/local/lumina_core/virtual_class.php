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
        if ($context && ($isadmin || has_capability('moodle/course:update', $context) || has_capability('moodle/course:manageactivities', $context))) {
            if ($action === 'save') {
                $meeturl   = required_param('meet_url', PARAM_RAW_TRIMMED);
                $meettitle = optional_param('meet_title', '', PARAM_TEXT);
                virtual_class_manager::set_course_meeting($courseid, $meeturl, $meettitle, $USER->id);
                \core\notification::success('Tautan kelas virtual berhasil diperbarui dan dipublikasikan ke mahasiswa!');
            } else if ($action === 'clear') {
                virtual_class_manager::clear_course_meeting($courseid);
                \core\notification::info('Sesi kelas virtual untuk mata kuliah ini telah diakhiri.');
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
/* Lumina Virtual Class UI */
.virtual-class-wrapper {
    max-width: 1140px;
    margin: 0 auto;
    padding-bottom: 3rem;
}
.virtual-header-card {
    background: linear-gradient(135deg, #1E1B4B 0%, #312E81 50%, #4338CA 100%);
    color: #FFFFFF;
    border-radius: 20px;
    padding: 2.25rem 2.5rem;
    box-shadow: 0 10px 30px rgba(49, 46, 129, 0.2);
    position: relative;
    overflow: hidden;
    margin-bottom: 2rem;
}
.virtual-header-card::after {
    content: '';
    position: absolute;
    right: -40px;
    bottom: -40px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.06);
    pointer-events: none;
}
.live-pulse-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: #10B981;
    color: #FFFFFF;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.35rem 0.85rem;
    border-radius: 9999px;
    box-shadow: 0 0 12px rgba(16, 185, 129, 0.4);
}
.live-dot {
    width: 8px;
    height: 8px;
    background: #FFFFFF;
    border-radius: 50%;
    animation: pulse-dot 1.5s infinite;
}
@keyframes pulse-dot {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.35); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
.meet-course-card {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    transition: all 0.2s ease;
    height: 100%;
    display: flex;
    flex-column: column;
    justify-content: space-between;
}
.meet-course-card:hover {
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    transform: translateY(-2px);
}
.meet-course-card.is-active {
    border-color: #A7F3D0;
    background: linear-gradient(180deg, #FFFFFF 0%, #F0FDF4 100%);
}
.platform-brand-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 0.3rem 0.75rem;
    border-radius: 9999px;
    border: 1px solid transparent;
}
</style>

<div class="virtual-class-wrapper">

    <!-- Header Hero Banner -->
    <div class="virtual-header-card">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-15 px-3 py-1 rounded-pill mb-3" style="font-size: 0.8rem; backdrop-filter: blur(8px);">
                    <span class="material-symbols-outlined" style="font-size: 16px;">videocam</span>
                    <span>Lumina Video Conference Connector</span>
                </div>
                <h2 class="h3 font-bold mb-2">Kelas Virtual &amp; Sesi Tatap Muka</h2>
                <p class="mb-0 text-white text-opacity-80" style="max-width: 650px; font-size: 0.95rem; line-height: 1.6;">
                    <?php if ($isteacher || $isadmin): ?>
                        Masukkan tautan Google Meet atau Zoom untuk mata kuliah Anda. Mahasiswa yang terdaftar di kelas akan langsung melihat tautan dan tombol bergabung.
                    <?php else: ?>
                        Bergabung ke pertemuan tatap muka daring terjadwal bersama dosen pengampu Anda melalui Google Meet atau Zoom.
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-4 mt-lg-0">
                <div class="p-3 bg-white bg-opacity-10 rounded-2xl d-inline-block text-start" style="backdrop-filter: blur(8px); min-width: 220px;">
                    <span class="small text-white text-opacity-75 d-block mb-1">Platform Didukung:</span>
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge bg-white text-dark rounded-pill px-2-5 py-1 d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-video text-danger"></i> Google Meet
                        </span>
                        <span class="badge bg-white text-dark rounded-pill px-2-5 py-1 d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-video text-primary"></i> Zoom
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content by Role -->
    <?php if ($isteacher || $isadmin): ?>
        <?php
        $teachercourses = virtual_class_manager::get_teacher_course_meetings($USER->id);
        ?>
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h4 class="h5 fw-bold mb-1">Kelola Sesi Tatap Muka Kelas Anda</h4>
                <p class="text-muted small mb-0">Tautan yang disimpan hanya akan dapat dilihat oleh siswa yang terdaftar di kelas tersebut.</p>
            </div>
            <a href="<?php echo (new \moodle_url('/my/'))->out(false); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1-5">
                ← Kembali ke Dashboard
            </a>
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
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2 py-0-5 font-semibold" style="font-size: 0.72rem;">
                                            <?php echo s($tc['course_shortname']); ?>
                                        </span>
                                        <h5 class="fw-bold mt-2 mb-1" style="font-size: 1.1rem;">
                                            <?php echo s($tc['course_fullname']); ?>
                                        </h5>
                                        <span class="text-muted small">
                                            <i class="fa-solid fa-users me-1"></i><?php echo $tc['students_count']; ?> Siswa Terdaftar
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

                                <?php if ($tc['has_meeting']): ?>
                                    <div class="p-3 rounded-2xl bg-success bg-opacity-10 border border-success border-opacity-25 mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="platform-brand-pill <?php echo $tc['badge_class']; ?>">
                                                <span class="material-symbols-outlined" style="font-size: 16px;"><?php echo $tc['icon']; ?></span>
                                                <?php echo s($tc['platform']); ?>
                                            </span>
                                            <span class="text-muted small" style="font-size: 0.75rem;">
                                                Diperbarui <?php echo s($tc['updated_ago']); ?>
                                            </span>
                                        </div>
                                        <p class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">
                                            <?php echo s($tc['meet_title']); ?>
                                        </p>
                                        <a href="<?php echo s($tc['meet_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-truncate d-block small text-primary mb-2">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><?php echo s($tc['meet_url']); ?>
                                        </a>
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo s($tc['meet_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success rounded-pill px-3 py-1 text-white font-semibold d-inline-flex align-items-center gap-1">
                                                <span class="material-symbols-outlined" style="font-size: 16px;">launch</span> Buka Sesi Langsung
                                            </a>
                                            <form method="post" action="<?php echo (new \moodle_url('/local/lumina_core/virtual_class.php'))->out(false); ?>" class="d-inline" onsubmit="return confirm('Akhiri dan hapus sesi kelas virtual ini? Mahasiswa tidak akan melihat tautan ini lagi.');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="clear">
                                                <input type="hidden" name="courseid" value="<?php echo $tc['courseid']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1">
                                                    Akhiri Sesi
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Form to Set / Update URL -->
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
                                        <input type="text" name="meet_title" class="form-control form-control-sm rounded-xl" placeholder="cth: Pertemuan 03 - Responsive Grid & Flexbox" value="<?php echo s($tc['meet_title']); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4 py-1-5 w-100 font-semibold shadow-xs">
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
        <!-- Student View: Only Show Enrolled Courses with Active Meetings -->
        <?php
        $studentmeetings = virtual_class_manager::get_student_active_meetings($USER->id);
        ?>
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h4 class="h5 fw-bold mb-1">Sesi Tatap Muka Terjadwal</h4>
                <p class="text-muted small mb-0">Hanya menampilkan ruang tatap muka dari mata kuliah yang Anda ikuti.</p>
            </div>
            <a href="<?php echo (new \moodle_url('/my/'))->out(false); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1-5">
                ← Kembali ke Dashboard
            </a>
        </div>

        <?php if (!empty($studentmeetings)): ?>
            <div class="row g-4 mb-4">
                <?php foreach ($studentmeetings as $sm): ?>
                    <div class="col-lg-6">
                        <div class="meet-course-card is-active shadow-sm p-4">
                            <div>
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border rounded-pill px-2 py-0-5 font-semibold" style="font-size: 0.72rem;">
                                            <?php echo s($sm['course_shortname']); ?>
                                        </span>
                                        <h5 class="fw-bold mt-2 mb-1" style="font-size: 1.15rem;">
                                            <?php echo s($sm['course_fullname']); ?>
                                        </h5>
                                        <?php if (!empty($sm['teacher_name'])): ?>
                                            <span class="text-muted small">
                                                <i class="fa-solid fa-chalkboard-user me-1 text-primary"></i>Dosen: <strong><?php echo s($sm['teacher_name']); ?></strong>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="live-pulse-badge">
                                        <span class="live-dot"></span> LIVE SEKARANG
                                    </span>
                                </div>

                                <div class="p-3 rounded-2xl bg-white border mb-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="platform-brand-pill <?php echo $sm['badge_class']; ?>">
                                            <span class="material-symbols-outlined" style="font-size: 16px;"><?php echo $sm['icon']; ?></span>
                                            <?php echo s($sm['platform']); ?>
                                        </span>
                                        <span class="text-muted small">
                                            Aktif sejak <?php echo s($sm['updated_ago']); ?>
                                        </span>
                                    </div>
                                    <p class="fw-bold text-dark mt-2 mb-0" style="font-size: 0.95rem;">
                                        <?php echo s($sm['title']); ?>
                                    </p>
                                </div>
                            </div>

                            <a href="<?php echo s($sm['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-lg btn-success text-white rounded-pill py-2-5 px-4 font-semibold w-100 d-inline-flex align-items-center justify-content-center gap-2 shadow-sm">
                                <span class="material-symbols-outlined">video_camera_front</span>
                                <span>Buka <?php echo s($sm['platform']); ?> &amp; Masuk Kelas</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-5 text-center bg-white rounded-3xl border">
                <span class="material-symbols-outlined text-muted" style="font-size: 56px;">videocam_off</span>
                <h5 class="mt-3 fw-bold">Belum Ada Sesi Kelas Daring yang Sedang Berlangsung</h5>
                <p class="text-muted small" style="max-width: 500px; margin: 0 auto;">
                    Dosen pengampu mata kuliah Anda belum membuka ruang Google Meet atau Zoom saat ini. Tautan tatap muka akan otomatis tersedia di halaman ini dan di kartu dashboard Anda begitu sesi dimulai oleh dosen.
                </p>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();

