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
 * Lumina LMS: Dedicated Material & Module Builder for Teachers.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

require_login();

$roleinfo = theme_lumina_role_resolver::resolve_role($USER);
if (!$roleinfo['is_teacher'] && !$roleinfo['is_admin']) {
    throw new moodle_exception('nopermissions', 'error', '', 'Akses terbatas untuk Pengajar / Guru.');
}

// 1. Determine accessible courses for this teacher
$accessiblecourses = [];
if ($roleinfo['is_admin']) {
    $accessiblecourses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC');
} else {
    $tdata = theme_lumina_role_resolver::get_teacher_dashboard_data($USER);
    $accessiblecourses = $tdata['courses'] ?? [];
    if (empty($accessiblecourses)) {
        $accessiblecourses = enrol_get_all_users_courses($USER->id, true);
    }
}

// 2. Resolve selected course ID
$courseid = optional_param('courseid', 0, PARAM_INT);
$validcourseids = [];
foreach ($accessiblecourses as $c) {
    $validcourseids[] = (int)(is_object($c) ? $c->id : $c['id']);
}

if ($courseid > 0 && !in_array((int)$courseid, $validcourseids, true) && !$roleinfo['is_admin']) {
    $firstcourse = !empty($accessiblecourses) ? reset($accessiblecourses) : null;
    $courseid = $firstcourse ? (int)(is_object($firstcourse) ? $firstcourse->id : $firstcourse['id']) : 0;
} else if (!$courseid && !empty($accessiblecourses)) {
    $firstcourse = reset($accessiblecourses);
    $courseid = (int)(is_object($firstcourse) ? $firstcourse->id : $firstcourse['id']);
}

$course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : null;
$context = $course ? context_course::instance($course->id) : context_system::instance();

$PAGE->set_url('/local/lumina_core/create_material.php', $courseid ? ['courseid' => $courseid] : []);
$PAGE->set_context($context);
$PAGE->set_title('Pusat Pembuatan Materi & Modul - Lumina LMS');
$PAGE->set_heading($course ? format_string($course->fullname) : 'Buat Materi & Modul Baru');

// 3. Resolve sections and modules if course is selected
$sections = [];
$existingmodules = [];
$selectedsection = optional_param('section', 0, PARAM_INT);

if ($course) {
    $modinfo = get_fast_modinfo($course);
    $sectioninfos = $modinfo->get_section_info_all();
    foreach ($sectioninfos as $sec) {
        $secname = get_section_name($course, $sec);
        $sections[$sec->section] = [
            'num' => $sec->section,
            'name' => $secname ?: ('Topik / Pertemuan ' . $sec->section),
        ];
    }
    // Default to section 1 if available and section 0 not explicitly chosen
    if ($selectedsection === 0 && count($sections) > 1 && !isset($_GET['section'])) {
        $selectedsection = 1;
    }

    foreach ($modinfo->get_cms() as $cm) {
        $secnum = $cm->sectionnum;
        $secobj = $modinfo->get_section_info($secnum);
        $secname = $secobj ? get_section_name($course, $secobj) : 'General';
        $existingmodules[] = [
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname,
            'sectionnum' => $secnum,
            'sectionname' => $secname,
            'url' => $cm->url ? $cm->url->out(false) : '#',
            'editurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out(false),
            'visible' => $cm->visible,
        ];
    }
}

