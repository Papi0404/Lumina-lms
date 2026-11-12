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
 * AJAX Endpoint for Exam Proctoring Violation Beacons.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/exam_monitor.php');

use local_lumina_core\exam_monitor;

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$sessionid = optional_param('sessionid', 0, PARAM_INT);
$action    = optional_param('action', 'violation', PARAM_ALPHAEXT);

try {
    if ($action === 'get_live_feed') {
        $systemcontext = context_system::instance();
        if (!is_siteadmin() && !has_capability('local/lumina_core:reviewexams', $systemcontext)) {
            echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
            exit;
        }
        $since  = optional_param('since', 0, PARAM_INT);
        $date   = optional_param('date', '', PARAM_TEXT);
        $search = optional_param('search', '', PARAM_TEXT);
        $feed   = exam_monitor::get_live_audit_feed($since, $date, $search);
        echo json_encode([
            'success'     => true,
            'feed'        => $feed,
            'server_time' => time(),
        ]);
        exit;
    }

    if ($action === 'get_audit_details') {
        $systemcontext = context_system::instance();
        if (!is_siteadmin() && !has_capability('local/lumina_core:reviewexams', $systemcontext)) {
            echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
            exit;
        }
        if ($sessionid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
            exit;
        }
        $events = exam_monitor::get_session_events($sessionid);
        echo json_encode([
            'success' => true,
            'events'  => $events,
        ]);
        exit;
    }

    if ($action === 'save_progress') {
        if ($sessionid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing sessionid']);
            exit;
        }
        $answersraw  = optional_param('answers', '', PARAM_RAW);
        $doubtfulraw = optional_param('doubtful', '', PARAM_RAW);
        $answers  = json_decode($answersraw, true) ?: [];
        $doubtful = json_decode($doubtfulraw, true) ?: [];

        $saved = exam_monitor::save_progress($sessionid, $USER->id, $answers, $doubtful);
        echo json_encode(['success' => $saved]);
        exit;
    }

    if ($action === 'submit_exam') {
        if ($sessionid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing sessionid']);
            exit;
        }
        $answersraw = optional_param('answers', '', PARAM_RAW);
        $answers = json_decode($answersraw, true);
        if (!is_array($answers)) {
            $answers = [];
        }

        $result = exam_monitor::submit_exam_attempt($sessionid, $USER->id, $answers);
        echo json_encode($result);
        exit;
    }

    // Default: record proctoring violation beacon
    if ($sessionid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing sessionid for violation']);
        exit;
    }
    $eventtype = required_param('eventtype', PARAM_ALPHAEXT);
    $metadata  = optional_param('metadata', '', PARAM_TEXT);

    $result = exam_monitor::record_violation($sessionid, $USER->id, $eventtype, $metadata);
    echo json_encode([
        'success'       => true,
        'warning_level' => $result['warning_level'],
        'status'        => $result['status'],
        'is_suspended'  => $result['is_suspended'],
        'message'       => $result['message'],
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
exit;

