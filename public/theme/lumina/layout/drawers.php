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
 * Lumina LMS primary drawer layout.
 *
 * @package    theme_lumina
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/behat/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/theme/lumina/classes/lumina_data.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

$addblockbutton = $OUTPUT->addblockbutton();

if (isloggedin() && !isguestuser()) {
    $courseindexopen = false; // Never auto-open course index; only open when explicitly clicked by user.
    $blockdraweropen = (get_user_preferences('drawer-open-block', false) == true);
    $railcollapsed = (get_user_preferences('lumina-rail-collapsed', false) == true);
} else {
    $courseindexopen = false;
    $blockdraweropen = false;
    $railcollapsed = false;
}

if (defined('BEHAT_SITE_RUNNING') && get_user_preferences('behat_keep_drawer_closed') != 1) {
    $blockdraweropen = true;
}

// Auto-routing for Proctored Anti-Cheat CBT Exams.
$h5pcmid = optional_param('id', 0, PARAM_INT);
$pagetype = $PAGE->pagetype ?? '';
$urlpath = $PAGE->url ? $PAGE->url->get_path() : '';

if (($pagetype === 'mod-h5pactivity-view' || strpos($urlpath, 'mod/h5pactivity/view.php') !== false) && $h5pcmid) {
    $proctorcm = get_coursemodule_from_id('h5pactivity', $h5pcmid, 0, false, IGNORE_MISSING);
    if ($proctorcm && ($proctorcm->id == 26 || strpos($proctorcm->name, 'Anti-Curang') !== false || strpos($proctorcm->name, 'Live Proctoring') !== false)) {
        global $USER;
        $roleinfo = theme_lumina_role_resolver::resolve_role($USER);
        $isstudent = (!empty($roleinfo['is_student']) || !has_capability('moodle/course:manageactivities', $PAGE->context));
        $isaction = optional_param('action', '', PARAM_ALPHA);
        if ($isstudent && empty($isaction) && !optional_param('noredirect', 0, PARAM_INT)) {
            $examurl = (new moodle_url('/local/lumina_core/exam_view.php', ['cmid' => $proctorcm->id]))->out(false);
            echo "<script>window.location.replace('" . $examurl . "');</script>";
            echo "<div style='padding: 50px; text-align: center; font-family: sans-serif;'><h3>Mengalihkan ke Ruang Ujian CBT Layar Penuh...</h3><p><a href='" . $examurl . "'>Klik di sini jika tidak beralih otomatis</a></p></div>";
            exit;
        }
    }
}

$extraclasses = ['uses-drawers', 'lumina-layout', 'lumina-role-' . ($roleinfo['role_key'] ?? 'student')];
if (!empty($PAGE->pagelayout)) {
    $extraclasses[] = 'layout-' . $PAGE->pagelayout;
}
if ($courseindexopen) {
    $extraclasses[] = 'drawer-open-index';
}
if ($railcollapsed) {
    $extraclasses[] = 'lumina-rail-collapsed';
}

$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));
if (!$hasblocks) {
    $blockdraweropen = false;
}

$courseindex = core_course_drawer();
if (!$courseindex) {
    $courseindexopen = false;
}

$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$forceblockdraweropen = $OUTPUT->firstview_fakeblocks();

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $tablistnav = $PAGE->has_tablist_secondary_navigation();
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs', true, $tablistnav);
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $selectmenu = new \core\output\select_menu(
            'tertiarynavigation',
            $overflowdata->urls,
            $overflowdata->selected,
        );
        $selectmenu->set_label($overflowdata->label, $overflowdata->labelattributes);
        $overflow = $selectmenu->export_for_template($OUTPUT);
    }
}

$primary = new core\navigation\output\primary($PAGE);
$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);
$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions() && !$PAGE->has_secondary_navigation();
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header ? $header->export_for_template($renderer) : null;

