<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_lumina_academic;

defined('MOODLE_INTERNAL') || die();

/**
 * Permanent Immutable Grade Snapshot Engine ("Buku Induk Digital" / Alumni Vault).
 * Preserves student academic records permanently, completely immune to course resets and deletions.
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alumni_vault {

    /**
     * Freeze student grades and create an immutable transcript vault record.
     *
     * @param int $userid
     * @param int|null $gradyear
     * @param int|null $issuedby
     * @return \stdClass Created transcript record
     */
    public static function freeze_student_grades(int $userid, ?int $gradyear = null, ?int $issuedby = null): \stdClass {
        global $DB, $USER, $CFG;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $profile = manager::get_or_create_student_profile($userid);

        // Check if an active finalized transcript already exists for this user
        $existing = $DB->get_record('lumina_transcripts', [
            'userid'             => $userid,
            'is_current_version' => 1,
            'status'             => 'finalized',
        ]);

        if ($existing) {
            return $existing;
        }

        // 1. Fetch all course grades from Moodle Gradebook
        $sql = "SELECT gg.finalgrade, gg.rawgrade, gi.grademax, gi.grademin,
                       c.id AS courseid, c.fullname AS course_name, c.shortname AS course_code,
                       (SELECT cc.timecompleted FROM {course_completions} cc WHERE cc.course = c.id AND cc.userid = :u1) AS timecompleted,
                       (SELECT u2.firstname FROM {role_assignments} ra
                          JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = c.id
                          JOIN {user} u2 ON u2.id = ra.userid
                         WHERE ra.roleid IN (3, 4) LIMIT 1) AS teacher_name
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                  JOIN {course} c ON c.id = gi.courseid
                 WHERE gg.userid = :u2 AND c.id > 1
              ORDER BY c.fullname ASC";

        $grades = $DB->get_records_sql($sql, ['u1' => $userid, 'u2' => $userid]);

        // 2. Fetch student historical class memberships to determine periods
        $memberships = $DB->get_records_sql(
            "SELECT cm.*, c.grade_level, y.name AS year_name, y.semester
               FROM {lumina_class_members} cm
               JOIN {lumina_classes} c ON c.id = cm.class_id
               JOIN {lumina_academic_years} y ON y.id = cm.academic_year_id
              WHERE cm.userid = :userid
           ORDER BY cm.enrollment_date ASC",
            ['userid' => $userid]
        );

        $primarymembership = !empty($memberships) ? end($memberships) : null;
        $academicperiod = $primarymembership ? ($primarymembership->year_name . ' S' . $primarymembership->semester) : ('T.A. ' . date('Y'));
        $gradelevel = $primarymembership ? (int)$primarymembership->grade_level : 12;

        // Process items and calculate cumulative GPA
        $itemsdata = [];
        $totalscore = 0;
        $coursecount = 0;

        foreach ($grades as $g) {
            $max = ($g->grademax > 0) ? (float)$g->grademax : 100.00;
            $raw = ($g->finalgrade !== null) ? (float)$g->finalgrade : (($g->rawgrade !== null) ? (float)$g->rawgrade : 0.00);

            // Normalized to 0 - 100 scale
            $normalizedgrade = round(($raw / $max) * 100, 2);

            // Letter grade & predicate
            if ($normalizedgrade >= 88.00) {
                $letter = 'A';
                $predicate = 'Sangat Baik (Sangat Kompeten)';
            } elseif ($normalizedgrade >= 78.00) {
                $letter = 'B';
                $predicate = 'Baik (Kompeten)';
            } elseif ($normalizedgrade >= 65.00) {
                $letter = 'C';
                $predicate = 'Cukup (Cukup Kompeten)';
            } else {
                $letter = 'D';
                $predicate = 'Kurang';
            }

            $itemsdata[] = [
                'course_id'       => (int)$g->courseid,
                'course_code'     => (string)$g->course_code,
                'course_name'     => (string)$g->course_name,
                'academic_period' => (string)$academicperiod,
                'semester_num'    => ($primarymembership ? (int)$primarymembership->semester : 1),
                'grade_level'     => (int)$gradelevel,
                'teacher_name'    => (string)($g->teacher_name ?? '-'),
                'raw_grade'       => number_format($normalizedgrade, 2, '.', ''),
                'letter_grade'    => (string)$letter,
                'predicate'       => (string)$predicate,
                'is_completed'    => !empty($g->timecompleted) ? 1 : 1,
            ];

            $totalscore += $normalizedgrade;
            $coursecount++;
        }

        $cumulativegpa = ($coursecount > 0) ? round($totalscore / $coursecount, 2) : 0.00;
        $gpaformatted  = number_format((float)$cumulativegpa, 2, '.', '');

        // Determine final overall predicate
        if ($cumulativegpa >= 90.00) {
            $finalpredicate = 'Dengan Pujian (Cum Laude)';
        } elseif ($cumulativegpa >= 80.00) {
            $finalpredicate = 'Sangat Memuaskan';
        } elseif ($cumulativegpa >= 70.00) {
            $finalpredicate = 'Memuaskan';
        } else {
            $finalpredicate = 'Cukup';
        }

        $uuid = \core\uuid::generate();
        $gradyearfinal = $gradyear ?: ($profile->expected_grad_year ?: (int)date('Y'));

        // Generate SHA-256 Anti-Tamper Integrity Hash
        $hashtarget = $userid . '|' . $profile->nis . '|' . ($profile->nisn ?? '') . '|' .
                      $gpaformatted . '|' . json_encode($itemsdata, JSON_UNESCAPED_UNICODE) . '|' .
                      ($CFG->passwordsaltmain ?? 'LUMINA_VAULT_SALT');
        $integrityhash = hash('sha256', $hashtarget);

        // Insert Master Transcript
        $transcript = new \stdClass();
        $transcript->transcript_uuid    = $uuid;
        $transcript->userid             = $userid;
        $transcript->student_nis        = $profile->nis;
        $transcript->student_nisn       = $profile->nisn;
        $transcript->student_name       = fullname($user);
        $transcript->major              = $profile->current_major;
        $transcript->entry_year         = (int)$profile->entry_year;
        $transcript->grad_year          = $gradyearfinal;
        $transcript->cumulative_gpa     = $cumulativegpa;
        $transcript->final_predicate    = $finalpredicate;
        $transcript->version            = 1;
        $transcript->is_current_version = 1;
        $transcript->integrity_hash     = $integrityhash;
        $transcript->status             = 'finalized';
        $transcript->issued_by          = $issuedby ?: (!empty($USER->id) ? (int)$USER->id : 2);
        $transcript->timefinalized      = time();

        $transcriptid = (int)$DB->insert_record('lumina_transcripts', $transcript);
        $transcript->id = $transcriptid;

        // Insert items
        foreach ($itemsdata as $item) {
            $itemrecord = (object)$item;
            $itemrecord->transcript_id = $transcriptid;
            $DB->insert_record('lumina_transcript_items', $itemrecord);
        }

        // Record version 1 in transcript versions
        $version = new \stdClass();
        $version->transcript_id  = $transcriptid;
        $version->version_number = 1;
        $version->reason         = 'Initial graduation grade vault finalization';
        $version->modified_by    = $transcript->issued_by;
        $version->snapshot_json  = json_encode($itemsdata, JSON_UNESCAPED_UNICODE);
        $version->previous_hash  = 'NONE';
        $version->new_hash       = $integrityhash;
        $version->timecreated    = time();
        $DB->insert_record('lumina_transcript_versions', $version);

        // Setup Alumni Access record
        if (!$DB->record_exists('lumina_alumni_access', ['userid' => $userid])) {
            $alumniaccess = new \stdClass();
            $alumniaccess->userid            = $userid;
            $alumniaccess->access_mode       = 'vault_only';
            $alumniaccess->suspension_reason = null;
            $alumniaccess->last_login        = null;
            $alumniaccess->timecreated       = time();
            $alumniaccess->timemodified      = time();
            $DB->insert_record('lumina_alumni_access', $alumniaccess);
        }

        audit_logger::log('transcript_vault_frozen', 'transcript', $transcriptid, null, [
            'uuid'          => $uuid,
            'userid'        => $userid,
            'gpa'           => $cumulativegpa,
            'total_courses' => $coursecount,
        ], 'Permanently froze grades in Alumni Vault');

        return $transcript;
    }

    /**
     * Verify transcript integrity against database tampering.
     *
     * @param string $uuid
     * @return array
     */
    public static function verify_transcript_integrity(string $uuid): array {
        global $DB, $CFG;

        $transcript = $DB->get_record('lumina_transcripts', ['transcript_uuid' => $uuid], '*', MUST_EXIST);
        $items = $DB->get_records('lumina_transcript_items', ['transcript_id' => $transcript->id], 'id ASC');

        $reconstructed = [];
        foreach ($items as $it) {
            $reconstructed[] = [
                'course_id'       => (int)$it->course_id,
                'course_code'     => (string)$it->course_code,
                'course_name'     => (string)$it->course_name,
                'academic_period' => (string)$it->academic_period,
                'semester_num'    => (int)$it->semester_num,
                'grade_level'     => (int)$it->grade_level,
                'teacher_name'    => (string)($it->teacher_name ?? '-'),
                'raw_grade'       => number_format((float)$it->raw_grade, 2, '.', ''),
                'letter_grade'    => (string)$it->letter_grade,
                'predicate'       => (string)$it->predicate,
                'is_completed'    => (int)$it->is_completed,
            ];
        }

        $gpaformatted = number_format((float)$transcript->cumulative_gpa, 2, '.', '');
        $hashtarget = $transcript->userid . '|' . $transcript->student_nis . '|' . ($transcript->student_nisn ?? '') . '|' .
                      $gpaformatted . '|' . json_encode($reconstructed, JSON_UNESCAPED_UNICODE) . '|' .
                      ($CFG->passwordsaltmain ?? 'LUMINA_VAULT_SALT');
        $computedhash = hash('sha256', $hashtarget);

        $isvalid = ($computedhash === $transcript->integrity_hash);

        return [
            'is_valid'      => $isvalid,
            'stored_hash'   => $transcript->integrity_hash,
            'computed_hash' => $computedhash,
            'tampered'      => !$isvalid,
            'transcript'    => $transcript,
            'items_count'   => count($items),
        ];
    }

    /**
     * Search alumni records in the Vault.
     *
     * @param string $query
     * @param int|null $gradyear
     * @param string $major
     * @return array
     */
    public static function search_alumni(string $query = '', ?int $gradyear = null, string $major = ''): array {
        global $DB;

        $params = [];
        $where = ["t.is_current_version = 1"];

        if (!empty($query)) {
            $where[] = "(" . $DB->sql_like('t.student_name', ':q1', false) .
                       " OR " . $DB->sql_like('t.student_nis', ':q2', false) .
                       " OR " . $DB->sql_like('t.student_nisn', ':q3', false) .
                       " OR " . $DB->sql_like('t.transcript_uuid', ':q4', false) . ")";
            $params['q1'] = '%' . $query . '%';
            $params['q2'] = '%' . $query . '%';
            $params['q3'] = '%' . $query . '%';
            $params['q4'] = '%' . $query . '%';
        }

        if ($gradyear !== null && $gradyear > 0) {
            $where[] = "t.grad_year = :gradyear";
            $params['gradyear'] = $gradyear;
        }

        if (!empty($major)) {
            $where[] = "t.major = :major";
            $params['major'] = $major;
        }

        $sql = "SELECT t.*, u.username, u.email,
                       (SELECT COUNT(*) FROM {lumina_transcript_items} ti WHERE ti.transcript_id = t.id) AS course_count
                  FROM {lumina_transcripts} t
                  JOIN {user} u ON u.id = t.userid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY t.grad_year DESC, t.student_name ASC";

        return $DB->get_records_sql($sql, $params, 0, 100);
    }

    /**
     * Official Grade Correction Workflow.
     * Creates a new version (v1 -> v2), documents the audit reason, recomputes GPA and updates hash.
     *
     * @param int $transcriptid
     * @param int $itemid
     * @param float $newgrade
     * @param string $reason
     * @param int $actorid
     * @return bool
     */
    public static function correct_grade(
        int $transcriptid,
        int $itemid,
        float $newgrade,
        string $reason,
        int $actorid
    ): bool {
        global $DB, $CFG;

        $transcript = $DB->get_record('lumina_transcripts', ['id' => $transcriptid], '*', MUST_EXIST);
        $item = $DB->get_record('lumina_transcript_items', ['id' => $itemid, 'transcript_id' => $transcriptid], '*', MUST_EXIST);

        $beforeitems = $DB->get_records('lumina_transcript_items', ['transcript_id' => $transcriptid], 'id ASC');

        // Update target item
        $oldgrade = (float)$item->raw_grade;
        $item->raw_grade = round($newgrade, 2);

        if ($newgrade >= 88.00) {
            $item->letter_grade = 'A';
            $item->predicate = 'Sangat Baik (Sangat Kompeten)';
        } elseif ($newgrade >= 78.00) {
            $item->letter_grade = 'B';
            $item->predicate = 'Baik (Kompeten)';
        } elseif ($newgrade >= 65.00) {
            $item->letter_grade = 'C';
            $item->predicate = 'Cukup (Cukup Kompeten)';
        } else {
            $item->letter_grade = 'D';
            $item->predicate = 'Kurang';
        }

        $DB->update_record('lumina_transcript_items', $item);

        // Recalculate GPA across all items
        $allitems = $DB->get_records('lumina_transcript_items', ['transcript_id' => $transcriptid], 'id ASC');
        $sum = 0;
        $reconstructed = [];
        foreach ($allitems as $it) {
            $sum += (float)$it->raw_grade;
            $reconstructed[] = [
                'course_id'       => $it->course_id,
                'course_code'     => $it->course_code,
                'course_name'     => $it->course_name,
                'academic_period' => $it->academic_period,
                'semester_num'    => (int)$it->semester_num,
                'grade_level'     => (int)$it->grade_level,
                'teacher_name'    => $it->teacher_name,
                'raw_grade'       => (float)$it->raw_grade,
                'letter_grade'    => $it->letter_grade,
                'predicate'       => $it->predicate,
                'is_completed'    => (int)$it->is_completed,
            ];
        }

        $newgpa = round($sum / count($allitems), 2);
        $gpaformatted = number_format((float)$newgpa, 2, '.', '');

        // Recompute hash
        $hashtarget = $transcript->userid . '|' . $transcript->student_nis . '|' . ($transcript->student_nisn ?? '') . '|' .
                      $gpaformatted . '|' . json_encode($reconstructed, JSON_UNESCAPED_UNICODE) . '|' .
                      ($CFG->passwordsaltmain ?? 'LUMINA_VAULT_SALT');
        $newhash = hash('sha256', $hashtarget);

        $previoushash = $transcript->integrity_hash;
        $newversionnum = $transcript->version + 1;

        // Record in versions table
        $version = new \stdClass();
        $version->transcript_id  = $transcriptid;
        $version->version_number = $newversionnum;
        $version->reason         = clean_param($reason, PARAM_TEXT);
        $version->modified_by    = $actorid;
        $version->snapshot_json  = json_encode($reconstructed, JSON_UNESCAPED_UNICODE);
        $version->previous_hash  = $previoushash;
        $version->new_hash       = $newhash;
        $version->timecreated    = time();
        $DB->insert_record('lumina_transcript_versions', $version);

        // Update transcript record
        $transcript->cumulative_gpa = $newgpa;
        $transcript->version        = $newversionnum;
        $transcript->integrity_hash = $newhash;
        $transcript->timefinalized  = time();
        $DB->update_record('lumina_transcripts', $transcript);

        audit_logger::log('grade_corrected', 'transcript', $transcriptid, [
            'itemid'   => $itemid,
            'oldgrade' => $oldgrade,
        ], [
            'itemid'   => $itemid,
            'newgrade' => $newgrade,
            'version'  => $newversionnum,
        ], $reason, $actorid);

        return true;
    }

    /**
     * Get transcript with its grade items.
     *
     * @param int|string $idoruuid ID or UUID
     * @return \stdClass|null
     */
    public static function get_transcript($idoruuid): ?\stdClass {
        global $DB;

        if (is_numeric($idoruuid)) {
            $transcript = $DB->get_record('lumina_transcripts', ['id' => (int)$idoruuid]);
        } else {
            $transcript = $DB->get_record('lumina_transcripts', ['transcript_uuid' => $idoruuid]);
        }

        if (!$transcript) {
            return null;
        }

        $transcript->items = $DB->get_records('lumina_transcript_items', ['transcript_id' => $transcript->id], 'semester_num ASC, course_name ASC');
        $transcript->versions = $DB->get_records('lumina_transcript_versions', ['transcript_id' => $transcript->id], 'version_number DESC');

        return $transcript;
    }
}
