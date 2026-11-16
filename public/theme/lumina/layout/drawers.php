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

// Auto-routing for Proctored Anti-Cheat CBT Exams (Universal for all Quiz/H5P exam activities).
$pagetype = $PAGE->pagetype ?? '';
$urlpath = $PAGE->url ? $PAGE->url->get_path() : '';
$quizguidehtml = '';
$isexamactivity = (
    $pagetype === 'mod-h5pactivity-view' ||
    strpos($urlpath, 'mod/h5pactivity/view.php') !== false ||
    $pagetype === 'mod-quiz-view' ||
    $pagetype === 'mod-quiz-attempt' ||
    strpos($urlpath, 'mod/quiz/view.php') !== false ||
    strpos($urlpath, 'mod/quiz/attempt.php') !== false
);

if ($isexamactivity) {
    global $USER, $DB;
    $cmid = optional_param('id', 0, PARAM_INT);
    if (!$cmid && (strpos($urlpath, 'mod/quiz/attempt.php') !== false || $pagetype === 'mod-quiz-attempt')) {
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if ($attemptid) {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
            if ($attempt) {
                $cmrec = get_coursemodule_from_instance('quiz', $attempt->quiz);
                if ($cmrec) {
                    $cmid = (int)$cmrec->id;
                }
            }
        }
    }

    if ($cmid) {
        $proctorcm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if ($proctorcm && in_array($proctorcm->modname, ['h5pactivity', 'quiz'], true)) {
            $iscbtenabled = false;
            if (file_exists($CFG->dirroot . '/local/lumina_cbt/lib.php')) {
                require_once($CFG->dirroot . '/local/lumina_cbt/lib.php');
                if (function_exists('local_lumina_cbt_is_quiz_cbt_enabled')) {
                    $iscbtenabled = local_lumina_cbt_is_quiz_cbt_enabled((int)$proctorcm->id);
                }
            }
            if (!$iscbtenabled) {
                $iscbtenabled = (bool)get_config('local_lumina_cbt', 'cbt_enabled_' . (int)$proctorcm->id);
            }

            if ($iscbtenabled) {
                $roleinfo = theme_lumina_role_resolver::resolve_role($USER);
                $isstudent = (!empty($roleinfo['is_student']) || !has_capability('moodle/course:manageactivities', $PAGE->context));
                $isaction = optional_param('action', '', PARAM_ALPHA);
                if ($isstudent && empty($isaction) && !optional_param('noredirect', 0, PARAM_INT)) {
                    $examurl = (new moodle_url('/local/lumina_cbt/exam_view.php', ['cmid' => $proctorcm->id]))->out(false);
                    echo "<script>window.location.replace('" . $examurl . "');</script>";
                    echo "<div style='padding: 50px; text-align: center; font-family: sans-serif;'><h3>Mengalihkan ke Ruang Ujian CBT Layar Penuh...</h3><p><a href='" . $examurl . "'>Klik di sini jika tidak beralih otomatis</a></p></div>";
                    exit;
                } else if (!$isstudent) {
                    // Teacher / Admin viewing CBT Exam in Moodle - Inject modern Teacher Hub Banner & Iframe auto-resizer
                    $cbtpreviewurl = (new moodle_url('/local/lumina_cbt/exam_view.php', ['cmid' => $proctorcm->id]))->out(false);
                    $auditurl = (new moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $proctorcm->course, 'activityid' => $proctorcm->id]))->out(false);
                    $editsettingsurl = (new moodle_url('/course/modedit.php', ['update' => $proctorcm->id, 'return' => 1]))->out(false);

                    $quizguidehtml .= '
                    <div class="lumina-exam-teacher-hub card border-0 mb-4 shadow-sm" style="border-radius: 20px; background: linear-gradient(135deg, #F8FAFC 0%, #EEF2FF 100%); border: 1.5px solid #C7D2FE !important; overflow: hidden;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-3 d-flex align-items-center justify-content-center shadow-xs" style="width: 52px; height: 52px; min-width: 52px; background: #4338CA; color: white;">
                                        <span class="material-symbols-outlined" style="font-size: 28px;">quiz</span>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge rounded-pill bg-primary px-2.5 py-1" style="font-size: 0.72rem; font-weight: 700;">Ujian CBT Lumina</span>
                                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-0.5" style="font-size: 0.72rem; font-weight: 600;">Anti-Curang Live Proctoring</span>
                                        </div>
                                        <h5 class="fw-bold mb-1 text-dark">Pratinjau &amp; Manajemen Ujian (Mode Guru)</h5>
                                        <p class="text-muted small mb-0">Uji coba simulasi lembar soal layar penuh seperti yang dilihat mahasiswa atau pantau audit integritas.</p>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <a href="' . $cbtpreviewurl . '" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-xs" style="background: #4338CA; border: none;" target="_blank">
                                        <span class="material-symbols-outlined" style="font-size: 18px;">fullscreen</span>
                                        Simulasi Ruang Ujian CBT &rarr;
                                    </a>
                                    <a href="' . $auditurl . '" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2 bg-white">
                                        <span class="material-symbols-outlined" style="font-size: 18px;">security</span>
                                        Audit Pelanggaran
                                    </a>
                                    <a href="' . $editsettingsurl . '" class="btn btn-light border rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2">
                                        <span class="material-symbols-outlined" style="font-size: 18px;">settings</span>
                                        Edit Pengaturan
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var ifr = document.querySelector("iframe.h5p-player");
                        if (ifr) {
                            ifr.style.setProperty("min-height", "650px", "important");
                            ifr.style.setProperty("height", "650px", "important");
                            ifr.addEventListener("load", function() {
                                setTimeout(function() {
                                    if (ifr.clientHeight < 400) {
                                        ifr.style.setProperty("height", "700px", "important");
                                    }
                                }, 500);
                            });
                        }
                    });
                    </script>';
                }
            }
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
if ($isquizadd) {
    $quizguidehtml .= '
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

// Priority 1: Page course context if set and valid (> 1)
if (!empty($PAGE->course) && $PAGE->course->id > 1) {
    $activecourseid = (int)$PAGE->course->id;
}

// Priority 2: Check URL query parameter 'courseid'
if (!$activecourseid) {
    $cid = optional_param('courseid', 0, PARAM_INT);
    if ($cid > 1 && $DB->record_exists('course', ['id' => $cid])) {
        $activecourseid = $cid;
    }
}

// Priority 3: Check 'id' parameter (handling activity cmid vs course id)
if (!$activecourseid) {
    $paramid = optional_param('id', 0, PARAM_INT);
    if ($paramid > 1) {
        $isactivityurl = (strpos($currentpagepath, '/mod/') !== false);
        if ($isactivityurl) {
            $cmcourse = $DB->get_field('course_modules', 'course', ['id' => $paramid]);
            if ($cmcourse && $cmcourse > 1) {
                $activecourseid = (int)$cmcourse;
            }
        } else if ($DB->record_exists('course', ['id' => $paramid])) {
            $activecourseid = $paramid;
        }
    }
}

// Priority 4: Fallback to first enrolled course
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

// Validate active course access safely
if ($activecourseid) {
    try {
        $ccontext = context_course::instance($activecourseid);
        if (!$roleinfo['is_admin'] && !is_siteadmin($USER->id)) {
            if (!is_enrolled($ccontext, $USER, '', true) && !has_capability('moodle/grade:viewall', $ccontext, $USER)) {
                $activecourseid = !empty($enrolledcourses) ? (int)array_key_first($enrolledcourses) : null;
            }
        }
    } catch (\Throwable $e) {
        $activecourseid = !empty($enrolledcourses) ? (int)array_key_first($enrolledcourses) : null;
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
    // Course Switcher Tabs for Teachers & Admins
    $allteachercourses = $enrolledcourses;
    if (empty($allteachercourses) && ($roleinfo['is_admin'] || is_siteadmin($USER->id))) {
        $allteachercourses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC', 'id, fullname, shortname');
    }
    if (!empty($allteachercourses) && count($allteachercourses) > 1) {
        $coursepills = '';
        foreach ($allteachercourses as $tc) {
            $isactive = ((int)$tc->id === (int)$activecourseid);
            $pillclass = $isactive ? 'btn-primary text-white shadow-xs active' : 'btn-outline-secondary bg-white text-secondary';
            $graderurl = (new \core\url('/grade/report/grader/index.php', ['id' => $tc->id]))->out(false);
            $activestyle = $isactive ? 'style="background: #4338CA !important; border-color: #4338CA !important; color: #FFFFFF !important;"' : 'style="background: #FFFFFF !important; color: #475569 !important;"';
            $coursepills .= '<a href="' . $graderurl . '" class="btn btn-sm ' . $pillclass . ' rounded-pill px-3 py-1-5 font-semibold d-inline-flex align-items-center gap-1 text-decoration-none' . ($isactive ? ' active' : '') . '" ' . $activestyle . '>
                <span class="material-symbols-outlined" style="font-size: 16px;">school</span>
                ' . s($tc->fullname) . ' (' . s($tc->shortname) . ')
                ' . ($isactive ? '<span class="badge bg-white text-primary rounded-pill px-1-5 py-0 ms-1 fw-bold" style="font-size: 0.68rem; color: #4338CA !important;">Aktif</span>' : '') . '
            </a>';
        }
        $quizguidehtml .= '
        <div class="card border-0 mb-3 shadow-2xs rounded-3 p-3 bg-light border border-slate-200">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-dark fw-bold small d-flex align-items-center gap-1">
                        <span class="material-symbols-outlined text-primary" style="font-size: 18px;">library_books</span>
                        Pilih Mata Kuliah untuk Penilaian:
                    </span>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        ' . $coursepills . '
                    </div>
                </div>
            </div>
        </div>';
    }

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

// 2.2 Calendar completed task helper for students & users
$iscalendarpage = ($PAGE->pagetype === 'calendar-view' || strpos($currentpagepath, '/calendar') !== false);
if ($iscalendarpage && $USER && !empty($USER->id)) {
    // Collect all submitted/completed activity IDs and names for this student
    $completedcmids = [];
    $completednames = [];
    try {
        // Submitted assignments
        $subs = $DB->get_records_sql(
            "SELECT cm.id, a.name
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              WHERE s.userid = ? AND s.status = 'submitted'",
            [$USER->id]
        );
        foreach ($subs as $sub) {
            $completedcmids[] = (int)$sub->id;
            $completednames[] = strtolower(trim($sub->name));
        }

        // Completed CBT exams
        $cbts = $DB->get_records_sql(
            "SELECT cm.id, h.name
               FROM {local_lumina_exam_sessions} les
               JOIN {course_modules} cm ON cm.id = les.activityid
               JOIN {h5pactivity} h ON h.id = cm.instance
              WHERE les.userid = ? AND les.status IN ('completed', 'approved')",
            [$USER->id]
        );
        foreach ($cbts as $cbt) {
            $completedcmids[] = (int)$cbt->id;
            $completednames[] = strtolower(trim($cbt->name));
        }

        // Finished quizzes
        $qzs = $DB->get_records_sql(
            "SELECT cm.id, q.name
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.course = q.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              WHERE qa.userid = ? AND qa.state = 'finished'",
            [$USER->id]
        );
        foreach ($qzs as $qz) {
            $completedcmids[] = (int)$qz->id;
            $completednames[] = strtolower(trim($qz->name));
        }
    } catch (\Throwable $ce) { }

    // Calendar Modern Events Helper: Completed (Green) vs Pending (Orange)
    $quizguidehtml .= '
    <style>
    /* Reset outer wrapper .event to ensure NO overlapping borders or backgrounds */
    .calendarwrapper .event,
    .calendar_event_course,
    .calendar_event_site,
    .calendar_event_user,
    .calendar_event_group,
    .event[data-type="event"],
    .event[data-event-id],
    .maincalendar .event {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin-top: 1.25rem !important;
    }

    /* Base event card styling */
    .event > .card,
    .calendarwrapper .card,
    .maincalendar .card,
    .lumina-calendar-card {
        border-radius: 16px !important;
        overflow: hidden !important;
        transition: all 0.25s ease !important;
        background: #FFFFFF !important;
        border: 1.5px solid #E2E8F0 !important;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04) !important;
    }

    /* Card Header inside event */
    .event > .card > .card-header,
    .calendarwrapper .card > .card-header,
    .maincalendar .card > .card-header,
    .lumina-calendar-card > .card-header {
        padding: 1rem 1.25rem !important;
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06) !important;
        border-radius: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
    }

    .event > .card > .card-header .name,
    .calendarwrapper .card > .card-header .name,
    .maincalendar .card > .card-header .name,
    .lumina-calendar-card > .card-header .name {
        font-size: 1.1rem !important;
        font-weight: 700 !important;
        margin: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
    }

    /* Card Body */
    .event > .card > .card-body,
    .calendarwrapper .card > .card-body,
    .maincalendar .card > .card-body,
    .lumina-calendar-card > .card-body {
        padding: 1.25rem !important;
        background: #FFFFFF !important;
        color: #334155 !important;
        border: none !important;
    }

    /* Card Footer */
    .event > .card > .card-footer,
    .calendarwrapper .card > .card-footer,
    .maincalendar .card > .card-footer,
    .lumina-calendar-card > .card-footer {
        padding: 0.85rem 1.25rem !important;
        border-top: 1px solid rgba(0, 0, 0, 0.06) !important;
        border-bottom: none !important;
        border-left: none !important;
        border-right: none !important;
        border-radius: 0 !important;
        display: flex !important;
        justify-content: flex-end !important;
        align-items: center !important;
    }

    /* ========================================================== */
    /* STATE 1: PENDING / BELUM KELAR (ORANGE THEME)              */
    /* ========================================================== */
    .event.lumina-event-pending > .card,
    .card.lumina-card-pending {
        border: 1.5px solid #FED7AA !important;
        border-left: 6px solid #F97316 !important;
        box-shadow: 0 4px 18px rgba(249, 115, 22, 0.1) !important;
    }

    .event.lumina-event-pending > .card > .card-header,
    .card.lumina-card-pending > .card-header {
        background: #FFF7ED !important;
        border-bottom: 1px solid #FFEDD5 !important;
        color: #9A3412 !important;
    }

    .event.lumina-event-pending > .card > .card-header .name,
    .card.lumina-card-pending > .card-header .name {
        color: #7C2D12 !important;
    }

    .event.lumina-event-pending > .card > .card-footer,
    .card.lumina-card-pending > .card-footer {
        background: #FFFBF7 !important;
        border-top: 1px solid #FFEDD5 !important;
    }

    .lumina-btn-pending {
        background: #F97316 !important;
        color: #FFFFFF !important;
        border: none !important;
        border-radius: 9999px !important;
        padding: 0.4rem 1.15rem !important;
        font-size: 0.8125rem !important;
        font-weight: 700 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.4rem !important;
        box-shadow: 0 2px 8px rgba(249, 115, 22, 0.3) !important;
        text-decoration: none !important;
        transition: all 0.15s ease !important;
        position: relative !important;
        z-index: 10 !important;
        cursor: pointer !important;
        pointer-events: auto !important;
    }
    .lumina-btn-pending:hover {
        background: #EA580C !important;
        box-shadow: 0 4px 12px rgba(249, 115, 22, 0.45) !important;
        color: #FFFFFF !important;
        transform: translateY(-1px);
    }
    .lumina-btn-pending *,
    .lumina-btn-completed * {
        pointer-events: none !important;
    }

    /* ========================================================== */
    /* STATE 2: COMPLETED / KELAR (HIJAU / GREEN THEME)          */
    /* ========================================================== */
    .event.lumina-event-completed > .card,
    .card.lumina-card-completed {
        border: 1.5px solid #BBF7D0 !important;
        border-left: 6px solid #10B981 !important;
        box-shadow: 0 4px 18px rgba(16, 185, 129, 0.1) !important;
    }

    .event.lumina-event-completed > .card > .card-header,
    .card.lumina-card-completed > .card-header {
        background: #F0FDF4 !important;
        border-bottom: 1px solid #DCFCE7 !important;
        color: #166534 !important;
    }

    .event.lumina-event-completed > .card > .card-header .name,
    .card.lumina-card-completed > .card-header .name {
        color: #064E3B !important;
    }

    .event.lumina-event-completed > .card > .card-footer,
    .card.lumina-card-completed > .card-footer {
        background: #F8FCF9 !important;
        border-top: 1px solid #DCFCE7 !important;
    }

    .lumina-btn-completed {
        background: #10B981 !important;
        color: #FFFFFF !important;
        border: none !important;
        border-radius: 9999px !important;
        padding: 0.4rem 1.15rem !important;
        font-size: 0.8125rem !important;
        font-weight: 700 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.4rem !important;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3) !important;
        text-decoration: none !important;
        transition: all 0.15s ease !important;
    }
    .lumina-btn-completed:hover {
        background: #059669 !important;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.45) !important;
        color: #FFFFFF !important;
        transform: translateY(-1px);
    }
    </style>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var completedIds = ' . json_encode($completedcmids) . ';
        var completedNames = ' . json_encode($completednames) . ';

        function tagCalendarEvents() {
            var eventEls = document.querySelectorAll(".event, [data-region=\'event-item\'], .maincalendar .event");
            if (!eventEls.length) {
                eventEls = document.querySelectorAll("[data-type=\'event\'], [data-event-id]");
            }

            var processedCards = new Set();

            eventEls.forEach(function(eventEl) {
                // Ensure outer wrapper has NO background or borders that could overlay or clash
                eventEl.style.border = "none";
                eventEl.style.background = "transparent";
                eventEl.style.padding = "0";
                eventEl.style.boxShadow = "none";

                var card = eventEl.classList.contains("card") ? eventEl : eventEl.querySelector(".card");
                if (!card) return;
                if (processedCards.has(card)) return;
                processedCards.add(card);

                // Reset any previously applied inline styles so CSS classes take full clean effect
                card.style.borderLeft = "";
                card.style.background = "";
                card.style.borderRadius = "";

                var text = (eventEl.innerText || "").toLowerCase();
                var links = eventEl.querySelectorAll("a[href*=\'id=\']");
                var isCompleted = false;

                links.forEach(function(a) {
                    var match = a.href.match(/id=(\d+)/);
                    if (match && completedIds.indexOf(parseInt(match[1])) !== -1) {
                        isCompleted = true;
                    }
                });

                if (!isCompleted) {
                    for (var i = 0; i < completedNames.length; i++) {
                        if (completedNames[i] && text.indexOf(completedNames[i]) !== -1) {
                            isCompleted = true;
                            break;
                        }
                    }
                }

                var titleNode = card.querySelector("h3, h4, h5, .name, .eventname");
                var actionBtn = card.querySelector(".card-footer a, a.card-link, .commands a");

                var targetState = isCompleted ? "completed" : "pending";
                if (card.getAttribute("data-lumina-state") === targetState) {
                    return;
                }
                card.setAttribute("data-lumina-state", targetState);

                if (isCompleted) {
                    eventEl.classList.remove("lumina-event-pending");
                    eventEl.classList.add("lumina-event-completed");
                    card.classList.remove("lumina-card-pending");
                    card.classList.add("lumina-card-completed");

                    if (titleNode) {
                        var existingBadge = titleNode.querySelector(".lumina-status-badge");
                        if (!existingBadge) {
                            existingBadge = document.createElement("span");
                            titleNode.appendChild(existingBadge);
                        }
                        existingBadge.className = "lumina-status-badge badge rounded-pill ms-2 px-2-5 py-1 font-semibold";
                        existingBadge.style.cssText = "background: #10B981 !important; color: #FFFFFF !important; font-size: 0.72rem !important; vertical-align: middle !important; display: inline-flex !important; align-items: center !important; gap: 4px !important;";
                        existingBadge.innerHTML = "✓ Sudah Selesai &amp; Dikumpulkan";
                    }

                    if (actionBtn) {
                        actionBtn.className = "lumina-btn-completed text-decoration-none";
                        actionBtn.innerHTML = "<span class=\'material-symbols-outlined\' style=\'font-size: 16px; vertical-align: -2px;\'>task_alt</span> ✓ Sudah Selesai (Lihat Aktivitas)";
                        actionBtn.onclick = function(e) {
                            e.stopPropagation();
                            if (this.href && this.href !== "#" && !this.href.startsWith("javascript:")) {
                                window.location.href = this.href;
                            }
                        };
                    }
                } else {
                    // Pending / Belum Selesai -> ORANGE
                    eventEl.classList.remove("lumina-event-completed");
                    eventEl.classList.add("lumina-event-pending");
                    card.classList.remove("lumina-card-completed");
                    card.classList.add("lumina-card-pending");

                    if (titleNode) {
                        var existingBadge = titleNode.querySelector(".lumina-status-badge");
                        if (!existingBadge) {
                            existingBadge = document.createElement("span");
                            titleNode.appendChild(existingBadge);
                        }
                        existingBadge.className = "lumina-status-badge badge rounded-pill ms-2 px-2-5 py-1 font-semibold";
                        existingBadge.style.cssText = "background: #F97316 !important; color: #FFFFFF !important; font-size: 0.72rem !important; vertical-align: middle !important; display: inline-flex !important; align-items: center !important; gap: 4px !important;";
                        existingBadge.innerHTML = "⏳ Belum Dikerjakan";
                    }

                    if (actionBtn) {
                        actionBtn.className = "lumina-btn-pending text-decoration-none";
                        actionBtn.innerHTML = "<span class=\'material-symbols-outlined\' style=\'font-size: 16px; vertical-align: -2px;\'>arrow_forward</span> Kerjakan Sekarang";

                        actionBtn.onclick = function(e) {
                            e.stopPropagation();
                            if (this.href && this.href !== "#" && !this.href.startsWith("javascript:")) {
                                window.location.href = this.href;
                            }
                        };
                    }
                }
            });
        }

        tagCalendarEvents();
        setTimeout(tagCalendarEvents, 300);
        setTimeout(tagCalendarEvents, 800);
        setTimeout(tagCalendarEvents, 1800);

        // MutationObserver for AJAX pagination or filter switches
        var calWrapper = document.querySelector(\'[data-region="calendar"]\') || document.querySelector(\'.calendarwrapper\') || document.querySelector(\'.maincalendar\');
        if (calWrapper && window.MutationObserver) {
            var observer = new MutationObserver(function() {
                tagCalendarEvents();
            });
            observer.observe(calWrapper, { childList: true, subtree: true });
        }
    });
    </script>';
}

