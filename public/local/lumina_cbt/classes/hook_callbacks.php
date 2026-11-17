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

namespace local_lumina_cbt;

defined('MOODLE_INTERNAL') || die();

/**
 * Modern PSR-14 Hook callbacks for Lumina CBT & Proctoring.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Intercept requests to exam activities (mod_quiz, mod_h5pactivity) and redirect to the CBT exam view
     * when CBT mode is enabled for that activity.
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers($hook): void {
        global $PAGE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $pagetype = $PAGE->pagetype ?? '';
        $urlpath = $PAGE->url ? $PAGE->url->get_path() : '';

        // Universal check: any H5P activity, Moodle Quiz, or question attempt
        $isexamactivity = (
            $pagetype === 'mod-h5pactivity-view' ||
            strpos($urlpath, 'mod/h5pactivity/view.php') !== false ||
            $pagetype === 'mod-quiz-view' ||
            $pagetype === 'mod-quiz-attempt' ||
            strpos($urlpath, 'mod/quiz/view.php') !== false ||
            strpos($urlpath, 'mod/quiz/attempt.php') !== false
        );

        if (!$isexamactivity) {
            return;
        }

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

        if (!$cmid) {
            return;
        }

        require_once($CFG->dirroot . '/local/lumina_cbt/lib.php');

        // Check if CBT mode is enabled for this activity!
        if (!function_exists('local_lumina_cbt_is_quiz_cbt_enabled') || !local_lumina_cbt_is_quiz_cbt_enabled($cmid)) {
            return;
        }

        // If user explicitly passed noredirect=1 (e.g. to access native settings/attempts), don't redirect
        if (optional_param('noredirect', 0, PARAM_INT) == 1) {
            return;
        }

        // Check if there is an explicit editing or management action
        $action = optional_param('action', '', PARAM_ALPHA);
        if (!empty($action) && in_array($action, ['edit', 'delete', 'update', 'grade'], true)) {
            return;
        }

        // Automatically launch the dedicated CBT Exam Room!
        redirect(new \moodle_url('/local/lumina_cbt/exam_view.php', ['cmid' => $cmid]));
    }

    /**
     * Inject universal CBT proctoring and navbar menu fallback into footer.
     *
     * @param \core\hook\output\before_standard_footer_html_generation $hook
     */
    public static function before_standard_footer_html_generation($hook): void {
        global $CFG;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            require_once($CFG->dirroot . '/local/lumina_cbt/lib.php');
            if (function_exists('local_lumina_cbt_before_footer')) {
                \local_lumina_cbt_before_footer();
            }
        } catch (\Throwable $e) {
            // Fail-safe: never interrupt footer rendering
        }
    }

    /**
     * Inject CBT & Audit menu into top taskbar / primary navigation menu across all themes.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend($hook): void {
        global $USER, $CFG;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            require_once($CFG->dirroot . '/local/lumina_cbt/lib.php');

            $canmonitor = function_exists('local_lumina_cbt_can_monitor_cached')
                ? \local_lumina_cbt_can_monitor_cached()
                : (is_siteadmin() || has_capability('moodle/course:manageactivities', \context_system::instance()));

            if ($canmonitor) {
                $primaryview = null;
                if (method_exists($hook, 'get_primaryview')) {
                    $primaryview = $hook->get_primaryview();
                } else if (method_exists($hook, 'get_primary_view')) {
                    $primaryview = $hook->get_primary_view();
                } else if (isset($hook->primaryview)) {
                    $primaryview = $hook->primaryview;
                }

                if ($primaryview && method_exists($primaryview, 'add')) {
                    $url = new \moodle_url('/local/lumina_cbt/monitor.php');
                    if (!$primaryview->find('lumina_cbt_primary_monitor', \navigation_node::TYPE_CUSTOM)) {
                        $node = $primaryview->add(
                            '🛡️ CBT & Audit',
                            $url,
                            \navigation_node::TYPE_CUSTOM,
                            null,
                            'lumina_cbt_primary_monitor'
                        );
                        if ($node) {
                            $node->showinflatnavigation = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe: never crash header navigation
        }
    }

    /**
     * Inject CBT Proctor & Audit tab into course secondary navigation menu.
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function secondary_extend($hook): void {
        global $COURSE, $CFG;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        if (!$COURSE || $COURSE->id == SITEID) {
            return;
        }

        try {
            require_once($CFG->dirroot . '/local/lumina_cbt/lib.php');

            $context = \context_course::instance($COURSE->id, IGNORE_MISSING);
            if (!$context) {
                return;
            }

            $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                          has_capability('moodle/grade:viewall', $context) ||
                          has_capability('moodle/course:manageactivities', $context) ||
                          is_siteadmin();

            if ($canmonitor) {
                $secondarynav = null;
                if (method_exists($hook, 'get_secondaryview')) {
                    $secondarynav = $hook->get_secondaryview();
                } else if (method_exists($hook, 'get_navigation')) {
                    $secondarynav = $hook->get_navigation();
                } else if (isset($hook->secondaryview)) {
                    $secondarynav = $hook->secondaryview;
                }

                if ($secondarynav && method_exists($secondarynav, 'add')) {
                    $url = new \moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $COURSE->id]);
                    if (!$secondarynav->find('lumina_cbt_secondary_monitor', \navigation_node::TYPE_SETTING)) {
                        $secondarynav->add(
                            get_string('course_cbt_menu', 'local_lumina_cbt'),
                            $url,
                            \navigation_node::TYPE_SETTING,
                            null,
                            'lumina_cbt_secondary_monitor',
                            new \pix_icon('i/shield', '')
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe: never crash course page
        }
    }

    /**
     * Inject "CBT & Audit Ujian" link into the native Moodle user dropdown menu
     * across ALL themes (Boost, Classic, Lumina, Learnova, etc.) via PSR-14 hook.
     *
     * @param \core_user\hook\extend_user_menu $hook
     */
    public static function extend_user_menu(\core_user\hook\extend_user_menu $hook): void {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            $sysctx = \context_system::instance();
            $canseemenu = has_capability('local/lumina_cbt:monitor', $sysctx) ||
                          has_capability('moodle/grade:viewall', $sysctx) ||
                          has_capability('moodle/course:manageactivities', $sysctx) ||
                          is_siteadmin();

            if (!$canseemenu) {
                return;
            }

            $url = new \core\url('/local/lumina_cbt/monitor.php');
            $title = 'CBT & Audit Ujian';

            if (class_exists('\\core_user\\output\\user_action_menu\\link')) {
                $icon = class_exists('\\core\\output\\pix_icon')
                    ? new \core\output\pix_icon('i/shield', '')
                    : null;
                $item = new \core_user\output\user_action_menu\link(
                    $url,
                    $title,
                    $title,
                    $icon,
                    null
                );
                $hook->add_menu_item($item);
            }
        } catch (\Throwable $e) {
            // Fail-safe: never crash user menu generation
        }
    }
}
