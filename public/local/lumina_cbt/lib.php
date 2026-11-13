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
 * Library callbacks and navigation injection for local_lumina_cbt.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/cbt_engine.php');

/**
 * Automatically inject "CBT Proctor & Audit" into Course Navigation for teachers and managers.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_lumina_cbt_extend_navigation_course(\navigation_node $navigation, \stdClass $course, \context_course $context) {
    global $CFG;

    if ($course->id == SITEID) {
        return;
    }

    $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                  has_capability('moodle/grade:viewall', $context) ||
                  has_capability('moodle/course:manageactivities', $context) ||
                  is_siteadmin();

    if ($canmonitor) {
        $url = new moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $course->id]);
        $cbtnode = navigation_node::create(
            get_string('course_cbt_menu', 'local_lumina_cbt'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'lumina_cbt_monitor',
            new pix_icon('i/shield', '')
        );
        $cbtnode->showinflatnavigation = true;
        $navigation->add_node($cbtnode);
    }
}

/**
 * Extend Course administration settings navigation.
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_lumina_cbt_extend_settings_navigation(\settings_navigation $nav, \context $context) {
    if ($context->contextlevel == CONTEXT_COURSE && $context->instanceid != SITEID) {
        $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                      has_capability('moodle/course:manageactivities', $context) ||
                      is_siteadmin();

        if ($canmonitor) {
            $coursenode = $nav->find('courseadmin', navigation_node::TYPE_COURSE);
            if ($coursenode) {
                $coursenode->add(
                    get_string('course_cbt_menu', 'local_lumina_cbt'),
                    new moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $context->instanceid]),
                    navigation_node::TYPE_SETTING,
                    null,
                    'lumina_cbt_settings_monitor'
                );
            }
        }
    }
}

/**
 * Universal Auto-Enforcement on assessment activity pages (mod_quiz, mod_h5pactivity).
 * Automatically locks down student's browser and starts proctor monitoring.
 */
function local_lumina_cbt_before_footer() {
    global $PAGE, $USER, $COURSE, $DB;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Check if universal enforcement is enabled (default: 1)
    $universal = get_config('local_lumina_cbt', 'universal_mode');
    if ($universal === false || $universal === null) {
        $universal = 1;
    }
    if (!(int)$universal) {
        return;
    }

    $cm = $PAGE->cm;
    if (!$cm || empty($cm->id) || empty($cm->course)) {
        return;
    }

    $path = $PAGE->url ? $PAGE->url->get_path() : '';

    // Check if current page is an active exam attempt or view
    $isexam = (
        strpos($path, '/mod/quiz/attempt.php') !== false ||
        strpos($path, '/mod/quiz/processattempt.php') !== false ||
        strpos($path, '/mod/h5pactivity/view.php') !== false ||
        strpos($path, '/local/lumina_cbt/exam_view.php') !== false
    );

    if (!$isexam) {
        return;
    }

    $context = context_module::instance($cm->id, IGNORE_MISSING);
    if (!$context) {
        return;
    }

    // Exclude teachers/admins so they don't get locked out while reviewing
    $isteacher = has_capability('moodle/course:manageactivities', $context) ||
                 has_capability('moodle/grade:viewall', $context) ||
                 is_siteadmin();

    if ($isteacher) {
        return;
    }

    // Start/retrieve candidate proctoring session
    $session = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, (int)$cm->id, (int)$USER->id);

    // Inject Proctoring AMD Module
    $PAGE->requires->js_call_amd('local_lumina_cbt/proctor', 'init', [[
        'sessionId'        => (int)$session->id,
        'cmid'             => (int)$cm->id,
        'courseId'         => (int)$cm->course,
        'sesskey'          => sesskey(),
        'maxWarnings'      => (int)(get_config('local_lumina_cbt', 'max_warnings') ?: 3),
        'strictFullscreen' => (bool)(get_config('local_lumina_cbt', 'enable_fullscreen') ?? true),
        'blockKeyboard'    => (bool)(get_config('local_lumina_cbt', 'block_keyboard') ?? false),
        'suppressAi'       => (bool)(get_config('local_lumina_cbt', 'suppress_ai') ?? true),
    ]]);
}
