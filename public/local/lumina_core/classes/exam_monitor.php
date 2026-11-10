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
 * Exam Proctoring & Monitoring Engine for Lumina LMS Core.
 *
 * Implements 3-Warning Protocol, fullscreen lock, blur detection, and Admin Review queue.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

use stdClass;

class exam_monitor {

    /**
     * Start or retrieve an exam session for a student.
     *
     * @param int $courseid
     * @param int $activityid (cmid)
     * @param int $userid
     * @return stdClass
     */
    public static function start_or_get_session(int $courseid, int $activityid, int $userid): stdClass {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', [
            'courseid'   => $courseid,
            'activityid' => $activityid,
            'userid'     => $userid,
        ], '*', IGNORE_MULTIPLE);

        if ($session) {
            return $session;
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

        $newsess->id = $DB->insert_record('local_lumina_exam_sessions', $newsess);
        return $newsess;
    }

    /**
     * Log a proctoring violation event and return current warning level and action.
     *
     * @param int $sessionid
     * @param int $userid
     * @param string $eventtype ('blur', 'visibility_hidden', 'mouseleave', 'fullscreen_exit')
     * @param string $metadata
     * @return array
     */
    public static function record_violation(int $sessionid, int $userid, string $eventtype, string $metadata = ''): array {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $now = time();

        // 1. Log event
        $ev = new stdClass();
        $ev->sessionid = $sessionid;
        $ev->userid = $userid;
        $ev->eventtype = clean_text($eventtype);
        $ev->eventtime = $now;
        $ev->metadata = clean_text($metadata);
        $DB->insert_record('local_lumina_exam_events', $ev);

        // If already suspended/under_review, don't re-increment
        if ($session->status === 'under_review' || $session->status === 'invalidated') {
            return [
                'warning_level' => 3,
                'status'        => $session->status,
                'is_suspended'  => true,
                'message'       => 'Ujian Anda telah ditangguhkan untuk pemeriksaan Administrator.',
            ];
        }

        // Increment warning
        $newwarning = min(3, (int)$session->warningcount + 1);
        $session->warningcount = $newwarning;

        // Record warning log
        $w = new stdClass();
        $w->sessionid = $sessionid;
        $w->warninglevel = $newwarning;
        $w->reason = 'Terdeteksi meninggalkan layar ujian: ' . $eventtype;
        $w->issuedat = $now;
        $DB->insert_record('local_lumina_exam_warnings', $w);

        $issuspended = false;
        if ($newwarning >= 3) {
            $session->status = 'under_review';
            $session->timefinish = $now;
            $issuspended = true;
        }

        $DB->update_record('local_lumina_exam_sessions', $session);

        $messages = [
            1 => 'Peringatan 1 dari 3: Jangan meninggalkan layar ujian! Setiap perpindahan jendela tercatat dalam sistem.',
            2 => 'Peringatan 2 dari 3: Pelanggaran terdeteksi! Satu kali lagi meninggalkan layar, ujian Anda akan otomatis ditangguhkan ke Administrator!',
            3 => 'Peringatan 3 dari 3: Ujian Anda telah ditangguhkan dan dikunci. Nilai Anda diserahkan ke Administrator untuk ditinjau.',
        ];

        return [
            'warning_level' => $newwarning,
            'status'        => $session->status,
            'is_suspended'  => $issuspended,
            'message'       => $messages[$newwarning] ?? '',
        ];
    }

    /**
     * Get all exam sessions flagged for admin review.
     *
     * @return array
     */
    public static function get_sessions_for_review(): array {
        global $DB;

        $sql = "SELECT s.id, s.courseid, s.activityid, s.userid, s.status, s.warningcount,
                       s.score, s.timestart, s.timefinish,
                       u.firstname, u.lastname, u.email,
                       c.fullname AS coursename, c.shortname AS courseshortname
                  FROM {local_lumina_exam_sessions} s
                  JOIN {user} u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.status = 'under_review'
              ORDER BY s.timefinish DESC, s.id DESC";

        $rows = $DB->get_records_sql($sql);
        $result = [];

        foreach ($rows as $r) {
            $eventcount = $DB->count_records('local_lumina_exam_events', ['sessionid' => $r->id]);
            $activityname = $DB->get_field_sql(
                "SELECT COALESCE(h.name, a.name, 'Ujian H5P / Kuis')
                   FROM {course_modules} cm
              LEFT JOIN {h5pactivity} h ON h.id = cm.instance
              LEFT JOIN {assign} a ON a.id = cm.instance
                  WHERE cm.id = ?",
                [$r->activityid]
            );

            $result[] = [
                'session_id'    => $r->id,
                'course_name'   => format_string($r->coursename),
                'activity_name' => format_string($activityname ?: 'Ujian Interaktif H5P'),
                'student_name'  => fullname($r),
                'student_email' => $r->email,
                'warning_count' => $r->warningcount,
                'event_count'   => $eventcount,
                'start_time'    => userdate($r->timestart, '%d %b %Y, %H:%M'),
                'finish_time'   => $r->timefinish ? userdate($r->timefinish, '%d %b %Y, %H:%M') : '-',
                'status'        => $r->status,
            ];
        }

        return $result;
    }

    /**
     * Admin review resolution: Approve or Invalidate session.
     *
     * @param int $sessionid
     * @param int $adminid
     * @param string $decision ('approved' | 'invalidated')
     * @param string $notes
     * @return bool
     */
    public static function resolve_session(int $sessionid, int $adminid, string $decision, string $notes = ''): bool {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $session->status = in_array($decision, ['approved', 'invalidated'], true) ? $decision : 'under_review';
        $session->adminreviewerid = $adminid;
        $session->adminreviewtime = time();
        $session->adminreviewnotes = clean_text($notes);

        if ($decision === 'invalidated') {
            $session->score = 0.00;
        }

        return $DB->update_record('local_lumina_exam_sessions', $session);
    }
}