// Guidance banner for Teachers on New Quiz page (explaining where Options A, B, C, D are)
$isquizadd = ($PAGE->pagetype === 'course-modedit' && optional_param('add', '', PARAM_ALPHA) === 'quiz');
$quizguidehtml = '';
if ($isquizadd) {
    $quizguidehtml = '
    <div class="alert alert-primary border-2 border-indigo-200 rounded-3xl p-4 mb-4 shadow-sm" style="background: linear-gradient(135deg, #EEF2FF 0%, #F5F3FF 100%); border-color: #C7D2FE !important;">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary text-white p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0; background: #4338CA !important;">
                <span class="material-symbols-outlined fs-4">tips_and_updates</span>
            </div>
            <div>
                <h6 class="fw-bold mb-1 text-dark">Panduan Menambahkan Soal Pilihan Ganda (A, B, C, D)</h6>
                <p class="mb-0 small text-muted">
                    Halaman ini untuk mengatur <strong>Nama Kuis</strong> dan batas waktu pengerjaan. Setelah mengisi nama kuis di bawah, gulir ke paling bawah lalu klik tombol biru <strong>"Save and display"</strong> untuk langsung menginput pertanyaan beserta opsi pilihan ganda A, B, C, D!
                </p>
            </div>
        </div>
    </div>';
}

$coursefullname = ($PAGE->course?->fullname) ? format_string(
    $PAGE->course->fullname,
    true,
    ['context' => context_course::instance($PAGE->course->id), 'escape' => false]
) : '';
$courseurl = $PAGE->course ? new \core\url('/course/view.php', ['id' => $PAGE->course->id]) : null;

// User context and dynamic academic statistics for Lumina.
global $USER, $DB;

// Fetch all Lumina data via the dedicated helper class.
$luminadata = theme_lumina_data::get_all($USER, $PAGE, $OUTPUT, $CFG, $DB);

// Resolve Multi-Role context (Admin, Guru, Murid) via Lumina Role Resolver.
$roleinfo = theme_lumina_role_resolver::resolve_role($USER);
$admindata = $roleinfo['is_admin'] ? theme_lumina_role_resolver::get_admin_dashboard_data() : [];
$teacherdata = $roleinfo['is_teacher'] ? theme_lumina_role_resolver::get_teacher_dashboard_data($USER) : [];

// Derived convenience variables (keep backward-compat with existing templatecontext keys).
$enrolledcourses          = isloggedin() && !isguestuser() ? enrol_get_all_users_courses($USER->id, true) : [];
$studentmaterials         = $roleinfo['is_student'] ? theme_lumina_role_resolver::get_student_learning_library($USER, $enrolledcourses) : [];
$enrolledcoursescount     = $luminadata['stats']['enrolled_courses_count'] ?? 0;
$unreadmessagescount      = $luminadata['stats']['unread_messages'] ?? 0;
$unreadnotificationscount = $luminadata['notifications_count'] ?? 0;
$userrolelabel            = $luminadata['user']['role_label'] ?? 'Mahasiswa Aktif';
$pendingtasks             = $luminadata['stats']['pending_tasks_count'] ?? 0;
$completedtasks           = $luminadata['stats']['completed_tasks_count'] ?? 0;
$totaltasks               = $luminadata['stats']['total_tasks_count'] ?? 0;
$avggradestat             = $luminadata['stats']['avg_grade_stat'] ?? '-';
$avgscore                 = $luminadata['grades']['avg_score'] ?? null;
$gradesummary             = $luminadata['grades']['per_course'] ?? [];
$semesterprogress         = $luminadata['stats']['progress_percent'] ?? 0;
$currentweek              = (int)ltrim($luminadata['stats']['active_week_label'] ?? 'Pekan ke-1', 'Pekan ke-');
$storagepercent           = $luminadata['storage']['percent'] ?? 0;
$usedformatted            = $luminadata['storage']['used_formatted'] ?? '0 KB';
$totalformatted           = $luminadata['storage']['total_formatted'] ?? '50 MB';

