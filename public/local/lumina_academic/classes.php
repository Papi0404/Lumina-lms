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

require_login();

$sysctx = context_system::instance();
require_capability('local/lumina_academic:manageclasses', $sysctx);

$classid     = optional_param('classid', 0, PARAM_INT);
$yearfilter  = optional_param('year_id', 0, PARAM_INT);
$levelfilter = optional_param('level', 0, PARAM_INT);
$majorfilter = optional_param('major', '', PARAM_ALPHANUMEXT);
$viewmembers = optional_param('members', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/lumina_academic/classes.php', array_filter([
    'classid' => $classid,
    'year_id' => $yearfilter,
    'level'   => $levelfilter,
    'major'   => $majorfilter,
    'members' => $viewmembers,
])));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_classes', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$notification = '';
$notiftype = 'success';

// Set default year if not specified
if ($yearfilter === 0) {
    $curyear = manager::get_current_academic_year();
    if ($curyear) {
        $yearfilter = (int)$curyear->id;
    }
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'create_class') {
        $name        = required_param('name', PARAM_TEXT);
        $code        = required_param('code', PARAM_ALPHANUMEXT);
        $acadyr      = required_param('academic_year_id', PARAM_INT);
        $gradelevel  = required_param('grade_level', PARAM_INT);
        $major       = required_param('major', PARAM_ALPHANUMEXT);
        $homeroomid  = optional_param('homeroom_teacher_id', 0, PARAM_INT) ?: null;

        try {
            $newclassid = manager::create_class($acadyr, $name, $code, $gradelevel, $major, $homeroomid);
            $notification = 'Kelas formal (Rombel) berhasil dibuat beserta cohort Moodle terpetakan!';
        } catch (\Exception $e) {
            $notification = 'Gagal membuat kelas: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'add_student' && $classid > 0) {
        $studentuserid = required_param('userid', PARAM_INT);
        try {
            manager::add_student_to_class($classid, $studentuserid);
            $notification = 'Siswa berhasil dimasukkan ke dalam kelas dan cohort Moodle!';
        } catch (\Exception $e) {
            $notification = 'Gagal memasukkan siswa: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'remove_student' && $classid > 0) {
        $studentuserid = required_param('userid', PARAM_INT);
        $reason        = optional_param('reason', 'Dikeluarkan oleh admin', PARAM_TEXT);
        try {
            manager::remove_student_from_class($classid, $studentuserid, $reason);
            $notification = 'Siswa berhasil dikeluarkan dari rombel dan cohort Moodle.';
        } catch (\Exception $e) {
            $notification = 'Gagal mengeluarkan siswa: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    }
}

$allclasses = manager::get_classes($yearfilter ?: null, $levelfilter, $majorfilter);
$allyears = $DB->get_records('lumina_academic_years', null, 'startdate DESC');

// Teachers for homeroom dropdown
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
                    <li class="breadcrumb-item active" aria-current="page">Manajemen Kelas (Rombel)</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-chalkboard text-primary me-2"></i>Struktur Kelas & Rombongan Belajar</h3>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#createClassModal">
                <i class="fa fa-plus me-1"></i> Buat Kelas Baru
            </button>
        </div>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($viewmembers && $classid > 0): ?>
        <?php
        $selectedclass = manager::get_class($classid);
        $classstudents = manager::get_class_students($classid, 'active');
        $availablestudents = manager::get_available_students_for_year($selectedclass->academic_year_id);
        ?>
        <!-- Members Management Subview -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0">
                        <i class="fa fa-users me-2"></i>Kelola Siswa: <?= s($selectedclass->name) ?>
                        <span class="badge bg-light text-primary ms-2">Tingkat <?= $selectedclass->grade_level ?> - <?= s($selectedclass->major) ?></span>
                    </h5>
                    <small class="text-white-50">Tahun Ajaran: <?= s($selectedclass->year_name) ?> &bull; Cohort: <code><?= s($selectedclass->cohort_idnumber) ?></code></small>
                </div>
                <a href="<?= new moodle_url('/local/lumina_academic/classes.php', ['year_id' => $yearfilter]) ?>" class="btn btn-sm btn-light fw-bold">
                    &larr; Kembali ke Daftar Kelas
                </a>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <!-- Enrolled students list -->
                    <div class="col-lg-8">
                        <h6 class="fw-bold mb-3 text-dark">
                            <i class="fa fa-user-check text-success me-1"></i>Siswa Terdaftar di Rombel Ini (<?= count($classstudents) ?>)
                        </h6>
                        <div class="table-responsive border rounded">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Nama Siswa</th>
                                        <th>NIS / NISN</th>
                                        <th>Tanggal Masuk</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($classstudents)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">Belum ada siswa yang dimasukkan ke kelas ini. Pilih dari daftar di sebelah kanan untuk menambahkan siswa.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($classstudents as $st): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= s(fullname($st)) ?></div>
                                                    <small class="text-muted"><?= s($st->email) ?></small>
                                                </td>
                                                <td>
                                                    <div><code><?= s($st->nis ?: '-') ?></code></div>
                                                    <small class="text-muted"><?= s($st->nisn ?: '-') ?></small>
                                                </td>
                                                <td class="small text-muted"><?= date('d M Y', $st->enrollment_date) ?></td>
                                                <td class="text-end">
                                                    <form method="post" action="<?= $PAGE->url ?>" class="d-inline" onsubmit="return confirm('Keluarkan siswa ini dari kelas dan cohort?');">
                                                        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                        <input type="hidden" name="action" value="remove_student">
                                                        <input type="hidden" name="classid" value="<?= $classid ?>">
                                                        <input type="hidden" name="userid" value="<?= $st->userid ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Keluarkan Siswa">
                                                            <i class="fa fa-times"></i> Keluarkan
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

                    <!-- Available students to add -->
                    <div class="col-lg-4">
                        <div class="card border bg-light h-100">
                            <div class="card-header bg-white fw-bold py-3">
                                <i class="fa fa-user-plus text-primary me-1"></i>Tambah Siswa ke Kelas
                                <div class="small text-muted fw-normal">Siswa aktif yang belum memiliki rombel di tahun ajaran ini</div>
                            </div>
                            <div class="card-body p-2" style="max-height: 480px; overflow-y: auto;">
                                <?php if (empty($availablestudents)): ?>
                                    <div class="text-center py-4 text-muted small">Semua siswa di institusi sudah memiliki penempatan kelas.</div>
                                <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($availablestudents as $ast): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent py-2">
                                                <div>
                                                    <div class="fw-semibold small text-dark"><?= s(fullname($ast)) ?></div>
                                                    <small class="text-muted">NIS: <?= s($ast->nis ?: $ast->username) ?></small>
                                                </div>
                                                <form method="post" action="<?= $PAGE->url ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                    <input type="hidden" name="action" value="add_student">
                                                    <input type="hidden" name="classid" value="<?= $classid ?>">
                                                    <input type="hidden" name="userid" value="<?= $ast->id ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary py-1 px-2" title="Tambahkan">
                                                        <i class="fa fa-plus"></i> Tambah
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 10px;">
        <div class="card-body py-3">
            <form method="get" action="<?= $PAGE->url ?>" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Tahun Ajaran</label>
                    <select name="year_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- Semua Tahun Ajaran --</option>
                        <?php foreach ($allyears as $y): ?>
                            <option value="<?= $y->id ?>" <?= $yearfilter == $y->id ? 'selected' : '' ?>>
                                <?= s($y->name) ?> (<?= s($y->code) ?>) <?= $y->iscurrent ? '★ Aktif' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Tingkat Kelas</label>
                    <select name="level" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- Semua Tingkat --</option>
                        <option value="10" <?= $levelfilter == 10 ? 'selected' : '' ?>>Kelas 10 (X)</option>
                        <option value="11" <?= $levelfilter == 11 ? 'selected' : '' ?>>Kelas 11 (XI)</option>
                        <option value="12" <?= $levelfilter == 12 ? 'selected' : '' ?>>Kelas 12 (XII)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Jurusan</label>
                    <input type="text" name="major" class="form-control form-control-sm" placeholder="Contoh: RPL" value="<?= s($majorfilter) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-secondary w-100 mt-3"><i class="fa fa-filter me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Classes Table -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small">
                        <tr>
                            <th>Nama Kelas</th>
                            <th>Kode Rombel</th>
                            <th>Tingkat & Jurusan</th>
                            <th>Wali Kelas</th>
                            <th>Mapped Cohort</th>
                            <th>Statistik</th>
                            <th class="text-end">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allclasses)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa fa-chalkboard fa-3x mb-3 text-secondary d-block"></i>
                                    Belum ada rombel yang terdaftar untuk filter ini. Klik <strong>"Buat Kelas Baru"</strong>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allclasses as $c): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= s($c->name) ?></div>
                                        <small class="text-muted"><?= s($c->year_name) ?></small>
                                    </td>
                                    <td><code><?= s($c->code) ?></code></td>
                                    <td>
                                        <span class="badge bg-primary me-1">Tingkat <?= $c->grade_level ?></span>
                                        <span class="badge bg-light text-dark border"><?= s($c->major) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($c->homeroom_firstname)): ?>
                                            <div class="small fw-semibold"><?= s($c->homeroom_firstname . ' ' . $c->homeroom_lastname) ?></div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted">Belum Ditentukan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary font-monospace">
                                            <i class="fa fa-users me-1"></i><?= s($c->cohort_idnumber ?? 'SYNCED') ?>
                                        </span>
                                    </td>
                                    <td class="small">
                                        <div><i class="fa fa-user-graduate text-success me-1"></i><strong><?= $c->total_students ?></strong> Siswa</div>
                                        <div><i class="fa fa-book-open text-info me-1"></i><strong><?= $c->total_courses ?></strong> Mapel</div>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= new moodle_url('/local/lumina_academic/classes.php', ['classid' => $c->id, 'members' => 1, 'year_id' => $yearfilter]) ?>" class="btn btn-sm btn-outline-primary" title="Kelola Anggota Siswa">
                                            <i class="fa fa-users me-1"></i> Kelola Siswa
                                        </a>
                                        <a href="<?= new moodle_url('/local/lumina_academic/assignments.php', ['classid' => $c->id]) ?>" class="btn btn-sm btn-outline-info" title="Hubungkan Mapel">
                                            <i class="fa fa-book me-1"></i> Mapel
                                        </a>
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

