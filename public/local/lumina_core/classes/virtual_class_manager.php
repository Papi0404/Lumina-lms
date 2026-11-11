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
 * Lumina LMS Virtual Class Manager: Zoom & Google Meet Integration.
 *
 * Provides course-isolated virtual meeting management for teachers and students.
 * Enrolled students see active meeting links only for their courses.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

class virtual_class_manager {

    const PLUGIN_NAME = 'local_lumina_core';

    /**
     * Detect video conferencing platform from URL.
     *
     * @param string $url
     * @return array [name, icon, badge_class]
     */
    public static function detect_platform(string $url): array {
        $low = strtolower($url);
        if (strpos($low, 'meet.google.com') !== false) {
            return [
                'platform'    => 'Google Meet',
                'icon'        => 'video_camera_front',
                'badge_class' => 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25',
                'brand_color' => '#EA4335',
            ];
        } else if (strpos($low, 'zoom.us') !== false || strpos($low, 'zoom.com') !== false) {
            return [
                'platform'    => 'Zoom Meeting',
                'icon'        => 'videocam',
                'badge_class' => 'bg-primary bg-opacity-10 text-primary border-primary border-opacity-25',
                'brand_color' => '#2D8CFF',
            ];
        } else if (strpos($low, 'teams.microsoft.com') !== false || strpos($low, 'teams.live.com') !== false) {
            return [
                'platform'    => 'Microsoft Teams',
                'icon'        => 'groups',
                'badge_class' => 'bg-indigo bg-opacity-10 text-indigo border-indigo border-opacity-25',
                'brand_color' => '#5059C9',
            ];
        } else {
            return [
                'platform'    => 'Kelas Daring (Tatap Muka)',
                'icon'        => 'videocam',
                'badge_class' => 'bg-success bg-opacity-10 text-success border-success border-opacity-25',
                'brand_color' => '#10B981',
            ];
        }
    }

    /**
     * Get meeting configuration for a course.
     *
     * @param int $courseid
     * @return array|null
     */
    public static function get_course_meeting(int $courseid): ?array {
        global $DB;

        $url = get_config(self::PLUGIN_NAME, 'meet_url_' . $courseid);
        if (empty($url) || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $title = get_config(self::PLUGIN_NAME, 'meet_title_' . $courseid);
        $time  = (int)get_config(self::PLUGIN_NAME, 'meet_time_' . $courseid);
        $by    = (int)get_config(self::PLUGIN_NAME, 'meet_by_' . $courseid);

        $teachername = '';
        if ($by > 0) {
            $tuser = $DB->get_record('user', ['id' => $by], 'id, firstname, lastname');
            if ($tuser) {
                $teachername = fullname($tuser);
            }
        }

        $platforminfo = self::detect_platform($url);

        return array_merge($platforminfo, [
            'courseid'     => $courseid,
            'url'          => $url,
            'title'        => $title ?: 'Sesi Kelas Tatap Muka Virtual',
            'timemodified' => $time ?: time(),
            'updated_ago'  => $time ? self::time_ago($time) : 'Baru saja',
            'teacher_name' => $teachername,
            'is_active'    => true,
        ]);
    }

    /**
     * Save or update meeting configuration for a course.
     *
     * @param int $courseid
     * @param string $url
     * @param string $title
     * @param int $userid
     * @return bool
     */
    public static function set_course_meeting(int $courseid, string $url, string $title = '', int $userid = 0): bool {
        $url = trim($url);
        if (empty($url)) {
            return self::clear_course_meeting($courseid);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        set_config('meet_url_' . $courseid, $url, self::PLUGIN_NAME);
        set_config('meet_title_' . $courseid, trim($title), self::PLUGIN_NAME);
        set_config('meet_time_' . $courseid, time(), self::PLUGIN_NAME);
        if ($userid > 0) {
            set_config('meet_by_' . $courseid, $userid, self::PLUGIN_NAME);
        }

        return true;
    }

    /**
     * Clear / end meeting for a course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function clear_course_meeting(int $courseid): bool {
        unset_config('meet_url_' . $courseid, self::PLUGIN_NAME);
        unset_config('meet_title_' . $courseid, self::PLUGIN_NAME);
        unset_config('meet_time_' . $courseid, self::PLUGIN_NAME);
        unset_config('meet_by_' . $courseid, self::PLUGIN_NAME);
        return true;
    }

    /**
     * Fetch active meetings for courses a student is enrolled in.
     * Strict isolation: only returns meetings for courses where user has student archetype.
     *
     * @param int $userid
     * @return array
     */
    public static function get_student_active_meetings(int $userid): array {
        global $DB;

        $meetings = [];

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = ? AND r.archetype = 'student' AND c.id > 1
              ORDER BY c.fullname ASC";
        $courses = $DB->get_records_sql($sql, [$userid]);

        foreach ($courses as $c) {
            $meet = self::get_course_meeting($c->id);
            if ($meet) {
                $meet['course_fullname']  = format_string($c->fullname);
                $meet['course_shortname'] = format_string($c->shortname);
                $meetings[] = $meet;
            }
        }

        return $meetings;
    }

    /**
     * Fetch meeting status for all courses a teacher instructs.
     *
     * @param int $userid
     * @return array
     */
    public static function get_teacher_course_meetings(int $userid): array {
        global $DB;

        $results = [];

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = ? AND r.archetype IN ('editingteacher', 'teacher', 'manager') AND c.id > 1
              ORDER BY c.fullname ASC";
        $courses = $DB->get_records_sql($sql, [$userid]);

        if (empty($courses) && is_siteadmin($userid)) {
            $courses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC', 'id, fullname, shortname', 0, 10);
        }

        foreach ($courses as $c) {
            $meet = self::get_course_meeting($c->id);

            // Student count for context
            $countsql = "SELECT COUNT(DISTINCT ra.userid)
                           FROM {role_assignments} ra
                           JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                           JOIN {role} r ON r.id = ra.roleid
                          WHERE ctx.instanceid = ? AND r.archetype = 'student'";
            $studentcount = (int)$DB->count_records_sql($countsql, [$c->id]);

            $results[] = [
                'courseid'         => $c->id,
                'course_fullname'  => format_string($c->fullname),
                'course_shortname' => format_string($c->shortname),
                'students_count'   => $studentcount,
                'has_meeting'      => !empty($meet),
                'meeting'          => $meet,
                'meet_url'         => $meet['url'] ?? '',
                'meet_title'       => $meet['title'] ?? '',
                'platform'         => $meet['platform'] ?? '',
                'icon'             => $meet['icon'] ?? 'videocam',
                'badge_class'      => $meet['badge_class'] ?? '',
                'updated_ago'      => $meet['updated_ago'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Format timestamp to human readable Indonesian time ago.
     *
     * @param int $timestamp
     * @return string
     */
    private static function time_ago(int $timestamp): string {
        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'Baru saja';
        } else if ($diff < 3600) {
            return floor($diff / 60) . ' mnt lalu';
        } else if ($diff < 86400) {
            return floor($diff / 3600) . ' jam lalu';
        } else {
            return floor($diff / 86400) . ' hari lalu';
        }
    }
}

