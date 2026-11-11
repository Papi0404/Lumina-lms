<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Resource Library & Private Study Space for Lumina LMS.
 *
 * Supports Course Libraries, School Digital Library, and 100% Private Student Study Files.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/library_manager.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

use local_lumina_core\library_manager;

require_login();

$tab      = optional_param('tab', '', PARAM_ALPHANUMEXT); // 'course', 'private', 'school'
$courseid = optional_param('courseid', -999, PARAM_INT);
$folderid = optional_param('folderid', 0, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHANUMEXT);

$systemcontext = context_system::instance();
$usercontext   = context_user::instance($USER->id);
$roleinfo      = theme_lumina_role_resolver::resolve_role($USER);

// Enrolled courses for dropdown / tab selection
$mycourses = [];
if ($roleinfo['is_admin']) {
    $mycourses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC');
} else if ($roleinfo['is_teacher']) {
    $tdata = theme_lumina_role_resolver::get_teacher_dashboard_data($USER);
    $mycourses = $tdata['courses'];
} else {
    $mycourses = enrol_get_all_users_courses($USER->id, true);
}

// Default tab & course resolution
$isprivate = false;
$firstcourse = !empty($mycourses) ? reset($mycourses) : null;
$firstcid = $firstcourse ? (is_object($firstcourse) ? $firstcourse->id : $firstcourse['id']) : 0;

if ($tab === 'private' || $courseid === -1) {
    $isprivate = true;
    $courseid = -1;
} else if ($courseid === -999) {
    // No parameter passed: default intelligently!
    // Students land on their enrolled course by default (not school library!)
    if (!empty($mycourses)) {
        $courseid = (int)$firstcid;
    } else {
        $isprivate = true;
        $courseid = -1;
    }
} else if ($courseid > 0 && !isset($mycourses[$courseid]) && !$roleinfo['is_admin']) {
    // Security check: Student cannot tamper courseid to inspect other courses!
    $courseid = (int)$firstcid;
}

$course = ($courseid > 0) ? $DB->get_record('course', ['id' => $courseid]) : null;
$coursecontext = $course ? context_course::instance($course->id) : $systemcontext;

// Permission Check for Course/School Library
// Students are strictly read-only in Course and School libraries!
$canmanage = false;
if (!$isprivate) {
    if ($roleinfo['is_admin']) {
        $canmanage = true;
    } else if ($roleinfo['is_teacher'] && $courseid > 0) {
        $canmanage = has_capability('moodle/course:update', $coursecontext) || has_capability('local/lumina_core:managelibrary', $coursecontext);
    }
}

// Seed default demo content for course or school library if empty
if (!$isprivate) {
    library_manager::ensure_default_structure($courseid, $USER->id);
}

$notice = '';
$noticetype = 'info';

