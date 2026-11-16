<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_lumina_academic;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/enrol/locallib.php');
require_once($CFG->dirroot . '/enrol/cohort/locallib.php');

/**
 * Course Assignment Engine: Links Academic Classes to Moodle Courses via Cohort Sync.
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_engine {

    /**
     * Assign a Moodle course to an Academic Class.
     * Automatically ensures cohort enrolment instance exists in the course and triggers sync.
     *
     * @param int $classid
     * @param int $courseid
     * @param int|null $teacherid
     * @param int $semester
     * @param int $ismandatory
     * @return int ID of the lumina_class_courses record
     */
    public static function assign_course_to_class(
        int $classid,
        int $courseid,
        ?int $teacherid = null,
        int $semester = 1,
        int $ismandatory = 1
    ): int {
        global $DB, $CFG;

        $class = $DB->get_record('lumina_classes', ['id' => $classid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Check if class already assigned to this course
        $existing = $DB->get_record('lumina_class_courses', [
            'class_id'  => $classid,
            'course_id' => $courseid,
        ]);

        $enrolinstanceid = null;

        // Check or create enrol_cohort instance for the course
        if (!empty($class->cohort_id)) {
            $instance = $DB->get_record('enrol', [
                'enrol'      => 'cohort',
                'courseid'   => $courseid,
                'customint1' => $class->cohort_id,
            ]);

            if (!$instance) {
                $enrolplugin = enrol_get_plugin('cohort');
                $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
                if ($enrolplugin) {
                    $fields = [
                        'customint1' => $class->cohort_id,
                        'roleid'     => $studentroleid ?: 5,
                        'status'     => ENROL_INSTANCE_ENABLED,
                    ];
                    $enrolinstanceid = $enrolplugin->add_instance($course, $fields);
                }
            } else {
                $enrolinstanceid = (int)$instance->id;
                if ($instance->status != ENROL_INSTANCE_ENABLED) {
                    $enrolplugin = enrol_get_plugin('cohort');
                    if ($enrolplugin) {
                        $enrolplugin->update_status($instance, ENROL_INSTANCE_ENABLED);
                    }
                }
            }

            // Trigger cohort sync for this course to immediately populate enrolled students
            $trace = new \null_progress_trace();
            enrol_cohort_sync($trace, $courseid);
            $trace->finished();
        }

        // Assign subject teacher if provided
        if (!empty($teacherid)) {
            $context = \context_course::instance($courseid);
            $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']) ?: 3;
            if (!user_has_role_assignment($teacherid, $editingteacherroleid, $context->id)) {
                role_assign($editingteacherroleid, $teacherid, $context->id);
            }
        }

        if ($existing) {
            $existing->teacher_id        = $teacherid;
            $existing->enrol_instance_id = $enrolinstanceid ?: $existing->enrol_instance_id;
            $existing->semester          = $semester;
            $existing->is_mandatory      = $ismandatory;
            $existing->status            = 'active';
            $existing->timemodified      = time();
            $DB->update_record('lumina_class_courses', $existing);
            $recordid = (int)$existing->id;
        } else {
            $record = new \stdClass();
            $record->class_id          = $classid;
            $record->course_id         = $courseid;
            $record->teacher_id        = $teacherid;
            $record->enrol_instance_id = $enrolinstanceid;
            $record->semester          = $semester;
            $record->is_mandatory      = $ismandatory;
            $record->status            = 'active';
            $record->timecreated       = time();
            $record->timemodified      = time();
            $recordid = (int)$DB->insert_record('lumina_class_courses', $record);
        }

        audit_logger::log('course_assigned_class', 'class', $classid, null, [
            'courseid'  => $courseid,
            'teacherid' => $teacherid,
        ], 'Assigned course ' . $course->fullname . ' to class ' . $class->name);

        return $recordid;
    }

    /**
     * Bulk assign multiple courses to an Academic Class.
     *
     * @param int $classid
     * @param array $courseids
     * @param int|null $teacherid
     * @param int $semester
     * @return array Array of result records
     */
    public static function assign_courses_bulk(
        int $classid,
        array $courseids,
        ?int $teacherid = null,
        int $semester = 1
    ): array {
        $results = [];
        foreach ($courseids as $cid) {
            $cid = (int)$cid;
            if ($cid > 1) { // Skip site frontpage course (id 1)
                $results[$cid] = self::assign_course_to_class($classid, $cid, $teacherid, $semester);
            }
        }
        return $results;
    }

    /**
     * Unassign course from class.
     *
     * @param int $classid
     * @param int $courseid
     * @return bool
     */
    public static function unassign_course_from_class(int $classid, int $courseid): bool {
        global $DB;

        $link = $DB->get_record('lumina_class_courses', [
            'class_id'  => $classid,
            'course_id' => $courseid,
        ]);

        if (!$link) {
            return false;
        }

        $class = $DB->get_record('lumina_classes', ['id' => $classid]);

        // Disable or remove the enrol instance for this cohort if exists
        if (!empty($link->enrol_instance_id)) {
            $instance = $DB->get_record('enrol', ['id' => $link->enrol_instance_id]);
            if ($instance) {
                $enrolplugin = enrol_get_plugin('cohort');
                if ($enrolplugin) {
                    $enrolplugin->delete_instance($instance);
                }
            }
        }

        $DB->delete_records('lumina_class_courses', ['id' => $link->id]);

        audit_logger::log('course_unassigned_class', 'class', $classid, $link, null, 'Unassigned course ' . $courseid);
        return true;
    }

    /**
     * Get all assigned courses for a class.
     *
     * @param int $classid
     * @return array
     */
    public static function get_assigned_courses(int $classid): array {
        global $DB;

        $sql = "SELECT cc.*, c.fullname AS course_name, c.shortname AS course_code, c.idnumber,
                       u.firstname AS teacher_firstname, u.lastname AS teacher_lastname, u.email AS teacher_email,
                       (SELECT COUNT(DISTINCT ue.userid)
                          FROM {user_enrolments} ue
                         WHERE ue.enrolid = cc.enrol_instance_id AND ue.status = 0) AS total_enrolled
                  FROM {lumina_class_courses} cc
                  JOIN {course} c ON c.id = cc.course_id
             LEFT JOIN {user} u ON u.id = cc.teacher_id
                 WHERE cc.class_id = :classid AND cc.status = 'active'
              ORDER BY cc.semester ASC, c.fullname ASC";

        return $DB->get_records_sql($sql, ['classid' => $classid]);
    }

    /**
     * Get courses available to be assigned to this class.
     *
     * @param int $classid
     * @param string $search
     * @return array
     */
    public static function get_available_courses(int $classid, string $search = ''): array {
        global $DB;

        $params = ['classid' => $classid];
        $searchwhere = '';

        if (!empty($search)) {
            $searchwhere = " AND (" . $DB->sql_like('c.fullname', ':s1', false) .
                           " OR " . $DB->sql_like('c.shortname', ':s2', false) . ")";
            $params['s1'] = '%' . $search . '%';
            $params['s2'] = '%' . $search . '%';
        }

        $sql = "SELECT c.id, c.fullname, c.shortname, c.category, cat.name AS category_name
                  FROM {course} c
                  JOIN {course_categories} cat ON cat.id = c.category
                 WHERE c.id > 1 AND c.visible = 1
                   AND c.id NOT IN (
                       SELECT cc.course_id
                         FROM {lumina_class_courses} cc
                        WHERE cc.class_id = :classid AND cc.status = 'active'
                   )
                   {$searchwhere}
              ORDER BY cat.name ASC, c.fullname ASC";

        return $DB->get_records_sql($sql, $params, 0, 100);
    }
}

