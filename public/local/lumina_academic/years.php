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
require_capability('local/lumina_academic:manageyears', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/years.php'));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_years', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$action = optional_param('action', '', PARAM_ALPHA);
$yearid = optional_param('id', 0, PARAM_INT);
$notification = '';
$notiftype = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'create') {
        $name      = required_param('name', PARAM_TEXT);
        $code      = required_param('code', PARAM_ALPHANUMEXT);
        $semester  = required_param('semester', PARAM_INT);
        $startstr  = required_param('startdate', PARAM_TEXT);
        $endstr    = required_param('enddate', PARAM_TEXT);
        $status    = optional_param('status', 'planning', PARAM_ALPHA);
        $iscurrent = optional_param('iscurrent', 0, PARAM_INT);

        $startdate = strtotime($startstr) ?: time();
        $enddate   = strtotime($endstr) ?: (time() + (180 * 86400));

        try {
            manager::create_academic_year($name, $code, $semester, $startdate, $enddate, $status, (bool)$iscurrent);
            $notification = 'Tahun ajaran baru berhasil ditambahkan!';
        } catch (\Exception $e) {
            $notification = 'Gagal membuat tahun ajaran: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'activate' && $yearid > 0) {
        manager::set_current_academic_year($yearid);
        $notification = 'Tahun ajaran berhasil diaktifkan sebagai periode berjalan!';
    } elseif ($action === 'close' && $yearid > 0) {
        $DB->set_field('lumina_academic_years', 'status', 'closed', ['id' => $yearid]);
        $DB->set_field('lumina_academic_years', 'iscurrent', 0, ['id' => $yearid]);
        $notification = 'Tahun ajaran berhasil ditutup.';
    }
}

$years = manager::get_academic_years();

echo $OUTPUT->header();
?>

<div class="lumina-academic-container my-3">
    <!-- Breadcrumb & Top bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= new moodle_url('/local/lumina_academic/overview.php') ?>">Akademik</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Tahun Ajaran</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-calendar-alt text-primary me-2"></i>Pengelolaan Tahun Ajaran & Semester</h3>
        </div>
        <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#createYearModal">
            <i class="fa fa-plus me-1"></i> Tambah Tahun Ajaran
        </button>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Table of Academic Years -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small">
                        <tr>
                            <th>Nama Tahun Ajaran</th>
                            <th>Kode</th>
                            <th>Semester</th>
                            <th>Periode Tanggal</th>
                            <th>Status</th>
                            <th>Rombel & Siswa</th>
                            <th class="text-end">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($years)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa fa-calendar-times fa-3x mb-3 text-secondary d-block"></i>
                                    Belum ada Tahun Ajaran yang terdaftar. Klik <strong>"Tambah Tahun Ajaran"</strong> untuk memulai.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($years as $y): ?>
                                <tr class="<?= $y->iscurrent ? 'table-primary-subtle border-start border-primary border-4' : '' ?>">
                                    <td>
                                        <div class="fw-bold text-dark"><?= s($y->name) ?></div>
                                        <?php if ($y->iscurrent): ?>
                                            <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Periode Berjalan (Aktif)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= s($y->code) ?></code></td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            Semester <?= $y->semester == 1 ? '1 (Ganjil)' : '2 (Genap)' ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted">
                                        <?= date('d M Y', $y->startdate) ?> &ndash; <?= date('d M Y', $y->enddate) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $badgeclass = 'bg-secondary';
                                        if ($y->status === 'active') $badgeclass = 'bg-success';
                                        elseif ($y->status === 'planning') $badgeclass = 'bg-info text-dark';
                                        elseif ($y->status === 'closed') $badgeclass = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?= $badgeclass ?> text-capitalize"><?= s($y->status) ?></span>
                                    </td>
                                    <td class="small">
                                        <div><i class="fa fa-chalkboard text-muted me-1"></i><?= $y->total_classes ?> Kelas</div>
                                        <div><i class="fa fa-user-graduate text-muted me-1"></i><?= $y->total_students ?> Siswa</div>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$y->iscurrent): ?>
                                            <form method="post" action="<?= $PAGE->url ?>" class="d-inline">
                                                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="id" value="<?= $y->id ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Aktifkan Periode Ini">
                                                    <i class="fa fa-play me-1"></i> Aktifkan
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($y->status === 'active'): ?>
                                            <form method="post" action="<?= $PAGE->url ?>" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menutup semester ini?');">
                                                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                <input type="hidden" name="action" value="close">
                                                <input type="hidden" name="id" value="<?= $y->id ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Tutup Semester">
                                                    <i class="fa fa-lock me-1"></i> Tutup
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<!-- Modal Tambah Tahun Ajaran -->
<div class="modal fade" id="createYearModal" tabindex="-1" aria-labelledby="createYearModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= $PAGE->url ?>">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="createYearModalLabel"><i class="fa fa-calendar-plus me-2"></i>Tambah Tahun Ajaran Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Tahun Ajaran <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Tahun Ajaran 2026/2027 Ganjil" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Kode Unik <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" placeholder="Contoh: 2026-2027-1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Semester <span class="text-danger">*</span></label>
                            <select name="semester" class="form-select" required>
                                <option value="1">Semester 1 (Ganjil)</option>
                                <option value="2">Semester 2 (Genap)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" name="startdate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Selesai <span class="text-danger">*</span></label>
                            <input type="date" name="enddate" class="form-control" value="<?= date('Y-m-d', strtotime('+6 months')) ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status Awal</label>
                        <select name="status" class="form-select">
                            <option value="planning">Perencanaan (Planning)</option>
                            <option value="active" selected>Aktif Berjalan (Active)</option>
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="iscurrent" value="1" id="checkIsCurrent" checked>
                        <label class="form-check-label fw-semibold" for="checkIsCurrent">
                            Tetapkan sebagai Tahun Ajaran Aktif Saat Ini
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> Simpan Tahun Ajaran</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();

