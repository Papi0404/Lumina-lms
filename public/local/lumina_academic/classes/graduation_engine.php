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
 * Graduation Eligibility Validator & Offboarding Engine.
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class graduation_engine {

    /**
     * Check student eligibility for graduation.
     *
     * @param int $userid
     * @return array
     */
    public static function check_eligibility(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $profile = manager::get_or_create_student_profile($userid);

        $reasons = [];
        $iseligible = true;

        // Check if grade level is senior (level 12 or above)
        if ($profile->current_level < 12) {
            $iseligible = false;
            $reasons[] = 'Siswa masih berada di tingkat ' . $profile->current_level . ' (Syarat minimal: Tingkat 12).';
        }

        // Fetch course count
        $gradescount = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
              WHERE gg.userid = :userid AND gi.courseid > 1 AND gg.finalgrade IS NOT NULL",
            ['userid' => $userid]
        );

        if ($gradescount < 1) {
            $iseligible = false;
            $reasons[] = 'Belum ada rekaman nilai mata pelajaran resmi yang tercatat di Gradebook.';
        }

        return [
            'is_eligible'       => $iseligible,
            'reasons'           => $reasons,
            'current_level'     => $profile->current_level,
            'courses_evaluated' => $gradescount,
            'profile'           => $profile,
            'user'              => $user,
        ];
    }

    /**
     * Finalize graduation for selected students.
     * Freezes grades into the permanent Vault and offboards students cleanly from active cohorts.
     *
     * @param array $userids
     * @param int $gradyear
     * @param string $certprefix
     * @param int|null $actorid
     * @return array
     */
    public static function finalize_graduation(
        array $userids,
        int $gradyear,
        string $certprefix = 'LUMINA-IJZ-',
        ?int $actorid = null
    ): array {
        global $DB, $USER;

        $actorid = $actorid ?: (!empty($USER->id) ? (int)$USER->id : 2);
        $results = [];

        foreach ($userids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 2) {
                continue;
            }

            // 1. Freeze grades in Alumni Vault (generates immutable SHA-256 signed transcript)
            $transcript = alumni_vault::freeze_student_grades($uid, $gradyear, $actorid);

            // 2. Generate Certificate Number
            $certnumber = $certprefix . $gradyear . '-' . str_pad((string)$uid, 5, '0', STR_PAD_LEFT);

            // 3. Insert or update graduation record
            $existinggrad = $DB->get_record('lumina_graduation', ['userid' => $uid]);
            if ($existinggrad) {
                $existinggrad->graduation_year    = $gradyear;
                $existinggrad->graduation_date    = time();
                $existinggrad->final_gpa          = $transcript->cumulative_gpa;
                $existinggrad->final_predicate    = $transcript->final_predicate;
                $existinggrad->certificate_number = $certnumber;
                $existinggrad->is_eligible        = 1;
                $existinggrad->verified_by        = $actorid;
                $DB->update_record('lumina_graduation', $existinggrad);
            } else {
                $grad = new \stdClass();
                $grad->userid             = $uid;
                $grad->graduation_year    = $gradyear;
                $grad->graduation_date    = time();
                $grad->final_gpa          = $transcript->cumulative_gpa;
                $grad->final_predicate    = $transcript->final_predicate;
                $grad->certificate_number = $certnumber;
                $grad->is_eligible        = 1;
                $grad->verified_by        = $actorid;
                $grad->timecreated        = time();
                $DB->insert_record('lumina_graduation', $grad);
            }

            // 4. Update student profile
            $DB->set_field('lumina_student_profiles', 'academic_status', 'graduated', ['userid' => $uid]);

            // 5. Update active class memberships to graduated
            $activememberships = $DB->get_records('lumina_class_members', [
                'userid' => $uid,
                'status' => 'active',
            ]);

            foreach ($activememberships as $m) {
                $m->status      = 'graduated';
                $m->exit_date   = time();
                $m->exit_reason = 'Graduated (Tahun Kelulusan ' . $gradyear . ')';
                $m->timemodified = time();
                $DB->update_record('lumina_class_members', $m);

                // Cleanly remove from class cohort so they no longer clutter active courses
                $cls = $DB->get_record('lumina_classes', ['id' => $m->class_id]);
                if ($cls && !empty($cls->cohort_id)) {
                    if (cohort_is_member($cls->cohort_id, $uid)) {
                        cohort_remove_member($cls->cohort_id, $uid);
                    }
                }
            }

            // 6. Ensure alumni access is active in vault_only mode
            $alumniaccess = $DB->get_record('lumina_alumni_access', ['userid' => $uid]);
            if ($alumniaccess) {
                $alumniaccess->access_mode  = 'vault_only';
                $alumniaccess->timemodified = time();
                $DB->update_record('lumina_alumni_access', $alumniaccess);
            }

            audit_logger::log('student_graduated', 'student', $uid, null, [
                'grad_year'    => $gradyear,
                'cert_number'  => $certnumber,
                'gpa'          => $transcript->cumulative_gpa,
                'transcriptid' => $transcript->id,
            ], 'Graduation finalized and student offboarded into Alumni Vault', $actorid);

            $results[$uid] = [
                'status'      => 'success',
                'cert_number' => $certnumber,
                'transcript'  => $transcript,
            ];
        }

        return $results;
    }
}