// Module types definition
$moduletypes = [
    [
        'type' => 'resource',
        'title' => 'Berkas / Slide Kuliah',
        'badge' => 'File Dokumen',
        'badge_class' => 'bg-primary-subtle text-primary border border-primary-subtle',
        'icon' => 'picture_as_pdf',
        'icon_color' => '#4338CA',
        'bg_color' => '#EEF2FF',
        'desc' => 'Unggah materi ajar presentasi PPT, e-book PDF, modul praktikum, atau RPS yang bisa diunduh mahasiswa.',
        'btn_text' => '+ Buat Berkas Materi',
    ],
    [
        'type' => 'assign',
        'title' => 'Tugas & Proyek Kuliah',
        'badge' => 'Penugasan Mahasiswa',
        'badge_class' => 'bg-warning-subtle text-warning border border-warning-subtle',
        'icon' => 'assignment',
        'icon_color' => '#D97706',
        'bg_color' => '#FEF3C7',
        'desc' => 'Berikan instruksi penugasan, tentukan tenggat waktu (deadline), kriteria penilaian, dan terima berkas tugas mahasiswa.',
        'btn_text' => '+ Buat Tugas Kuliah',
    ],
    [
        'type' => 'quiz',
        'title' => 'Kuis & Ujian Online',
        'badge' => 'Evaluasi Daring',
        'badge_class' => 'bg-danger-subtle text-danger border border-danger-subtle',
        'icon' => 'quiz',
        'icon_color' => '#DC2626',
        'bg_color' => '#FEE2E2',
        'desc' => 'Buat evaluasi berkala atau ujian daring dengan pilihan ganda, esai, timer pengerjaan, dan proteksi anti-curang.',
        'btn_text' => '+ Buat Kuis / Ujian',
    ],
    [
        'type' => 'page',
        'title' => 'Halaman Bacaan Digital',
        'badge' => 'Modul Teks Interaktif',
        'badge_class' => 'bg-success-subtle text-success border border-success-subtle',
        'icon' => 'article',
        'icon_color' => '#059669',
        'bg_color' => '#D1FAE5',
        'desc' => 'Susun materi ringkasan langsung di web lengkap dengan teks berformat rapi, gambar, dan tabel tanpa perlu download file.',
        'btn_text' => '+ Buat Halaman Materi',
    ],
    [
        'type' => 'url',
        'title' => 'Tautan / Video Eksternal',
        'badge' => 'Tautan Media',
        'badge_class' => 'bg-info-subtle text-info border border-info-subtle',
        'icon' => 'link',
        'icon_color' => '#0284C7',
        'bg_color' => '#E0F2FE',
        'desc' => 'Sematkan tautan video pembelajaran YouTube, rekaman zoom, artikel jurnal ilmiah, atau berkas Google Drive.',
        'btn_text' => '+ Buat Tautan Materi',
    ],
    [
        'type' => 'forum',
        'title' => 'Forum Diskusi Kelas',
        'badge' => 'Kolaborasi Tematik',
        'badge_class' => 'bg-purple-subtle text-purple border border-purple-subtle',
        'icon' => 'forum',
        'icon_color' => '#7C3AED',
        'bg_color' => '#F3E8FF',
        'desc' => 'Buka ruang tanya jawab dan diskusi studi kasus antar mahasiswa yang terfokus pada materi pertemuan ini.',
        'btn_text' => '+ Buat Forum Diskusi',
    ],
];

echo $OUTPUT->header();
?>

