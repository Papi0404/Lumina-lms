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
 * AJAX endpoint for CBT proctoring violation recording and exam submission.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/cbt_engine.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

global $USER, $DB;

$action = optional_param('action', '', PARAM_ALPHAEXT);
$sessionid = optional_param('session_id', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

// 1. Record Proctoring Infraction
if ($action === 'record_violation') {
    $eventtype = required_param('event_type', PARAM_ALPHANUMEXT);
    $metadata  = optional_param('metadata', '', PARAM_RAW_TRIMMED);

    if ($sessionid <= 0) {
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $sess = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, $cmid, (int)$USER->id);
            $sessionid = (int)$sess->id;
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing session_id or cmid']);
            exit;
        }
    }

    $result = \local_lumina_cbt\cbt_engine::record_violation($sessionid, (int)$USER->id, $eventtype, $metadata);
    echo json_encode(array_merge(['success' => true], $result));
    exit;
}

// 2. Save In-Progress Candidate Answers (Auto-Save)
if ($action === 'save_progress') {
    $answersraw  = optional_param('answers', '{}', PARAM_RAW);
    $doubtfulraw = optional_param('doubtful', '{}', PARAM_RAW);

    $answers  = json_decode($answersraw, true) ?: [];
    $doubtful = json_decode($doubtfulraw, true) ?: [];

    if ($sessionid <= 0 && $cmid > 0) {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $sess = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, $cmid, (int)$USER->id);
        $sessionid = (int)$sess->id;
    }

    $saved = \local_lumina_cbt\cbt_engine::save_progress($sessionid, (int)$USER->id, $answers, $doubtful);
    echo json_encode(['success' => $saved]);
    exit;
}

// 3. Submit Exam Attempt
if ($action === 'submit_exam') {
    $answersraw = optional_param('answers', '{}', PARAM_RAW);
    $answers = json_decode($answersraw, true) ?: [];

    if ($sessionid <= 0 && $cmid > 0) {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $sess = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, $cmid, (int)$USER->id);
        $sessionid = (int)$sess->id;
    }

    $res = \local_lumina_cbt\cbt_engine::submit_exam_attempt($sessionid, (int)$USER->id, $answers);
    echo json_encode($res);
    exit;
}

// 4. Teacher Simulation Self-Reset
if ($action === 'teacher_sim_reset') {
    $courseid = required_param('courseid', PARAM_INT);
    $activityid = required_param('activityid', PARAM_INT);

    $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
    $isteacher = ($coursectx && (has_capability('moodle/course:manageactivities', $coursectx) || has_capability('moodle/grade:viewall', $coursectx))) || is_siteadmin();

    if (!$isteacher) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $warningtable = \local_lumina_cbt\cbt_engine::get_warnings_table();
    $eventstable  = \local_lumina_cbt\cbt_engine::get_events_table();

    $sess = $DB->get_record($sessiontable, ['courseid' => $courseid, 'activityid' => $activityid, 'userid' => $USER->id]);
    if ($sess) {
        $sess->warningcount = 0;
        $sess->status = 'in_progress';
        $sess->score = null;
        $sess->timefinish = 0;
        $DB->update_record($sessiontable, $sess);
        $DB->delete_records($warningtable, ['sessionid' => $sess->id]);

        $ev = new stdClass();
        $ev->sessionid = $sess->id;
        $ev->userid = $USER->id;
        $ev->eventtype = 'simulation_reset';
        $ev->eventtime = time();
        $ev->metadata = 'Pengajar me-reset simulasi ujian secara mandiri.';
        $DB->insert_record($eventstable, $ev);

        \local_lumina_cbt\cbt_engine::sync_grade_to_gradebook($activityid, $USER->id, null);
    }

    echo json_encode(['success' => true]);
    exit;
}

// 5. Real-Time Student Heartbeat & Integrity Status Check
if ($action === 'heartbeat') {
    if ($sessionid <= 0 && $cmid > 0) {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $sess = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, $cmid, (int)$USER->id);
        $sessionid = (int)$sess->id;
    }

    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $sess = $DB->get_record($sessiontable, ['id' => $sessionid, 'userid' => $USER->id]);

    if (!$sess) {
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }

    if ($sess->status === 'not_started') {
        $sess->status = 'in_progress';
        if (empty($sess->timestart)) {
            $sess->timestart = time();
        }
        $DB->update_record($sessiontable, $sess);
    }

    $isDisqualified = ($sess->status === 'invalidated');
    $isSuspended    = ($sess->status === 'under_review' || (int)$sess->warningcount >= 3);

    $msg = '';
    if ($isDisqualified) {
        $msg = 'Anda ujiannya telah dibekukan karna melanggar integritas, silahkan hubungi admin.';
    } else if ($isSuspended) {
        $msg = 'Sesi ujian Anda telah ditangguhkan karena mencapai batas pelanggaran. Silakan hubungi pengawas.';
    }

    echo json_encode([
        'success'         => true,
        'session_id'      => (int)$sess->id,
        'status'          => $sess->status,
        'warning_level'   => (int)$sess->warningcount,
        'is_suspended'    => $isSuspended,
        'is_disqualified' => $isDisqualified,
        'message'         => $msg,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
