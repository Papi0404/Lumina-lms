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

// Defensive definitions for Moodle calendar constants across all Moodle versions & PHP 8+
if (!defined('ASSIGN_EVENT_TYPE_DUE')) {
    define('ASSIGN_EVENT_TYPE_DUE', 'due');
}
if (!defined('ASSIGN_EVENT_TYPE_GRADINGDUE')) {
    define('ASSIGN_EVENT_TYPE_GRADINGDUE', 'gradingdue');
}
if (!defined('ASSIGN_EVENT_TYPE_OPEN')) {
    define('ASSIGN_EVENT_TYPE_OPEN', 'open');
}
if (!defined('ASSIGN_EVENT_TYPE_CLOSE')) {
    define('ASSIGN_EVENT_TYPE_CLOSE', 'close');
}
if (!defined('ASSIGN_EVENT_TYPE_EXTENSION')) {
    define('ASSIGN_EVENT_TYPE_EXTENSION', 'extension');
}

require_once(__DIR__ . '/classes/cbt_engine.php');

/**
 * Core callback triggered immediately after configuration is loaded.
 * Ensures critical calendar constants are universally defined across PHP 8+ and all Moodle versions.
 */
function local_lumina_cbt_after_config() {
    if (!defined('ASSIGN_EVENT_TYPE_DUE')) {
        define('ASSIGN_EVENT_TYPE_DUE', 'due');
    }
    if (!defined('ASSIGN_EVENT_TYPE_GRADINGDUE')) {
        define('ASSIGN_EVENT_TYPE_GRADINGDUE', 'gradingdue');
    }
    if (!defined('ASSIGN_EVENT_TYPE_OPEN')) {
        define('ASSIGN_EVENT_TYPE_OPEN', 'open');
    }
    if (!defined('ASSIGN_EVENT_TYPE_CLOSE')) {
        define('ASSIGN_EVENT_TYPE_CLOSE', 'close');
    }
    if (!defined('ASSIGN_EVENT_TYPE_EXTENSION')) {
        define('ASSIGN_EVENT_TYPE_EXTENSION', 'extension');
    }
}

/**
 * Global navigation extension: runs on EVERY page load across all Moodle versions (3.9 - 5.x).
 * Guarantees "CBT & Audit" is registered in:
 * 1. Native Moodle 4.0 - 5.x Primary Navigation ($PAGE->primarynav)
 * 2. Classic & Flat Navigation ($navigation)
 * 3. Client-Side DOM Injection for 100% theme compatibility (Boost, Classic, Moove, Adaptable, Trema, Almond, etc.)
 *
 * @param global_navigation $navigation
 */
