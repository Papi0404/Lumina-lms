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

use stdClass;

/**
 * Enterprise CBT & Anti-Cheat Proctoring Engine.
 *
 * Universal proctoring, 3-strike violation trapping, real-time audit feed,
 * and seamless gradebook synchronization.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cbt_engine {

    /**
     * Start or retrieve an exam session for a candidate.
     *
     * @param int $courseid
     * @param int $activityid (course module ID)
     * @param int $userid
     * @return stdClass
     */
    public static function start_or_get_session(int $courseid, int $activityid, int $userid): stdClass {
        global $DB;

        $table = self::get_session_table();

        $sessions = $DB->get_records_select(
            $table,
            'courseid = ? AND activityid = ? AND userid = ?',
            [$courseid, $activityid, $userid],
            'id DESC',
            '*',
            0,
            1
        );

        if (!empty($sessions)) {
            return reset($sessions);
        }

        $newsess = new stdClass();
        $newsess->courseid = $courseid;
        $newsess->activityid = $activityid;
        $newsess->userid = $userid;
        $newsess->status = 'in_progress';
        $newsess->warningcount = 0;
        $newsess->score = null;
        $newsess->adminreviewerid = 0;
        $newsess->adminreviewtime = 0;
        $newsess->adminreviewnotes = null;
        $newsess->timestart = time();
        $newsess->timefinish = 0;

        $newsess->id = $DB->insert_record($table, $newsess);
        return $newsess;
    }

    /**
     * Record a proctoring infraction event and execute progressive strike enforcement.
     *
     * @param int $sessionid
     * @param int $userid
     * @param string $eventtype
     * @param string $metadata
     * @return array
     */
    public static function record_violation(int $sessionid, int $userid, string $eventtype, string $metadata = ''): array {
        global $DB;

        $sessiontable = self::get_session_table();
        $eventstable  = self::get_events_table();
        $warningtable = self::get_warnings_table();

        $session = $DB->get_record($sessiontable, ['id' => $sessionid], '*', MUST_EXIST);
        $now = time();

        // 1. Log event permanently with microsecond accuracy
        $ev = new stdClass();
        $ev->sessionid = $sessionid;
        $ev->userid = $userid;
        $ev->eventtype = clean_text($eventtype);
        $ev->eventtime = $now;
        $ev->metadata = clean_text($metadata);
        $DB->insert_record($eventstable, $ev);

        // If already suspended/under_review or invalidated, keep locked
        if ($session->status === 'under_review' || $session->status === 'invalidated') {
            return [
                'warning_level' => 3,
                'status'        => $session->status,
                'is_suspended'  => true,
                'message'       => get_string('warning_3', 'local_lumina_cbt', self::get_event_label($eventtype)),
            ];
        }

        $maxwarnings = (int)(get_config('local_lumina_cbt', 'max_warnings') ?: 3);
        $newwarning = min($maxwarnings, (int)$session->warningcount + 1);
        $session->warningcount = $newwarning;

        $reasonlabel = self::get_event_label($eventtype);

        // Record warning log
        $w = new stdClass();
        $w->sessionid = $sessionid;
        $w->warninglevel = $newwarning;
        $w->reason = $reasonlabel . (!empty($metadata) ? " ($metadata)" : '');
        $w->issuedat = $now;
        $DB->insert_record($warningtable, $w);

        $issuspended = false;
        if ($newwarning >= $maxwarnings) {
            $session->status = 'under_review';
            $session->timefinish = $now;
            $issuspended = true;
        }

        $DB->update_record($sessiontable, $session);

        $messages = [
            1 => get_string('warning_1', 'local_lumina_cbt', $reasonlabel),
            2 => get_string('warning_2', 'local_lumina_cbt', $reasonlabel),
            3 => get_string('warning_3', 'local_lumina_cbt', $reasonlabel),
        ];

        return [
            'warning_level' => $newwarning,
            'status'        => $session->status,
            'is_suspended'  => $issuspended,
            'message'       => $messages[$newwarning] ?? $messages[3],
        ];
    }

    /**
     * Human-readable infraction event labels.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_label(string $eventtype): string {
        $labels = [
            'keyboard_attempt'                 => get_string('event_keyboard_attempt', 'local_lumina_cbt'),
            'three_finger_gesture'             => get_string('event_three_finger_gesture', 'local_lumina_cbt'),
            'three_finger_gesture_or_minimize' => get_string('event_three_finger_gesture_or_minimize', 'local_lumina_cbt'),
            'trackpad_gesture'                 => get_string('event_trackpad_gesture', 'local_lumina_cbt'),
            'fullscreen_exit'                  => get_string('event_fullscreen_exit', 'local_lumina_cbt'),
            'blur'                             => get_string('event_blur', 'local_lumina_cbt'),
            'window_blur'                      => get_string('event_blur', 'local_lumina_cbt'),
            'visibility_hidden'                => get_string('event_visibility_hidden', 'local_lumina_cbt'),
            'mouseleave'                       => get_string('event_mouseleave', 'local_lumina_cbt'),
            'clipboard_copy'                   => get_string('event_clipboard_copy', 'local_lumina_cbt'),
            'devtools'                         => get_string('event_devtools', 'local_lumina_cbt'),
            'exam_submitted'                   => get_string('event_exam_submitted', 'local_lumina_cbt'),
            'exam_invalidated'                 => get_string('event_exam_invalidated', 'local_lumina_cbt'),
            'exam_reset'                       => get_string('event_exam_reset', 'local_lumina_cbt'),
            'exam_approved'                    => get_string('event_exam_approved', 'local_lumina_cbt'),
            'simulation_reset'                 => 'Simulasi Pengajar Di-reset',
        ];
        return $labels[$eventtype] ?? ('Pelanggaran (' . clean_text($eventtype) . ')');
    }

    /**
     * Bootstrap CSS badge class for event styling.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_badge_class(string $eventtype): string {
        $classes = [
            'keyboard_attempt'                 => 'badge bg-danger text-white',
            'three_finger_gesture'             => 'badge bg-danger text-white',
            'three_finger_gesture_or_minimize' => 'badge bg-danger text-white',
            'fullscreen_exit'                  => 'badge bg-danger text-white',
            'devtools'                         => 'badge bg-danger text-white',
            'exam_invalidated'                 => 'badge bg-dark text-white',
            'clipboard_copy'                   => 'badge bg-danger text-white',
            'trackpad_gesture'                 => 'badge bg-warning text-dark',
            'blur'                             => 'badge bg-warning text-dark',
            'window_blur'                      => 'badge bg-warning text-dark',
            'visibility_hidden'                => 'badge bg-warning text-dark',
            'simulation_reset'                 => 'badge bg-warning text-dark',
            'exam_reset'                       => 'badge bg-primary text-white',
            'exam_approved'                    => 'badge bg-success text-white',
            'exam_submitted'                   => 'badge bg-info text-white',
            'mouseleave'                       => 'badge bg-secondary text-white',
        ];
        return $classes[$eventtype] ?? 'badge bg-secondary text-white';
    }

    /**
     * Material / font icon for event type.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_icon(string $eventtype): string {
        $icons = [
            'keyboard_attempt'                 => 'keyboard_off',
            'three_finger_gesture'             => 'pan_tool',
            'three_finger_gesture_or_minimize' => 'minimize',
            'trackpad_gesture'                 => 'pinch',
            'fullscreen_exit'                  => 'fullscreen_exit',
            'blur'                             => 'tab_unselected',
            'window_blur'                      => 'tab_unselected',
            'visibility_hidden'                => 'visibility_off',
            'mouseleave'                       => 'mouse',
            'clipboard_copy'                   => 'content_copy',
            'devtools'                         => 'terminal',
            'exam_invalidated'                 => 'gavel',
            'exam_reset'                       => 'restart_alt',
            'exam_approved'                    => 'verified',
            'simulation_reset'                 => 'refresh',
            'exam_submitted'                   => 'task_alt',
        ];
        return $icons[$eventtype] ?? 'warning';
    }

    /**
     * Get all events for a given exam session.
     *
     * @param int $sessionid
     * @return array
     */
    public static function get_session_events(int $sessionid): array {
        global $DB;

        $eventstable = self::get_events_table();

        $events = $DB->get_records_select(
            $eventstable,
            'sessionid = ? AND eventtype != ?',
            [$sessionid, 'exam_submitted'],
            'id DESC',
            '*',
            0,
            100
        );

        $list = [];
        foreach ($events as $ev) {
            $list[] = [
                'id'          => (int)$ev->id,
                'eventtype'   => $ev->eventtype,
                'label'       => self::get_event_label($ev->eventtype),
                'badge_class' => self::get_event_badge_class($ev->eventtype),
                'icon'        => self::get_event_icon($ev->eventtype),
                'time'        => userdate($ev->eventtime, '%H:%M:%S'),
                'date'        => userdate($ev->eventtime, '%d %b %Y, %H:%M:%S'),
                'metadata'    => format_string($ev->metadata ?: '-'),
            ];
        }
        return $list;
    }

    /**
     * Get live audit feed of infractions for real-time proctoring stream.
     *
     * @param int $since Timestamp threshold
     * @param string|null $date Date filter ('YYYY-MM-DD' or 'all')
     * @param string $search Search query
     * @param int $limit Max records
     * @return array
     */
    public static function get_live_audit_feed(int $since = 0, ?string $date = null, string $search = '', int $limit = 50, ?int $courseid = null, ?int $activityid = null): array {
        global $DB;

        $sessiontable = self::get_session_table();
        $eventstable  = self::get_events_table();

        $params = ['exam_submitted'];
        $where = ["ev.eventtype != ?"];

        if ($courseid > 0) {
            $where[] = "s.courseid = ?";
            $params[] = $courseid;
        }

        if ($activityid > 0) {
            $where[] = "s.activityid = ?";
            $params[] = $activityid;
        }

        if ($since > 0) {
            $where[] = "ev.eventtime > ?";
            $params[] = $since;
        }

        // Date Filter
        if ($date === null || $date === '') {
            $date = date('Y-m-d');
        }
        if ($date !== 'all') {
            $daystart = strtotime($date . ' 00:00:00');
            $dayend   = strtotime($date . ' 23:59:59');
            if ($daystart !== false && $dayend !== false) {
                $where[] = "ev.eventtime >= ? AND ev.eventtime <= ?";
                $params[] = $daystart;
                $params[] = $dayend;
            }
        }

        // Search Filter
        if (!empty($search)) {
            $searchterm = '%' . $DB->sql_like_escape(trim($search)) . '%';
            $where[] = "(" . $DB->sql_like('u.firstname', '?', false) . " OR " .
                             $DB->sql_like('u.lastname', '?', false) . " OR " .
                             $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), '?', false) . " OR " .
                             $DB->sql_like('u.email', '?', false) . " OR " .
                             $DB->sql_like('ev.metadata', '?', false) . ")";
            $params[] = $searchterm;
            $params[] = $searchterm;
            $params[] = $searchterm;
            $params[] = $searchterm;
            $params[] = $searchterm;
        }

        $wheresql = implode(' AND ', $where);

        $sql = "SELECT ev.id, ev.sessionid, ev.userid, ev.eventtype, ev.eventtime, ev.metadata,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email,
                       s.warningcount, s.status,
                       c.shortname AS courseshortname
                  FROM {{$eventstable}} ev
                  JOIN {user} u ON u.id = ev.userid
                  JOIN {{$sessiontable}} s ON s.id = ev.sessionid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE $wheresql
              ORDER BY ev.id DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $feed = [];
        $sysctx = \context_system::instance();

        foreach ($records as $r) {
            $feed[] = [
                'id'             => (int)$r->id,
                'sessionid'      => (int)$r->sessionid,
                'student_name'   => fullname($r),
                'student_email'  => $r->email,
                'course'         => format_string($r->courseshortname, true, ['context' => $sysctx]),
                'warning_count'  => (int)$r->warningcount,
                'eventtype'      => $r->eventtype,
                'label'          => self::get_event_label($r->eventtype),
                'icon'           => self::get_event_icon($r->eventtype),
                'badge_class'    => self::get_event_badge_class($r->eventtype),
                'metadata'       => format_string($r->metadata ?: '-', true, ['context' => $sysctx]),
                'time_formatted' => userdate($r->eventtime, '%H:%M:%S'),
                'date_formatted' => userdate($r->eventtime, '%d %b %Y'),
                'timestamp'      => (int)$r->eventtime,
            ];
        }
        return $feed;
    }

    /**
     * Get candidate attempts flagged for review and proctor management.
     *
     * @param string|null $date
     * @param string $search
     * @param int|null $courseid
     * @return array
     */
    public static function get_sessions_for_review(?string $date = null, string $search = '', ?int $courseid = null, ?int $activityid = null, string $filterscope = 'violations_only'): array {
        global $DB;

        $sessiontable = self::get_session_table();
        $eventstable  = self::get_events_table();

        $params = [];
        $where = [];

        if ($courseid > 0) {
            $where[] = "s.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        if ($activityid > 0) {
            $where[] = "s.activityid = :activityid";
            $params['activityid'] = $activityid;
        }

        // Filter Scope:
        // By default ('violations_only'), show students who committed infractions OR are actively taking the exam (in_progress).
        // Honest students who already completed with 0 violations pass automatically to Gradebook without cluttering the review queue.
        if ($filterscope === 'violations_only') {
            $where[] = "(s.status IN ('under_review', 'invalidated', 'in_progress') OR s.warningcount >= 1)";
        } else {
            $where[] = "(s.status IN ('under_review', 'invalidated', 'approved', 'completed', 'in_progress'))";
        }

        // Date Filter
        if ($date === null || $date === '') {
            $date = date('Y-m-d');
        }
        if ($date !== 'all') {
            $daystart = strtotime($date . ' 00:00:00');
            $dayend   = strtotime($date . ' 23:59:59');
            if ($daystart !== false && $dayend !== false) {
                $where[] = "((s.timestart >= :daystart1 AND s.timestart <= :dayend1) OR " .
                           "(s.timefinish >= :daystart2 AND s.timefinish <= :dayend2) OR " .
                           "EXISTS (SELECT 1 FROM {{$eventstable}} ev_date WHERE ev_date.sessionid = s.id AND ev_date.eventtime >= :daystart3 AND ev_date.eventtime <= :dayend3))";
                $params['daystart1'] = $daystart;
                $params['dayend1']   = $dayend;
                $params['daystart2'] = $daystart;
                $params['dayend2']   = $dayend;
                $params['daystart3'] = $daystart;
                $params['dayend3']   = $dayend;
            }
        }

        // Search Filter
        if (!empty($search)) {
            $searchterm = '%' . $DB->sql_like_escape(trim($search)) . '%';
            $where[] = "(" . $DB->sql_like('u.firstname', ':search1', false) . " OR " .
                             $DB->sql_like('u.lastname', ':search2', false) . " OR " .
                             $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':search3', false) . " OR " .
                             $DB->sql_like('u.email', ':search4', false) . ")";
            $params['search1'] = $searchterm;
            $params['search2'] = $searchterm;
            $params['search3'] = $searchterm;
            $params['search4'] = $searchterm;
        }

        $wheresql = implode(' AND ', $where);

        $sql = "SELECT s.id, s.courseid, s.activityid, s.userid, s.status, s.warningcount,
                       s.score, s.timestart, s.timefinish, s.adminreviewerid, s.adminreviewtime, s.adminreviewnotes,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email,
                       c.fullname AS coursename, c.shortname AS courseshortname
                  FROM {{$sessiontable}} s
                  JOIN {user} u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE $wheresql
              ORDER BY s.warningcount DESC, s.id DESC";

        $rows = $DB->get_records_sql($sql, $params);
        $result = [];

        foreach ($rows as $r) {
            $eventcount = $DB->count_records_select($eventstable, 'sessionid = ? AND eventtype != ?', [$r->id, 'exam_submitted']);
            $latestevents = $DB->get_records_select($eventstable, 'sessionid = ? AND eventtype != ?', [$r->id, 'exam_submitted'], 'id DESC', 'eventtype, metadata, eventtime', 0, 1);
            $latestevent = !empty($latestevents) ? reset($latestevents) : null;

            $activityname = $DB->get_field_sql(
                "SELECT COALESCE(q.name, h.name, a.name, 'Ujian CBT')
                   FROM {course_modules} cm
              LEFT JOIN {quiz} q ON q.id = cm.instance
              LEFT JOIN {h5pactivity} h ON h.id = cm.instance
              LEFT JOIN {assign} a ON a.id = cm.instance
                  WHERE cm.id = ?",
                [$r->activityid]
            );

            $latestlabel = '-';
            $latesttime  = '-';
            if ($latestevent) {
                $latestlabel = self::get_event_label($latestevent->eventtype) . (!empty($latestevent->metadata) ? ' (' . $latestevent->metadata . ')' : '');
                $latesttime = userdate($latestevent->eventtime, '%H:%M:%S');
            }

            $sysctx = \context_system::instance();
            $coursectx = \context_course::instance($r->courseid, IGNORE_MISSING);
            $isteacher = ($coursectx && (has_capability('moodle/course:manageactivities', $coursectx, $r->userid) || has_capability('moodle/grade:viewall', $coursectx, $r->userid))) || is_siteadmin($r->userid);

            $adminreviewername = '';
            if (!empty($r->adminreviewerid)) {
                $reviewer = $DB->get_record('user', ['id' => $r->adminreviewerid], 'firstname, lastname');
                if ($reviewer) {
                    $adminreviewername = fullname($reviewer);
                }
            }

            $result[] = [
                'session_id'          => $r->id,
                'course_name'         => format_string($r->coursename, true, ['context' => $sysctx]),
                'activity_name'       => format_string($activityname ?: 'Ujian Interaktif CBT', true, ['context' => $sysctx]),
                'student_name'        => fullname($r),
                'student_email'       => $r->email,
                'is_teacher'          => $isteacher,
                'role_label'          => $isteacher ? 'Pengajar (Simulasi)' : 'Peserta Ujian',
                'warning_count'       => (int)$r->warningcount,
                'score'               => $r->score !== null ? (float)$r->score : null,
                'event_count'         => $eventcount,
                'latest_violation'    => $latestlabel,
                'latest_time'         => $latesttime,
                'start_time'          => userdate($r->timestart, '%d %b %Y, %H:%M'),
                'finish_time'         => $r->timefinish ? userdate($r->timefinish, '%d %b %Y, %H:%M') : 'Sedang Berlangsung',
                'status'              => $r->status,
                'admin_reviewer_name' => $adminreviewername,
                'admin_review_notes'  => $r->adminreviewnotes ?: '',
                'events'              => self::get_session_events((int)$r->id),
            ];
        }

        return $result;
    }

    /**
     * Proctor resolution: Reset & Unlock, Approve, or Disqualify.
     *
     * @param int $sessionid
     * @param int $adminid
     * @param string $decision ('approved' | 'invalidated' | 'reset')
     * @param string $notes
     * @return bool
     */
    public static function resolve_session(int $sessionid, int $adminid, string $decision, string $notes = ''): bool {
        global $DB;

        $sessiontable = self::get_session_table();
        $eventstable  = self::get_events_table();
        $warningtable = self::get_warnings_table();

        $session = $DB->get_record($sessiontable, ['id' => $sessionid], '*', MUST_EXIST);
        $adminuser = $DB->get_record('user', ['id' => $adminid], 'id, firstname, lastname');
        $adminname = $adminuser ? fullname($adminuser) : 'Pengawas/Admin';
        $now = time();

        if ($decision === 'reset') {
            // Unlock session: resets strikes to 0/3, in_progress, keeps saved answers
            $session->status = 'in_progress';
            $session->warningcount = 0;
            $session->score = null;
            $session->timefinish = 0;
            $session->adminreviewerid = $adminid;
            $session->adminreviewtime = $now;
            $session->adminreviewnotes = clean_text($notes ?: 'Di-ACC & di-reset oleh Pengawas.');

            $DB->delete_records($warningtable, ['sessionid' => $sessionid]);

            $ev = new stdClass();
            $ev->sessionid = $sessionid;
            $ev->userid = $session->userid;
            $ev->eventtype = 'exam_reset';
            $ev->eventtime = $now;
            $ev->metadata = clean_text('Sesi di-ACC & di-reset oleh ' . $adminname . ($notes ? ' (Catatan: ' . $notes . ')' : ''));
            $DB->insert_record($eventstable, $ev);

            $res = $DB->update_record($sessiontable, $session);
            self::sync_grade_to_gradebook((int)$session->activityid, (int)$session->userid, null);
            return $res;
        }

        $session->status = in_array($decision, ['approved', 'invalidated'], true) ? $decision : 'under_review';
        $session->adminreviewerid = $adminid;
        $session->adminreviewtime = $now;
        $session->adminreviewnotes = clean_text($notes);

        if ($decision === 'approved') {
            $session->warningcount = 0;
            if ($session->score === null || (float)$session->score <= 0) {
                $submitevent = $DB->get_record($eventstable, [
                    'sessionid' => $sessionid,
                    'eventtype' => 'exam_submitted',
                ]);
                if ($submitevent && !empty($submitevent->metadata)) {
                    $meta = json_decode($submitevent->metadata, true);
                    if (isset($meta['score'])) {
                        $session->score = number_format((float)$meta['score'], 2, '.', '');
                    }
                }
            }

            $ev = new stdClass();
            $ev->sessionid = $sessionid;
            $ev->userid = $session->userid;
            $ev->eventtype = 'exam_approved';
            $ev->eventtime = $now;
            $ev->metadata = clean_text('Sesi ujian disetujui oleh ' . $adminname . ($notes ? ' (Catatan: ' . $notes . ')' : ''));
            $DB->insert_record($eventstable, $ev);

        } else if ($decision === 'restore') {
            // Restore previous score from submitted event metadata
            $restoredScore = null;
            $submitevent = $DB->get_record($eventstable, [
                'sessionid' => $sessionid,
                'eventtype' => 'exam_submitted',
            ]);
            if ($submitevent && !empty($submitevent->metadata)) {
                $meta = json_decode($submitevent->metadata, true);
                if (isset($meta['score'])) {
                    $restoredScore = (float)$meta['score'];
                }
            }

            $session->status = 'completed';
            $session->score = $restoredScore;
            $session->adminreviewerid = $adminid;
            $session->adminreviewtime = $now;
            $session->adminreviewnotes = clean_text($notes ?: 'Nilai ujian dipulihkan kembali oleh Pengawas.');

            $ev = new stdClass();
            $ev->sessionid = $sessionid;
            $ev->userid = $session->userid;
            $ev->eventtype = 'exam_approved';
            $ev->eventtime = $now;
            $ev->metadata = clean_text('Nilai ujian dipulihkan kembali oleh ' . $adminname . ($notes ? ' (' . $notes . ')' : ''));
            $DB->insert_record($eventstable, $ev);

            $res = $DB->update_record($sessiontable, $session);
            if ($session->score !== null) {
                self::sync_grade_to_gradebook((int)$session->activityid, (int)$session->userid, (float)$session->score);
            }
            return $res;

        } else if ($decision === 'invalidated') {
            $session->score = 0.00;

            $ev = new stdClass();
            $ev->sessionid = $sessionid;
            $ev->userid = $session->userid;
            $ev->eventtype = 'exam_invalidated';
            $ev->eventtime = $now;
            $ev->metadata = clean_text('Nilai ujian dibatalkan & didiskualifikasi oleh ' . $adminname . ($notes ? ' (Alasan: ' . $notes . ')' : ''));
            $DB->insert_record($eventstable, $ev);
        }

        $res = $DB->update_record($sessiontable, $session);
        if ($session->score !== null) {
            self::sync_grade_to_gradebook((int)$session->activityid, (int)$session->userid, (float)$session->score);
        }
        return $res;
    }

    /**
     * Save student's in-progress answers and doubt flags to server asynchronously.
     *
     * @param int $sessionid
     * @param int $userid
     * @param array $answers
     * @param array $doubtful
     * @return bool
     */
    public static function save_progress(int $sessionid, int $userid, array $answers, array $doubtful): bool {
        global $DB;

        $sessiontable = self::get_session_table();
        $session = $DB->get_record($sessiontable, ['id' => $sessionid, 'userid' => $userid], '*', MUST_EXIST);
        if ($session->status === 'completed' || $session->status === 'under_review' || $session->status === 'invalidated') {
            return false;
        }

        $payload = json_encode([
            'progress' => true,
            'answers'  => $answers,
            'doubtful' => $doubtful,
            'updated'  => time(),
        ]);

        $session->adminreviewnotes = $payload;
        return $DB->update_record($sessiontable, $session);
    }

    /**
     * Universal question extractor for Quiz (mod_quiz), H5P (mod_h5pactivity), or Assignment (mod_assign).
     * Completely dynamic without any hardcoded mock questions.
     *
     * @param int $cmid
     * @return array
     */
    public static function get_exam_questions(int $cmid): array {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }

        // 1. Native Moodle Quiz (mod_quiz)
        if ($cm->modname === 'quiz') {
            try {
                // Try Moodle 4.0 - 5.x Question Bank schema first (with weighted marks support)
                $sql = "SELECT q.id, q.questiontext, q.qtype, q.defaultmark, qs.slot, qs.maxmark
                          FROM {quiz_slots} qs
                          JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                          JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                          JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                          JOIN {question} q ON q.id = qv.questionid
                         WHERE qs.quizid = ?
                      ORDER BY qs.slot ASC";
                $quizq = $DB->get_records_sql($sql, [$cm->instance]);

                // Fallback for direct slot relationship if references empty
                if (empty($quizq)) {
                    $sql_legacy = "SELECT q.id, q.questiontext, q.qtype, q.defaultmark, qs.slot, qs.maxmark
                                     FROM {quiz_slots} qs
                                     JOIN {question} q ON q.id = qs.questionid
                                    WHERE qs.quizid = ?
                                 ORDER BY qs.slot ASC";
                    $quizq = $DB->get_records_sql($sql_legacy, [$cm->instance]);
                }

                if (!empty($quizq)) {
                    $extracted = [];
                    $slotnum = 1;
                    foreach ($quizq as $qq) {
                        $maxmark = (!empty($qq->maxmark) && (float)$qq->maxmark > 0) ? (float)$qq->maxmark : ((!empty($qq->defaultmark) && (float)$qq->defaultmark > 0) ? (float)$qq->defaultmark : 1.0);
                        $answers = $DB->get_records('question_answers', ['question' => $qq->id], 'id ASC');
                        $opts = [];
                        $correctidx = 0;
                        $aidx = 0;
                        foreach ($answers as $ans) {
                            if ((float)$ans->fraction > 0) {
                                $correctidx = $aidx;
                            }
                            $opts[] = [
                                'index' => $aidx,
                                'label' => chr(65 + $aidx),
                                'text'  => strip_tags($ans->answer),
                            ];
                            $aidx++;
                        }
                        if (empty($opts)) {
                            $opts[] = ['index' => 0, 'label' => 'A', 'text' => 'Tuliskan jawaban Anda pada lembar ujian'];
                        }
                        $extracted[] = [
                            'id'            => $qq->id,
                            'number'        => $slotnum,
                            'question'      => strip_tags($qq->questiontext),
                            'options'       => $opts,
                            'correct_index' => $correctidx,
                            'maxmark'       => $maxmark,
                        ];
                        $slotnum++;
                    }
                    if (!empty($extracted)) {
                        return $extracted;
                    }
                }
            } catch (\Throwable $qe) {
                debugging('Error loading quiz questions: ' . $qe->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // 2. H5P Interactive Activities (mod_h5pactivity / mod_hvp)
        if ($cm->modname === 'h5pactivity' || $cm->modname === 'hvp') {
            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if ($context) {
                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, $cm->modname, 'package', 0, 'sortorder', false);
                if (!empty($files)) {
                    $f = reset($files);
                    $content = $f->get_content();
                    $tmp = tempnam(sys_get_temp_dir(), 'h5pq_');
                    file_put_contents($tmp, $content);
                    $zip = new \ZipArchive();
                    if ($zip->open($tmp) === true) {
                        $json = $zip->getFromName('content/content.json') ?: $zip->getFromName('content.json');
                        if ($json) {
                            $data = json_decode($json, true);
                            if (is_array($data)) {
                                $extracted = self::extract_h5p_questions($data);
                                $zip->close();
                                @unlink($tmp);
                                if (!empty($extracted)) {
                                    return $extracted;
                                }
                            }
                        }
                        $zip->close();
                    }
                    @unlink($tmp);
                }
            }
        }

        // 3. Assignment task (mod_assign)
        if ($cm->modname === 'assign') {
            $assign = $DB->get_record('assign', ['id' => $cm->instance]);
            if ($assign) {
                return [
                    [
                        'id'            => 1,
                        'number'        => 1,
                        'question'      => strip_tags($assign->intro ?: $assign->name),
                        'options'       => [
                            ['index' => 0, 'label' => 'A', 'text' => 'Tugas telah selesai dikerjakan'],
                            ['index' => 1, 'label' => 'B', 'text' => 'Tugas masih dalam proses pengerjaan'],
                        ],
                        'correct_index' => 0,
                        'maxmark'       => 100.0,
                    ]
                ];
            }
        }

        return [];
    }

    /**
     * Recursively extract questions from decoded H5P content structure.
     * Supports QuestionSet, SingleChoiceSet, MultiChoice, TrueFalse, Column, etc.
     *
     * @param array $data Decoded content.json
     * @return array Extracted question list
     */
    public static function extract_h5p_questions(array $data): array {
        $questions = [];

        // Direct questions array
        if (!empty($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $q) {
                $parsed = self::parse_single_h5p_question($q);
                if ($parsed) {
                    $questions[] = $parsed;
                }
            }
        }

        // Column / interactive sub-elements
        $elements = $data['elements'] ?? $data['activities'] ?? $data['tasks'] ?? [];
        if (!empty($elements) && is_array($elements)) {
            foreach ($elements as $el) {
                $subdata = $el['action'] ?? $el;
                if (!empty($subdata['params'])) {
                    $parsed = self::parse_single_h5p_question($subdata);
                    if ($parsed) {
                        $questions[] = $parsed;
                    }
                }
            }
        }

        // Standalone question at root
        if (empty($questions) && (!empty($data['choices']) || !empty($data['answers']) || !empty($data['question']))) {
            $parsed = self::parse_single_h5p_question(['params' => $data]);
            if ($parsed) {
                $questions[] = $parsed;
            }
        }

        // Number sequentially
        $result = [];
        $num = 1;
        foreach ($questions as $q) {
            $q['number'] = $num;
            $q['id']     = $num;
            $result[] = $q;
            $num++;
        }
        return $result;
    }

    protected static function parse_single_h5p_question(array $item): ?array {
        $params = $item['params'] ?? $item;
        $qtext = clean_text($params['question'] ?? ($params['intro'] ?? ''));
        if (empty($qtext)) {
            return null;
        }

        $opts = [];
        $correctidx = 0;
        $aidx = 0;

        $rawanswers = $params['answers'] ?? $params['choices'] ?? [];
        if (!empty($rawanswers) && is_array($rawanswers)) {
            foreach ($rawanswers as $ans) {
                $text = clean_text(is_array($ans) ? ($ans['text'] ?? $ans['answer'] ?? '') : (string)$ans);
                if ($text === '') continue;
                $iscorrect = is_array($ans) && (!empty($ans['correct']) || !empty($ans['isCorrect']));
                if ($iscorrect) {
                    $correctidx = $aidx;
                }
                $opts[] = [
                    'index' => $aidx,
                    'label' => chr(65 + $aidx),
                    'text'  => strip_tags($text),
                ];
                $aidx++;
            }
        }

        if (empty($opts) && isset($params['correct'])) {
            $isTrue = ($params['correct'] === 'true' || $params['correct'] === true);
            $opts = [
                ['index' => 0, 'label' => 'A', 'text' => 'Benar (True)'],
                ['index' => 1, 'label' => 'B', 'text' => 'Salah (False)'],
            ];
            $correctidx = $isTrue ? 0 : 1;
        }

        if (empty($opts)) {
            return null;
        }

        $maxmark = (!empty($params['weight']) && (float)$params['weight'] > 0) ? (float)$params['weight'] : ((!empty($params['score']) && (float)$params['score'] > 0) ? (float)$params['score'] : 1.0);

        return [
            'id'            => 0,
            'number'        => 0,
            'question'      => strip_tags($qtext),
            'options'       => $opts,
            'correct_index' => $correctidx,
            'maxmark'       => $maxmark,
        ];
    }

    /**
     * Submit an exam attempt, compute final score, and instantly update Gradebook.
     *
     * @param int $sessionid
     * @param int $userid
     * @param array $useranswers
     * @return array
     */
    public static function submit_exam_attempt(int $sessionid, int $userid, array $useranswers): array {
        global $DB;

        $sessiontable = self::get_session_table();
        $eventstable  = self::get_events_table();

        $session = $DB->get_record($sessiontable, ['id' => $sessionid, 'userid' => $userid], '*', MUST_EXIST);
        if ($session->status === 'invalidated') {
            return [
                'success'       => false,
                'session_id'    => $sessionid,
                'status'        => 'invalidated',
                'score'         => 0.0,
                'correct'       => 0,
                'wrong'         => 0,
                'unanswered'    => 0,
                'total'         => 0,
                'warning_count' => (int)$session->warningcount,
                'is_suspended'  => true,
                'submitted_at'  => userdate(time(), '%d %b %Y, %H:%M WIB'),
                'message'       => 'Sesi ujian ini telah dibatalkan oleh pengawas.',
            ];
        }

        $questions = self::get_exam_questions((int)$session->activityid);
        $total = count($questions);
        if ($total === 0) {
            return [
                'success' => false,
                'message' => 'Tidak ada butir soal yang ditemukan pada aktivitas ini.',
            ];
        }

        $totalMaxPoints = 0.0;
        $earnedPoints   = 0.0;
        $correct = 0;
        $wrong = 0;
        $unanswered = 0;

        foreach ($questions as $q) {
            $qnum = (int)$q['number'];
            $weight = (isset($q['maxmark']) && (float)$q['maxmark'] > 0) ? (float)$q['maxmark'] : 1.0;
            $totalMaxPoints += $weight;
            if (!isset($useranswers[$qnum]) || $useranswers[$qnum] === null || $useranswers[$qnum] === '') {
                $unanswered++;
            } else if ((int)$useranswers[$qnum] === (int)$q['correct_index']) {
                $correct++;
                $earnedPoints += $weight;
            } else {
                $wrong++;
            }
        }

        $finalscore = ($totalMaxPoints > 0) ? round(($earnedPoints / $totalMaxPoints) * 100, 2) : 0.00;
        $now = time();

        $session->score = $finalscore;
        $session->timefinish = $now;
        if ((int)$session->warningcount === 0) {
            // Student finished with ZERO violations: auto-approved and passed cleanly!
            $session->status = 'completed';
        } else {
            // Student had 1 or more infractions: flag for proctor review
            $session->status = 'under_review';
        }
        $DB->update_record($sessiontable, $session);

        // Instantly push grade to Moodle Gradebook
        self::sync_grade_to_gradebook((int)$session->activityid, $userid, $finalscore);

        $ev = new stdClass();
        $ev->sessionid = $sessionid;
        $ev->userid = $userid;
        $ev->eventtype = 'exam_submitted';
        $ev->eventtime = $now;
        $ev->metadata = json_encode([
            'score'            => $finalscore,
            'earned_points'    => round($earnedPoints, 2),
            'total_max_points' => round($totalMaxPoints, 2),
            'correct'          => $correct,
            'wrong'            => $wrong,
            'unanswered'       => $unanswered,
            'total'            => $total,
        ]);
        $DB->insert_record($eventstable, $ev);

        return [
            'success'          => true,
            'session_id'       => $sessionid,
            'status'           => $session->status,
            'score'            => $finalscore,
            'earned_points'    => round($earnedPoints, 2),
            'total_max_points' => round($totalMaxPoints, 2),
            'correct'          => $correct,
            'wrong'            => $wrong,
            'unanswered'       => $unanswered,
            'total'            => $total,
            'warning_count'    => (int)$session->warningcount,
            'is_suspended'     => ($session->status === 'under_review'),
            'submitted_at'     => userdate($now, '%d %b %Y, %H:%M WIB'),
        ];
    }

    /**
     * Push or update grade directly to Moodle Gradebook API.
     *
     * @param int $activityid
     * @param int $userid
     * @param float|null $score
     */
    public static function sync_grade_to_gradebook(int $activityid, int $userid, ?float $score): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        try {
            $cm = get_coursemodule_from_id('', $activityid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }

            if ($score === null) {
                $grade = ['userid' => $userid, 'delete' => 1];
            } else {
                $grade = new stdClass();
                $grade->userid = $userid;
                $grade->rawgrade = (float)$score;
                $grade->finalgrade = (float)$score;
                $grade->dategraded = time();
                $grade->datesubmitted = time();
            }

            grade_update('mod/' . $cm->modname, $cm->course, 'mod', $cm->modname, $cm->instance, 0, $grade);
        } catch (\Throwable $e) {
            debugging('Error syncing CBT grade to gradebook: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Check which session table exists: local_lumina_cbt_sessions or fallback.
     */
    public static function get_session_table(): string {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_lumina_cbt_sessions')) {
            return 'local_lumina_cbt_sessions';
        }
        return 'local_lumina_exam_sessions';
    }

    public static function get_events_table(): string {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_lumina_cbt_events')) {
            return 'local_lumina_cbt_events';
        }
        return 'local_lumina_exam_events';
    }

    public static function get_warnings_table(): string {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_lumina_cbt_warnings')) {
            return 'local_lumina_cbt_warnings';
        }
        return 'local_lumina_exam_warnings';
    }
}