$useravatarhtml = $luminadata['user']['avatar_html'] ?? $OUTPUT->user_picture($USER, ['size' => 36, 'link' => false, 'class' => 'userpicture']);
$currentpagepath = $PAGE->url ? $PAGE->url->get_path() : '';

// Determine active course ID strictly based on role and enrollment isolation.
$activecourseid = null;
if (!empty($PAGE->course) && $PAGE->course->id > 1) {
    if ($roleinfo['is_admin'] || is_enrolled(context_course::instance($PAGE->course->id), $USER, '', true)) {
        $activecourseid = (int)$PAGE->course->id;
    }
}
if (!$activecourseid) {
    if (!empty($enrolledcourses)) {
        $activecourseid = (int)array_key_first($enrolledcourses);
    } else if (is_siteadmin($USER->id) || $roleinfo['is_admin']) {
        $firstrealcourse = $DB->get_field_sql("SELECT id FROM {course} WHERE id > 1 ORDER BY id ASC LIMIT 1");
        if ($firstrealcourse) {
            $activecourseid = (int)$firstrealcourse;
        }
    }
}

// 1. Smart Forum URL (Points to Secure Lumina Forum Discussion Hub)
$forumurl = (new \core\url('/local/lumina_core/forum.php', $activecourseid ? ['courseid' => $activecourseid] : []))->out(false);

// 2. Smart Grade URL — safe for all roles
if (($roleinfo['is_admin'] || $roleinfo['is_teacher']) && $activecourseid) {
    $gradesurl = (new \core\url('/grade/report/grader/index.php', ['id' => $activecourseid]))->out(false);
} else {
    $gradesurl = (new \core\url('/grade/report/overview/index.php'))->out(false);
}