// 2.3 Admin User Management instant live search & page cleaner
$isadminuserpage = ($PAGE->pagetype === 'admin-user' || strpos($currentpagepath, '/admin/user.php') !== false);
if ($isadminuserpage && !empty($roleinfo['is_admin'])) {
    $quizguidehtml .= '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // 1. Polish Page Title & Hide Secondary Navigation / Settings Search
        var secNav = document.querySelector(".secondary-navigation");
        if (secNav) secNav.style.setProperty("display", "none", "important");

        var siteSearch = document.querySelector("#page-header form[action*=\'search.php\']") || document.querySelector("#page-header .searchform");
        if (siteSearch) siteSearch.style.setProperty("display", "none", "important");

        var pageTitleEl = document.querySelector("#page-header .page-header-headings h1") || document.querySelector("#page-header h1");
        if (pageTitleEl && pageTitleEl.textContent.trim().toLowerCase() === "belajar moodle") {
            pageTitleEl.innerHTML = "<span class=\"material-symbols-outlined align-middle me-2\" style=\"font-size: 32px; color: #4338CA;\">group</span>Manajemen Pengguna";
            if (!document.getElementById("lumina-user-mgr-subtitle")) {
                var subtitle = document.createElement("p");
                subtitle.id = "lumina-user-mgr-subtitle";
                subtitle.className = "text-muted small mb-0 mt-1";
                subtitle.textContent = "Kelola akun pengguna, pengajar/dosen, mahasiswa, dan hak akses dengan mudah.";
                pageTitleEl.parentNode.appendChild(subtitle);
            }
        }

        // 2. Inject Instant Real-Time User Search Bar
        function initUserLiveSearch() {
            var reportRegion = document.querySelector(\'[data-region="core_reportbuilder/report"]\') || document.querySelector(\'[data-region="report-user-list-wrapper"]\');
            if (!reportRegion) return;

            var toolbar = reportRegion.querySelector(".dropdown.d-flex");
            if (!toolbar || toolbar.getAttribute("data-lumina-search-ready") === "true") return;

            toolbar.setAttribute("data-lumina-search-ready", "true");

            // Wrap existing buttons (Add a new user, Filters) into an action buttons container
            var existingItems = Array.from(toolbar.children);
            var actionGroup = document.createElement("div");
            actionGroup.className = "lumina-action-buttons";

            existingItems.forEach(function(item) {
                actionGroup.appendChild(item);
            });

            // Create Search Bar Wrapper
            var searchWrapper = document.createElement("div");
            searchWrapper.className = "lumina-user-search-wrapper";
            searchWrapper.innerHTML = \'<span class="material-symbols-outlined lumina-search-icon">search</span>\' +
                \'<input type="text" id="lumina-user-live-search" class="form-control" placeholder="Cari nama pengguna, email, atau username..." autocomplete="off">\' +
                \'<button type="button" id="lumina-clear-user-search" class="lumina-clear-btn" title="Reset pencarian" style="display: none;">✕</button>\';

            toolbar.innerHTML = "";
            toolbar.appendChild(searchWrapper);
            toolbar.appendChild(actionGroup);

            var searchInput = document.getElementById("lumina-user-live-search");
            var clearBtn = document.getElementById("lumina-clear-user-search");

            function filterUserRows() {
                var query = (searchInput.value || "").toLowerCase().trim();
                if (clearBtn) {
                    clearBtn.style.display = query.length > 0 ? "flex" : "none";
                }

                var tableBody = reportRegion.querySelector("table.reportbuilder-table tbody");
                if (!tableBody) return;

                var rows = tableBody.querySelectorAll("tr:not(.lumina-no-results-row)");
                var visibleCount = 0;

                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var text = (row.textContent || row.innerText || "").toLowerCase();
                    if (!query || text.indexOf(query) !== -1) {
                        row.style.display = "";
                        visibleCount++;
                    } else {
                        row.style.display = "none";
                    }
                }

                var noResults = tableBody.querySelector(".lumina-no-results-row");
                if (visibleCount === 0 && rows.length > 0) {
                    if (!noResults) {
                        noResults = document.createElement("tr");
                        noResults.className = "lumina-no-results-row";
                        var table = reportRegion.querySelector("table.reportbuilder-table");
                        var colCount = (table ? table.querySelectorAll("thead th") : []).length || 5;
                        noResults.innerHTML = \'<td colspan="\' + colCount + \'" class="text-center py-5 text-muted" style="background: #FAFAFA;">\' +
                            \'<span class="material-symbols-outlined d-block mb-2 text-secondary" style="font-size: 40px; opacity: 0.5;">person_search</span>\' +
                            \'<div class="fw-bold fs-6 text-dark mb-1">Pengguna Tidak Ditemukan</div>\' +
                            \'<div class="small">Tidak ada data pengguna yang cocok dengan kata kunci "<strong>\' + query.replace(/</g, "&lt;") + \'</strong>"</div>\' +
                            \'</td>\';
                        tableBody.appendChild(noResults);
                    } else {
                        noResults.style.display = "";
                        var qStrong = noResults.querySelector("strong");
                        if (qStrong) qStrong.textContent = query;
                    }
                } else if (noResults) {
                    noResults.style.display = "none";
                }
            }

            searchInput.addEventListener("input", filterUserRows);
            searchInput.addEventListener("keyup", filterUserRows);

            clearBtn.addEventListener("click", function() {
                searchInput.value = "";
                filterUserRows();
                searchInput.focus();
            });

            // Prevent Enter from submitting page
            searchInput.addEventListener("keydown", function(e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    filterUserRows();
                }
            });
        }

        initUserLiveSearch();
        setTimeout(initUserLiveSearch, 200);
        setTimeout(initUserLiveSearch, 600);

        // Keep active when reportbuilder reloads via AJAX
        var reportRegion = document.querySelector(\'[data-region="core_reportbuilder/report"]\') || document.querySelector(\'[data-region="report-user-list-wrapper"]\');
        if (reportRegion && window.MutationObserver) {
            var observer = new MutationObserver(function() {
                var searchInput = document.getElementById("lumina-user-live-search");
                if (!searchInput) {
                    initUserLiveSearch();
                } else if (searchInput.value.trim().length > 0) {
                    var tableBody = reportRegion.querySelector("table.reportbuilder-table tbody");
                    if (tableBody) {
                        var rows = tableBody.querySelectorAll("tr:not(.lumina-no-results-row)");
                        var query = searchInput.value.toLowerCase().trim();
                        for (var i = 0; i < rows.length; i++) {
                            var row = rows[i];
                            var text = (row.textContent || row.innerText || "").toLowerCase();
                            if (!query || text.indexOf(query) !== -1) {
                                row.style.display = "";
                            } else {
                                row.style.display = "none";
                            }
                        }
                    }
                }
            });
            observer.observe(reportRegion, { childList: true, subtree: true });
        }
    });
    </script>';
}

