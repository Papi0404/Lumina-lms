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
use local_lumina_academic\audit_logger;

require_login();

$sysctx = context_system::instance();
require_capability('moodle/site:config', $sysctx);

$PAGE->set_url(new moodle_url('/local/lumina_academic/overview.php'));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('academic_management', 'local_lumina_academic') . ' - ' . get_string('menu_overview', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

// Fetch metrics
$currentyear = manager::get_current_academic_year();
$totalyears = $DB->count_records('lumina_academic_years');
$totalclasses = $DB->count_records('lumina_classes', ['status' => 'active']);
$totalstudents = $DB->count_records('lumina_class_members', ['status' => 'active']);
$totalassignedcourses = $DB->count_records('lumina_class_courses', ['status' => 'active']);
$totalalumni = $DB->count_records('lumina_transcripts', ['is_current_version' => 1]);
$recentevents = audit_logger::get_recent_logs(null, null, 15);

echo $OUTPUT->header();
?>

<div class="lumina-academic-container my-3">
    <!-- Hero / Active Year Banner -->
    <div class="card border-0 shadow-sm mb-4 bg-primary text-white" style="border-radius: 12px; background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <span class="badge bg-white text-primary px-3 py-2 fw-bold text-uppercase mb-2">Lumina LMS Academic Suite</span>
                    <h2 class="fw-bold mb-1 text-white">Sistem Manajemen Akademik & Buku Induk Digital</h2>
                    <p class="mb-0 text-white-50">
                        <?php if ($currentyear): ?>
                            Tahun Ajaran Aktif: <strong class="text-white"><?= s($currentyear->name) ?> (Semester <?= $currentyear->semester == 1 ? 'Ganjil' : 'Genap' ?>)</strong>
                            &bull; Kode: <code><?= s($currentyear->code) ?></code>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Belum ada Tahun Ajaran yang diaktifkan</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <a href="<?= new moodle_url('/local/lumina_academic/years.php') ?>" class="btn btn-light fw-bold text-primary shadow-sm">
                        <i class="fa fa-calendar-alt me-1"></i> Kelola Tahun Ajaran
                    </a>
                    <a href="<?= new moodle_url('/local/lumina_academic/promotion.php') ?>" class="btn btn-outline-light fw-bold shadow-sm">
                        <i class="fa fa-arrow-up me-1"></i> Kenaikan Kelas
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Kelas Aktif (Rombel)</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <h3 class="fw-bold mb-0 text-dark"><?= $totalclasses ?></h3>
                        <div class="p-3 bg-light text-primary rounded-circle"><i class="fa fa-chalkboard fa-lg"></i></div>
                    </div>
                    <small class="text-muted mt-2 d-block"><a href="<?= new moodle_url('/local/lumina_academic/classes.php') ?>" class="text-decoration-none">Lihat Semua Kelas &rarr;</a></small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Siswa Terdaftar di Rombel</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <h3 class="fw-bold mb-0 text-success"><?= $totalstudents ?></h3>
                        <div class="p-3 bg-light text-success rounded-circle"><i class="fa fa-user-graduate fa-lg"></i></div>
                    </div>
                    <small class="text-muted mt-2 d-block">Status aktif periode ini</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Mata Pelajaran Terhubung</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <h3 class="fw-bold mb-0 text-info"><?= $totalassignedcourses ?></h3>
                        <div class="p-3 bg-light text-info rounded-circle"><i class="fa fa-book-open fa-lg"></i></div>
                    </div>
                    <small class="text-muted mt-2 d-block"><a href="<?= new moodle_url('/local/lumina_academic/assignments.php') ?>" class="text-decoration-none">Kelola Penugasan &rarr;</a></small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 10px;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Alumni di Vault</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <h3 class="fw-bold mb-0 text-warning"><?= $totalalumni ?></h3>
                        <div class="p-3 bg-light text-warning rounded-circle"><i class="fa fa-archive fa-lg"></i></div>
                    </div>
                    <small class="text-muted mt-2 d-block"><a href="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>" class="text-decoration-none">Cari Buku Induk &rarr;</a></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Navigation Hub -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa fa-th-large text-primary me-2"></i>Pusat Navigasi Modul Akademik</h5>
        </div>
        <div class="card-body pt-0">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-primary me-2">1</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/years.php') ?>" class="text-decoration-none text-dark">Tahun Ajaran</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Konfigurasi periode kalender akademik, semester ganjil/genap, dan penetapan tahun aktif.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/years.php') ?>" class="btn btn-sm btn-outline-primary">Buka Tahun Ajaran &rarr;</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-primary me-2">2</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/classes.php') ?>" class="text-decoration-none text-dark">Kelas & Rombel</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Kelola rombel formal (X, XI, XII RPL), wali kelas, dan pendaftaran siswa dengan cohort otomatis.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/classes.php') ?>" class="btn btn-sm btn-outline-primary">Buka Manajemen Kelas &rarr;</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-primary me-2">3</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/assignments.php') ?>" class="text-decoration-none text-dark">Penugasan Mapel</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Hubungkan banyak mata pelajaran (courses) ke rombel dalam 1 klik via sinkronisasi cohort Moodle.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/assignments.php') ?>" class="btn btn-sm btn-outline-primary">Buka Penugasan Mapel &rarr;</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-indigo me-2" style="background:#6366f1;color:#fff;">4</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/groups.php') ?>" class="text-decoration-none text-dark">Kelompok Fleksibel</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Kelola kelompok belajar non-formal: Remedial, Tim Olimpiade, Proyek, dan Ekstrakurikuler.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/groups.php') ?>" class="btn btn-sm btn-outline-primary">Buka Kelompok Fleksibel &rarr;</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-success me-2">5</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/promotion.php') ?>" class="text-decoration-none text-dark">Kenaikan Kelas (Promosi)</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Wizard kenaikan kelas massal/individual berpelindung transaksi dengan fitur rollback instan.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/promotion.php') ?>" class="btn btn-sm btn-outline-success">Buka Wizard Promosi &rarr;</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded h-100 bg-light">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-warning text-dark me-2">6</span>
                            <h6 class="fw-bold mb-0"><a href="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>" class="text-decoration-none text-dark">Buku Induk & Alumni Vault</a></h6>
                        </div>
                        <p class="small text-muted mb-2">Arsip abadi nilai siswa (kebal reset course), penerbitan transkrip PDF resmi, dan verifikasi QR hash.</p>
                        <a href="<?= new moodle_url('/local/lumina_academic/alumni.php') ?>" class="btn btn-sm btn-outline-warning text-dark">Buka Vault Alumni &rarr;</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Audit Logs -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa fa-history text-secondary me-2"></i>Riwayat Aktivitas Akademik Terkini</h5>
            <span class="badge bg-light text-dark"><?= count($recentevents) ?> Aktivitas Terakhir</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small">
                        <tr>
                            <th>Waktu</th>
                            <th>Aktivitas</th>
                            <th>Entitas</th>
                            <th>Operator</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentevents)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat aktivitas akademik yang tercatat.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentevents as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= userdate($log->timestamp, '%d %b %Y %H:%M') ?></td>
                                    <td><span class="badge bg-secondary"><?= s($log->event_name) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= s($log->entity_type) ?> #<?= $log->entity_id ?></span></td>
                                    <td class="small fw-semibold"><?= s($log->firstname . ' ' . $log->lastname) ?></td>
                                    <td class="small text-muted"><?= s($log->reason ?: '-') ?></td>
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