// 2.1 Grader Report helper banner & pending submissions hub for Teachers/Admins
$isgraderreport = ($PAGE->pagetype === 'gradereport_grader-index' || strpos($currentpagepath, '/grade/report/grader') !== false);
if ($isgraderreport && ($roleinfo['is_teacher'] || $roleinfo['is_admin']) && $activecourseid) {
    // Query pending submissions for this course
    $pendingsql = "SELECT s.id AS subid, s.assignment, s.userid, s.timemodified,
                          a.name AS assignmentname, u.firstname, u.lastname, u.email,
                          cm.id AS cmid, gi.id AS gradeitemid
                     FROM {assign_submission} s
                     JOIN {assign} a ON a.id = s.assignment
                     JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     JOIN {grade_items} gi ON gi.courseid = a.course AND gi.itemtype = 'mod' AND gi.itemmodule = 'assign' AND gi.iteminstance = a.id
                     JOIN {user} u ON u.id = s.userid
                LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                    WHERE a.course = ?
                      AND s.status = 'submitted'
                      AND s.latest = 1
                      AND (g.grade IS NULL OR g.grade < 0)
                 ORDER BY s.timemodified DESC";
    $pendingsubs = $DB->get_records_sql($pendingsql, [$activecourseid]);
    $fs = get_file_storage();

    if (!empty($pendingsubs)) {
        $subcards = '';
        $scriptmappings = [];
        $firstcmid = null;
        foreach ($pendingsubs as $ps) {
            if (!$firstcmid) {
                $firstcmid = $ps->cmid;
            }
            $context = context_module::instance($ps->cmid);
            $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $ps->subid, 'id', false);
            $fileitems = [];
            foreach ($files as $f) {
                $filesize_kb = round($f->get_filesize() / 1024, 1);
                $fileitems[] = '<span class="badge bg-light text-primary border border-primary-subtle py-1 px-2 d-inline-flex align-items-center gap-1 font-monospace"><i class="fa fa-file-pdf-o"></i> ' . s($f->get_filename()) . ' (' . $filesize_kb . ' KB)</span>';
            }
            $filehtml = !empty($fileitems) ? implode(' ', $fileitems) : '<span class="text-muted small">Tidak ada lampiran berkas fisik</span>';

            $gradeurl = (new \core\url('/mod/assign/view.php', ['id' => $ps->cmid, 'action' => 'grader', 'userid' => $ps->userid]))->out(false);
            $gradingurl = (new \core\url('/mod/assign/view.php', ['id' => $ps->cmid, 'action' => 'grading']))->out(false);

            $fullname = s($ps->firstname . ' ' . $ps->lastname);
            $initials = strtoupper(substr($ps->firstname, 0, 1) . substr($ps->lastname, 0, 1));

            $subcards .= '
            <div class="p-3 mb-2 bg-white rounded-3 border border-slate-200 d-flex align-items-center justify-content-between flex-wrap gap-3 shadow-2xs">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 44px; height: 44px; min-width: 44px; border: 1px solid rgba(67, 56, 202, 0.2); font-size: 0.9rem;">
                        ' . $initials . '
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark fs-6">' . $fullname . '</span>
                            <span class="badge bg-warning text-dark px-2 py-1" style="font-size: 0.7rem; font-weight: 600;">Menunggu Penilaian</span>
                        </div>
                        <div class="text-muted small mt-1">
                            <strong>Tugas:</strong> ' . s($ps->assignmentname) . '
                        </div>
                        <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                            ' . $filehtml . '
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="' . $gradeurl . '" class="btn btn-primary btn-sm px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm" style="background: #4338CA; border-color: #4338CA; border-radius: 8px;">
                        <i class="fa fa-pencil-square-o"></i> Beri Nilai &amp; Buka Berkas ' . $fullname . ' &rarr;
                    </a>
                    <a href="' . $gradingurl . '" class="btn btn-outline-secondary btn-sm px-2 py-2 fw-semibold" style="border-radius: 8px;" title="Lihat daftar pengumpulan kelas">
                        <i class="fa fa-list"></i>
                    </a>
                </div>
            </div>';

            $scriptmappings[] = [
                'userid' => (int)$ps->userid,
                'gradeitemid' => (int)$ps->gradeitemid,
                'studentname' => $fullname,
                'gradeurl' => $gradeurl,
            ];
        }

        $allgradingurl = $firstcmid ? (new \core\url('/mod/assign/view.php', ['id' => $firstcmid, 'action' => 'grading']))->out(false) : '#';

        $quizguidehtml .= '
        <div class="lumina-gradebook-guide card border-0 mb-4 shadow-sm" style="border-radius: 16px; background: linear-gradient(135deg, #F8FAFC 0%, #EEF2FF 100%); border: 1px solid #C7D2FE !important;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; min-width: 48px; background: #4338CA; color: #FFFFFF;">
                        <span class="material-symbols-outlined" style="font-size: 26px;">assignment_turned_in</span>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                            <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                                Berkas Tugas Siswa yang Siap Dinilai
                                <span class="badge bg-danger rounded-pill px-2 py-1 fs-6" style="font-size: 0.75rem !important;">' . count($pendingsubs) . ' Berkas Masuk</span>
                            </h5>
                            <a href="' . $allgradingurl . '" class="btn btn-sm btn-outline-primary fw-semibold" style="border-radius: 8px;">
                                <i class="fa fa-folder-open me-1"></i> Buka Semua Pengumpulan
                            </a>
                        </div>
                        <p class="text-muted small mb-3">
                            <strong>Panduan Guru:</strong> Tabel di bawah adalah <strong>Buku Nilai (Matriks Rekapitulasi Angka)</strong>. Berkas asli (PDF/Word) yang diunggah siswa dibaca dan dinilai langsung melalui tombol penilai berikut:
                        </p>
                        <div class="pending-list">
                            ' . $subcards . '
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            var mappings = ' . json_encode($scriptmappings) . ';
            if (!mappings || !mappings.length) return;
            setTimeout(function() {
                var table = document.querySelector("#user-grades");
                if (!table) return;
                mappings.forEach(function(m) {
                    var cell = table.querySelector("td.cell[data-itemid=\"" + m.gradeitemid + "\"]");
                    if (cell && !cell.querySelector(".lumina-grade-action-btn")) {
                        var btn = document.createElement("a");
                        btn.className = "lumina-grade-action-btn btn btn-sm btn-warning text-dark fw-bold d-inline-flex align-items-center gap-1 my-1";
                        btn.style.cssText = "border-radius: 6px; font-size: 0.725rem; padding: 2px 7px; text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,0.1);";
                        btn.href = m.gradeurl;
                        btn.title = "Buka Berkas & Beri Nilai " + m.studentname;
                        btn.innerHTML = "<i class=\"fa fa-file-pdf-o\"></i> Periksa Berkas (Nilai)";
                        cell.appendChild(btn);
                    }
                });
            }, 300);
        });
        </script>';
    } else {
        $quizguidehtml .= '
        <div class="alert alert-light border border-slate-200 rounded-3 p-3 mb-4 shadow-2xs d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 text-muted small">
                <span class="material-symbols-outlined text-primary fs-5">info</span>
                <span><strong>Informasi Buku Nilai:</strong> Matriks di bawah menyajikan rekapitulasi nilai angka kelas. Untuk melihat dan mengunduh berkas tugas mahasiswa, klik tautan nama tugas pada judul kolom tabel.</span>
            </div>
        </div>';
    }
}

