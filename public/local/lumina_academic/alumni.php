<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_lumina_academic\alumni_vault;

require_login();

$sysctx = context_system::instance();
require_capability('local/lumina_academic:managealumni', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/alumni.php'));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_alumni', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$search   = optional_param('search', '', PARAM_TEXT);
$gradyear = optional_param('gradyear', 0, PARAM_INT);
$major    = optional_param('major', '', PARAM_ALPHANUMEXT);
$action   = optional_param('action', '', PARAM_ALPHA);

$notification = '';
$notiftype = 'success';

// Handle Grade Correction POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'correct_grade') {
        require_capability('local/lumina_academic:correctgrades', $sysctx);
        $transcriptid = required_param('transcript_id', PARAM_INT);
        $itemid       = required_param('item_id', PARAM_INT);
        $newgrade     = required_param('new_grade', PARAM_FLOAT);
        $reason       = required_param('reason', PARAM_TEXT);

        try {
            alumni_vault::correct_grade($transcriptid, $itemid, $newgrade, $reason, (int)$USER->id);
            $notification = 'Koreksi nilai resmi berhasil disimpan! Versi transkrip baru telah diterbitkan dengan audit log lengkap.';
        } catch (\Exception $e) {
            $notification = 'Gagal melakukan koreksi nilai: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    }
}

$alumnilist = alumni_vault::search_alumni($search, $gradyear ?: null, $major);
$allyears = $DB->get_records_sql("SELECT DISTINCT grad_year FROM {lumina_transcripts} ORDER BY grad_year DESC");

echo $OUTPUT->header();
?>

<div class="lumina-academic-container my-3">
    <!-- Top breadcrumb and title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= new moodle_url('/local/lumina_academic/overview.php') ?>">Akademik</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Buku Induk & Alumni Vault</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-archive text-warning me-2"></i>Buku Induk Digital & Vault Nilai Alumni</h3>
            <p class="text-muted small mb-0">Arsip nilai akademik permanen yang kebal terhadap reset atau penghapusan course Moodle. Terverifikasi dengan hash SHA-256 anti-manipulasi.</p>
        </div>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-3">
            <form method="get" action="<?= $PAGE->url ?>" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted mb-1">Pencarian Cepat Alumni</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="fa fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Cari berdasarkan Nama Siswa, NIS, NISN, atau UUID..." value="<?= s($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Tahun Kelulusan</label>
                    <select name="gradyear" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- Semua Tahun Lulus --</option>
                        <?php foreach ($allyears as $yr): ?>
                            <option value="<?= $yr->grad_year ?>" <?= $gradyear == $yr->grad_year ? 'selected' : '' ?>>
                                Tahun Kelulusan <?= $yr->grad_year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Jurusan</label>
                    <input type="text" name="major" class="form-control form-control-sm" placeholder="Contoh: RPL" value="<?= s($major) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-3 fw-bold"><i class="fa fa-filter me-1"></i> Cari Data</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Alumni List Table -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Nama Alumni</th>
                            <th>NIS / NISN</th>
                            <th>Jurusan & Angkatan</th>
                            <th>Rata-rata Nilai (GPA)</th>
                            <th>Predikat Akhir</th>
                            <th>Integritas Vault</th>
                            <th class="text-end">Dokumen Resmi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($alumnilist)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa fa-folder-open fa-3x mb-3 text-secondary d-block"></i>
                                    Tidak ada catatan alumni yang cocok dengan pencarian Anda.<br>
                                    Data siswa akan masuk ke sini secara otomatis saat kelulusan diproses pada menu <strong>Manajemen Kelulusan</strong>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($alumnilist as $al): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= s($al->student_name) ?></div>
                                        <small class="text-muted"><?= s($al->email) ?></small>
                                    </td>
                                    <td>
                                        <div><code><?= s($al->student_nis) ?></code></div>
                                        <small class="text-muted"><?= s($al->student_nisn ?: '-') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= s($al->major) ?></span>
                                        <div class="small text-muted mt-1">Lulus Tahun <?= $al->grad_year ?></div>
                                    </td>
                                    <td>
                                        <h5 class="fw-bold text-primary mb-0"><?= number_format((float)$al->cumulative_gpa, 2) ?></h5>
                                        <small class="text-muted"><?= $al->course_count ?> Mata Pelajaran</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-success-subtle text-success fw-bold">
                                            <?= s($al->final_predicate) ?>
                                        </span>
                                        <?php if ($al->version > 1): ?>
                                            <span class="badge bg-info text-dark ms-1">Rev <?= $al->version ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?= new moodle_url('/local/lumina_academic/verify.php', ['uuid' => $al->transcript_uuid]) ?>" target="_blank" class="text-decoration-none" title="Verifikasi Hash Anti-Tamper">
                                            <span class="badge bg-light text-dark border">
                                                <i class="fa fa-shield-alt text-success me-1"></i> Terverifikasi
                                            </span>
                                            <div class="font-monospace text-muted mt-1" style="font-size: 10px;">
                                                <?= substr($al->integrity_hash, 0, 16) ?>...
                                            </div>
                                        </a>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= new moodle_url('/local/lumina_academic/transcript.php', ['uuid' => $al->transcript_uuid]) ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Buka Buku Induk Digital">
                                            <i class="fa fa-file-invoice me-1"></i> Buku Induk
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

<?php
echo $OUTPUT->footer();
