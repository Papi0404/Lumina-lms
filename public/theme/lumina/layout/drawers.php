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