<div class="lumina-material-builder-page pb-5">
    <!-- Breadcrumb & Header Title -->
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2 small">
                <li class="breadcrumb-item"><a href="<?php echo $CFG->wwwroot; ?>/my/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item text-muted">Portal Pengajar</li>
                <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Buat Materi &amp; Modul Baru</li>
            </ol>
        </nav>
        
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h3 class="fw-bold mb-1" style="color: #1E293B; letter-spacing: -0.02em;">Pusat Pembuatan Materi &amp; Modul Pembelajaran</h3>
                <p class="text-muted mb-0 small">Pilih mata kuliah dan jenis aktivitas atau materi yang ingin Anda terbitkan untuk mahasiswa di kelas Anda.</p>
            </div>
            <?php if ($course): ?>
            <div>
                <a href="<?php echo (new moodle_url('/course/view.php', ['id' => $course->id, 'edit' => 1, 'sesskey' => sesskey()]))->out(false); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-xs d-inline-flex align-items-center text-nowrap">
                    <span class="material-symbols-outlined align-middle fs-6 me-1">tune</span>
                    Atur Tata Letak (<?php echo s($course->shortname ?: $course->fullname); ?>)
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card: Pilih Mata Kuliah & Pertemuan -->
    <div class="card border-0 rounded-4 shadow-sm mb-4" style="background: #FFFFFF; border: 1px solid #E2E8F0 !important;">
        <div class="card-body p-4">
            <form method="get" action="create_material.php" id="material-filter-form">
                <div class="row g-3 align-items-center">
                    <div class="col-md-6 col-lg-5">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label fw-bold small text-muted text-uppercase mb-0" style="letter-spacing: 0.05em;">1. Pilih Mata Kuliah / Kelas:</label>
                            <?php if ($course): ?>
                            <a href="<?php echo (new moodle_url('/course/view.php', ['id' => $course->id, 'edit' => 1, 'sesskey' => sesskey()]))->out(false); ?>" class="small text-primary fw-semibold text-decoration-none" title="Buka dan tata letak materi kelas ini">
                                <span class="material-symbols-outlined align-middle" style="font-size: 14px;">tune</span> Atur Tata Letak &rarr;
                            </a>
                            <?php endif; ?>
                        </div>
                        <select name="courseid" class="form-select rounded-3 py-2" style="border-color: #CBD5E1; font-weight: 500;" onchange="window.location.href='create_material.php?courseid=' + this.value">
                            <?php if (empty($accessiblecourses)): ?>
                                <option value="">Tidak ada mata kuliah yang diampu</option>
                            <?php else: ?>
                                <?php foreach ($accessiblecourses as $cid => $cobj): ?>
                                    <?php 
                                        $c_id = is_object($cobj) ? $cobj->id : $cobj['id'];
                                        $c_name = is_object($cobj) ? $cobj->fullname : $cobj['fullname'];
                                    ?>
                                    <option value="<?php echo $c_id; ?>" <?php echo ($c_id == $courseid) ? 'selected' : ''; ?>>
                                        <?php echo s($c_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-6 col-lg-5">
                        <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.05em;">2. Pilih Pertemuan / Topik Tujuan:</label>
                        <select name="section" id="section-select" class="form-select rounded-3 py-2" style="border-color: #CBD5E1; font-weight: 500;">
                            <?php if (empty($sections)): ?>
                                <option value="0">General / Umum</option>
                            <?php else: ?>
                                <?php foreach ($sections as $snum => $sdata): ?>
                                    <option value="<?php echo $snum; ?>" <?php echo ($snum == $selectedsection) ? 'selected' : ''; ?>>
                                        <?php echo s($sdata['name']); ?> (Bagian <?php echo $snum; ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-12 col-lg-2 text-lg-end pt-lg-4">
                        <button type="submit" class="btn btn-light border rounded-pill px-3 py-2 w-100 fw-semibold text-secondary text-nowrap" style="white-space: nowrap !important;">
                            Terapkan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$course): ?>
        <div class="alert alert-warning rounded-4 p-4 text-center">
            <span class="material-symbols-outlined fs-1 text-warning mb-2">warning</span>
            <h5 class="fw-bold">Belum Ada Mata Kuliah yang Diampu</h5>
            <p class="text-muted mb-0">Akun pengajar Anda belum terdaftar sebagai pengajar di mata kuliah aktif apa pun. Silakan hubungi Administrator untuk penugasan kelas.</p>
        </div>
    <?php else: ?>

        <!-- Grid 6 Kartu Pembuatan Modul -->
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0 text-dark">Pilih Jenis Modul / Materi yang Ingin Dibuat:</h5>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill small">
                    Target: <strong id="target-section-label" class="text-primary"><?php echo s($sections[$selectedsection]['name'] ?? 'General'); ?></strong>
                </span>
            </div>

            <div class="row g-3">
                <?php foreach ($moduletypes as $mod): ?>
                    <?php 
                        $modurl = (new moodle_url('/course/modedit.php', [
                            'add' => $mod['type'],
                            'course' => $course->id,
                            'section' => $selectedsection
                        ]))->out(false);
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 rounded-4 shadow-sm p-4 d-flex flex-column justify-content-between float-hover" style="background: #FFFFFF; border: 1px solid #E2E8F0 !important; transition: all 0.2s ease;">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="d-flex align-items-center justify-content-center rounded-3" style="width: 48px; height: 48px; background: <?php echo $mod['bg_color']; ?>;">
                                        <span class="material-symbols-outlined fs-3" style="color: <?php echo $mod['icon_color']; ?>;"><?php echo $mod['icon']; ?></span>
                                    </div>
                                    <span class="badge rounded-pill small fw-medium px-2 py-1 <?php echo $mod['badge_class']; ?>">
                                        <?php echo $mod['badge']; ?>
                                    </span>
                                </div>
                                <h6 class="fw-bold mb-2 text-dark"><?php echo $mod['title']; ?></h6>
                                <p class="text-muted small mb-4" style="line-height: 1.5; font-size: 0.85rem;">
                                    <?php echo $mod['desc']; ?>
                                </p>
                            </div>
                            
                            <div>
                                <a href="<?php echo $modurl; ?>" 
                                   class="btn btn-sm w-100 rounded-pill py-2 fw-semibold text-white btn-create-mod shadow-xs" 
                                   data-modtype="<?php echo $mod['type']; ?>"
                                   style="background: <?php echo $mod['icon_color']; ?>; border: none;">
                                    <?php echo $mod['btn_text']; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Daftar Materi / Modul yang Sudah Ada di Kelas Ini -->
        <div class="card border-0 rounded-4 shadow-sm mt-5" style="background: #FFFFFF; border: 1px solid #E2E8F0 !important;">
            <div class="card-header bg-transparent border-0 px-4 pt-4 pb-2 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="fw-bold mb-1 text-dark">Modul &amp; Materi yang Sudah Terbit di Kelas Ini</h5>
                    <p class="text-muted small mb-0">Total terdapat <strong><?php echo count($existingmodules); ?> aktivitas/materi</strong> yang terdaftar di mata kuliah <em><?php echo s($course->fullname); ?></em>.</p>
                </div>
            </div>

            <div class="card-body p-4 pt-2">
                <?php if (empty($existingmodules)): ?>
                    <div class="text-center py-5">
                        <span class="material-symbols-outlined fs-1 text-muted opacity-50 mb-2">folder_open</span>
                        <h6 class="fw-bold text-secondary">Belum ada materi atau tugas di mata kuliah ini</h6>
                        <p class="text-muted small">Silakan pilih salah satu kartu jenis modul di atas untuk menambahkan materi pembelajaran pertama Anda.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="border-collapse: separate; border-spacing: 0;">
                            <thead>
                                <tr class="text-muted small text-uppercase" style="background: #F8FAFC; border-bottom: 2px solid #E2E8F0;">
                                    <th class="py-3 px-3 rounded-start">Jenis</th>
                                    <th class="py-3 px-3">Judul Aktivitas / Materi</th>
                                    <th class="py-3 px-3">Topik / Pertemuan</th>
                                    <th class="py-3 px-3">Status</th>
                                    <th class="py-3 px-3 text-end rounded-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($existingmodules as $m): ?>
                                    <?php
                                        $icon = 'article';
                                        $badgeclass = 'bg-secondary';
                                        switch ($m['modname']) {
                                            case 'resource': $icon = 'picture_as_pdf'; $badgeclass = 'bg-primary-subtle text-primary'; break;
                                            case 'assign': $icon = 'assignment'; $badgeclass = 'bg-warning-subtle text-warning'; break;
                                            case 'quiz': $icon = 'quiz'; $badgeclass = 'bg-danger-subtle text-danger'; break;
                                            case 'page': $icon = 'article'; $badgeclass = 'bg-success-subtle text-success'; break;
                                            case 'url': $icon = 'link'; $badgeclass = 'bg-info-subtle text-info'; break;
                                            case 'forum': $icon = 'forum'; $badgeclass = 'bg-purple-subtle text-purple'; break;
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid #F1F5F9;">
                                        <td class="py-3 px-3" style="width: 140px;">
                                            <span class="badge rounded-pill px-2 py-1 small fw-medium <?php echo $badgeclass; ?>">
                                                <span class="material-symbols-outlined align-middle me-1" style="font-size: 14px;"><?php echo $icon; ?></span>
                                                <?php echo ucfirst($m['modname']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3">
                                            <a href="<?php echo $m['url']; ?>" class="fw-semibold text-dark text-decoration-none">
                                                <?php echo s($m['name']); ?>
                                            </a>
                                        </td>
                                        <td class="py-3 px-3 text-muted small">
                                            <?php echo s($m['sectionname']); ?>
                                        </td>
                                        <td class="py-3 px-3">
                                            <?php if ($m['visible']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small px-2 py-1">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-muted rounded-pill small px-2 py-1">Disembunyikan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="<?php echo $m['url']; ?>" class="btn btn-sm btn-light rounded-pill px-3 py-1 fw-medium text-secondary" title="Lihat Materi">
                                                    Lihat
                                                </a>
                                                <a href="<?php echo $m['editurl']; ?>" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-medium shadow-xs" title="Edit Pengaturan Modul">
                                                    Edit
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const secSelect = document.getElementById('section-select');
    const targetLabel = document.getElementById('target-section-label');
    const courseId = <?php echo (int)($course ? $course->id : 0); ?>;
    const wwwRoot = "<?php echo $CFG->wwwroot; ?>";

    if (secSelect) {
        secSelect.addEventListener('change', function() {
            const secVal = this.value;
            const selectedText = this.options[this.selectedIndex].text;
            if (targetLabel) {
                targetLabel.textContent = selectedText;
            }

            // Dynamically update hrefs for module buttons
            document.querySelectorAll('.btn-create-mod').forEach(function(btn) {
                const modType = btn.getAttribute('data-modtype');
                btn.href = wwwRoot + '/course/modedit.php?add=' + modType + '&course=' + courseId + '&section=' + secVal;
            });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();

