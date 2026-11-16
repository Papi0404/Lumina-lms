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
require_capability('local/lumina_academic:manageflexiblegroups', $sysctx);

$groupid     = optional_param('groupid', 0, PARAM_INT);
$typefilter  = optional_param('type', '', PARAM_ALPHA);
$viewmembers = optional_param('members', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/lumina_academic/groups.php', array_filter([
    'groupid' => $groupid,
    'type'    => $typefilter,
    'members' => $viewmembers,
])));
$PAGE->set_context($sysctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('menu_groups', 'local_lumina_academic'));
$PAGE->set_heading(get_string('academic_management', 'local_lumina_academic'));

$notification = '';
$notiftype = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'create_group') {
        $name      = required_param('name', PARAM_TEXT);
        $code      = required_param('code', PARAM_ALPHANUMEXT);
        $grouptype = required_param('group_type', PARAM_ALPHA);
        $mentorid  = optional_param('mentor_id', 0, PARAM_INT) ?: null;

        try {
            $newgroupid = manager::create_flexible_group($name, $code, $grouptype, $mentorid);
            $notification = 'Kelompok fleksibel berhasil dibuat!';
        } catch (\Exception $e) {
            $notification = 'Gagal membuat kelompok: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'add_member' && $groupid > 0) {
        $userid = required_param('userid', PARAM_INT);
        $role   = optional_param('role_in_group', 'member', PARAM_ALPHA);
        try {
            manager::add_student_to_flexible_group($groupid, $userid, $role);
            $notification = 'Anggota berhasil ditambahkan ke kelompok fleksibel.';
        } catch (\Exception $e) {
            $notification = 'Gagal menambahkan anggota: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    } elseif ($action === 'remove_member' && $groupid > 0) {
        $userid = required_param('userid', PARAM_INT);
        try {
            manager::remove_student_from_flexible_group($groupid, $userid);
            $notification = 'Anggota dikeluarkan dari kelompok fleksibel.';
        } catch (\Exception $e) {
            $notification = 'Gagal menghapus anggota: ' . $e->getMessage();
            $notiftype = 'danger';
        }
    }
}

$groups = manager::get_flexible_groups($typefilter);
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
                    <li class="breadcrumb-item active" aria-current="page">Kelompok Belajar Fleksibel</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-dark"><i class="fa fa-users-cog text-primary me-2"></i>Kelompok Belajar & Tim Fleksibel</h3>
        </div>
        <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#createGroupModal">
            <i class="fa fa-plus me-1"></i> Buat Kelompok Baru
        </button>
    </div>

    <?php if (!empty($notification)): ?>
        <div class="alert alert-<?= $notiftype ?> alert-dismissible fade show" role="alert">
            <?= s($notification) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($viewmembers && $groupid > 0): ?>
        <?php
        $selectedgroup = $DB->get_record('lumina_flexible_groups', ['id' => $groupid], '*', MUST_EXIST);
        $members = manager::get_flexible_group_members($groupid);
        // Available students to add
        $allstudents = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.username, u.email
               FROM {user} u
              WHERE u.deleted = 0 AND u.id NOT IN (1, 2)
                AND u.id NOT IN (SELECT fm.userid FROM {lumina_flexible_members} fm WHERE fm.group_id = :gid)
           ORDER BY u.firstname ASC LIMIT 100",
            ['gid' => $groupid]
        );
        ?>
        <!-- Members Subview -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-header bg-indigo text-white py-3 d-flex justify-content-between align-items-center" style="background:#4f46e5;">
                <div>
                    <h5 class="fw-bold mb-0 text-white">
                        <i class="fa fa-users me-2"></i>Kelola Anggota: <?= s($selectedgroup->name) ?>
                        <span class="badge bg-light text-dark ms-2 text-uppercase"><?= s($selectedgroup->group_type) ?></span>
                    </h5>
                    <small class="text-white-50">Kode: <code><?= s($selectedgroup->code) ?></code></small>
                </div>
                <a href="<?= new moodle_url('/local/lumina_academic/groups.php', ['type' => $typefilter]) ?>" class="btn btn-sm btn-light fw-bold">
                    &larr; Kembali ke Daftar Kelompok
                </a>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <h6 class="fw-bold mb-3 text-dark">Daftar Anggota Kelompok (<?= count($members) ?>)</h6>
                        <div class="table-responsive border rounded">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Nama Siswa</th>
                                        <th>Peran di Kelompok</th>
                                        <th>Bergabung</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">Belum ada anggota. Tambahkan siswa dari daftar di sebelah kanan.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($members as $m): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= s(fullname($m)) ?></div>
                                                    <small class="text-muted"><?= s($m->email) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $m->role_in_group === 'leader' ? 'bg-warning text-dark' : 'bg-light text-dark border' ?> text-capitalize">
                                                        <?= s($m->role_in_group) ?>
                                                    </span>
                                                </td>
                                                <td class="small text-muted"><?= date('d M Y', $m->joined_date) ?></td>
                                                <td class="text-end">
                                                    <form method="post" action="<?= $PAGE->url ?>" class="d-inline">
                                                        <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                        <input type="hidden" name="action" value="remove_member">
                                                        <input type="hidden" name="groupid" value="<?= $groupid ?>">
                                                        <input type="hidden" name="userid" value="<?= $m->userid ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Keluarkan">
                                                            <i class="fa fa-times"></i> Hapus
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
                    <div class="col-lg-4">
                        <div class="card border bg-light h-100">
                            <div class="card-header bg-white fw-bold py-3">
                                <i class="fa fa-user-plus text-primary me-1"></i>Tambah Anggota Baru
                            </div>
                            <div class="card-body p-2" style="max-height: 480px; overflow-y: auto;">
                                <?php if (empty($allstudents)): ?>
                                    <div class="text-center py-4 text-muted small">Semua siswa sudah bergabung di kelompok ini.</div>
                                <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($allstudents as $st): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent py-2">
                                                <div>
                                                    <div class="fw-semibold small text-dark"><?= s(fullname($st)) ?></div>
                                                    <small class="text-muted"><?= s($st->username) ?></small>
                                                </div>
                                                <form method="post" action="<?= $PAGE->url ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                                    <input type="hidden" name="action" value="add_member">
                                                    <input type="hidden" name="groupid" value="<?= $groupid ?>">
                                                    <input type="hidden" name="userid" value="<?= $st->id ?>">
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
                <div class="col-md-6">
                    <label class="form-label small fw-bold text-muted mb-1">Jenis Kelompok</label>
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Semua Jenis Kelompok --</option>
                        <option value="remedial" <?= $typefilter === 'remedial' ? 'selected' : '' ?>>Remedial</option>
                        <option value="competition" <?= $typefilter === 'competition' ? 'selected' : '' ?>>Tim Olimpiade / Lomba</option>
                        <option value="project" <?= $typefilter === 'project' ? 'selected' : '' ?>>Kelompok Proyek</option>
                        <option value="extracurricular" <?= $typefilter === 'extracurricular' ? 'selected' : '' ?>>Ekstrakurikuler</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-secondary w-100 mt-3"><i class="fa fa-filter me-1"></i> Terapkan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Groups List Table -->
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small">
                        <tr>
                            <th>Nama Kelompok</th>
                            <th>Kode</th>
                            <th>Jenis Kelompok</th>
                            <th>Pembimbing / Mentor</th>
                            <th>Anggota</th>
                            <th class="text-end">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groups)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa fa-users fa-3x mb-3 text-secondary d-block"></i>
                                    Belum ada kelompok fleksibel. Klik <strong>"Buat Kelompok Baru"</strong>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($groups as $g): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= s($g->name) ?></div>
                                    </td>
                                    <td><code><?= s($g->code) ?></code></td>
                                    <td>
                                        <?php
                                        $typebadge = 'bg-secondary';
                                        if ($g->group_type === 'remedial') $typebadge = 'bg-danger';
                                        elseif ($g->group_type === 'competition') $typebadge = 'bg-warning text-dark';
                                        elseif ($g->group_type === 'project') $typebadge = 'bg-info text-dark';
                                        elseif ($g->group_type === 'extracurricular') $typebadge = 'bg-success';
                                        ?>
                                        <span class="badge <?= $typebadge ?> text-uppercase"><?= s($g->group_type) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($g->mentor_firstname)): ?>
                                            <div class="small fw-semibold"><?= s($g->mentor_firstname . ' ' . $g->mentor_lastname) ?></div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted">Belum Ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <i class="fa fa-user-check me-1"></i><?= $g->total_members ?> Anggota
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= new moodle_url('/local/lumina_academic/groups.php', ['groupid' => $g->id, 'members' => 1, 'type' => $typefilter]) ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fa fa-users-cog me-1"></i> Kelola Anggota
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

<!-- Modal Buat Kelompok Baru -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-labelledby="createGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= $PAGE->url ?>">
                <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                <input type="hidden" name="action" value="create_group">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="createGroupModalLabel"><i class="fa fa-plus-circle me-2"></i>Buat Kelompok Belajar Fleksibel</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Kelompok <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Tim Olimpiade Web Laravel" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kode Kelompok <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control" placeholder="Contoh: OLY-WEB-2026" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Jenis Kelompok <span class="text-danger">*</span></label>
                        <select name="group_type" class="form-select" required>
                            <option value="project">Kelompok Proyek</option>
                            <option value="competition">Tim Olimpiade / Perlombaan</option>
                            <option value="remedial">Remedial Belajar</option>
                            <option value="extracurricular">Ekstrakurikuler</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Guru Pembimbing / Mentor</label>
                        <select name="mentor_id" class="form-select">
                            <option value="0">-- Pilih Guru Pembimbing (Opsional) --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t->id ?>"><?= s($t->firstname . ' ' . $t->lastname) ?> (<?= s($t->email) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> Simpan Kelompok</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
