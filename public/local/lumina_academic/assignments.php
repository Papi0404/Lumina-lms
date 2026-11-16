<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_lumina_academic\manager;
use local_lumina_academic\course_engine;

require_login();

$sysctx = context_system::instance();
require_capability('local/lumina_academic:assigncourses', $sysctx);

$classid = optional_param('classid', 0, PARAM_INT);
$action  = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/lumina_academic/assignments.php', array_filter(['classid' => $classid])));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_assignments', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$notification = '';
$notiftype = 'success';

// Default to first active class if none selected
$curyear = manager::get_current_academic_year();
$allclasses = manager::get_classes($curyear ? (int)$curyear->id : null);

if ($classid === 0 && !empty($allclasses)) {
    $firstclass = reset($allclasses);
    $classid = (int)$firstclass->id;
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'bulk_assign' && $classid > 0) {
        $selectedcourses = optional_param_array('courseids', [], PARAM_INT);
        $teacherid       = optional_param('teacher_id', 0, PARAM_INT) ?: null;
        $semester        = optional_param('semester', 1, PARAM_INT);

        if (empty($selectedcourses)) {
            $notification = 'Pilih minimal satu mata pelajaran untuk dihubungkan.';
            $notiftype = 'warning';
        } else {
            try {
                $results = course_engine::assign_courses_bulk($classid, $selectedcourses, $teacherid, $semester);
                $notification = count($results) . ' mata pelajaran berhasil dihubungkan ke kelas! Seluruh siswa otomatis terdaftar melalui sinkronisasi cohort.';
            } catch (\Exception $e) {
                $notification = 'Gagal menghubungkan mata pelajaran: ' . $e->getMessage();
                $notiftype = 'danger';
            }
        }
    } elseif ($action === 'unassign' && $classid > 0) {
        $targetcourseid = required_param('courseid', PARAM_INT);
        try {
            course_engine::unassign_course_from_class($classid, $targetcourseid);
            $notification = 'Mata pelajaran berhasil dilepas dari kelas ini.';
        } catch (\Exception $e) {
            $notification = 'Gagal melepas mata pelajaran: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    }
}

$selectedclass = $classid > 0 ? manager::get_class($classid) : null;
$assignedcourses = $classid > 0 ? course_engine::get_assigned_courses($classid) : [];
$availablecourses = $classid > 0 ? course_engine::get_available_courses($classid) : [];

// Teachers for dropdown
$teachers = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
       FROM {user} u
       JOIN {role_assignments} ra ON ra.userid = u.id
      WHERE ra.roleid IN (1, 2, 3, 4) AND u.deleted = 0
   ORDER BY u.firstname ASC"
);

echo $OUTPUT->header();
?>

