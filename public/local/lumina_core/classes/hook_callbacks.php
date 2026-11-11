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
 * Hook callbacks for Lumina Core companion plugin.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

use core\hook\output\before_http_headers;

class hook_callbacks {

    /**
     * Intercept requests to proctored exam activities and redirect to the CBT exam view.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Auto-sync any scheduled CBT exam deadlines to Moodle's calendar {event} table
        \local_lumina_core\exam_monitor::sync_cbt_events();

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

        if ($isexamactivity) {
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
                $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
                if ($cm && in_array($cm->modname, ['h5pactivity', 'quiz'], true)) {
                    require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');
                    $roleinfo = \theme_lumina_role_resolver::resolve_role($USER);
                    $isstudent = (!empty($roleinfo['is_student']) || !has_capability('moodle/course:manageactivities', $PAGE->context));
                    $action = optional_param('action', '', PARAM_ALPHA);

                    if ($isstudent && empty($action) && !optional_param('noredirect', 0, PARAM_INT)) {
                        redirect(new \moodle_url('/local/lumina_core/exam_view.php', ['cmid' => $cm->id]));
                    }
                }
            }
        }
    }
}