<!-- Modal Buat Kelas Baru -->
<div class="modal fade" id="createClassModal" tabindex="-1" aria-labelledby="createClassModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= $PAGE->url ?>">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                <input type="hidden" name="action" value="create_class">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="createClassModalLabel"><i class="fa fa-chalkboard-teacher me-2"></i>Buat Kelas Formal (Rombel)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tahun Ajaran <span class="text-danger">*</span></label>
                        <select name="academic_year_id" class="form-select" required>
                            <?php foreach ($allyears as $y): ?>
                                <option value="<?= $y->id ?>" <?= $y->iscurrent ? 'selected' : '' ?>>
                                    <?= s($y->name) ?> (<?= s($y->code) ?>) <?= $y->iscurrent ? '★ Aktif' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Kelas (Rombel) <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: X RPL 1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kode Unik Sistem <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control" placeholder="Contoh: 2026-X-RPL-1" required>
                        <small class="text-muted">Akan digunakan untuk penamaan cohort Moodle otomatis (<code>LUMINA_CLASS_...</code>).</small>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tingkat Kelas <span class="text-danger">*</span></label>
                            <select name="grade_level" class="form-select" required>
                                <option value="10">Kelas 10 (X)</option>
                                <option value="11">Kelas 11 (XI)</option>
                                <option value="12">Kelas 12 (XII)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Jurusan / Program <span class="text-danger">*</span></label>
                            <input type="text" name="major" class="form-control" placeholder="Contoh: RPL" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Wali Kelas</label>
                        <select name="homeroom_teacher_id" class="form-select">
                            <option value="0">-- Pilih Guru Wali Kelas (Opsional) --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t->id ?>"><?= s($t->firstname . ' ' . $t->lastname) ?> (<?= s($t->email) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> Simpan Kelas & Buat Cohort</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();

