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

$uuid = optional_param('uuid', '', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/local/lumina_academic/verify.php', ['uuid' => $uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title('Verifikasi Keaslian Transkrip Akademik Digital - Lumina LMS');

echo $OUTPUT->header();

$verification = !empty($uuid) ? alumni_vault::verify_transcript_integrity($uuid) : null;
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card border-0 shadow-lg text-center p-4 p-md-5" style="border-radius: 16px;">
                <div class="mb-3">
                    <span class="badge bg-primary px-3 py-2 text-uppercase fw-bold mb-2">Lumina LMS Trust & Verification Center</span>
                    <h3 class="fw-bold text-dark">Portal Verifikasi Dokumen Ijazah & Transkrip</h3>
                </div>

                <?php if (empty($uuid)): ?>
                    <div class="alert alert-warning">
                        <i class="fa fa-search me-1"></i> Masukkan kode UUID Dokumen untuk memverifikasi keabsahan transkrip.
                    </div>
                    <form method="get" action="<?= $PAGE->url ?>">
                        <div class="input-group mb-3">
                            <input type="text" name="uuid" class="form-control" placeholder="Contoh: a84c98e1-512c-47bc..." required>
                            <button type="submit" class="btn btn-primary fw-bold">Verifikasi</button>
                        </div>
                    </form>
                <?php elseif (!$verification || empty($verification['transcript'])): ?>
                    <div class="p-4 bg-danger-subtle text-danger rounded mb-3">
                        <i class="fa fa-times-circle fa-4x mb-2 text-danger"></i>
                        <h4 class="fw-bold">Dokumen Tidak Ditemukan</h4>
                        <p class="small mb-0">Kode UUID dokumen transkrip yang Anda masukkan tidak terdaftar dalam database resmi Lumina LMS.</p>
                    </div>
                <?php elseif ($verification['is_valid']): ?>
                    <?php $t = $verification['transcript']; ?>
                    <div class="p-4 bg-success-subtle text-success rounded mb-4">
                        <i class="fa fa-check-circle fa-4x mb-2 text-success"></i>
                        <h4 class="fw-bold mb-1">DOKUMEN ASLI & TERVERIFIKASI</h4>
                        <div class="small fw-semibold">Integritas Kriptografi SHA-256 Sah & Cocok 100%</div>
                    </div>

                    <div class="text-start p-3 bg-light rounded mb-4">
                        <div class="mb-2">
                            <span class="text-muted small">Nama Siswa / Alumni:</span>
                            <div class="fw-bold fs-5 text-dark"><?= s($t->student_name) ?></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <span class="text-muted small">Nomor Induk Siswa (NIS):</span>
                                <div class="fw-semibold text-dark"><code><?= s($t->student_nis) ?></code></div>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small">NISN Resmi:</span>
                                <div class="fw-semibold text-dark"><code><?= s($t->student_nisn ?: '-') ?></code></div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <span class="text-muted small">Program / Jurusan:</span>
                                <div class="fw-semibold text-dark"><?= s($t->major) ?></div>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small">Tahun Kelulusan:</span>
                                <div class="fw-bold text-success">Lulus <?= $t->grad_year ?></div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <span class="text-muted small">Nilai Kumulatif (GPA):</span>
                                <div class="fw-bold text-primary fs-5"><?= number_format((float)$t->cumulative_gpa, 2) ?></div>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small">Predikat Kelulusan:</span>
                                <div class="fw-semibold text-dark"><?= s($t->final_predicate) ?></div>
                            </div>
                        </div>
                        <div class="border-top pt-2 mt-2">
                            <span class="text-muted small">Versi Transkrip:</span>
                            <span class="badge bg-secondary">Versi <?= $t->version ?></span>
                            <span class="text-muted small ms-2">&bull; Diterbitkan: <?= date('d M Y H:i', $t->timefinalized) ?> WIB</span>
                        </div>
                        <div class="border-top pt-2 mt-2 font-monospace small" style="font-size: 10px; word-break: break-all;">
                            SHA-256 Hash: <strong><?= s($verification['stored_hash']) ?></strong>
                        </div>
                    </div>

                    <a href="<?= new moodle_url('/local/lumina_academic/transcript.php', ['uuid' => $t->transcript_uuid]) ?>" class="btn btn-outline-primary fw-bold" target="_blank">
                        <i class="fa fa-file-invoice me-1"></i> Buka Transkrip Lengkap
                    </a>
                <?php else: ?>
                    <div class="p-4 bg-danger-subtle text-danger rounded mb-3">
                        <i class="fa fa-exclamation-triangle fa-4x mb-2 text-danger"></i>
                        <h4 class="fw-bold">PERINGATAN: INTEGRITAS GAGAL</h4>
                        <p class="small mb-0">Terdeteksi ketidakcocokan antara rekaman nilai dengan tanda tangan digital SHA-256 dokumen. Data nilai terindikasi telah dimanipulasi secara tidak sah!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();

