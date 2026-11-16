<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_lumina_academic\graduation_engine;
use local_lumina_academic\alumni_vault;

require_login();

$sysctx = context_system::instance();
require_capability('local/lumina_academic:graduatestudents', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/graduation.php'));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_graduation', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$action = optional_param('action', '', PARAM_ALPHA);
$notification = '';
$notiftype = 'success';

// Handle POST Graduation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'finalize') {
        $userids  = optional_param_array('userids', [], PARAM_INT);
        $gradyear = required_param('graduation_year', PARAM_INT);
        $prefix   = optional_param('cert_prefix', 'LUMINA-IJZ-', PARAM_TEXT);

        if (empty($userids)) {
            $notification = 'Pilih minimal satu siswa untuk diproses kelulusannya.';
            $notiftype = 'warning';
        } else {
            try {
                $results = graduation_engine::finalize_graduation($userids, $gradyear, $prefix);
                $notification = count($results) . ' siswa berhasil diluluskan! Seluruh nilai telah dibekukan secara permanen ke dalam Vault Alumni dengan hash SHA-256 anti-tamper.';
            } catch (\Exception $e) {
                $notification = 'Gagal memproses kelulusan: ' . $e->getMessage();
                $notiftype = 'danger';
            }
        }
    }
}

// Fetch potential graduates (Grade level 12 or senior students)
$sql = "SELECT u.id, u.firstname, u.lastname, u.username, u.email,
               p.nis, p.nisn, p.current_major, p.current_level, p.academic_status,
               g.graduation_year, g.certificate_number, g.final_gpa,
               t.transcript_uuid
          FROM {user} u
     LEFT JOIN {lumina_student_profiles} p ON p.userid = u.id
     LEFT JOIN {lumina_graduation} g ON g.userid = u.id
     LEFT JOIN {lumina_transcripts} t ON t.userid = u.id AND t.is_current_version = 1
         WHERE u.deleted = 0 AND u.id NOT IN (1, 2)
      ORDER BY p.current_level DESC, u.lastname ASC LIMIT 100";

$candidates = $DB->get_records_sql($sql);

echo $OUTPUT->header();
?>

<div class="lumina-academic-container my-3">
    <!-- Top breadcrumb and title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= new moodle_url('/local/lumina_academic/overview.php') ?>">Akademik</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Manajemen Kelulusan</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-graduation-cap text-primary me-2"></i>Manajemen Kelulusan & Arsip Nilai Akhir</h3>
            <p class="text-muted small mb-0">Pembekuan nilai akhir ke Vault permanen, penerbitan nomor ijazah resmi, dan offboarding siswa dari kelas aktif.</p>
        </div>
        <a href="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>" class="btn btn-outline-primary fw-bold shadow-sm">
            <i class="fa fa-archive me-1"></i> Buka Vault & Buku Induk Alumni &rarr;
        </a>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Finalization Form -->
    <form method="post" action="<?= $PAGE->url ?>">
        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
        <input type="hidden" name="action" value="finalize">

        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <label class="form-label small fw-bold mb-0">Tahun Kelulusan Resmi:</label>
                        <input type="number" name="graduation_year" class="form-control form-control-sm" value="<?= date('Y') ?>" style="width: 120px;" required>
                    </div>
                    <div>
                        <label class="form-label small fw-bold mb-0">Prefix No. Ijazah / Sertifikat:</label>
                        <input type="text" name="cert_prefix" class="form-control form-control-sm" value="LUMINA-IJZ-" style="width: 160px;" required>
                    </div>
                </div>
                <div>
                    <button type="submit" class="btn btn-success fw-bold shadow-sm" onclick="return confirm('PERINGATAN: Finalisasi kelulusan akan membekukan seluruh nilai siswa ke dalam Alumni Vault secara permanen dan mengeluarkan mereka dari cohort rombel aktif. Lanjutkan?');">
                        <i class="fa fa-award me-1"></i> Proses Kelulusan Siswa Terpilih
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="checkAllGraduates">
                                </th>
                                <th>Nama Siswa</th>
                                <th>NIS / NISN</th>
                                <th>Tingkat & Jurusan</th>
                                <th>Status Akademik</th>
                                <th>No. Ijazah / Transkrip</th>
                                <th class="text-end">Rincian Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($candidates)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">Belum ada data siswa yang ditemukan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($candidates as $c): ?>
                                    <tr class="<?= !empty($c->certificate_number) ? 'table-success-subtle' : '' ?>">
                                        <td>
                                            <?php if (empty($c->certificate_number)): ?>
                                                <input type="checkbox" name="userids[]" value="<?= $c->id ?>" class="form-check-input grad-chk">
                                            <?php else: ?>
                                                <i class="fa fa-check-circle text-success" title="Sudah Lulus"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= s(fullname($c)) ?></div>
                                            <small class="text-muted"><?= s($c->email) ?></small>
                                        </td>
                                        <td>
                                            <div><code><?= s($c->nis ?: '-') ?></code></div>
                                            <small class="text-muted"><?= s($c->nisn ?: '-') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                Tingkat <?= $c->current_level ?: 10 ?> &bull; <?= s($c->current_major ?: '-') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($c->academic_status === 'graduated'): ?>
                                                <span class="badge bg-success"><i class="fa fa-graduation-cap me-1"></i>Lulus (Alumni)</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary text-capitalize"><?= s($c->academic_status ?: 'Aktif') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($c->certificate_number)): ?>
                                                <div class="fw-bold text-success small"><?= s($c->certificate_number) ?></div>
                                                <small class="text-muted">GPA: <strong><?= number_format((float)$c->final_gpa, 2) ?></strong> (T.L. <?= $c->graduation_year ?>)</small>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted">Belum Lulus</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!empty($c->transcript_uuid)): ?>
                                                <a href="<?= new moodle_url('/local/lumina_academic/transcript.php', ['uuid' => $c->transcript_uuid]) ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Lihat Buku Induk / Transkrip">
                                                    <i class="fa fa-file-invoice me-1"></i> Buku Induk
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Tersedia pasca-kelulusan</span>
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
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var checkAll = document.getElementById('checkAllGraduates');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            var chks = document.querySelectorAll('.grad-chk');
            chks.forEach(function(c) { c.checked = checkAll.checked; });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();

