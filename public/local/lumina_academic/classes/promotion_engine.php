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
 * Transactional Promotion Wizard Engine (Kenaikan Kelas & Mutasi Siswa).
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class promotion_engine {

    /**
     * Create a new promotion batch.
     *
     * @param int $fromyearid
     * @param int $toyearid
     * @param int|null $fromclassid
     * @param int|null $toclassid
     * @param string $mode bulk or individual
     * @return \stdClass Batch record
     */
    public static function create_batch(
        int $fromyearid,
        int $toyearid,
        ?int $fromclassid = null,
        ?int $toclassid = null,
        string $mode = 'bulk'
    ): \stdClass {
        global $DB, $USER;

        $batch = new \stdClass();
        $batch->batch_uuid      = \core\uuid::generate();
        $batch->from_year_id    = $fromyearid;
        $batch->to_year_id      = $toyearid;
        $batch->from_class_id   = $fromclassid;
        $batch->to_class_id     = $toclassid;
        $batch->mode            = $mode;
        $batch->status          = 'pending';
        $batch->total_students  = 0;
        $batch->success_count   = 0;
        $batch->failure_count   = 0;
        $batch->executed_by     = (int)($USER->id ?? 0);
        $batch->timecreated     = time();
        $batch->timecompleted   = null;

        $batch->id = (int)$DB->insert_record('lumina_promotion_batches', $batch);

        self::log_batch($batch->id, 'info', 'Created promotion batch ' . $batch->batch_uuid);
        return $batch;
    }

    /**
     * Preview promotion for a class or set of students.
     *
     * @param int $fromclassid
     * @param int|null $toclassid
     * @return array
     */
    public static function preview_promotion(int $fromclassid, ?int $toclassid = null): array {
        global $DB;

        $fromclass = $DB->get_record('lumina_classes', ['id' => $fromclassid], '*', MUST_EXIST);
        $toclass = $toclassid ? $DB->get_record('lumina_classes', ['id' => $toclassid]) : null;

        $students = manager::get_class_students($fromclassid, 'active');
        $preview = [];

        foreach ($students as $student) {
            $item = new \stdClass();
            $item->userid        = $student->userid;
            $item->username      = $student->username;
            $item->fullname      = fullname($student);
            $item->nis           = $student->nis;
            $item->current_class = $fromclass->name;
            $item->current_level = $fromclass->grade_level;

            // Suggested action
            if ($toclass) {
                $item->suggested_action = 'promote';
                $item->target_class_id  = $toclass->id;
                $item->target_class     = $toclass->name;
                $item->target_level     = $toclass->grade_level;
            } elseif ($fromclass->grade_level >= 12) {
                $item->suggested_action = 'graduate';
                $item->target_class_id  = null;
                $item->target_class     = 'Kelulusan / Alumni';
                $item->target_level     = 12;
            } else {
                $item->suggested_action = 'promote';
                $item->target_class_id  = null;
                $item->target_class     = 'Tingkat ' . ($fromclass->grade_level + 1);
                $item->target_level     = $fromclass->grade_level + 1;
            }

            $preview[] = $item;
        }

        return $preview;
    }

    /**
     * Execute a promotion batch with full transaction safety and audit logs.
     *
     * @param int $batchid
     * @param array $studentactions Array of ['userid' => int, 'action' => string, 'target_class_id' => int|null, 'notes' => string]
     * @return bool
     */
    public static function execute_batch(int $batchid, array $studentactions): bool {
        global $DB, $USER;

        $batch = $DB->get_record('lumina_promotion_batches', ['id' => $batchid], '*', MUST_EXIST);
        if ($batch->status === 'completed') {
            throw new \moodle_exception('error_invalid_batch', 'local_lumina_academic');
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            $successcount = 0;
            $failcount = 0;

            foreach ($studentactions as $actionitem) {
                $userid        = (int)$actionitem['userid'];
                $action        = $actionitem['action'] ?? 'promote'; // promote, retain, transfer, graduate, skip
                $targetclassid = !empty($actionitem['target_class_id']) ? (int)$actionitem['target_class_id'] : $batch->to_class_id;
                $notes         = $actionitem['notes'] ?? '';

                if ($action === 'skip') {
                    continue;
                }

                // Create promotion item record
                $item = new \stdClass();
                $item->batch_id      = $batch->id;
                $item->userid        = $userid;
                $item->action        = $action;
                $item->from_class_id = (int)$batch->from_class_id;
                $item->to_class_id   = $targetclassid;
                $item->status        = 'pending';
                $item->notes         = $notes;
                $item->timecreated   = time();
                $itemid = $DB->insert_record('lumina_promotion_items', $item);

                // Fetch source class membership
                $sourcemember = $DB->get_record('lumina_class_members', [
                    'class_id' => $batch->from_class_id,
                    'userid'   => $userid,
                    'status'   => 'active',
                ]);

                if (!$sourcemember) {
                    $DB->set_field('lumina_promotion_items', 'status', 'failed', ['id' => $itemid]);
                    self::log_batch($batch->id, 'warning', "Student $userid has no active membership in source class {$batch->from_class_id}");
                    $failcount++;
                    continue;
                }

                $fromclass = $DB->get_record('lumina_classes', ['id' => $batch->from_class_id]);

                // 1. Update source class membership status
                $sourcemember->status      = ($action === 'promote') ? 'promoted' : (($action === 'retain') ? 'retained' : $action);
                $sourcemember->exit_date   = time();
                $sourcemember->exit_reason = 'Promotion batch ' . $batch->batch_uuid . ' (' . $action . ')';
                $sourcemember->timemodified = time();
                $DB->update_record('lumina_class_members', $sourcemember);

                // 2. Remove student from source class cohort
                if (!empty($fromclass->cohort_id)) {
                    if (cohort_is_member($fromclass->cohort_id, $userid)) {
                        cohort_remove_member($fromclass->cohort_id, $userid);
                    }
                }

                // 3. Handle target placement
                if ($action === 'promote' || $action === 'retain' || $action === 'transfer') {
                    if (!$targetclassid) {
                        throw new \moodle_exception('error_no_target_class', 'local_lumina_academic');
                    }

                    $targetclass = $DB->get_record('lumina_classes', ['id' => $targetclassid], '*', MUST_EXIST);

                    // Add student to target class
                    $newmember = new \stdClass();
                    $newmember->class_id         = $targetclass->id;
                    $newmember->userid           = $userid;
                    $newmember->academic_year_id = $batch->to_year_id;
                    $newmember->status           = 'active';
                    $newmember->enrollment_date  = time();
                    $newmember->timecreated      = time();
                    $newmember->timemodified     = time();
                    $DB->insert_record('lumina_class_members', $newmember);

                    // Add student to target class cohort
                    if (!empty($targetclass->cohort_id)) {
                        if (!cohort_is_member($targetclass->cohort_id, $userid)) {
                            cohort_add_member($targetclass->cohort_id, $userid);
                        }
                    }

                    // Update student profile
                    manager::get_or_create_student_profile($userid, [
                        'current_level' => $targetclass->grade_level,
                        'current_major' => $targetclass->major,
                    ]);
                } elseif ($action === 'graduate') {
                    // Update profile academic status
                    $DB->set_field('lumina_student_profiles', 'academic_status', 'graduated', ['userid' => $userid]);
                }

                $DB->set_field('lumina_promotion_items', 'status', 'success', ['id' => $itemid]);
                $successcount++;
            }

            // Mark batch completed
            $batch->status         = 'completed';
            $batch->total_students = count($studentactions);
            $batch->success_count  = $successcount;
            $batch->failure_count  = $failcount;
            $batch->timecompleted  = time();
            $DB->update_record('lumina_promotion_batches', $batch);

            self::log_batch($batch->id, 'info', "Batch execution finished. Success: $successcount, Failed: $failcount");
            audit_logger::log('promotion_batch_executed', 'batch', $batch->id, null, $batch, 'Batch promotion completed');

            $transaction->allow_commit();
            return true;

        } catch (\Exception $e) {
            $transaction->rollback($e);
            $batch->status = 'failed';
            $DB->update_record('lumina_promotion_batches', $batch);
            self::log_batch($batch->id, 'error', 'Batch failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Safely rollback a promotion batch, restoring students to their prior state.
     *
     * @param int $batchid
     * @return bool
     */
    public static function rollback_batch(int $batchid): bool {
        global $DB;

        $batch = $DB->get_record('lumina_promotion_batches', ['id' => $batchid], '*', MUST_EXIST);
        if ($batch->status !== 'completed') {
            throw new \moodle_exception('error_cannot_rollback', 'local_lumina_academic');
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            $items = $DB->get_records('lumina_promotion_items', [
                'batch_id' => $batch->id,
                'status'   => 'success',
            ]);

            $fromclass = $DB->get_record('lumina_classes', ['id' => $batch->from_class_id]);

            foreach ($items as $item) {
                // 1. Delete target membership if was added
                if (!empty($item->to_class_id)) {
                    $targetclass = $DB->get_record('lumina_classes', ['id' => $item->to_class_id]);
                    $DB->delete_records('lumina_class_members', [
                        'class_id' => $item->to_class_id,
                        'userid'   => $item->userid,
                        'academic_year_id' => $batch->to_year_id,
                    ]);

                    // Remove from target cohort
                    if ($targetclass && !empty($targetclass->cohort_id)) {
                        if (cohort_is_member($targetclass->cohort_id, $item->userid)) {
                            cohort_remove_member($targetclass->cohort_id, $item->userid);
                        }
                    }
                }

                // 2. Restore source membership
                $sourcemember = $DB->get_record('lumina_class_members', [
                    'class_id' => $item->from_class_id,
                    'userid'   => $item->userid,
                ]);

                if ($sourcemember) {
                    $sourcemember->status      = 'active';
                    $sourcemember->exit_date   = null;
                    $sourcemember->exit_reason = null;
                    $sourcemember->timemodified = time();
                    $DB->update_record('lumina_class_members', $sourcemember);
                }

                // 3. Re-add to source cohort
                if ($fromclass && !empty($fromclass->cohort_id)) {
                    if (!cohort_is_member($fromclass->cohort_id, $item->userid)) {
                        cohort_add_member($fromclass->cohort_id, $item->userid);
                    }
                }

                $DB->set_field('lumina_promotion_items', 'status', 'reverted', ['id' => $item->id]);
            }

            $batch->status = 'rolled_back';
            $DB->update_record('lumina_promotion_batches', $batch);

            self::log_batch($batch->id, 'info', 'Batch successfully rolled back');
            audit_logger::log('promotion_batch_rolled_back', 'batch', $batch->id, null, $batch, 'Batch rollback executed');

            $transaction->allow_commit();
            return true;

        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Log a message in lumina_promotion_logs.
     *
     * @param int $batchid
     * @param string $level info, warning, error
     * @param string $message
     */
    private static function log_batch(int $batchid, string $level, string $message) {
        global $DB;
        $log = new \stdClass();
        $log->batch_id    = $batchid;
        $log->log_level   = $level;
        $log->message     = $message;
        $log->timecreated = time();
        $DB->insert_record('lumina_promotion_logs', $log);
    }
}