// 3. Smart Resource Library URL (Role & Course-isolated)
if ($roleinfo['is_admin']) {
    $resourcesurl = (new \core\url('/local/lumina_core/library.php'))->out(false);
    $resourceslabel = 'Perpustakaan Sumber Belajar';
    $resourcesicon  = 'folder_special';
} else if ($roleinfo['is_student']) {
    $firstcourseid = !empty($enrolledcourses) ? (int)array_key_first($enrolledcourses) : 0;
    $targetcid = $activecourseid ?: $firstcourseid;
    $resourcesurl = (new \core\url('/local/lumina_core/library.php', $targetcid ? ['courseid' => $targetcid] : []))->out(false);
    $resourceslabel = 'Perpustakaan Sumber Belajar';
    $resourcesicon  = 'folder_special';
} else if ($activecourseid) {
    $resourcesurl = (new \core\url('/local/lumina_core/library.php', ['courseid' => $activecourseid]))->out(false);
    $resourceslabel = 'Perpustakaan Sumber Belajar';
    $resourcesicon  = 'folder_special';
} else {
    $resourcesurl = (new \core\url('/local/lumina_core/library.php'))->out(false);
    $resourceslabel = 'Perpustakaan Sumber Belajar';
    $resourcesicon  = 'folder_special';
}

// 4. Teacher-specific Action URLs (Dedicated Material & Module Builder)
if ($activecourseid) {
    $teachermanagematerialurl = (new \core\url('/local/lumina_core/create_material.php', ['courseid' => $activecourseid]))->out(false);
    $teacherclasslisturl       = (new \core\url('/local/lumina_core/attendance.php', ['id' => $activecourseid]))->out(false);
} else {
    $teachermanagematerialurl = (new \core\url('/local/lumina_core/create_material.php'))->out(false);
    $teacherclasslisturl       = (new \core\url('/my/courses.php'))->out(false);
}

// 5. Admin-only Global Course Creation URL
$firstcategoryid = $DB->get_field_sql("SELECT id FROM {course_categories} ORDER BY sortorder ASC, id ASC LIMIT 1");
$admincreatecourseurl = $firstcategoryid
    ? (new \core\url('/course/edit.php', ['category' => $firstcategoryid]))->out(false)
    : (new \core\url('/course/management.php'))->out(false);

// Check Virtual Class (Zoom / Google Meet) via Lumina Core Manager
$virtualclassurl = (new \core\url('/local/lumina_core/virtual_class.php'))->out(false);
$virtualclasstitle = 'Kelas Virtual';
$virtualclasssub   = 'Zoom & Google Meet';
$virtualclassicon  = 'videocam';
$virtualclassisactive = false;

