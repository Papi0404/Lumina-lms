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
 * Immutable audit logger for all academic operations.
 *
 * @package    local_lumina_academic
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_logger {

    /**
     * Log an academic event.
     *
     * @param string $eventname e.g. class_created, student_promoted, grade_snapshotted
     * @param string $entitytype e.g. class, student, transcript, year
     * @param int $entityid
     * @param mixed $statebefore State before operation (array/object/null)
     * @param mixed $stateafter State after operation (array/object/null)
     * @param string $reason
     * @param int|null $actorid User ID who performed the action (defaults to current $USER->id)
     * @return int Inserted event ID
     */
    public static function log(
        string $eventname,
        string $entitytype,
        int $entityid,
        $statebefore = null,
        $stateafter = null,
        string $reason = '',
        ?int $actorid = null
    ): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->actor_userid = $actorid ?? (!empty($USER->id) ? (int)$USER->id : 0);
        $record->event_name   = clean_param($eventname, PARAM_ALPHANUMEXT);
        $record->entity_type  = clean_param($entitytype, PARAM_ALPHA);
        $record->entity_id    = $entityid;
        $record->state_before = $statebefore !== null ? json_encode($statebefore, JSON_UNESCAPED_UNICODE) : null;
        $record->state_after  = $stateafter !== null ? json_encode($stateafter, JSON_UNESCAPED_UNICODE) : null;
        $record->reason       = substr($reason, 0, 255);
        $record->ip_address   = getremoteaddr();
        $record->timestamp    = time();

        return (int)$DB->insert_record('lumina_academic_events', $record);
    }

    /**
     * Retrieve recent logs for a specific entity or overall system.
     *
     * @param string|null $entitytype
     * @param int|null $entityid
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs(?string $entitytype = null, ?int $entityid = null, int $limit = 50): array {
        global $DB;

        $sql = "SELECT e.*, u.firstname, u.lastname, u.email
                  FROM {lumina_academic_events} e
             LEFT JOIN {user} u ON u.id = e.actor_userid";

        $params = [];
        $where = [];

        if ($entitytype !== null) {
            $where[] = "e.entity_type = :entitytype";
            $params['entitytype'] = $entitytype;
        }
        if ($entityid !== null) {
            $where[] = "e.entity_id = :entityid";
            $params['entityid'] = $entityid;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY e.timestamp DESC, e.id DESC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }
}