// Process Actions
if (data_submitted() && confirm_sesskey()) {
    $fs = get_file_storage();

    // 1. Private Study File Upload
    if ($action === 'upload_private_file') {
        $title = optional_param('private_title', '', PARAM_TEXT);

        if (!empty($_FILES['private_file']['name']) && is_uploaded_file($_FILES['private_file']['tmp_name'])) {
            $originalname = clean_param($_FILES['private_file']['name'], PARAM_FILE);
            $ext = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));

            $typemap = [
                'pdf'  => 'pdf',
                'doc'  => 'doc',
                'docx' => 'doc',
                'ppt'  => 'doc',
                'pptx' => 'doc',
                'zip'  => 'doc',
                'txt'  => 'doc',
                'png'  => 'doc',
                'jpg'  => 'doc',
                'jpeg' => 'doc',
            ];
            $itemtype = $typemap[$ext] ?? 'doc';

            // Store in Moodle's secure user private file area
            $fileinfo = [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea'  => 'private',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $originalname,
                'userid'    => $USER->id,
            ];

            // If file already exists, delete existing
            $existingfile = $fs->get_file($usercontext->id, 'user', 'private', 0, '/', $originalname);
            if ($existingfile) {
                $existingfile->delete();
            }

            $storedfile = $fs->create_file_from_pathname($fileinfo, $_FILES['private_file']['tmp_name']);
            $fileurl = moodle_url::make_pluginfile_url(
                $usercontext->id,
                'user',
                'private',
                null,
                '/',
                $originalname
            )->out(false);

            $it = new stdClass();
            $it->folderid = 0;
            $it->courseid = -1; // -1 = Private Study File!
            $it->title = clean_text($title ?: $originalname);
            $it->itemtype = $itemtype;
            $it->contentref = $fileurl;
            $it->fileid = $storedfile->get_id();
            $it->filesize = $storedfile->get_filesize();
            $it->createdby = $USER->id;
            $it->timecreated = time();
            $it->timemodified = time();

            $DB->insert_record('local_lumina_lib_items', $it);
            $notice = 'Berkas belajar pribadi berhasil diunggah secara rahasia!';
            $noticetype = 'success';
            $isprivate = true;
            $courseid = -1;
        } else {
            $notice = 'Gagal mengunggah berkas. Pastikan file valid dan tidak melebihi batas ukuran.';
            $noticetype = 'danger';
        }
    } else if ($action === 'add_private_link') {
        $linktitle = required_param('link_title', PARAM_TEXT);
        $linkurl   = required_param('link_url', PARAM_URL);
        $linktype  = optional_param('link_type', 'link', PARAM_ALPHA);

        $it = new stdClass();
        $it->folderid = 0;
        $it->courseid = -1;
        $it->title = clean_text($linktitle);
        $it->itemtype = in_array($linktype, ['h5p', 'link', 'pdf'], true) ? $linktype : 'link';
        $it->contentref = clean_text($linkurl);
        $it->fileid = 0;
        $it->filesize = 0;
        $it->createdby = $USER->id;
        $it->timecreated = time();
        $it->timemodified = time();

        $DB->insert_record('local_lumina_lib_items', $it);
        $notice = 'Tautan referensi belajar pribadi berhasil disimpan!';
        $noticetype = 'success';
        $isprivate = true;
        $courseid = -1;
    } else if ($action === 'delete_private_item') {
        $itemid = required_param('item_id', PARAM_INT);
        $target = $DB->get_record('local_lumina_lib_items', ['id' => $itemid, 'createdby' => $USER->id, 'courseid' => -1]);

        if ($target) {
            if ($target->fileid) {
                $f = $fs->get_file_by_id($target->fileid);
                if ($f && $f->get_userid() == $USER->id) {
                    $f->delete();
                }
            }
            $DB->delete_records('local_lumina_lib_items', ['id' => $target->id]);
            $notice = 'Berkas pribadi berhasil dihapus dari ruang belajar Anda.';
            $noticetype = 'success';
        }
        $isprivate = true;
        $courseid = -1;
    } else if ($canmanage) {
        // Teacher / Admin Actions for Course or School Library.
        // SECURITY CHECK: School Library (courseid = 0) CAN ONLY BE MANAGED BY ADMIN!
        if ($courseid === 0 && !$roleinfo['is_admin']) {
            $notice = 'Akses ditolak: Hanya Administrator Sekolah yang berhak menambah, mengubah, atau menghapus materi di Perpustakaan Sekolah.';
            $noticetype = 'danger';
        } else if ($action === 'create_folder') {
            $foldername = required_param('folder_name', PARAM_TEXT);
            $folderdesc = optional_param('folder_desc', '', PARAM_TEXT);
            $parent = optional_param('parent_id', 0, PARAM_INT);

            $f = new stdClass();
            $f->courseid = $courseid;
            $f->parentid = $parent;
            $f->name = clean_text($foldername);
            $f->description = clean_text($folderdesc);
            $f->icon = ($parent > 0) ? 'folder_open' : 'folder';
            $f->sortorder = 1;
            $f->createdby = $USER->id;
            $f->timecreated = time();
            $f->timemodified = time();

            $DB->insert_record('local_lumina_lib_folders', $f);
            $notice = 'Folder berhasil dibuat!';
            $noticetype = 'success';
        } else if ($action === 'edit_folder') {
            $editfolderid = required_param('edit_folder_id', PARAM_INT);
            $editname     = required_param('edit_folder_name', PARAM_TEXT);
            $editdesc     = optional_param('edit_folder_desc', '', PARAM_TEXT);

            if (library_manager::update_folder($editfolderid, $editname, $editdesc)) {
                $notice = 'Folder perpustakaan berhasil diperbarui!';
                $noticetype = 'success';
            } else {
                $notice = 'Gagal memperbarui folder.';
                $noticetype = 'danger';
            }
        } else if ($action === 'delete_folder') {
            $delfolderid = optional_param('delete_folder_id', 0, PARAM_INT);
            if (!$delfolderid) {
                $delfolderid = optional_param('folderid', 0, PARAM_INT);
            }

            if ($delfolderid > 0 && library_manager::delete_folder($delfolderid)) {
                $notice = 'Folder beserta seluruh isinya berhasil dihapus!';
                $noticetype = 'success';
                // If the user was inside the deleted folder, jump back to root
                if ($folderid == $delfolderid) {
                    $folderid = 0;
                }
            } else {
                $notice = 'Gagal menghapus folder.';
                $noticetype = 'danger';
            }
        } else if ($action === 'add_item' && $folderid > 0) {
            $title = required_param('item_title', PARAM_TEXT);
            $type = required_param('item_type', PARAM_ALPHA);
            $source = optional_param('item_source', 'url', PARAM_ALPHA); // 'file' or 'url'

            $fileurl = '';
            $fileid = 0;
            $filesize = 0;

            if ($source === 'file' && !empty($_FILES['item_file']['name']) && is_uploaded_file($_FILES['item_file']['tmp_name'])) {
                $originalname = clean_param($_FILES['item_file']['name'], PARAM_FILE);
                $ext = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));

                $typemap = [
                    'pdf'  => 'pdf',
                    'doc'  => 'doc',
                    'docx' => 'doc',
                    'ppt'  => 'doc',
                    'pptx' => 'doc',
                    'zip'  => 'doc',
                    'txt'  => 'doc',
                    'png'  => 'doc',
                    'jpg'  => 'doc',
                    'jpeg' => 'doc',
                ];
                $type = $typemap[$ext] ?? 'doc';

                $fileinfo = [
                    'contextid' => $coursecontext->id,
                    'component' => 'local_lumina_core',
                    'filearea'  => 'library',
                    'itemid'    => $folderid,
                    'filepath'  => '/',
                    'filename'  => $originalname,
                    'userid'    => $USER->id,
                ];

                // Remove existing if conflict
                $existing = $fs->get_file($coursecontext->id, 'local_lumina_core', 'library', $folderid, '/', $originalname);
                if ($existing) {
                    $existing->delete();
                }

                $storedfile = $fs->create_file_from_pathname($fileinfo, $_FILES['item_file']['tmp_name']);
                $fileurl = moodle_url::make_pluginfile_url(
                    $coursecontext->id,
                    'local_lumina_core',
                    'library',
                    $folderid,
                    '/',
                    $originalname
                )->out(false);

                $fileid = $storedfile->get_id();
                $filesize = $storedfile->get_filesize();
            } else {
                $fileurl = optional_param('item_url', '', PARAM_URL);
                if (empty($fileurl)) {
                    $fileurl = '#';
                }
            }

            $it = new stdClass();
            $it->folderid = $folderid;
            $it->courseid = $courseid;
            $it->title = clean_text($title);
            $it->itemtype = in_array($type, ['h5p', 'pdf', 'page', 'doc', 'link'], true) ? $type : 'link';
            $it->contentref = clean_text($fileurl);
            $it->fileid = $fileid;
            $it->filesize = $filesize ?: 1048576;
            $it->createdby = $USER->id;
            $it->timecreated = time();
            $it->timemodified = time();

            $DB->insert_record('local_lumina_lib_items', $it);
            $notice = 'Bahan ajar berhasil ditambahkan ke dalam folder!';
            $noticetype = 'success';
        } else if ($action === 'edit_item') {
            $edititemid = required_param('edit_item_id', PARAM_INT);
            $edittitle  = required_param('edit_item_title', PARAM_TEXT);
            $edittype   = required_param('edit_item_type', PARAM_ALPHA);
            $editurl    = optional_param('edit_item_url', '', PARAM_URL);

            if (library_manager::update_item($edititemid, $edittitle, $edittype, $editurl)) {
                $notice = 'Materi berhasil diperbarui!';
                $noticetype = 'success';
            } else {
                $notice = 'Gagal memperbarui materi.';
                $noticetype = 'danger';
            }
        } else if ($action === 'delete_item') {
            $delitemid = optional_param('delete_item_id', 0, PARAM_INT);
            if (!$delitemid) {
                $delitemid = optional_param('item_id', 0, PARAM_INT);
            }

            if ($delitemid > 0 && library_manager::delete_item($delitemid)) {
                $notice = 'Materi berhasil dihapus dari perpustakaan!';
                $noticetype = 'success';
            } else {
                $notice = 'Gagal menghapus materi.';
                $noticetype = 'danger';
            }
        }
    }
}