if (class_exists('\local_lumina_core\virtual_class_manager')) {
    if ($is_teacher || $is_admin) {
        $virtualclassurl   = (new \core\url('/local/lumina_core/virtual_class.php'))->out(false);
        $virtualclasstitle = 'Buka Kelas Virtual';
        $virtualclasssub   = 'Kelola Zoom & Google Meet';
        $virtualclassicon  = 'videocam';
    } else if ($is_student) {
        $activemeets = \local_lumina_core\virtual_class_manager::get_student_active_meetings($USER->id);
        if (!empty($activemeets)) {
            $virtualclassisactive = true;
            if (count($activemeets) === 1) {
                $virtualclassurl   = $activemeets[0]['url'];
                $virtualclasstitle = $activemeets[0]['platform'] . ': ' . $activemeets[0]['course_shortname'];
                $virtualclasssub   = 'Sesi Tatap Muka Aktif (Klik Masuk)';
                $virtualclassicon  = 'video_camera_front';
            } else {
                $virtualclassurl   = (new \core\url('/local/lumina_core/virtual_class.php'))->out(false);
                $virtualclasstitle = count($activemeets) . ' Kelas Daring Aktif';
                $virtualclasssub   = 'Pilih ruang tatap muka aktif';
                $virtualclassicon  = 'video_camera_front';
            }
        }
    }
}

