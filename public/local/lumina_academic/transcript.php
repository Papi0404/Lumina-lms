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

$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$transcript = alumni_vault::get_transcript($uuid);

if (!$transcript) {
    throw new moodle_exception('error_invalid_transcript', 'local_lumina_academic');
}

$sysctx = context_system::instance();

// IDOR & Authorization check:
// Either user has viewalltranscripts capability or user is viewing their own transcript
if ($USER->id != $transcript->userid) {
    require_capability('local/lumina_academic:viewalltranscripts', $sysctx);
}

$verification = alumni_vault::verify_transcript_integrity($uuid);
$cancorrect = has_capability('local/lumina_academic:correctgrades', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/transcript.php', ['uuid' => $uuid]));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('base'); // Clean layout ideal for printing/PDF
$PAGE->set_title('Transkrip Nilai Akademik Resmi - ' . s($transcript->student_name));

echo $OUTPUT->header();
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; font-size: 12pt; }
    .card { border: none !important; box-shadow: none !important; }
}
.transcript-card {
    max-width: 900px;
    margin: 20px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}
.letterhead {
    border-bottom: 3px double #1e293b;
    padding-bottom: 15px;
    margin-bottom: 25px;
}
</style>

<div class="container my-4">
    <!-- Action buttons (no-print) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print" style="max-width: 900px; margin: 0 auto;">
        <a href="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>" class="btn btn-outline-secondary btn-sm">
            &larr; Kembali ke Daftar Alumni
        </a>
        <div class="d-flex gap-2">
            <?php if ($cancorrect): ?>
                <button type="button" class="btn btn-warning btn-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#correctGradeModal">
                    <i class="fa fa-edit me-1"></i> Ajukan Koreksi Nilai
                </button>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-primary btn-sm fw-bold">
                <i class="fa fa-print me-1"></i> Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- Main Official Transcript Document -->
    <div class="card transcript-card p-4 p-md-5">
        <!-- Letterhead / Kop Surat -->
        <div class="letterhead text-center">
            <h3 class="fw-bold text-uppercase mb-1" style="letter-spacing: 1px;">LUMINA LEARNING ACADEMY</h3>
            <h6 class="text-secondary mb-1">PUSAT DOKUMENTASI AKADEMIK & BUKU INDUK SISWA</h6>
            <p class="small text-muted mb-0">Jl. Pendidikan No. 101, Kompleks Teknologi Nusantara &bull; Telp: (021) 555-0199 &bull; Website: www.lumina-lms.edu</p>
        </div>

        <!-- Title -->
        <div class="text-center mb-4">
            <h4 class="fw-bold text-uppercase text-decoration-underline mb-1">TRANSKRIP NILAI AKADEMIK</h4>
            <div class="small text-muted">Nomor Dokumen: <strong>TRANS-<?= strtoupper(substr($transcript->transcript_uuid, 0, 8)) ?>-<?= $transcript->grad_year ?></strong></div>
            <?php if ($transcript->version > 1): ?>
                <span class="badge bg-info text-dark mt-1">Edisi Revisi Resmi (Versi <?= $transcript->version ?>)</span>
            <?php endif; ?>
        </div>

        <!-- Student Biodata Block -->
        <div class="row g-3 mb-4 p-3 bg-light rounded">
            <div class="col-sm-6">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <td class="text-muted fw-semibold" style="width: 140px;">Nama Lengkap</td>
                        <td>: <strong class="text-dark"><?= s($transcript->student_name) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Nomor Induk Siswa</td>
                        <td>: <code><?= s($transcript->student_nis) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">NISN</td>
                        <td>: <code><?= s($transcript->student_nisn ?: '-') ?></code></td>
                    </tr>
                </table>
            </div>
            <div class="col-sm-6">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <td class="text-muted fw-semibold" style="width: 140px;">Program / Jurusan</td>
                        <td>: <strong><?= s($transcript->major) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tahun Masuk</td>
                        <td>: <?= $transcript->entry_year ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tahun Kelulusan</td>
                        <td>: <strong class="text-success"><?= $transcript->grad_year ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Grade Items Table -->
        <h6 class="fw-bold text-dark text-uppercase mb-2">I. Rekapitulasi Capaian Pembelajaran & Nilai Akhir</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-sm align-middle text-center">
                <thead class="table-secondary text-uppercase small">
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th class="text-start">Mata Pelajaran (Course)</th>
                        <th>Semester</th>
                        <th>Nilai Angka</th>
                        <th>Nilai Huruf</th>
                        <th class="text-start">Predikat Kelulusan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transcript->items)): ?>
                        <tr>
                            <td colspan="6" class="py-4 text-muted">Belum ada item nilai yang tercatat pada transkrip ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($transcript->items as $it): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td class="text-start">
                                    <div class="fw-bold text-dark"><?= s($it->course_name) ?></div>
                                    <small class="text-muted"><?= s($it->course_code) ?></small>
                                </td>
                                <td><?= $it->semester_num ?></td>
                                <td class="fw-bold text-dark"><?= number_format((float)$it->raw_grade, 2) ?></td>
                                <td><span class="badge bg-light text-dark border fw-bold"><?= s($it->letter_grade) ?></span></td>
                                <td class="text-start small"><?= s($it->predicate ?: 'Kompeten') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Cumulative Summary -->
        <div class="p-3 bg-light rounded mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold text-uppercase mb-1">II. Rangkuman Kumulatif Akhir</h6>
                    <div class="small text-muted">Predikat Kelulusan: <strong class="text-dark"><?= s($transcript->final_predicate) ?></strong></div>
                </div>
                <div class="text-end">
                    <div class="small text-muted text-uppercase fw-bold">Nilai Rata-rata Kumulatif (GPA)</div>
                    <h2 class="fw-bold text-primary mb-0"><?= number_format((float)$transcript->cumulative_gpa, 2) ?></h2>
                </div>
            </div>
        </div>

        <!-- Anti-Tamper Verification & Signatures Block -->
        <div class="row align-items-end mt-4 pt-3">
            <div class="col-7">
                <div class="p-2 border rounded bg-white" style="font-size: 11px;">
                    <div class="fw-bold text-dark mb-1">
                        <i class="fa fa-shield-alt text-success me-1"></i> Keabsahan Digital & Integritas Dokumen (Anti-Tamper)
                    </div>
                    <div class="text-muted">
                        Dokumen ini telah dibekukan dalam Alumni Vault dan ditandatangani secara kriptografis menggunakan algoritma SHA-256.
                    </div>
                    <div class="font-monospace text-dark mt-1" style="word-break: break-all;">
                        Hash: <strong><?= s($transcript->integrity_hash) ?></strong>
                    </div>
                    <div class="mt-1">
                        Verifikasi Publik:
                        <a href="<?= new moodle_url('/local/lumina_academic/verify.php', ['uuid' => $transcript->transcript_uuid]) ?>" target="_blank" class="text-primary text-decoration-none">
                            lumina-lms.edu/verify?uuid=<?= substr($transcript->transcript_uuid, 0, 8) ?>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-5 text-center">
                <div class="small text-muted mb-4">Diterbitkan pada: <?= date('d F Y', $transcript->timefinalized) ?></div>
                <div class="fw-bold text-dark">Kepala Lembaga Pendidikan Lumina</div>
                <div style="height: 60px;"></div>
                <div class="fw-bold text-dark text-decoration-underline">( Prof. Dr. Januar Zidane, M.Kom )</div>
                <div class="small text-muted">NIP. 19850404 201001 1 001</div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Koreksi Nilai Resmi -->
