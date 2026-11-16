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
 * High-performance AJAX endpoint for CBT Stealth Webcam Proctoring.
 *
 * Facilitates real-time silent frame streaming from examinee webcams,
 * live camera inspection for proctors (CCTV/Videocall style), and
 * instant evidence snapshot preservation.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cbt_engine.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

global $USER, $DB, $CFG;

$action = optional_param('action', '', PARAM_ALPHAEXT);
$sessionid = optional_param('session_id', 0, PARAM_INT);

$camdir = $CFG->dataroot . '/cbt_cam';
if (!is_dir($camdir)) {
    @mkdir($camdir, 0777, true);
}

// -------------------------------------------------------------
// 1. PUSH FRAME (Examinee streams camera frame to server)
// -------------------------------------------------------------
if ($action === 'push_frame') {
    if ($sessionid <= 0) {
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $sess = \local_lumina_cbt\cbt_engine::start_or_get_session((int)$cm->course, $cmid, (int)$USER->id);
            $sessionid = (int)$sess->id;
        }
    }

    if ($sessionid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid session']);
        exit;
    }

    $rawframe = optional_param('frame', '', PARAM_RAW);
    if (empty($rawframe)) {
        echo json_encode(['success' => false, 'error' => 'Empty frame']);
        exit;
    }

    // Strip base64 prefix if present
    if (strpos($rawframe, 'base64,') !== false) {
        $rawframe = explode('base64,', $rawframe)[1];
    }

    $imgdata = base64_decode($rawframe);
    if (!$imgdata) {
        echo json_encode(['success' => false, 'error' => 'Invalid image payload']);
        exit;
    }

    $jpgfile = $camdir . '/session_' . $sessionid . '.jpg';
    $metafile = $camdir . '/session_' . $sessionid . '.json';

    file_put_contents($jpgfile, $imgdata, LOCK_EX);

    $meta = [
        'session_id' => $sessionid,
        'userid'     => (int)$USER->id,
        'timestamp'  => time(),
        'ip'         => getremoteaddr(),
    ];
    file_put_contents($metafile, json_encode($meta), LOCK_EX);

    echo json_encode([
        'success'   => true,
        'timestamp' => time(),
    ]);
    exit;
}

// -------------------------------------------------------------
// Authorization Check for Proctor Endpoints (get_frame, etc.)
// -------------------------------------------------------------
$canmonitor = local_lumina_cbt_can_monitor_cached() ||
              is_siteadmin() ||
              has_capability('local/lumina_cbt:monitor', \context_system::instance()) ||
              has_capability('moodle/grade:viewall', \context_system::instance()) ||
              has_capability('moodle/course:manageactivities', \context_system::instance());

if (!$canmonitor) {
    echo json_encode(['success' => false, 'error' => 'Access denied: Proctor privilege required.']);
    exit;
}

// -------------------------------------------------------------
// 2. GET FRAME (Proctor requests student's latest webcam frame)
// -------------------------------------------------------------
if ($action === 'get_frame') {
    if ($sessionid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing session_id']);
        exit;
    }

    $jpgfile  = $camdir . '/session_' . $sessionid . '.jpg';
    $metafile = $camdir . '/session_' . $sessionid . '.json';

    if (!file_exists($jpgfile)) {
        echo json_encode([
            'success'     => true,
            'has_frame'   => false,
            'is_live'     => false,
            'message'     => 'Kamera siswa belum aktif atau izin belum diberikan.',
        ]);
        exit;
    }

    $mtime = filemtime($jpgfile);
    $age = time() - $mtime;
    $islive = ($age <= 10); // Live if frame updated within last 10 seconds

    $data = file_get_contents($jpgfile);
    $base64 = 'data:image/jpeg;base64,' . base64_encode($data);

    echo json_encode([
        'success'     => true,
        'has_frame'   => true,
        'is_live'     => $islive,
        'age_seconds' => $age,
        'timestamp'   => $mtime,
        'frame'       => $base64,
        'time_str'    => userdate($mtime, '%H:%M:%S'),
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. GET ACTIVE CAMERAS (Map of all sessions with live camera)
// -------------------------------------------------------------
if ($action === 'get_active_cameras') {
    $now = time();
    $activeSessions = [];

    $files = glob($camdir . '/session_*.json');
    if ($files) {
        foreach ($files as $file) {
            $json = @file_get_contents($file);
            if ($json) {
                $meta = json_decode($json, true);
                if (!empty($meta['timestamp']) && ($now - $meta['timestamp']) <= 12) {
                    $sid = (int)($meta['session_id'] ?? 0);
                    if ($sid > 0) {
                        $activeSessions[$sid] = [
                            'is_live'   => true,
                            'age'       => $now - $meta['timestamp'],
                            'timestamp' => $meta['timestamp'],
                        ];
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'active'  => $activeSessions,
        'count'   => count($activeSessions),
    ]);
    exit;
}

// -------------------------------------------------------------
// 4. SAVE EVIDENCE SNAPSHOT (Capture & log proof of cheating)
// -------------------------------------------------------------
if ($action === 'save_evidence') {
    if ($sessionid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing session_id']);
        exit;
    }

    $jpgfile = $camdir . '/session_' . $sessionid . '.jpg';
    if (!file_exists($jpgfile)) {
        echo json_encode(['success' => false, 'error' => 'Tidak ada tangkapan frame untuk sesi ini.']);
        exit;
    }

    $evidencedir = $CFG->dataroot . '/cbt_evidence';
    if (!is_dir($evidencedir)) {
        @mkdir($evidencedir, 0777, true);
    }

    $ts = time();
    $destfile = $evidencedir . '/evidence_' . $sessionid . '_' . $ts . '.jpg';
    copy($jpgfile, $destfile);

    // Record audit event
    $sessiontable = \local_lumina_cbt\cbt_engine::get_session_table();
    $eventstable  = \local_lumina_cbt\cbt_engine::get_events_table();

    $sess = $DB->get_record($sessiontable, ['id' => $sessionid]);
    if ($sess) {
        $ev = new stdClass();
        $ev->sessionid = $sess->id;
        $ev->userid    = $sess->userid;
        $ev->eventtype = 'camera_snapshot';
        $ev->eventtime = $ts;
        $ev->metadata  = 'Pengawas mengambil tangkapan layar bukti (Live Camera Snapshot oleh: ' . fullname($USER) . ').';
        $DB->insert_record($eventstable, $ev);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Foto bukti pengawas berhasil diabadikan ke dalam audit log.',
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);

