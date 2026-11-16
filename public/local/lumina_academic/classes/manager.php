<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_lumina_academic;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Master Academic Manager: Academic Years, Formal Classes (Rombel), and Flexible Groups.
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    // ==========================================
    // 1. ACADEMIC YEARS & SEMESTERS
    // ==========================================

    /**
     * Create an academic year.
     *
     * @param string $name
     * @param string $code
     * @param int $semester 1 = Odd, 2 = Even
     * @param int $startdate
     * @param int $enddate
     * @param string $status planning, active, closed, archived
     * @param bool $iscurrent
     * @return int
     */
    public static function create_academic_year(
        string $name,
        string $code,
        int $semester,
        int $startdate,
        int $enddate,
        string $status = 'planning',
        bool $iscurrent = false
    ): int {
        global $DB, $USER;

        if ($DB->record_exists('lumina_academic_years', ['code' => $code])) {
            throw new \moodle_exception('error_year_exists', 'local_lumina_academic');
        }

        $record = new \stdClass();
        $record->name         = $name;
        $record->code         = clean_param($code, PARAM_ALPHANUMEXT);
        $record->semester     = ($semester === 2) ? 2 : 1;
        $record->startdate    = $startdate;
        $record->enddate      = $enddate;
        $record->status       = $status;
        $record->iscurrent    = $iscurrent ? 1 : 0;
        $record->timecreated  = time();
        $record->timemodified = time();
        $record->createdby    = (int)($USER->id ?? 0);

        if ($iscurrent) {
            // Unset current flag on other years
            $DB->set_field('lumina_academic_years', 'iscurrent', 0);
        }

        $id = (int)$DB->insert_record('lumina_academic_years', $record);
        audit_logger::log('academic_year_created', 'year', $id, null, $record, 'Created academic year ' . $code);

        return $id;
    }

    /**
     * Set a specific academic year as active/current.
     *
     * @param int $yearid
     * @return bool
     */
    public static function set_current_academic_year(int $yearid): bool {
        global $DB;

        $target = $DB->get_record('lumina_academic_years', ['id' => $yearid], '*', MUST_EXIST);

        $DB->set_field('lumina_academic_years', 'iscurrent', 0);
        $target->iscurrent = 1;
        $target->status = 'active';
        $target->timemodified = time();
        $DB->update_record('lumina_academic_years', $target);

        audit_logger::log('academic_year_activated', 'year', $yearid, null, $target, 'Set as current active year');
        return true;
    }

    /**
     * Get the active current academic year.
     *
     * @return \stdClass|null
     */
    public static function get_current_academic_year(): ?\stdClass {
        global $DB;
        $record = $DB->get_record('lumina_academic_years', ['iscurrent' => 1]);
        return $record ?: null;
    }

    /**
     * Get list of academic years.
     *
     * @param string $status Filter by status if provided
     * @return array
     */
    public static function get_academic_years(string $status = ''): array {
        global $DB;
        $params = [];
        $sql = "SELECT y.*, u.firstname, u.lastname,
                       (SELECT COUNT(*) FROM {lumina_classes} c WHERE c.academic_year_id = y.id) AS total_classes,
                       (SELECT COUNT(*) FROM {lumina_class_members} cm WHERE cm.academic_year_id = y.id AND cm.status = 'active') AS total_students
                  FROM {lumina_academic_years} y
             LEFT JOIN {user} u ON u.id = y.createdby";

        if (!empty($status)) {
            $sql .= " WHERE y.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY y.startdate DESC, y.id DESC";

        return $DB->get_records_sql($sql, $params);
    }

    // ==========================================
    // 2. FORMAL ACADEMIC CLASSES (ROMBEL)
    // ==========================================

    /**
     * Create an Academic Class (Rombel) with 1:1 mapped Moodle Cohort.
     *
     * @param int $academicyearid
     * @param string $name
     * @param string $code
     * @param int $gradelevel 10, 11, 12, etc.
     * @param string $major e.g. RPL, TKJ
     * @param int|null $homeroomteacherid
     * @return int
     */
    public static function create_class(
        int $academicyearid,
        string $name,
        string $code,
        int $gradelevel,
        string $major,
        ?int $homeroomteacherid = null
    ): int {
        global $DB, $USER;

        $cleancode = clean_param($code, PARAM_ALPHANUMEXT);
        if ($DB->record_exists('lumina_classes', ['code' => $cleancode])) {
            throw new \moodle_exception('error_class_exists', 'local_lumina_academic');
        }

        // Verify academic year exists
        $year = $DB->get_record('lumina_academic_years', ['id' => $academicyearid], '*', MUST_EXIST);

        // 1:1 Cohort Mapping
        $cohortidnumber = 'LUMINA_CLASS_' . strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $cleancode));
        $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumber]);

        if (!$cohort) {
            $newcohort = new \stdClass();
            $newcohort->contextid   = \context_system::instance()->id;
            $newcohort->name        = 'Kelas: ' . $name . ' (' . $year->code . ')';
            $newcohort->idnumber    = $cohortidnumber;
            $newcohort->description = 'Auto-generated cohort for Lumina Academic Class ' . $cleancode;
            $newcohort->component   = 'local_lumina_academic';
            $cohortid = cohort_add_cohort($newcohort);
        } else {
            $cohortid = (int)$cohort->id;
        }

        $record = new \stdClass();
        $record->academic_year_id    = $academicyearid;
        $record->name                = $name;
        $record->code                = $cleancode;
        $record->grade_level         = $gradelevel;
        $record->major               = $major;
        $record->homeroom_teacher_id = $homeroomteacherid;
        $record->cohort_id           = $cohortid;
        $record->status              = 'active';
        $record->timecreated         = time();
        $record->timemodified        = time();
        $record->createdby           = (int)($USER->id ?? 0);

        $classid = (int)$DB->insert_record('lumina_classes', $record);

        audit_logger::log('class_created', 'class', $classid, null, $record, 'Created class ' . $name . ' with cohort ' . $cohortidnumber);

        return $classid;
    }

    /**
     * Update an existing Academic Class.
     *
     * @param int $classid
     * @param array $data
     * @return bool
     */
    public static function update_class(int $classid, array $data): bool {
        global $DB;

        $class = $DB->get_record('lumina_classes', ['id' => $classid], '*', MUST_EXIST);
        $before = clone $class;

        if (isset($data['name'])) {
            $class->name = $data['name'];
        }
        if (isset($data['grade_level'])) {
            $class->grade_level = (int)$data['grade_level'];
        }
        if (isset($data['major'])) {
            $class->major = $data['major'];
        }
        if (array_key_exists('homeroom_teacher_id', $data)) {
            $class->homeroom_teacher_id = !empty($data['homeroom_teacher_id']) ? (int)$data['homeroom_teacher_id'] : null;
        }
        if (isset($data['status'])) {
            $class->status = $data['status'];
        }

        $class->timemodified = time();
        $DB->update_record('lumina_classes', $class);

        // Update cohort name if class name changed
        if (!empty($class->cohort_id) && isset($data['name'])) {
            $cohort = $DB->get_record('cohort', ['id' => $class->cohort_id]);
            if ($cohort) {
                $cohort->name = 'Kelas: ' . $class->name;
                cohort_update_cohort($cohort);
            }
        }

        audit_logger::log('class_updated', 'class', $classid, $before, $class, 'Updated class information');
        return true;
    }

    /**
     * Get class by ID with relations.
     *
     * @param int $classid
     * @return \stdClass|null
     */
    public static function get_class(int $classid): ?\stdClass {
        global $DB;

        $sql = "SELECT c.*, y.name AS year_name, y.code AS year_code, y.semester,
                       u.firstname AS homeroom_firstname, u.lastname AS homeroom_lastname, u.email AS homeroom_email,
                       co.idnumber AS cohort_idnumber,
                       (SELECT COUNT(*) FROM {lumina_class_members} cm WHERE cm.class_id = c.id AND cm.status = 'active') AS total_students,
                       (SELECT COUNT(*) FROM {lumina_class_courses} cc WHERE cc.class_id = c.id AND cc.status = 'active') AS total_courses
                  FROM {lumina_classes} c
                  JOIN {lumina_academic_years} y ON y.id = c.academic_year_id
             LEFT JOIN {user} u ON u.id = c.homeroom_teacher_id
             LEFT JOIN {cohort} co ON co.id = c.cohort_id
                 WHERE c.id = :id";

        $record = $DB->get_record_sql($sql, ['id' => $classid]);
        return $record ?: null;
    }

    /**
     * Get classes with optional filters.
     *
     * @param int|null $academicyearid
     * @param int $gradelevel
     * @param string $major
     * @return array
     */
    public static function get_classes(?int $academicyearid = null, int $gradelevel = 0, string $major = ''): array {
        global $DB;

        $params = [];
        $where = [];

        if ($academicyearid !== null) {
            $where[] = "c.academic_year_id = :yearid";
            $params['yearid'] = $academicyearid;
        }
        if ($gradelevel > 0) {
            $where[] = "c.grade_level = :gradelevel";
            $params['gradelevel'] = $gradelevel;
        }
        if (!empty($major)) {
            $where[] = "c.major = :major";
            $params['major'] = $major;
        }

        $sql = "SELECT c.*, y.name AS year_name, y.code AS year_code,
                       u.firstname AS homeroom_firstname, u.lastname AS homeroom_lastname,
                       (SELECT COUNT(*) FROM {lumina_class_members} cm WHERE cm.class_id = c.id AND cm.status = 'active') AS total_students,
                       (SELECT COUNT(*) FROM {lumina_class_courses} cc WHERE cc.class_id = c.id AND cc.status = 'active') AS total_courses
                  FROM {lumina_classes} c
                  JOIN {lumina_academic_years} y ON y.id = c.academic_year_id
             LEFT JOIN {user} u ON u.id = c.homeroom_teacher_id";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY c.grade_level ASC, c.name ASC";

        return $DB->get_records_sql($sql, $params);
    }

    // ==========================================
    // 3. CLASS MEMBERSHIP & STUDENT ASSIGNMENT
    // ==========================================

    /**
     * Add student to a formal class.
     * Enforces the rule: 1 student can only have 1 active formal class membership in an academic year.
     * Synchronizes student into the mapped Moodle cohort automatically.
     *
     * @param int $classid
     * @param int $userid
     * @return bool
     */
    public static function add_student_to_class(int $classid, int $userid): bool {
        global $DB;

        $class = $DB->get_record('lumina_classes', ['id' => $classid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

        // Check if student already has an active class membership in this academic year
        $existing = $DB->get_record('lumina_class_members', [
            'userid'           => $userid,
            'academic_year_id' => $class->academic_year_id,
            'status'           => 'active',
        ]);

        if ($existing) {
            if ($existing->class_id == $classid) {
                // Already in this class
                return true;
            }
            // Active in another class in the same year!
            throw new \moodle_exception('error_student_already_active', 'local_lumina_academic');
        }

        // Insert membership
        $member = new \stdClass();
        $member->class_id         = $classid;
        $member->userid           = $userid;
        $member->academic_year_id = $class->academic_year_id;
        $member->status           = 'active';
        $member->enrollment_date  = time();
        $member->timecreated      = time();
        $member->timemodified     = time();
        $DB->insert_record('lumina_class_members', $member);

        // Synchronize Moodle cohort
        if (!empty($class->cohort_id)) {
            if (!cohort_is_member($class->cohort_id, $userid)) {
                cohort_add_member($class->cohort_id, $userid);
            }
        }

        // Ensure student profile exists and update current level/major
        self::get_or_create_student_profile($userid, [
            'current_major' => $class->major,
            'current_level' => $class->grade_level,
        ]);

        audit_logger::log('student_enrolled_class', 'class', $classid, null, [
            'userid'  => $userid,
            'classid' => $classid,
        ], 'Enrolled student into class ' . $class->name);

        return true;
    }

    /**
     * Remove student from class.
     *
     * @param int $classid
     * @param int $userid
     * @param string $reason
     * @param string $newstatus transferred, dropped, etc.
     * @return bool
     */
    public static function remove_student_from_class(
        int $classid,
        int $userid,
        string $reason = 'Manual Removal',
        string $newstatus = 'transferred'
    ): bool {
        global $DB;

        $class = $DB->get_record('lumina_classes', ['id' => $classid], '*', MUST_EXIST);

        $membership = $DB->get_record('lumina_class_members', [
            'class_id' => $classid,
            'userid'   => $userid,
            'status'   => 'active',
        ]);

        if (!$membership) {
            return false;
        }

        $before = clone $membership;
        $membership->status      = $newstatus;
        $membership->exit_date   = time();
        $membership->exit_reason = $reason;
        $membership->timemodified = time();
        $DB->update_record('lumina_class_members', $membership);

        // Remove from cohort
        if (!empty($class->cohort_id)) {
            if (cohort_is_member($class->cohort_id, $userid)) {
                cohort_remove_member($class->cohort_id, $userid);
            }
        }

        audit_logger::log('student_removed_class', 'class', $classid, $before, $membership, $reason);
        return true;
    }

    /**
     * Get students enrolled in a class.
     *
     * @param int $classid
     * @param string $status
     * @return array
     */
    public static function get_class_students(int $classid, string $status = 'active'): array {
        global $DB;

        $sql = "SELECT cm.id AS membership_id, cm.status AS membership_status, cm.enrollment_date, cm.exit_date,
                       u.id AS userid, u.username, u.firstname, u.lastname, u.email,
                       p.nis, p.nisn, p.current_major, p.current_level
                  FROM {lumina_class_members} cm
                  JOIN {user} u ON u.id = cm.userid
             LEFT JOIN {lumina_student_profiles} p ON p.userid = u.id
                 WHERE cm.class_id = :classid AND cm.status = :status AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";

        return $DB->get_records_sql($sql, ['classid' => $classid, 'status' => $status]);
    }

    /**
     * Get available students who are not yet placed in any active class for the specified academic year.
     *
     * @param int $academicyearid
     * @param string $search
     * @return array
     */
    public static function get_available_students_for_year(int $academicyearid, string $search = ''): array {
        global $DB;

        $params = ['yearid' => $academicyearid];
        $searchwhere = '';

        if (!empty($search)) {
            $searchwhere = " AND (" . $DB->sql_like('u.firstname', ':s1', false) .
                           " OR " . $DB->sql_like('u.lastname', ':s2', false) .
                           " OR " . $DB->sql_like('u.username', ':s3', false) .
                           " OR " . $DB->sql_like('p.nis', ':s4', false) . ")";
            $params['s1'] = '%' . $search . '%';
            $params['s2'] = '%' . $search . '%';
            $params['s3'] = '%' . $search . '%';
            $params['s4'] = '%' . $search . '%';
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email,
                       p.nis, p.nisn, p.current_major, p.current_level
                  FROM {user} u
             LEFT JOIN {lumina_student_profiles} p ON p.userid = u.id
                 WHERE u.deleted = 0
                   AND u.id NOT IN (
                       SELECT cm.userid
                         FROM {lumina_class_members} cm
                        WHERE cm.academic_year_id = :yearid AND cm.status = 'active'
                   )
                   AND u.id NOT IN (1, 2) -- Skip guest and site main admin
                   {$searchwhere}
              ORDER BY u.lastname ASC, u.firstname ASC";

        return $DB->get_records_sql($sql, $params, 0, 100);
    }

    // ==========================================
    // 4. FLEXIBLE GROUPS (REMEDIAL, OLYMPIAD, ETC.)
    // ==========================================

    /**
     * Create a flexible non-formal group.
     *
     * @param string $name
     * @param string $code
     * @param string $grouptype remedial, competition, project, extracurricular
     * @param int|null $mentorid
     * @return int
     */
    public static function create_flexible_group(
        string $name,
        string $code,
        string $grouptype = 'project',
        ?int $mentorid = null
    ): int {
        global $DB;

        $cleancode = clean_param($code, PARAM_ALPHANUMEXT);

        $record = new \stdClass();
        $record->name         = $name;
        $record->code         = $cleancode;
        $record->group_type   = $grouptype;
        $record->mentor_id    = $mentorid;
        $record->status       = 'active';
        $record->timecreated  = time();
        $record->timemodified = time();

        $groupid = (int)$DB->insert_record('lumina_flexible_groups', $record);
        audit_logger::log('flexible_group_created', 'group', $groupid, null, $record, 'Created group ' . $name);

        return $groupid;
    }

    /**
     * Add student to flexible group.
     * Note: 1 student can join multiple flexible groups freely.
     *
     * @param int $groupid
     * @param int $userid
     * @param string $role leader, member
     * @return bool
     */
    public static function add_student_to_flexible_group(int $groupid, int $userid, string $role = 'member'): bool {
        global $DB;

        if ($DB->record_exists('lumina_flexible_members', ['group_id' => $groupid, 'userid' => $userid])) {
            return true;
        }

        $record = new \stdClass();
        $record->group_id      = $groupid;
        $record->userid        = $userid;
        $record->role_in_group = $role;
        $record->status        = 'active';
        $record->timecreated   = time();
        $record->timemodified  = time();

        $DB->insert_record('lumina_flexible_members', $record);
        return true;
    }

    /**
     * Remove student from flexible group.
     * Does NOT affect formal class enrollment or course access granted by other sources.
     *
     * @param int $groupid
     * @param int $userid
     * @return bool
     */
    public static function remove_student_from_flexible_group(int $groupid, int $userid): bool {
        global $DB;
        $DB->delete_records('lumina_flexible_members', ['group_id' => $groupid, 'userid' => $userid]);
        return true;
    }

    /**
     * Get flexible groups.
     *
     * @param string $grouptype
     * @return array
     */
    public static function get_flexible_groups(string $grouptype = ''): array {
        global $DB;

        $params = [];
        $where = "";

        if (!empty($grouptype)) {
            $where = "WHERE g.group_type = :grouptype";
            $params['grouptype'] = $grouptype;
        }

        $sql = "SELECT g.*, u.firstname AS mentor_firstname, u.lastname AS mentor_lastname,
                       (SELECT COUNT(*) FROM {lumina_flexible_members} fm WHERE fm.group_id = g.id AND fm.status = 'active') AS total_members
                  FROM {lumina_flexible_groups} g
             LEFT JOIN {user} u ON u.id = g.mentor_id
                  {$where}
              ORDER BY g.name ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get members of a flexible group.
     *
     * @param int $groupid
     * @return array
     */
    public static function get_flexible_group_members(int $groupid): array {
        global $DB;

        $sql = "SELECT fm.id AS membership_id, fm.role_in_group, fm.timecreated AS joined_date,
                       u.id AS userid, u.username, u.firstname, u.lastname, u.email,
                       p.nis, p.current_major, p.current_level
                  FROM {lumina_flexible_members} fm
                  JOIN {user} u ON u.id = fm.userid
             LEFT JOIN {lumina_student_profiles} p ON p.userid = u.id
                 WHERE fm.group_id = :groupid AND fm.status = 'active' AND u.deleted = 0
              ORDER BY fm.role_in_group ASC, u.lastname ASC";

        return $DB->get_records_sql($sql, ['groupid' => $groupid]);
    }

    // ==========================================
    // 5. STUDENT PROFILES & HELPERS
    // ==========================================

    /**
     * Get or create a permanent student academic profile.
     *
     * @param int $userid
     * @param array $initialdata
     * @return \stdClass
     */
    public static function get_or_create_student_profile(int $userid, array $initialdata = []): \stdClass {
        global $DB;

        $profile = $DB->get_record('lumina_student_profiles', ['userid' => $userid]);

        if ($profile) {
            $updated = false;
            foreach ($initialdata as $k => $v) {
                if (isset($profile->$k) && $profile->$k != $v) {
                    $profile->$k = $v;
                    $updated = true;
                }
            }
            if ($updated) {
                $profile->timemodified = time();
                $DB->update_record('lumina_student_profiles', $profile);
            }
            return $profile;
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

        $newprofile = new \stdClass();
        $newprofile->userid             = $userid;
        $newprofile->nis                = $initialdata['nis'] ?? ('NIS' . str_pad((string)$userid, 5, '0', STR_PAD_LEFT));
        $newprofile->nisn               = $initialdata['nisn'] ?? null;
        $newprofile->entry_year         = $initialdata['entry_year'] ?? (int)date('Y');
        $newprofile->expected_grad_year = $initialdata['expected_grad_year'] ?? ((int)date('Y') + 3);
        $newprofile->current_major      = $initialdata['current_major'] ?? 'Umum';
        $newprofile->current_level      = $initialdata['current_level'] ?? 10;
        $newprofile->academic_status    = 'active';
        $newprofile->timecreated        = time();
        $newprofile->timemodified       = time();

        $newprofile->id = (int)$DB->insert_record('lumina_student_profiles', $newprofile);
        return $newprofile;
    }
}