// 2.4 Live Real-Time Notification Poller & Focus Sync
if (isloggedin() && !isguestuser() && !empty($USER->id)) {
    $quizguidehtml .= '
    <script>
    (function() {
        var currentUserId = ' . (int)$USER->id . ';
        if (!currentUserId || currentUserId <= 1) return;

        function updateNotificationBadge(count) {
            var bellBtn = document.querySelector(\'a.action-icon-btn[aria-label="Notifikasi"]\');
            if (!bellBtn) return;
            var pill = bellBtn.querySelector(".unread-count-pill");
            if (count > 0) {
                if (pill) {
                    pill.textContent = count;
                } else {
                    pill = document.createElement("span");
                    pill.className = "unread-count-pill";
                    pill.textContent = count;
                    bellBtn.appendChild(pill);
                }
            } else if (pill) {
                pill.remove();
            }
        }

        function checkLiveNotifications() {
            if (typeof require !== "function") return;
            require(["message_popup/notification_repository"], function(Repo) {
                if (!Repo || typeof Repo.countUnread !== "function") return;
                Repo.countUnread({useridto: currentUserId}).then(function(count) {
                    updateNotificationBadge(parseInt(count, 10));
                }).catch(function() {});
            });
        }

        // Live check on window focus (user returns to LMS tab)
        window.addEventListener("focus", checkLiveNotifications);

        // Periodic background poll every 15 seconds
        setInterval(checkLiveNotifications, 15000);
    })();
    </script>';
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
    if (!empty($roleinfo['is_teacher']) || !empty($roleinfo['is_admin'])) {
        $virtualclassurl   = (new \core\url('/local/lumina_core/virtual_class.php'))->out(false);
        $virtualclasstitle = 'Buka Kelas Virtual';
        $virtualclasssub   = 'Kelola Zoom & Google Meet';
        $virtualclassicon  = 'videocam';
    } else if (!empty($roleinfo['is_student'])) {
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
        'current_lang' => current_language(),
        'is_lang_id' => (current_language() === 'id'),
        'is_lang_en' => (current_language() === 'en'),
        'current_lang_label' => (current_language() === 'id' ? 'ID' : 'EN'),
        'current_lang_flag' => (current_language() === 'id' ? '🇮🇩' : '🇬🇧'),
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
    'lumina_completed_activities' => $luminadata['completed_activities'] ?? [],
    'has_completed_activities'    => $luminadata['has_completed_activities'] ?? false,
    'lumina_nav' => [
        'is_dashboard' => (strpos($currentpagepath, '/my') !== false && strpos($currentpagepath, '/my/courses') === false),
        'is_academic_overview' => (strpos($currentpagepath, '/local/lumina_academic/overview.php') !== false),
        'is_academic_classes' => (strpos($currentpagepath, '/local/lumina_academic/classes.php') !== false),
        'is_academic_assignments' => (strpos($currentpagepath, '/local/lumina_academic/assignments.php') !== false),
        'is_academic_promotion' => (strpos($currentpagepath, '/local/lumina_academic/promotion.php') !== false),
        'is_academic_alumni' => (strpos($currentpagepath, '/local/lumina_academic/alumni.php') !== false || strpos($currentpagepath, '/local/lumina_academic/transcript.php') !== false),
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
        'is_cbt_monitor' => (strpos($currentpagepath, '/local/lumina_cbt/monitor.php') !== false || strpos($currentpagepath, '/local/lumina_core/exam_review.php') !== false),
        'is_exam_review' => (strpos($currentpagepath, '/local/lumina_cbt/monitor.php') !== false || strpos($currentpagepath, '/local/lumina_core/exam_review.php') !== false),
        'cbt_monitor_url' => (new \moodle_url('/local/lumina_cbt/monitor.php'))->out(false),
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
