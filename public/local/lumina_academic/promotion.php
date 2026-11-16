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
use local_lumina_academic\promotion_engine;

require_login();

$sysctx = context_system::instance();
require_capability('local/lumina_academic:promotestudents', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/promotion.php'));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_promotion', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$fromyearid  = optional_param('from_year', 0, PARAM_INT);
$fromclassid = optional_param('from_class', 0, PARAM_INT);
$toyearid    = optional_param('to_year', 0, PARAM_INT);
$toclassid   = optional_param('to_class', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHA);

$notification = '';
$notiftype = 'success';

// Handle POST execution & rollback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'execute_batch') {
        $fromyear  = required_param('from_year_exec', PARAM_INT);
        $toyear    = required_param('to_year_exec', PARAM_INT);
        $fromclass = required_param('from_class_exec', PARAM_INT);
        $toclass   = optional_param('to_class_exec', 0, PARAM_INT) ?: null;

        $studentids = optional_param_array('student_ids', [], PARAM_INT);
        $actions    = optional_param_array('student_actions', [], PARAM_ALPHA);
        $targetcls  = optional_param_array('student_target_classes', [], PARAM_INT);
        $notesarr   = optional_param_array('student_notes', [], PARAM_TEXT);

        $actionsmatrix = [];
        foreach ($studentids as $sid) {
            $act = $actions[$sid] ?? 'promote';
            $tgt = !empty($targetcls[$sid]) ? (int)$targetcls[$sid] : $toclass;
            $nte = $notesarr[$sid] ?? '';
            $actionsmatrix[] = [
                'userid'          => $sid,
                'action'          => $act,
                'target_class_id' => $tgt,
                'notes'           => $nte,
            ];
        }

        try {
            $batch = promotion_engine::create_batch($fromyear, $toyear, $fromclass, $toclass, 'bulk');
            promotion_engine::execute_batch($batch->id, $actionsmatrix);
            $notification = "Batch kenaikan kelas berhasil dieksekusi! ID Batch: {$batch->batch_uuid}. Riwayat kelas lama dipertahankan, dan siswa telah dipindahkan ke rombel/cohort tujuan.";
        } catch (\Exception $e) {
            $notification = 'Gagal mengeksekusi kenaikan kelas: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'rollback_batch') {
        $batchid = required_param('batch_id', PARAM_INT);
        try {
            promotion_engine::rollback_batch($batchid);
            $notification = 'Batch kenaikan kelas berhasil di-rollback secara aman! Status keanggotaan dan cohort siswa telah dikembalikan ke kondisi semula.';
        } catch (\Exception $e) {
            $notification = 'Gagal melakukan rollback: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    }
}

$allyears = $DB->get_records('lumina_academic_years', null, 'startdate DESC');
$sourceclasses = $fromyearid > 0 ? manager::get_classes($fromyearid) : [];
$targetclasses = $toyearid > 0 ? manager::get_classes($toyearid) : [];

// Student preview list if source class chosen
$previewstudents = $fromclassid > 0 ? promotion_engine::preview_promotion($fromclassid, $toclassid ?: null) : [];

// Batch history
$batchhistory = $DB->get_records_sql(
    "SELECT b.*, u.firstname, u.lastname,
            y1.name AS from_year_name, y2.name AS to_year_name,
            c1.name AS from_class_name, c2.name AS to_class_name
       FROM {lumina_promotion_batches} b
       JOIN {lumina_academic_years} y1 ON y1.id = b.from_year_id
       JOIN {lumina_academic_years} y2 ON y2.id = b.to_year_id
  LEFT JOIN {lumina_classes} c1 ON c1.id = b.from_class_id
  LEFT JOIN {lumina_classes} c2 ON c2.id = b.to_class_id
  LEFT JOIN {user} u ON u.id = b.executed_by
   ORDER BY b.timecreated DESC LIMIT 20"
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
                    <li class="breadcrumb-item active" aria-current="page">Wizard Kenaikan Kelas</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-arrow-circle-up text-success me-2"></i>Wizard Kenaikan Kelas & Mutasi Siswa</h3>
            <p class="text-muted small mb-0">Kelola kenaikan kelas massal atau individual dengan jaminan keutuhan riwayat nilai, integritas transaksi, dan opsi rollback seketika.</p>
        </div>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Wizard Step 1: Select Source & Target Parameters -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa fa-sliders-h text-primary me-2"></i>Langkah 1: Tentukan Asal & Tujuan Rombel</h5>
        </div>
        <div class="card-body">
            <form method="get" action="<?= $PAGE->url ?>" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">1. Tahun Ajaran Asal <span class="text-danger">*</span></label>
                    <select name="from_year" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- Pilih Tahun Asal --</option>
                        <?php foreach ($allyears as $y): ?>
                            <option value="<?= $y->id ?>" <?= $fromyearid == $y->id ? 'selected' : '' ?>>
                                <?= s($y->name) ?> (<?= s($y->code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">2. Kelas / Rombel Asal <span class="text-danger">*</span></label>
                    <select name="from_class" class="form-select form-select-sm" onchange="this.form.submit()" <?= empty($sourceclasses) ? 'disabled' : '' ?>>
                        <option value="0">-- Pilih Kelas Asal --</option>
                        <?php foreach ($sourceclasses as $sc): ?>
                            <option value="<?= $sc->id ?>" <?= $fromclassid == $sc->id ? 'selected' : '' ?>>
                                <?= s($sc->name) ?> (Tingkat <?= $sc->grade_level ?> - <?= $sc->total_students ?> Siswa)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">3. Tahun Ajaran Tujuan <span class="text-danger">*</span></label>
                    <select name="to_year" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- Pilih Tahun Tujuan --</option>
                        <?php foreach ($allyears as $y): ?>
                            <option value="<?= $y->id ?>" <?= $toyearid == $y->id ? 'selected' : '' ?>>
                                <?= s($y->name) ?> (<?= s($y->code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">4. Kelas / Rombel Tujuan (Default)</label>
                    <select name="to_class" class="form-select form-select-sm" onchange="this.form.submit()" <?= empty($targetclasses) ? 'disabled' : '' ?>>
                        <option value="0">-- Pilih Kelas Tujuan --</option>
                        <?php foreach ($targetclasses as $tc): ?>
                            <option value="<?= $tc->id ?>" <?= $toclassid == $tc->id ? 'selected' : '' ?>>
                                <?= s($tc->name) ?> (Tingkat <?= $tc->grade_level ?> - <?= s($tc->major) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($fromclassid > 0 && !empty($previewstudents)): ?>
        <!-- Wizard Step 2: Individual & Bulk Student Decision Matrix -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-primary text-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-white">
                        <i class="fa fa-users-cog me-2"></i>Langkah 2: Pratinjau & Penentuan Aksi Kenaikan Siswa (<?= count($previewstudents) ?> Siswa)
                    </h5>
                    <small class="text-white-50">Tentukan status per siswa: Naik Kelas, Tinggal Kelas, Mutasi, atau Lulus.</small>
                </div>
                <span class="badge bg-light text-primary fw-bold px-3 py-2">Idempotensi & Transaksi Aman</span>
            </div>

            <form method="post" action="<?= $PAGE->url ?>">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                <input type="hidden" name="action" value="execute_batch">
                <input type="hidden" name="from_year_exec" value="<?= $fromyearid ?>">
                <input type="hidden" name="to_year_exec" value="<?= $toyearid ?>">
                <input type="hidden" name="from_class_exec" value="<?= $fromclassid ?>">
                <input type="hidden" name="to_class_exec" value="<?= $toclassid ?>">

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th>Nama Siswa</th>
                                    <th>NIS</th>
                                    <th>Tingkat Saat Ini</th>
                                    <th>Tindakan Promosi</th>
                                    <th>Rombel Tujuan Spesifik</th>
                                    <th>Catatan / Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewstudents as $pst): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="student_ids[]" value="<?= $pst->userid ?>">
                                            <div class="fw-bold text-dark"><?= s($pst->fullname) ?></div>
                                            <small class="text-muted"><?= s($pst->username) ?></small>
                                        </td>
                                        <td><code><?= s($pst->nis ?: '-') ?></code></td>
                                        <td><span class="badge bg-light text-dark border">Tingkat <?= $pst->current_level ?></span></td>
                                        <td>
                                            <select name="student_actions[<?= $pst->userid ?>]" class="form-select form-select-sm fw-semibold">
                                                <option value="promote" <?= $pst->suggested_action === 'promote' ? 'selected' : '' ?>>Naik Kelas (Promote)</option>
                                                <option value="retain" <?= $pst->suggested_action === 'retain' ? 'selected' : '' ?>>Tinggal Kelas (Retain)</option>
                                                <option value="transfer">Mutasi Jurusan / Rombel</option>
                                                <option value="graduate" <?= $pst->suggested_action === 'graduate' ? 'selected' : '' ?>>Tandai Kelulusan</option>
                                                <option value="skip">Lewati (Skip)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="student_target_classes[<?= $pst->userid ?>]" class="form-select form-select-sm">
                                                <option value="0">Default (<?= $toclassid ? 'Ikuti Pilihan Kelas Tujuan' : 'Belum Ditentukan' ?>)</option>
                                                <?php foreach ($targetclasses as $tc): ?>
                                                    <option value="<?= $tc->id ?>" <?= ($pst->target_class_id == $tc->id || $toclassid == $tc->id) ? 'selected' : '' ?>>
                                                        <?= s($tc->name) ?> (Tingkat <?= $tc->grade_level ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="student_notes[<?= $pst->userid ?>]" class="form-control form-control-sm" placeholder="Opsional">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer bg-light p-3 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        <i class="fa fa-info-circle text-primary me-1"></i>
                        Data kelas lama dan nilai semester sebelumnya tetap tersimpan utuh di histori akademik.
                    </span>
                    <button type="submit" class="btn btn-success fw-bold shadow-sm px-4" onclick="return confirm('Apakah Anda yakin ingin mengeksekusi batch kenaikan kelas ini?');">
                        <i class="fa fa-check-circle me-1"></i> Eksekusi Kenaikan Kelas Sekarang
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Promotion Batch History with Rollback Button -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa fa-history text-secondary me-2"></i>Riwayat Batch Kenaikan Kelas & Log Transaksi</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Waktu Eksekusi</th>
                            <th>Batch UUID</th>
                            <th>Asal ➔ Tujuan</th>
                            <th>Jumlah Siswa</th>
                            <th>Status</th>
                            <th>Operator</th>
                            <th class="text-end">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batchhistory)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Belum ada riwayat batch promosi yang dijalankan.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batchhistory as $b): ?>
                                <tr>
                                    <td class="small text-muted"><?= userdate($b->timecreated, '%d %b %Y %H:%M') ?></td>
                                    <td><code class="small"><?= substr($b->batch_uuid, 0, 18) ?>...</code></td>
                                    <td class="small">
                                        <strong><?= s($b->from_class_name ?: 'Massal') ?></strong> (<?= s($b->from_year_name) ?>)<br>
                                        &rarr; <strong><?= s($b->to_class_name ?: 'Ditentukan') ?></strong> (<?= s($b->to_year_name) ?>)
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= $b->success_count ?> / <?= $b->total_students ?> Berhasil
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($b->status === 'completed'): ?>
                                            <span class="badge bg-success">Selesai</span>
                                        <?php elseif ($b->status === 'rolled_back'): ?>
                                            <span class="badge bg-warning text-dark">Di-Rollback</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Gagal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= s($b->firstname . ' ' . $b->lastname) ?></td>
                                    <td class="text-end">
                                        <?php if ($b->status === 'completed'): ?>
                                            <form method="post" action="<?= $PAGE->url ?>" class="d-inline" onsubmit="return confirm('PERINGATAN: Rollback akan mengembalikan seluruh siswa pada batch ini ke kelas dan cohort asal mereka. Lanjutkan?');">
                                                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                <input type="hidden" name="action" value="rollback_batch">
                                                <input type="hidden" name="batch_id" value="<?= $b->id ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Batalkan Batch (Rollback)">
                                                    <i class="fa fa-undo me-1"></i> Rollback
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">&mdash;</span>
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

<?php
echo $OUTPUT->footer();