function local_lumina_cbt_extend_navigation(\global_navigation $navigation) {
    global $USER, $PAGE, $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $syscontext = \context_system::instance();
    $canmonitor = is_siteadmin() ||
                  has_capability('local/lumina_cbt:monitor', $syscontext) ||
                  has_capability('moodle/grade:viewall', $syscontext) ||
                  has_capability('moodle/course:manageactivities', $syscontext);

    if (!$canmonitor) {
        $courses = enrol_get_all_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $ccontext = \context_course::instance($c->id, IGNORE_MISSING);
            if ($ccontext && (has_capability('local/lumina_cbt:monitor', $ccontext) ||
                              has_capability('moodle/grade:viewall', $ccontext) ||
                              has_capability('moodle/course:manageactivities', $ccontext))) {
                $canmonitor = true;
                break;
            }
        }
    }

    if ($canmonitor) {
        $cbturl = new \moodle_url('/local/lumina_cbt/monitor.php');

        // 1. Native Primary Navigation in Moodle 4.0+ (Boost and derivatives)
        try {
            if (isset($PAGE->primarynav)) {
                $primary = $PAGE->primarynav;
                if ($primary && method_exists($primary, 'add') && !$primary->find('lumina_cbt_primary_monitor', \navigation_node::TYPE_CUSTOM)) {
                    $node = $primary->add(
                        '🛡️ CBT & Audit',
                        $cbturl,
                        \navigation_node::TYPE_CUSTOM,
                        null,
                        'lumina_cbt_primary_monitor'
                    );
                    if ($node) {
                        $node->showinflatnavigation = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback
        }

        // 2. Global / Flat Navigation tree (Classic & drawer)
        if (!$navigation->find('lumina_cbt_global_monitor', \navigation_node::TYPE_CUSTOM)) {
            $node = $navigation->add(
                '🛡️ CBT & Audit',
                $cbturl,
                \navigation_node::TYPE_CUSTOM,
                null,
                'lumina_cbt_global_monitor'
            );
            if ($node) {
                $node->showinflatnavigation = true;
            }
        }

        // 3. Client-Side DOM Injection Fallback for 100% theme compatibility
        $monUrl = $cbturl->out(false);
        $PAGE->requires->js_amd_inline("
            require([], function() {
                function injectCbtTaskbar() {
                    if (document.querySelector('a[href*=\"/local/lumina_cbt/monitor.php\"]')) return;

                    var nav = document.querySelector('.primary-navigation .navigation') ||
                              document.querySelector('#header .navbar-nav') ||
                              document.querySelector('.navbar .navbar-nav') ||
                              document.querySelector('.primary-navigation ul') ||
                              document.querySelector('[role=\"menubar\"]') ||
                              document.querySelector('.navbar-nav') ||
                              document.querySelector('#header .nav') ||
                              document.querySelector('.navbar');
                    if (!nav) return;

                    var isCurrent = window.location.pathname.indexOf('/local/lumina_cbt/monitor.php') !== -1;
                    var li = document.createElement('li');
                    li.className = 'nav-item cbt-taskbar-item' + (isCurrent ? ' active' : '');
                    li.setAttribute('role', 'none');

                    var a = document.createElement('a');
                    a.className = 'nav-link' + (isCurrent ? ' active' : '');
                    a.href = " . json_encode($monUrl) . ";
                    a.setAttribute('role', 'menuitem');
                    a.innerHTML = '<span style=\"margin-right:5px;\">🛡️</span><span style=\"font-weight:600;\">CBT &amp; Audit</span>';
                    li.appendChild(a);

                    var userMenu = nav.querySelector('.usermenu') || nav.querySelector('.ms-auto');
                    if (userMenu) {
                        nav.insertBefore(li, userMenu);
                    } else {
                        nav.appendChild(li);
                    }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', injectCbtTaskbar);
                } else {
                    injectCbtTaskbar();
                }
                setTimeout(injectCbtTaskbar, 300);
                setTimeout(injectCbtTaskbar, 1000);
            });
        ");
    }

    // Also verify proctor lockdown on assessment activities
    local_lumina_cbt_init_proctor_if_needed();
}

/**
 * Automatically inject "CBT Proctor & Audit" into Course Navigation for teachers and managers.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_lumina_cbt_extend_navigation_course(\navigation_node $navigation, \stdClass $course, \context_course $context) {
    if (!$course || $course->id == SITEID) {
        return;
    }

    try {
        $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                      has_capability('moodle/grade:viewall', $context) ||
                      has_capability('moodle/course:manageactivities', $context) ||
                      is_siteadmin();

        if ($canmonitor && method_exists($navigation, 'add_node')) {
            $url = new \moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $course->id]);
            $cbtnode = \navigation_node::create(
                get_string('course_cbt_menu', 'local_lumina_cbt'),
                $url,
                \navigation_node::TYPE_SETTING,
                null,
                'lumina_cbt_monitor',
                new \pix_icon('i/shield', '')
            );
            $cbtnode->showinflatnavigation = true;
            $navigation->add_node($cbtnode);
        }
    } catch (\Throwable $e) {
        // Fail-safe: ensure course pages never crash
    }
}

/**
 * Extend Course administration settings navigation.
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_lumina_cbt_extend_settings_navigation(\settings_navigation $nav, \context $context) {
    try {
        if ($context->contextlevel == CONTEXT_COURSE && $context->instanceid != SITEID) {
            $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                          has_capability('moodle/course:manageactivities', $context) ||
                          is_siteadmin();

            if ($canmonitor) {
                $coursenode = $nav->find('courseadmin', \navigation_node::TYPE_COURSE);
                if ($coursenode) {
                    $coursenode->add(
                        get_string('course_cbt_menu', 'local_lumina_cbt'),
                        new \moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $context->instanceid]),
                        \navigation_node::TYPE_SETTING,
                        null,
                        'lumina_cbt_settings_monitor'
                    );
                }
            }
        }
    } catch (\Throwable $e) {
        // Fail-safe
    }
}

/**
 * Legacy & PSR-14 before_footer callback.
 */
function local_lumina_cbt_before_footer() {
    local_lumina_cbt_init_proctor_if_needed();
}

/**
 * Helper to initialize proctoring lockdown on exam activities across any Moodle version.
 */
function local_lumina_cbt_init_proctor_if_needed() {
    global $PAGE, $USER, $DB;

    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    if (!isloggedin() || isguestuser()) {
        return;
    }

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

    $isexam = (
        strpos($path, '/mod/quiz/attempt.php') !== false ||
        strpos($path, '/mod/quiz/processattempt.php') !== false ||
        strpos($path, '/mod/h5pactivity/view.php') !== false ||
        strpos($path, '/local/lumina_cbt/exam_view.php') !== false
    );

    if (!$isexam) {
        return;
    }

    $context = \context_module::instance($cm->id, IGNORE_MISSING);
    if (!$context) {
        return;
    }

    // Exclude teachers/admins from lockdown so they can preview/grade
    $isteacher = has_capability('moodle/course:manageactivities', $context) ||
                 has_capability('moodle/grade:viewall', $context) ||
                 is_siteadmin();

    if ($isteacher) {
        return;
    }

    // Start/retrieve candidate proctoring session
    $session = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, (int)$cm->id, (int)$USER->id);

    // Course URL for fallback redirect
    $courseurl = (new \moodle_url('/course/view.php', ['id' => $cm->course]))->out(false);

    // Inject Proctoring AMD Module
    $PAGE->requires->js_call_amd('local_lumina_cbt/proctor', 'init', [[
        'sessionId'        => (int)$session->id,
        'cmid'             => (int)$cm->id,
        'courseId'         => (int)$cm->course,
        'courseUrl'        => $courseurl,
        'sesskey'          => sesskey(),
        'maxWarnings'      => (int)(get_config('local_lumina_cbt', 'max_warnings') ?: 3),
        'strictFullscreen' => (bool)(get_config('local_lumina_cbt', 'enable_fullscreen') ?? true),
        'blockKeyboard'    => (bool)(get_config('local_lumina_cbt', 'block_keyboard') ?? false),
        'suppressAi'       => (bool)(get_config('local_lumina_cbt', 'suppress_ai') ?? true),
    ]]);
}