<?php if ($cancorrect): ?>
<div class="modal fade" id="correctGradeModal" tabindex="-1" aria-labelledby="correctGradeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                <input type="hidden" name="action" value="correct_grade">
                <input type="hidden" name="transcript_id" value="<?= $transcript->id ?>">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="correctGradeModalLabel"><i class="fa fa-edit me-2"></i>Pengajuan Koreksi Nilai Resmi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        <i class="fa fa-exclamation-triangle me-1"></i>
                        Koreksi nilai akan menaikkan versi transkrip (v<?= $transcript->version ?> &rarr; v<?= $transcript->version + 1 ?>), menghitung ulang GPA, dan menerbitkan hash kriptografi baru. Alasan perubahan wajib dicatat di audit log.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Mata Pelajaran <span class="text-danger">*</span></label>
                        <select name="item_id" class="form-select" required>
                            <?php foreach ($transcript->items as $it): ?>
                                <option value="<?= $it->id ?>"><?= s($it->course_name) ?> (Nilai Saat Ini: <?= $it->raw_grade ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nilai Baru (Skala 0 - 100) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" name="new_grade" class="form-control" placeholder="Contoh: 88.50" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Alasan Resmi Koreksi Nilai <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Sebutkan dasar pertimbangan perbaikan nilai (misal: Berdasarkan Keputusan Sidang Remedial No. 102/2026)" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning fw-bold text-dark"><i class="fa fa-check me-1"></i> Sahkan Koreksi Nilai</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
echo $OUTPUT->footer();