<div class="lumina-academic-container my-3">
    <!-- Top breadcrumb and title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= new moodle_url('/local/lumina_academic/overview.php') ?>">Akademik</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Penugasan Mapel (Course Assignment)</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-book-reader text-primary me-2"></i>Penugasan Mata Pelajaran ke Rombel (Bulk Assign)</h3>
            <p class="text-muted small mb-0">Hubungkan kelas formal ke mata pelajaran Moodle secara massal via sinkronisasi cohort tanpa perlu enroll satu per satu.</p>
        </div>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Class Selection Banner -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-3">
            <form method="get" action="<?= $PAGE->url ?>" class="row g-3 align-items-center">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted mb-1">Pilih Kelas Akademik (Rombel)</label>
                    <select name="classid" class="form-select fw-bold" onchange="this.form.submit()">
                        <?php if (empty($allclasses)): ?>
                            <option value="0">-- Belum ada kelas yang terdaftar --</option>
                        <?php else: ?>
                            <?php foreach ($allclasses as $c): ?>
                                <option value="<?= $c->id ?>" <?= $classid == $c->id ? 'selected' : '' ?>>
                                    <?= s($c->name) ?> (Tingkat <?= $c->grade_level ?> - <?= s($c->major) ?>) [<?= s($c->year_name) ?>]
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <?php if ($selectedclass): ?>
                    <div class="col-md-7">
                        <div class="d-flex flex-wrap gap-2 align-items-center pt-md-3">
                            <span class="badge bg-primary px-3 py-2">
                                <i class="fa fa-users me-1"></i> <?= $selectedclass->total_students ?> Siswa di Rombel
                            </span>
                            <span class="badge bg-secondary px-3 py-2 font-monospace">
                                Cohort: <?= s($selectedclass->cohort_idnumber) ?>
                            </span>
                            <?php if (!empty($selectedclass->homeroom_firstname)): ?>
                                <span class="badge bg-light text-dark border px-3 py-2">
                                    <i class="fa fa-user-tie me-1"></i> Wali: <?= s($selectedclass->homeroom_firstname . ' ' . $selectedclass->homeroom_lastname) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($selectedclass): ?>
        <div class="row g-4">
            <!-- Currently Assigned Courses -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px;">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="fa fa-check-circle text-success me-2"></i>Mata Pelajaran Terhubung (<?= count($assignedcourses) ?>)
                        </h5>
                        <span class="badge bg-light text-muted">Sinkronisasi Cohort Aktif</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Mata Pelajaran</th>
                                        <th>Guru Pengampu</th>
                                        <th>Semester</th>
                                        <th>Siswa Ter-sync</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assignedcourses)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fa fa-book-open fa-3x mb-3 text-secondary d-block"></i>
                                                Belum ada mata pelajaran yang dihubungkan ke kelas ini.<br>
                                                Pilih mata pelajaran dari panel sebelah kanan untuk menambahkan.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($assignedcourses as $ac): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= s($ac->course_name) ?></div>
                                                    <small class="text-muted">Kode: <code><?= s($ac->course_code) ?></code></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($ac->teacher_firstname)): ?>
                                                        <div class="small fw-semibold"><?= s($ac->teacher_firstname . ' ' . $ac->teacher_lastname) ?></div>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-muted">Belum Ditugaskan</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        Semester <?= $ac->semester ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success fw-bold">
                                                        <i class="fa fa-user-check me-1"></i><?= $selectedclass->total_students ?> Siswa
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <form method="post" action="<?= $PAGE->url ?>" class="d-inline" onsubmit="return confirm('Lepas mata pelajaran ini dari rombel? Siswa tidak lagi dapat mengakses course via cohort.');">
                                                        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                        <input type="hidden" name="action" value="unassign">
                                                        <input type="hidden" name="classid" value="<?= $classid ?>">
                                                        <input type="hidden" name="courseid" value="<?= $ac->course_id ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Lepaskan Mapel">
                                                            <i class="fa fa-unlink"></i> Lepas
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Available Courses to Bulk Assign -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px;">
                    <div class="card-header bg-primary text-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-white">
                            <i class="fa fa-layer-group me-2"></i>Hubungkan Mapel Baru (Massal)
                        </h5>
                        <small class="text-white-50">Centang mata pelajaran yang akan dihubungkan ke rombel ini</small>
                    </div>
                    <form method="post" action="<?= $PAGE->url ?>">
                        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                        <input type="hidden" name="action" value="bulk_assign">
                        <input type="hidden" name="classid" value="<?= $classid ?>">

                        <div class="card-body">
                            <!-- Options for Teacher & Semester -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Guru Pengampu (Opsional)</label>
                                <select name="teacher_id" class="form-select form-select-sm">
                                    <option value="0">-- Pilih Guru Pengampu --</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= $t->id ?>"><?= s($t->firstname . ' ' . $t->lastname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Semester Penempatan</label>
                                <select name="semester" class="form-select form-select-sm">
                                    <option value="1">Semester 1 (Ganjil)</option>
                                    <option value="2">Semester 2 (Genap)</option>
                                </select>
                            </div>

                            <hr>

                            <!-- Course Checklist -->
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold text-dark mb-0">Pilih Mata Pelajaran:</label>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" id="btnToggleAll">
                                    Pilih Semua
                                </button>
                            </div>

                            <div class="border rounded p-2 bg-light" style="max-height: 320px; overflow-y: auto;">
                                <?php if (empty($availablecourses)): ?>
                                    <div class="text-center py-4 text-muted small">
                                        Seluruh mata pelajaran yang tersedia sudah terhubung ke rombel ini.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($availablecourses as $crs): ?>
                                        <div class="form-check p-2 border-bottom bg-white rounded mb-1">
                                            <input class="form-check-input course-chk" type="checkbox" name="courseids[]" value="<?= $crs->id ?>" id="chk_crs_<?= $crs->id ?>">
                                            <label class="form-check-label w-100 cursor-pointer" for="chk_crs_<?= $crs->id ?>">
                                                <div class="fw-semibold small text-dark"><?= s($crs->fullname) ?></div>
                                                <small class="text-muted">Kode: <code><?= s($crs->shortname) ?></code> &bull; Kategori: <?= s($crs->category_name) ?></small>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-footer bg-white border-0 p-3">
                            <button type="submit" class="btn btn-primary fw-bold w-100 shadow-sm" <?= empty($availablecourses) ? 'disabled' : '' ?>>
                                <i class="fa fa-link me-1"></i> Hubungkan Mata Pelajaran Terpilih
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('btnToggleAll');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var chks = document.querySelectorAll('.course-chk');
            var allChecked = true;
            chks.forEach(function(c) { if (!c.checked) allChecked = false; });
            chks.forEach(function(c) { c.checked = !allChecked; });
            toggleBtn.textContent = allChecked ? 'Pilih Semua' : 'Batal Pilih Semua';
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();

