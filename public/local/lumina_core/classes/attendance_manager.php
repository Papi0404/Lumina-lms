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
 * Attendance Manager for Lumina LMS Core.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

use stdClass;
use context_course;

class attendance_manager {

    /**
     * Get or create default session for a course if none exists.
     *
     * @param int $courseid
     * @param int $teacherid
     * @return stdClass
     */
    public static function ensure_default_session(int $courseid, int $teacherid): stdClass {
        global $DB;

        $existing = $DB->get_records('local_lumina_att_sessions', ['courseid' => $courseid], 'sessiondate DESC', '*', 0, 1);
        if (!empty($existing)) {
            return reset($existing);
        }

        $session = new stdClass();
        $session->courseid = $courseid;
        $session->teacherid = $teacherid;
        $session->name = 'Pertemuan 01: Orientasi Perkuliahan & Kontrak Belajar';
        $session->sessiondate = time();
        $session->timestart = strtotime('today 08:00');
        $session->timeend = strtotime('today 10:00');
        $session->description = 'Sesi pengenalan silabus dan materi perkuliahan semester.';
        $session->timecreated = time();
        $session->timemodified = time();

        $session->id = $DB->insert_record('local_lumina_att_sessions', $session);
        return $session;
    }

    /**
     * Get all sessions for a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_sessions(int $courseid): array {
        global $DB;
        return $DB->get_records('local_lumina_att_sessions', ['courseid' => $courseid], 'sessiondate DESC, id DESC');
    }

    /**
     * Get enrolled students and their attendance status for a given session.
     *
     * @param int $courseid
     * @param int $sessionid
     * @return array
     */
    public static function get_session_roster(int $courseid, int $sessionid): array {
        global $DB;

        $context = context_course::instance($courseid);
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.contextid = ? AND r.archetype = 'student' AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";

        $students = $DB->get_records_sql($sql, [$context->id]);
        $existingattendance = $DB->get_records('local_lumina_attendance', ['sessionid' => $sessionid], '', 'userid, id, status, remarks, timemarked');

        $roster = [];
        foreach ($students as $s) {
            $att = $existingattendance[$s->id] ?? null;
            $status = $att ? $att->status : 'present'; // Default present if not yet set
            $remarks = $att ? $att->remarks : '';

            $roster[] = [
                'userid'       => $s->id,
                'fullname'     => fullname($s),
                'email'        => $s->email,
                'status'       => $status,
                'is_present'   => ($status === 'present'),
                'is_sick'      => ($status === 'sick'),
                'is_permitted' => ($status === 'permitted'),
                'is_absent'    => ($status === 'absent'),
                'remarks'      => $remarks,
            ];
        }

        return $roster;
    }

    /**
     * Save student attendance records for a session.
     *
     * @param int $sessionid
     * @param int $courseid
     * @param int $markedby User ID of teacher
     * @param array $records Array of ['userid' => int, 'status' => string, 'remarks' => string]
     * @return bool
     */
    public static function save_roster(int $sessionid, int $courseid, int $markedby, array $records): bool {
        global $DB;

        $now = time();
        foreach ($records as $r) {
            $userid = (int)($r['userid'] ?? 0);
            $status = in_array($r['status'] ?? '', ['present', 'sick', 'permitted', 'absent'], true) ? $r['status'] : 'present';
            $remarks = clean_text($r['remarks'] ?? '', PARAM_TEXT);

            if (!$userid) {
                continue;
            }

            $existing = $DB->get_record('local_lumina_attendance', ['sessionid' => $sessionid, 'userid' => $userid]);
            if ($existing) {
                $existing->status = $status;
                $existing->remarks = $remarks;
                $existing->markedby = $markedby;
                $existing->timemarked = $now;
                $DB->update_record('local_lumina_attendance', $existing);
            } else {
                $rec = new stdClass();
                $rec->sessionid = $sessionid;
                $rec->courseid = $courseid;
                $rec->userid = $userid;
                $rec->status = $status;
                $rec->remarks = $remarks;
                $rec->markedby = $markedby;
                $rec->timemarked = $now;
                $DB->insert_record('local_lumina_attendance', $rec);
            }
        }

        return true;
    }

    /**
     * Get attendance summary for a student across all sessions in a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function get_student_summary(int $courseid, int $userid): array {
        global $DB;

        $sessions = self::get_course_sessions($courseid);
        $total = count($sessions);
        if ($total === 0) {
            return [
                'total_sessions' => 0,
                'present_count'  => 0,
                'sick_count'     => 0,
                'permit_count'   => 0,
                'absent_count'   => 0,
                'percent'        => 100,
                'records'        => [],
            ];
        }

        $sessionids = array_keys($sessions);
        list($insql, $inparams) = $DB->get_in_or_equal($sessionids);
        $sql = "SELECT id, sessionid, status, remarks, timemarked
                  FROM {local_lumina_attendance}
                 WHERE userid = ? AND sessionid $insql";
        $attrecords = $DB->get_records_sql($sql, array_merge([$userid], $inparams));

        $bySession = [];
        foreach ($attrecords as $ar) {
            $bySession[$ar->sessionid] = $ar;
        }

        $present = 0;
        $sick = 0;
        $permit = 0;
        $absent = 0;
        $detailrecords = [];

        foreach ($sessions as $s) {
            $ar = $bySession[$s->id] ?? null;
            $st = $ar ? $ar->status : 'absent';

            if ($st === 'present') {
                $present++;
            } else if ($st === 'sick') {
                $sick++;
            } else if ($st === 'permitted') {
                $permit++;
            } else {
                $absent++;
            }

            $detailrecords[] = [
                'session_name' => $s->name,
                'date_str'     => userdate($s->sessiondate, '%d %b %Y'),
                'time_str'     => userdate($s->timestart, '%H:%M') . ' - ' . userdate($s->timeend, '%H:%M'),
                'status'       => $st,
                'status_label' => self::status_label($st),
                'badge_class'  => self::status_badge_class($st),
                'remarks'      => $ar ? $ar->remarks : '',
            ];
        }

        // Percentage calculated as (present + (sick * 0.8) + (permit * 0.8)) / total
        $effective = $present + ($sick * 0.8) + ($permit * 0.8);
        $pct = round(($effective / $total) * 100);

        return [
            'total_sessions' => $total,
            'present_count'  => $present,
            'sick_count'     => $sick,
            'permit_count'   => $permit,
            'absent_count'   => $absent,
            'percent'        => min(100, max(0, $pct)),
            'records'        => $detailrecords,
        ];
    }

    public static function status_label(string $status): string {
        $labels = [
            'present'   => 'Hadir',
            'sick'      => 'Sakit',
            'permitted' => 'Izin',
            'absent'    => 'Alpa',
        ];
        return $labels[$status] ?? 'Belum Ditandai';
    }

    public static function status_badge_class(string $status): string {
        $classes = [
            'present'   => 'bg-success text-success bg-opacity-15 border border-success border-opacity-25',
            'sick'      => 'bg-warning text-warning bg-opacity-15 border border-warning border-opacity-25',
            'permitted' => 'bg-info text-info bg-opacity-15 border border-info border-opacity-25',
            'absent'    => 'bg-danger text-danger bg-opacity-15 border border-danger border-opacity-25',
        ];
        return $classes[$status] ?? 'bg-secondary text-secondary bg-opacity-15 border';
    }
}