// Data fetching
$privateitems = [];
if ($isprivate) {
    // Strictly isolate to current logged-in user!
    // Even admin cannot see other users' private files!
    $prows = $DB->get_records('local_lumina_lib_items', [
        'courseid'  => -1,
        'createdby' => $USER->id,
    ], 'id DESC');

    $iconmap = [
        'pdf'  => 'picture_as_pdf',
        'h5p'  => 'interactive_subtitles',
        'page' => 'menu_book',
        'doc'  => 'description',
        'link' => 'open_in_new',
    ];

    $badgemap = [
        'pdf'  => 'bg-danger text-danger bg-opacity-15 border border-danger border-opacity-25',
        'h5p'  => 'bg-primary text-primary bg-opacity-15 border border-primary border-opacity-25',
        'page' => 'bg-success text-success bg-opacity-15 border border-success border-opacity-25',
        'doc'  => 'bg-info text-info bg-opacity-15 border border-info border-opacity-25',
        'link' => 'bg-secondary text-secondary bg-opacity-15 border',
    ];

    $typemap = [
        'pdf'  => 'Dokumen PDF Pribadi',
        'h5p'  => 'Latihan Interaktif H5P',
        'doc'  => 'Berkas Mandiri',
        'link' => 'Tautan Referensi',
    ];

    foreach ($prows as $it) {
        $type = $it->itemtype;
        $privateitems[] = [
            'id'          => $it->id,
            'title'       => format_string($it->title),
            'itemtype'    => $type,
            'type_label'  => $typemap[$type] ?? strtoupper($type),
            'icon'        => $iconmap[$type] ?? 'article',
            'badge_class' => $badgemap[$type] ?? 'bg-light text-dark',
            'url'         => $it->contentref,
            'is_h5p'      => ($type === 'h5p'),
            'size_str'    => ($it->filesize > 0 && function_exists('display_size')) ? display_size($it->filesize) : '',
            'created_str' => userdate($it->timecreated, '%d %b %Y, %H:%M'),
        ];
    }
} else {
    $folders = library_manager::get_folders($courseid, $folderid);
    $items = ($folderid > 0) ? library_manager::get_items($folderid) : [];
    $breadcrumbs = ($folderid > 0) ? library_manager::get_breadcrumbs($folderid) : [];
}

$PAGE->set_url('/local/lumina_core/library.php', [
    'courseid' => $courseid,
    'folderid' => $folderid,
    'tab'      => $isprivate ? 'private' : '',
]);
$PAGE->set_context($isprivate ? $usercontext : $coursecontext);
$PAGE->set_title('Perpustakaan Sumber Belajar & Modul');
$PAGE->set_heading('Perpustakaan Sumber Belajar');

