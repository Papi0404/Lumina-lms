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

$sessionid = required_param('sessionid', PARAM_INT);
$eventtype = required_param('eventtype', PARAM_ALPHAEXT);
$metadata  = optional_param('metadata', '', PARAM_TEXT);

try {
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