$is_server_settings = (strpos($currentpagepath, '/admin/category.php') !== false && optional_param('category', '', PARAM_SAFEDIR) === 'server');
$is_admin_search = (strpos($currentpagepath, '/admin/search.php') !== false || (strpos($currentpagepath, '/admin') !== false && strpos($currentpagepath, '/admin/user.php') === false && !$is_server_settings));

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
    'coursefullname' => $coursefullname,
    'courseurl' => $courseurl ? $courseurl->out(false) : null,
    'output' => $OUTPUT,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
    'bodyattributes' => $bodyattributes,
    'courseindexopen' => $courseindexopen,
    'blockdraweropen' => $blockdraweropen,
    'courseindex' => $courseindex,
    'primarymoremenu' => $primarymenu['moremenu'] ?? null,
    'secondarymoremenu' => $secondarynavigation ?: false,
    'mobileprimarynav' => $primarymenu['mobileprimarynav'] ?? null,
    'usermenu' => $primarymenu['user'] ?? null,
    'langmenu' => $primarymenu['lang'] ?? null,
    'forceblockdraweropen' => $forceblockdraweropen,
    'regionmainsettingsmenu' => $regionmainsettingsmenu,
    'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
    'overflow' => $overflow,
    'headercontent' => $headercontent,
    'lumina_quiz_guide' => $quizguidehtml,
    'addblockbutton' => $addblockbutton,
    // Lumina LMS custom contexts:
    'lumina_role' => $roleinfo,
    'lumina_admin' => $admindata,
    'lumina_teacher' => $teacherdata,
    'lumina_user' => [
        'fullname' => fullname($USER),
        'email' => $USER->email ?? '',
        'avatar_html' => $useravatarhtml,
        'role_label' => $roleinfo['role_label'],
        'role_badge_class' => $roleinfo['role_badge_class'],
        'role_key' => $roleinfo['role_key'],
        'is_logged_in' => isloggedin() && !isguestuser(),
        'is_siteadmin' => is_siteadmin(),
        'is_admin_role' => $roleinfo['is_admin'],
        'is_teacher_role' => $roleinfo['is_teacher'],
        'is_student_role' => $roleinfo['is_student'],
        'can_switch_preview' => $roleinfo['can_switch_preview'],
        'active_preview_role' => $roleinfo['active_preview_role'],
        'is_preview_active' => $roleinfo['is_preview_active'],
        'enrolled_count' => $enrolledcoursescount,
        'unread_messages' => $unreadmessagescount,
        'unread_notifications' => $unreadnotificationscount,
    ],
    'lumina_stats' => [
        'active_week_label' => 'Pekan ke-' . $currentweek,
        'progress_percent' => $semesterprogress,
        'avg_grade_stat' => $avggradestat,
        'gpa_stat' => $avggradestat, // Kept as alias for backward compat
        'completed_tasks_count' => $completedtasks,
        'total_tasks_count' => $totaltasks,
        'avg_score' => $avgscore !== null ? $avgscore . '%' : '-',
        'pending_tasks_count' => $pendingtasks,
        'enrolled_courses_count' => $enrolledcoursescount,
    ],
    'lumina_storage' => [
        'percent' => $storagepercent,
        'used_formatted' => $usedformatted,
        'total_formatted' => $totalformatted,
    ],
    'lumina_nav' => [
        'is_dashboard' => (strpos($currentpagepath, '/my') !== false && strpos($currentpagepath, '/my/courses') === false),
        'is_mycourses' => (strpos($currentpagepath, '/my/courses') !== false || strpos($currentpagepath, '/course/view.php') !== false),
        'is_manage_material' => (strpos($currentpagepath, '/local/lumina_core/create_material.php') !== false),
        'is_calendar' => (strpos($currentpagepath, '/calendar') !== false),
        'is_grades' => (strpos($currentpagepath, '/grade') !== false || strpos($currentpagepath, '/course/user.php') !== false),
        'is_resources' => (strpos($currentpagepath, '/local/lumina_core/library.php') !== false || strpos($currentpagepath, '/contentbank') !== false || strpos($currentpagepath, '/user/files.php') !== false),
        'is_admin' => $is_admin_search,
        'is_server_settings' => $is_server_settings,
        'is_manage_users' => (strpos($currentpagepath, '/admin/user.php') !== false),
        'is_manage_courses' => (strpos($currentpagepath, '/course/management.php') !== false),
        'is_security_report' => (strpos($currentpagepath, '/report/security') !== false),
        'is_exam_review' => (strpos($currentpagepath, '/local/lumina_core/exam_review.php') !== false),
        'is_forum' => (strpos($currentpagepath, '/local/lumina_core/forum.php') !== false || strpos($currentpagepath, '/mod/forum') !== false),
        'is_classlist' => (strpos($currentpagepath, '/local/lumina_core/attendance.php') !== false || strpos($currentpagepath, '/user/index.php') !== false),
        'forum_url' => $forumurl,
        'grades_url' => $gradesurl,
        'resources_url' => $resourcesurl,
        'resources_label' => $resourceslabel,
        'resources_icon' => $resourcesicon,
        'teacher_manage_material_url' => $teachermanagematerialurl,
        'class_list_url' => $teacherclasslisturl,
        'admin_create_course_url' => $admincreatecourseurl,
        'admin_manage_courses_url' => (new \core\url('/course/management.php'))->out(false),
        'createcourse_url' => $admincreatecourseurl,
        'virtualclass_url' => $virtualclassurl,
        'virtualclass_title' => $virtualclasstitle,
        'virtualclass_subtitle' => $virtualclasssub,
        'virtualclass_icon' => $virtualclassicon,
        'virtualclass_is_active' => $virtualclassisactive,
    ],
    'lumina_semester'            => $luminadata['semester_label'] ?? 'Semester Ganjil 2026/2027',
    'lumina_upcoming_assignments'=> $luminadata['upcoming_assignments'] ?? [],
    'lumina_upcoming_events'     => $luminadata['upcoming_events'] ?? [],
    'lumina_enrolled_courses'    => [],
    'lumina_grade_summary'       => $gradesummary,
    'has_grade_summary'          => !empty($gradesummary),
    'lumina_grade_items'         => $luminadata['grade_items'] ?? [],
    'has_grade_items'            => !empty($luminadata['grade_items']),
    'lumina_security'            => $luminadata['security'] ?? [],
    'lumina_student_materials'   => $studentmaterials,
    'has_student_materials'      => !empty($studentmaterials),
    'has_local_lumina_ai'        => (\core_plugin_manager::instance()->get_plugin_info('local_lumina_ai') !== null && !empty(get_config('local_lumina_ai', 'enabled'))),
];

echo $OUTPUT->render_from_template('theme_lumina/drawers', $templatecontext);
