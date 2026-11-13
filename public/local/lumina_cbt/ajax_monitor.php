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
 * AJAX endpoint for proctor live audit feed and candidate resolution.
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

$sysctx = \context_system::instance();
$courseid = optional_param('courseid', 0, PARAM_INT);
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

// 1. Live Infraction Feed (Polled every 2-3 seconds)
if ($action === 'feed') {
    $since  = optional_param('since', 0, PARAM_INT);
    $date   = optional_param('date', date('Y-m-d'), PARAM_RAW);
    $search = optional_param('search', '', PARAM_RAW_TRIMMED);

    $feed = \local_lumina_cbt\cbt_engine::get_live_audit_feed($since, $date, $search, 50);

    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $eventstable  = \local_lumina_cbt\cbt_engine::get_events_table();

    $activecount = $DB->count_records($sessiontable, ['status' => 'in_progress']);
    $reviewcount = $DB->count_records($sessiontable, ['status' => 'under_review']);
    $totalviolations = $DB->count_records_select($eventstable, "eventtype != 'exam_submitted'");

    echo json_encode([
        'success'          => true,
        'feed'             => $feed,
        'active_count'     => $activecount,
        'review_count'     => $reviewcount,
        'total_violations' => $totalviolations,
        'server_time'      => time(),
    ]);
    exit;
}

// 2. Proctor Resolution (Reset/Unlock, Approve, Invalidate)
if ($action === 'resolve') {
    $canreview = has_capability('local/lumina_cbt:review', $coursectx) ||
                 has_capability('moodle/course:manageactivities', $coursectx) ||
                 is_siteadmin();

    if (!$canreview) {
        echo json_encode(['success' => false, 'error' => 'Review permission required']);
        exit;
    }

    $sessionid = required_param('session_id', PARAM_INT);
    $decision  = required_param('decision', PARAM_ALPHA);
    $notes     = optional_param('notes', '', PARAM_RAW_TRIMMED);

    $resolved = \local_lumina_cbt\cbt_engine::resolve_session($sessionid, (int)$USER->id, $decision, $notes);
    echo json_encode([
        'success'  => $resolved,
        'decision' => $decision,
        'message'  => get_string($decision . '_success', 'local_lumina_cbt'),
    ]);
    exit;
}

// 3. Candidate Specific Audit Trail
if ($action === 'session_events') {
    $sessionid = required_param('session_id', PARAM_INT);
    $events = \local_lumina_cbt\cbt_engine::get_session_events($sessionid);
    echo json_encode(['success' => true, 'events' => $events]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
