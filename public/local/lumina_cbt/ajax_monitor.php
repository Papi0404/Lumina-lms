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
 * AJAX endpoint for proctor live audit feed, real-time candidate synchronization,
 * and instant resolution actions.
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

// Release session lock to allow concurrent AJAX requests
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

global $USER, $DB;

$sysctx = \context_system::instance();
$courseid = optional_param('courseid', 0, PARAM_INT);
$activityid = optional_param('activityid', 0, PARAM_INT);
$filterscope = optional_param('filterscope', 'violations_only', PARAM_ALPHAEXT);

$coursectx = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : $sysctx;

// Authorization check: User must be teacher, manager, or site admin
$canmonitor = has_capability('local/lumina_cbt:monitor', $coursectx) ||
              has_capability('moodle/grade:viewall', $coursectx) ||
              has_capability('moodle/course:manageactivities', $coursectx) ||
              is_siteadmin();

if (!$canmonitor) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = optional_param('action', 'feed', PARAM_ALPHAEXT);

// 1. Live Infraction Feed & Candidate List (Polled every 2-3 seconds)
if ($action === 'feed') {
    $since  = optional_param('since', 0, PARAM_INT);
    $date   = optional_param('date', date('Y-m-d'), PARAM_RAW);
    $dateto = optional_param('dateto', '', PARAM_RAW);
    $search = optional_param('search', '', PARAM_RAW_TRIMMED);

    $metrics = \local_lumina_cbt\cbt_engine::get_range_metrics(
        $date,
        $dateto,
        $courseid ?: null,
        $activityid ?: null,
        $search
    );

    $feed = \local_lumina_cbt\cbt_engine::get_live_audit_feed(
        $since,
        $date,
        $search,
        50,
        $courseid ?: null,
        $activityid ?: null,
        $dateto
    );

    $candidates = \local_lumina_cbt\cbt_engine::get_sessions_for_review(
        $date,
        $search,
        $courseid ?: null,
        $activityid ?: null,
        $filterscope,
        $dateto
    );

    // Scan active cameras without extra roundtrip HTTP calls
    $camdir = $CFG->dataroot . '/cbt_cam';
    $activecams = [];
    $now = time();
    $files = @glob($camdir . '/session_*.jpg');
    if ($files) {
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if (($now - $mtime) <= 10) {
                if (preg_match('/session_(\d+)\.jpg$/', $file, $m)) {
                    $activecams[(int)$m[1]] = true;
                }
            }
        }
    }

    echo json_encode([
        'success'          => true,
        'feed'             => $feed,
        'candidates'       => $candidates,
        'active_cameras'   => $activecams,
        'active_count'     => $metrics['active_count'],
        'review_count'     => $metrics['locked_count'],
        'clean_count'      => $metrics['clean_count'],
        'violations_count' => $metrics['total_violations'],
        'range_label'      => $metrics['range_label'],
        'server_time'      => time(),
    ]);
    exit;
}

// 2. Proctor Resolution (Reset/Unlock, Approve, Invalidate)
if ($action === 'resolve') {
    $sessionid = required_param('session_id', PARAM_INT);
    $decision  = required_param('decision', PARAM_ALPHA);
    $notes     = optional_param('notes', '', PARAM_RAW_TRIMMED);

    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $sessionrec = $DB->get_record($sessiontable, ['id' => $sessionid]);
    if (!$sessionrec) {
        echo json_encode(['success' => false, 'error' => 'Sesi ujian tidak ditemukan.']);
        exit;
    }

    $targetctx = $sessionrec->courseid > 0 ? \context_course::instance((int)$sessionrec->courseid, IGNORE_MISSING) : $coursectx;
    if (!$targetctx) {
        $targetctx = $sysctx;
    }

    $canreview = is_siteadmin() ||
                 has_capability('local/lumina_cbt:review', $targetctx) ||
                 has_capability('moodle/course:manageactivities', $targetctx) ||
                 has_capability('moodle/grade:viewall', $targetctx) ||
                 has_capability('local/lumina_cbt:monitor', $targetctx);

    if (!$canreview) {
        echo json_encode(['success' => false, 'error' => 'Review permission required']);
        exit;
    }

    $resolved = \local_lumina_cbt\cbt_engine::resolve_session($sessionid, (int)$USER->id, $decision, $notes);
    echo json_encode([
        'success'  => $resolved,
        'decision' => $decision,
        'message'  => get_string($decision . '_success', 'local_lumina_cbt'),
    ]);
    exit;
}

// 3. Candidate Specific Audit Trail & Evidence Photos
if ($action === 'session_events') {
    $sessionid = required_param('session_id', PARAM_INT);
    $events = \local_lumina_cbt\cbt_engine::get_session_events($sessionid);
    $photos = \local_lumina_cbt\cbt_engine::get_session_evidence_photos($sessionid);

    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $sessionrec = $DB->get_record($sessiontable, ['id' => $sessionid], 'id, status, warningcount, score, activityid, userid');

    echo json_encode([
        'success'      => true,
        'status'       => $sessionrec ? $sessionrec->status : '',
        'warning_count'=> $sessionrec ? (int)$sessionrec->warningcount : 0,
        'score'        => $sessionrec && $sessionrec->score !== null ? (float)$sessionrec->score : null,
        'events'       => $events,
        'photos'       => $photos,
    ]);
    exit;
}

// 4. Get Activities for selected Course (for dynamic dropdown filter)
if ($action === 'get_activities') {
    $cid = required_param('courseid', PARAM_INT);
    $sql = "SELECT cm.id, COALESCE(q.name, h.name, a.name, cm.id) AS name, m.name AS modname
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
         LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
         LEFT JOIN {h5pactivity} h ON h.id = cm.instance AND m.name = 'h5pactivity'
         LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
             WHERE cm.course = ? AND m.name IN ('quiz', 'h5pactivity', 'assign') AND cm.deletioninprogress = 0
          ORDER BY cm.id ASC";

    $records = $DB->get_records_sql($sql, [$cid]);
    $actList = [];
    foreach ($records as $r) {
        $badge = ($r->modname === 'quiz' ? '📝 Quiz' : ($r->modname === 'h5pactivity' ? '🎮 H5P' : '📋 Tugas'));
        $actList[] = [
            'id'   => (int)$r->id,
            'name' => format_string($r->name) . ' (' . $badge . ')',
        ];
    }
    echo json_encode(['success' => true, 'activities' => $actList]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