echo $OUTPUT->header();
?>

<div class="lumina-library-container py-3">
    <!-- Header Section -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-primary" style="font-size: 32px;">folder_special</span>
                Perpustakaan Sumber Belajar &amp; Ruang Mandiri
            </h2>
            <span class="text-muted small">
                Akses bahan ajar kursus, repositori berkas publik sekolah, dan ruang berkas belajar mandiri yang 100% rahasia.
            </span>
        </div>

        <div>
            <?php if ($isprivate): ?>
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" data-bs-toggle="modal" data-bs-target="#uploadPrivateModal">
                <span class="material-symbols-outlined" style="font-size: 18px;">cloud_upload</span>
                Unggah Berkas Belajar Pribadi
            </button>
            <?php elseif ($canmanage): ?>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                    <span class="material-symbols-outlined" style="font-size: 18px;">create_new_folder</span>
                    <?php echo $folderid > 0 ? 'Buat Subfolder Baru' : 'Buat Folder Baru'; ?>
                </button>
                <?php if ($folderid > 0): ?>
                <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" data-bs-toggle="modal" data-bs-target="#addItemModal">
                    <span class="material-symbols-outlined" style="font-size: 18px;">upload_file</span>
                    Tambah Materi Kuliah
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice): ?>
    <div class="alert alert-<?php echo $noticetype; ?> rounded-2xl alert-dismissible fade show mb-4 shadow-xs" role="alert">
        <div class="d-flex align-items-center gap-2">
            <span class="material-symbols-outlined">info</span>
            <strong><?php echo $notice; ?></strong>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Master Navigation Tabs: Private Files vs Course Library vs School Library -->
    <div class="antigravity-glass-card rounded-3xl p-3 mb-4">
        <div class="d-flex align-items-center gap-2 overflow-auto py-1">
            <!-- 1. Private Study Files Tab -->
            <a href="library.php?tab=private" class="btn btn-sm rounded-pill px-3 py-2 text-nowrap d-inline-flex align-items-center gap-1 font-semibold <?php echo $isprivate ? 'btn-primary shadow-xs' : 'btn-light border text-dark'; ?>">
                <span class="material-symbols-outlined" style="font-size: 16px; color: <?php echo $isprivate ? '#fff' : '#4338CA'; ?>;">lock</span>
                <span>Berkas Belajar Pribadi</span>
                <span class="badge rounded-pill bg-warning text-dark px-2 py-0-5 ms-1" style="font-size: 0.65rem;">100% Rahasia</span>
            </a>

            <!-- 2. Enrolled Courses Tabs -->
            <?php foreach ($mycourses as $c): ?>
                <?php
                $cid = is_object($c) ? $c->id : $c['id'];
                $cname = is_object($c) ? $c->fullname : $c['fullname'];
                $cshort = is_object($c) ? $c->shortname : $c['shortname'];
                $active = (!$isprivate && $courseid == $cid);
                ?>
                <a href="library.php?courseid=<?php echo $cid; ?>" class="btn btn-sm rounded-pill px-3 py-2 text-nowrap d-inline-flex align-items-center gap-1 font-semibold <?php echo $active ? 'btn-primary shadow-xs' : 'btn-light border text-secondary'; ?>">
                    <span class="material-symbols-outlined" style="font-size: 16px;">auto_stories</span>
                    <?php echo format_string($cname); ?> (<?php echo format_string($cshort); ?>)
                </a>
            <?php endforeach; ?>

            <!-- 3. School Library Tab -->
            <a href="library.php?courseid=0" class="btn btn-sm rounded-pill px-3 py-2 text-nowrap d-inline-flex align-items-center gap-1 font-semibold <?php echo (!$isprivate && $courseid === 0) ? 'btn-primary shadow-xs' : 'btn-light border text-secondary'; ?>">
                <span class="material-symbols-outlined" style="font-size: 16px;">local_library</span>
                Perpustakaan Umum Sekolah
            </a>
        </div>
    </div>

    <?php if ($isprivate): ?>
        <!-- ================================================================= -->
        <!-- PRIVATE STUDY FILES VIEW (100% PRIVATE & UNTOUCHABLE BY ADMIN)    -->
        <!-- ================================================================= -->
        <div class="alert alert-info rounded-3xl p-4 mb-4 border-0 shadow-xs bg-indigo bg-opacity-10 text-dark">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle p-2 bg-indigo text-white d-flex align-items-center justify-content-center">
                    <span class="material-symbols-outlined" style="font-size: 24px;">security</span>
                </div>
                <div>
                    <h5 class="fw-bold text-indigo mb-1">Ruang Belajar Mandiri Pribadi (Terproteksi &amp; Rahasia)</h5>
                    <p class="mb-0 small text-muted" style="line-height: 1.5;">
                        Semua berkas dan catatan di halaman ini tersimpan di ruang penyimpanan pribadi Anda (<strong><?php echo fullname($USER); ?></strong>). 
                        Berkas ini 100% rahasia — dosen pengampu maupun Administrator sekolah <strong>TIDAK BISA</strong> melihat, membuka, atau mengakses berkas di ruang ini.
                    </p>
                </div>
            </div>
        </div>

        <div class="antigravity-glass-card rounded-3xl p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0">Daftar Berkas Belajar Pribadi Anda</h5>
                    <span class="text-muted small">Total: <strong><?php echo count($privateitems); ?></strong> Berkas Tersimpan</span>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 font-semibold" data-bs-toggle="modal" data-bs-target="#addPrivateLinkModal">
                        <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">add_link</span>
                        Simpan Tautan / H5P Pribadi
                    </button>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 font-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#uploadPrivateModal">
                        <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">upload</span>
                        Unggah File
                    </button>
                </div>
            </div>

            <?php if (!empty($privateitems)): ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($privateitems as $item): ?>
                <div class="p-3 rounded-2xl border bg-white d-flex align-items-center justify-content-between flex-wrap gap-3 shadow-xs">
                    <div class="d-flex align-items-center gap-3 overflow-hidden">
                        <div class="rounded-circle p-2 bg-indigo bg-opacity-10 text-indigo d-flex align-items-center justify-content-center">
                            <span class="material-symbols-outlined" style="font-size: 22px;"><?php echo $item['icon']; ?></span>
                        </div>
                        <div>
                            <h6 class="fw-bold text-dark mb-0"><?php echo $item['title']; ?></h6>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge rounded-pill px-2 py-0-5 font-semibold <?php echo $item['badge_class']; ?>" style="font-size: 0.68rem;">
                                    <?php echo $item['type_label']; ?>
                                </span>
                                <?php if ($item['size_str']): ?>
                                    <span class="text-muted small" style="font-size: 0.72rem;">&bull; <?php echo $item['size_str']; ?></span>
                                <?php endif; ?>
                                <span class="text-muted small" style="font-size: 0.72rem;">&bull; Disimpan pada <?php echo $item['created_str']; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <?php if ($item['is_h5p']): ?>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 font-semibold" onclick="openH5pModal('<?php echo s($item['title']); ?>', '<?php echo s($item['url']); ?>')">
                            <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">play_arrow</span>
                            Buka Interaktif
                        </button>
                        <?php else: ?>
                        <a href="<?php echo $item['url']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 font-semibold">
                            <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">download</span>
                            Buka / Unduh
                        </a>
                        <?php endif; ?>

                        <!-- Delete Button: Only the student can delete their own private file -->
                        <form method="post" action="library.php?tab=private&action=delete_private_item" onsubmit="return confirm('Hapus berkas pribadi ini secara permanen?');" class="d-inline">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2-5 py-1" title="Hapus Berkas Pribadi">
                                <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">delete</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <span class="material-symbols-outlined text-muted" style="font-size: 48px;">cloud_off</span>
                <h5 class="fw-bold mt-2 mb-1">Belum Ada Berkas Belajar Pribadi</h5>
                <p class="small mb-3">Unggah materi latihan, modul rangkuman, atau simpan tautan belajar mandiri Anda di sini.</p>
                <button type="button" class="btn btn-primary rounded-pill px-4 py-2 font-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#uploadPrivateModal">
                    Unggah Berkas Pertama
                </button>
            </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- COURSE / SCHOOL LIBRARY VIEW (HIERARCHICAL FOLDERS & SUBFOLDERS)  -->
        <!-- ================================================================= -->
        <?php if ($courseid === 0): ?>
            <?php if ($roleinfo['is_admin']): ?>
            <div class="alert alert-primary rounded-3xl p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 border-0 bg-primary bg-opacity-10 text-dark">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle p-2 bg-primary text-white d-flex align-items-center justify-content-center">
                        <span class="material-symbols-outlined" style="font-size: 24px;">admin_panel_settings</span>
                    </div>
                    <div>
                        <strong class="text-primary d-block">Mode Administrator &bull; Perpustakaan Umum Sekolah</strong>
                        <div class="small text-muted">Hanya Administrator yang memiliki hak akses penuh untuk <strong>menambah folder/berkas, mengedit, dan menghapus</strong> materi sekolah. Pengajar dan siswa hanya memiliki izin baca &amp; unduh.</div>
                    </div>
                </div>
                <span class="badge bg-primary rounded-pill px-3 py-1 font-semibold">Wewenang Administrator</span>
            </div>
            <?php else: ?>
            <div class="alert alert-light border rounded-3xl p-3 mb-4 d-flex align-items-center gap-3 text-secondary shadow-xs">
                <div class="rounded-circle p-2 bg-indigo bg-opacity-10 text-indigo d-flex align-items-center justify-content-center">
                    <span class="material-symbols-outlined" style="font-size: 24px;">local_library</span>
                </div>
                <div class="small">
                    <strong class="text-dark d-block">Perpustakaan Umum Sekolah (Mode Baca &amp; Unduh)</strong>
                    Koleksi buku panduan, jurnal, dan modul akademik publik dikelola secara terpusat oleh <strong>Administrator Sekolah</strong>. Anda dapat membaca dan mengunduh seluruh materi yang tersedia.
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Breadcrumb Hierarchy Path -->
        <div class="antigravity-glass-card rounded-2xl px-4 py-2-5 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 small">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="library.php?courseid=<?php echo $courseid; ?>&folderid=0" class="text-decoration-none fw-semibold text-primary d-inline-flex align-items-center gap-1">
                    <span class="material-symbols-outlined" style="font-size: 18px;">home</span>
                    Root Folder
                </a>

                <?php foreach ($breadcrumbs as $crumb): ?>
                    <span class="text-muted">&rsaquo;</span>
                    <?php if ($crumb['id'] == $folderid): ?>
                        <span class="fw-bold text-dark"><?php echo $crumb['name']; ?></span>
                    <?php else: ?>
                        <a href="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $crumb['id']; ?>" class="text-decoration-none text-primary fw-semibold">
                            <?php echo $crumb['name']; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($canmanage && $folderid > 0): ?>
            <?php 
                $curf = $DB->get_record('local_lumina_lib_folders', ['id' => $folderid]);
                $parentid = $curf ? (int)$curf->parentid : 0;
            ?>
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-xs btn-light border rounded-pill px-2-5 py-1 text-secondary" title="Edit Folder Ini" onclick="openEditFolderModal(<?php echo $folderid; ?>, '<?php echo $curf ? s($curf->name) : ''; ?>', '<?php echo $curf ? s($curf->description) : ''; ?>')">
                    <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">edit</span>
                    <span class="small font-semibold">Edit Folder Ini</span>
                </button>
                <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $parentid; ?>" onsubmit="return confirm('Apakah Anda yakin ingin menghapus folder ini beserta seluruh isinya? Tindakan ini permanen.');" class="d-inline">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="delete_folder">
                    <input type="hidden" name="delete_folder_id" value="<?php echo $folderid; ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill px-2-5 py-1" title="Hapus Folder Ini">
                        <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: -2px;">delete</span>
                        <span class="small font-semibold">Hapus Folder Ini</span>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Folders Grid -->
        <?php if (!empty($folders)): ?>
        <div class="mb-4">
            <h6 class="fw-bold text-muted text-uppercase small mb-3">Folder &amp; Subfolder</h6>
            <div class="row g-3">
                <?php foreach ($folders as $f): ?>
                <div class="col-md-4 col-sm-6">
                    <div class="antigravity-glass-card rounded-2xl p-3 h-100 transition-all hover-shadow-md border d-flex flex-column justify-content-between">
                        <a href="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $f['id']; ?>" class="text-decoration-none">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-2xl p-2-5 bg-warning bg-opacity-15 text-warning d-flex align-items-center justify-content-center">
                                    <span class="material-symbols-outlined" style="font-size: 28px;">folder</span>
                                </div>
                                <div class="overflow-hidden flex-fill">
                                    <h6 class="fw-bold text-dark mb-1 text-truncate"><?php echo $f['name']; ?></h6>
                                    <p class="text-muted small mb-2 text-truncate" style="font-size: 0.75rem;">
                                        <?php echo $f['description'] ?: 'Tidak ada deskripsi.'; ?>
                                    </p>
                                    <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2 py-0-5" style="font-size: 0.68rem;">
                                        <?php echo $f['total_count']; ?> Konten / Subfolder
                                    </span>
                                </div>
                            </div>
                        </a>

                        <?php if ($canmanage): ?>
                        <div class="d-flex align-items-center justify-content-end gap-1 mt-3 pt-2 border-top">
                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2-5 py-1 text-secondary" title="Edit Folder" onclick="openEditFolderModal(<?php echo $f['id']; ?>, '<?php echo s($f['name']); ?>', '<?php echo s($f['description']); ?>')">
                                <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: -2px;">edit</span>
                                <span class="small font-semibold">Edit</span>
                            </button>
                            <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>" onsubmit="return confirm('Apakah Anda yakin ingin menghapus folder \'<?php echo s($f['name']); ?>\' beserta seluruh isinya? Tindakan ini permanen.');" class="d-inline">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="delete_folder">
                                <input type="hidden" name="delete_folder_id" value="<?php echo $f['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2-5 py-1" title="Hapus Folder">
                                    <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: -2px;">delete</span>
                                    <span class="small font-semibold">Hapus</span>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
            <?php if ($folderid === 0): ?>
            <div class="antigravity-glass-card rounded-3xl p-5 mb-4 text-center">
                <div class="mx-auto rounded-circle bg-light d-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                    <span class="material-symbols-outlined text-muted" style="font-size: 32px;">folder_off</span>
                </div>
                <h6 class="fw-bold mb-1">Belum Ada Folder</h6>
                <p class="text-muted small mb-3">Perpustakaan ini masih kosong. Silakan buat folder baru untuk mengorganisir bahan ajar dan modul.</p>
                <?php if ($canmanage): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 py-2 font-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                    <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">create_new_folder</span>
                    Buat Folder Pertama
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Items Inside Active Folder -->
        <?php if ($folderid > 0): ?>
        <div class="antigravity-glass-card rounded-3xl p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0">Bahan Ajar &amp; Berkas</h5>
                    <span class="text-muted small">Total: <strong><?php echo count($items); ?></strong> Berkas di folder ini</span>
                </div>
                <?php if ($canmanage): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1-5 font-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#addItemModal">
                    <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">upload_file</span>
                    Tambah Berkas / Materi
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($items)): ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($items as $it): ?>
                <div class="p-3 rounded-2xl border bg-white d-flex align-items-center justify-content-between flex-wrap gap-3 shadow-xs">
                    <div class="d-flex align-items-center gap-3 overflow-hidden">
                        <div class="rounded-circle p-2 bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center">
                            <span class="material-symbols-outlined" style="font-size: 22px;"><?php echo $it['icon']; ?></span>
                        </div>
                        <div>
                            <h6 class="fw-bold text-dark mb-0"><?php echo $it['title']; ?></h6>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge rounded-pill px-2 py-0-5 font-semibold <?php echo $it['badge_class']; ?>" style="font-size: 0.68rem;">
                                    <?php echo $it['type_label']; ?>
                                </span>
                                <?php if ($it['size_str']): ?>
                                    <span class="text-muted small" style="font-size: 0.72rem;">&bull; <?php echo $it['size_str']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php if ($it['is_h5p']): ?>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1-5 font-semibold shadow-xs" onclick="openH5pModal('<?php echo s($it['title']); ?>', '<?php echo s($it['url']); ?>')">
                            <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -3px;">play_arrow</span>
                            Buka Interaktif H5P
                        </button>
                        <?php else: ?>
                        <a href="<?php echo $it['url']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1-5 font-semibold">
                            <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -3px;">download</span>
                            Buka / Unduh
                        </a>
                        <?php endif; ?>

                        <?php if ($canmanage): ?>
                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-2-5 py-1 text-secondary" title="Edit Materi" onclick="openEditItemModal(<?php echo $it['id']; ?>, '<?php echo s($it['title']); ?>', '<?php echo $it['itemtype']; ?>', '<?php echo s($it['url']); ?>')">
                            <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: -2px;">edit</span>
                            <span class="small font-semibold">Edit</span>
                        </button>
                        <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>" onsubmit="return confirm('Hapus berkas materi ini dari perpustakaan?');" class="d-inline">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="delete_item_id" value="<?php echo $it['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2-5 py-1" title="Hapus Berkas">
                                <span class="material-symbols-outlined" style="font-size: 15px; vertical-align: -2px;">delete</span>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-4 text-muted">
                <span class="material-symbols-outlined" style="font-size: 40px;">draft</span>
                <p class="mb-0 mt-2 small">Belum ada bahan ajar yang diunggah ke dalam folder ini.</p>
                <?php if ($canmanage): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 py-2 mt-2 font-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#addItemModal">
                    Unggah Berkas Pertama
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal Buka Konten H5P -->
<div class="modal fade" id="h5pModal" tabindex="-1" aria-labelledby="h5pModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <div class="modal-header border-bottom px-4 pt-3 pb-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="h5pModalLabel">
                    <span class="material-symbols-outlined text-primary">interactive_subtitles</span>
                    <span id="h5pModalTitle">Materi Interaktif H5P</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="min-height: 550px;">
                <iframe id="h5pIframe" src="" width="100%" height="550" frameborder="0" allowfullscreen="allowfullscreen"></iframe>
            </div>
            <div class="modal-footer border-top px-4 py-2">
                <span class="text-muted small me-auto">Lumina LMS Interactive Engine &bull; H5P HTML5 Compliant</span>
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Upload Berkas Pribadi (Private) -->
<div class="modal fade" id="uploadPrivateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?tab=private" enctype="multipart/form-data">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="upload_private_file">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-primary">lock</span>
                        Unggah Berkas Belajar Pribadi
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Judul / Catatan Berkas (Opsional)</label>
                        <input type="text" name="private_title" class="form-control rounded-2xl" placeholder="Contoh: Rangkuman Mandiri Bab 1 & 2">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Pilih Berkas Komputer</label>
                        <input type="file" name="private_file" class="form-control rounded-2xl" required>
                        <span class="form-text text-muted small" style="font-size: 0.72rem;">Mendukung PDF, Word, PowerPoint, TXT, ZIP, dan Gambar.</span>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Unggah Sekarang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Simpan Tautan Belajar Pribadi -->
<div class="modal fade" id="addPrivateLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?tab=private">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="add_private_link">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-primary">link</span>
                        Simpan Tautan Referensi Mandiri
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Judul Tautan / Latihan</label>
                        <input type="text" name="link_title" class="form-control rounded-2xl" placeholder="Contoh: Latihan Interaktif H5P di Luar Kampus" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Tipe Konten</label>
                        <select name="link_type" class="form-select rounded-2xl">
                            <option value="link">Tautan Web Umum / Referensi</option>
                            <option value="h5p">Modul Interaktif H5P Embed</option>
                            <option value="pdf">Tautan Berkas PDF Eksternal</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">URL Tautan</label>
                        <input type="url" name="link_url" class="form-control rounded-2xl" placeholder="https://..." required>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Simpan Tautan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openH5pModal(title, url) {
    document.getElementById('h5pModalTitle').textContent = title;
    document.getElementById('h5pIframe').src = url;
    const modal = new bootstrap.Modal(document.getElementById('h5pModal'));
    modal.show();
}

function openEditFolderModal(id, name, desc) {
    document.getElementById('editFolderId').value = id;
    document.getElementById('editFolderName').value = name;
    document.getElementById('editFolderDesc').value = desc;
    const modal = new bootstrap.Modal(document.getElementById('editFolderModal'));
    modal.show();
}

function openEditItemModal(id, title, type, url) {
    document.getElementById('editItemId').value = id;
    document.getElementById('editItemTitle').value = title;
    document.getElementById('editItemType').value = type;
    document.getElementById('editItemUrl').value = url;
    const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
    modal.show();
}

function toggleItemSource(mode) {
    const fileGroup = document.getElementById('itemFileGroup');
    const urlGroup = document.getElementById('itemUrlGroup');
    const fileInput = document.getElementById('itemFileInput');
    const urlInput = document.getElementById('itemUrlInput');

    if (mode === 'file') {
        fileGroup.classList.remove('d-none');
        urlGroup.classList.add('d-none');
        fileInput.setAttribute('required', 'required');
        urlInput.removeAttribute('required');
    } else {
        fileGroup.classList.add('d-none');
        urlGroup.classList.remove('d-none');
        urlInput.setAttribute('required', 'required');
        fileInput.removeAttribute('required');
    }
}
</script>

<?php if ($canmanage): ?>
<!-- Modal Buat Folder / Subfolder -->
<div class="modal fade" id="createFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="create_folder">
                <input type="hidden" name="parent_id" value="<?php echo $folderid; ?>">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold"><?php echo $folderid > 0 ? 'Buat Subfolder Baru' : ($courseid === 0 ? 'Tambah Folder Sekolah Baru' : 'Buat Folder Baru'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Nama Folder</label>
                        <input type="text" name="folder_name" class="form-control rounded-2xl" placeholder="Contoh: Modul Kurikulum 2026 atau Bab 1: Pengantar" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Deskripsi Singkat</label>
                        <textarea name="folder_desc" class="form-control rounded-2xl" rows="2" placeholder="Keterangan materi di dalam folder ini..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Simpan Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Folder -->
<div class="modal fade" id="editFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="edit_folder">
                <input type="hidden" name="edit_folder_id" id="editFolderId" value="">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold">Ubah Informasi Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Nama Folder</label>
                        <input type="text" name="edit_folder_name" id="editFolderName" class="form-control rounded-2xl" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Deskripsi Folder</label>
                        <textarea name="edit_folder_desc" id="editFolderDesc" class="form-control rounded-2xl" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Perbarui Folder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tambah Materi ke Folder -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>" enctype="multipart/form-data">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="add_item">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold">Tambah Materi ke Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Judul Materi</label>
                        <input type="text" name="item_title" class="form-control rounded-2xl" placeholder="Contoh: Diktat Pemrograman Web Lengkap (PDF)" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted d-block">Sumber Materi</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="item_source" id="sourceFile" value="file" checked onchange="toggleItemSource('file')">
                            <label class="btn btn-outline-primary rounded-start-pill py-2 font-semibold" for="sourceFile">
                                <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">upload_file</span>
                                Unggah File Komputer
                            </label>

                            <input type="radio" class="btn-check" name="item_source" id="sourceUrl" value="url" onchange="toggleItemSource('url')">
                            <label class="btn btn-outline-primary rounded-end-pill py-2 font-semibold" for="sourceUrl">
                                <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">link</span>
                                Tautan / URL / H5P
                            </label>
                        </div>
                    </div>

                    <div class="mb-3" id="itemFileGroup">
                        <label class="form-label fw-semibold small text-muted">Pilih Berkas</label>
                        <input type="file" name="item_file" id="itemFileInput" class="form-control rounded-2xl" required>
                        <span class="form-text text-muted small" style="font-size: 0.72rem;">Mendukung PDF, Word, PowerPoint, TXT, ZIP, dan Gambar.</span>
                    </div>

                    <div class="mb-3 d-none" id="itemUrlGroup">
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-muted">Tipe Konten Tautan</label>
                            <select name="item_type" class="form-select rounded-2xl">
                                <option value="pdf">Dokumen PDF (.pdf)</option>
                                <option value="h5p">Modul Interaktif H5P Embed</option>
                                <option value="doc">Dokumen / Presentasi Luar</option>
                                <option value="link">Tautan Web / Referensi Umum</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label fw-semibold small text-muted">URL Tautan / Embed</label>
                            <input type="url" name="item_url" id="itemUrlInput" class="form-control rounded-2xl" placeholder="https://...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Tambahkan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Materi -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="library.php?courseid=<?php echo $courseid; ?>&folderid=<?php echo $folderid; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="edit_item_id" id="editItemId" value="">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold">Ubah Rincian Materi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Judul Materi</label>
                        <input type="text" name="edit_item_title" id="editItemTitle" class="form-control rounded-2xl" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Tipe Konten</label>
                        <select name="edit_item_type" id="editItemType" class="form-select rounded-2xl">
                            <option value="pdf">Dokumen PDF</option>
                            <option value="h5p">Modul Interaktif H5P</option>
                            <option value="doc">Dokumen / Berkas (.doc, .ppt)</option>
                            <option value="link">Tautan Web Luar</option>
                            <option value="page">Halaman Modul</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">URL Tautan / Ref (Kosongkan jika menggunakan berkas tersimpan)</label>
                        <input type="text" name="edit_item_url" id="editItemUrl" class="form-control rounded-2xl">
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
echo $OUTPUT->footer();
