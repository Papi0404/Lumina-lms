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

// CRITICAL PERFORMANCE BOOST: Release PHP session lock immediately!
// This enables non-blocking, fully concurrent camera frame streaming & viewing without queuing.
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

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

    // Only update metadata JSON file every 8 seconds to minimize disk I/O overhead
    if (!file_exists($metafile) || (time() - filemtime($metafile)) >= 8) {
        $meta = [
            'session_id' => $sessionid,
            'userid'     => (int)$USER->id,
            'timestamp'  => time(),
            'ip'         => getremoteaddr(),
        ];
        @file_put_contents($metafile, json_encode($meta), LOCK_EX);
    }

    echo json_encode([
        'success'   => true,
        'timestamp' => time(),
    ]);
    exit;
}

// -------------------------------------------------------------
// 1b. CHECKPOINT PHOTO (Automated 3-Point Facial Verification)
// Checkpoint 1: Exam Start ('start')
// Checkpoint 2: 5 Minutes In ('5min')
// Checkpoint 3: On Submit ('submit')
// -------------------------------------------------------------
if ($action === 'checkpoint_photo') {
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

    $checkpoint = optional_param('checkpoint', 'start', PARAM_ALPHAEXT);
    $rawframe = optional_param('frame', '', PARAM_RAW);

    // If frame is empty, check if we have a recent streaming frame in $camdir
    $imgdata = null;
    if (!empty($rawframe)) {
        if (strpos($rawframe, 'base64,') !== false) {
            $rawframe = explode('base64,', $rawframe)[1];
        }
        $imgdata = base64_decode($rawframe);
    } else {
        $existingframe = $camdir . '/session_' . $sessionid . '.jpg';
        if (file_exists($existingframe)) {
            $imgdata = @file_get_contents($existingframe);
        }
    }

    if (!$imgdata) {
        echo json_encode(['success' => false, 'error' => 'No image data available']);
        exit;
    }

    $evidencedir = $CFG->dataroot . '/cbt_evidence';
    if (!is_dir($evidencedir)) {
        @mkdir($evidencedir, 0777, true);
    }

    $now = time();
    $destfile = $evidencedir . '/evidence_' . $sessionid . '_' . $now . '.jpg';
    file_put_contents($destfile, $imgdata, LOCK_EX);

    // Also update current frame for live CCTV preview
    $jpgfile = $camdir . '/session_' . $sessionid . '.jpg';
    @file_put_contents($jpgfile, $imgdata, LOCK_EX);

    // Record audit event
    $eventstable = \local_lumina_cbt\cbt_engine::get_events_table();
    $eventtype = 'photo_' . $checkpoint;
    $checkpointlabels = [
        'start'  => 'Foto Verifikasi Wajah: Mulai Masuk Ujian',
        '5min'   => 'Foto Verifikasi Wajah: Menit ke-5 Pengerjaan Ujian',
        'submit' => 'Foto Verifikasi Wajah: Saat Pengumpulan Ujian (Selesai)',
    ];

    $ev = new stdClass();
    $ev->sessionid = $sessionid;
    $ev->userid    = (int)$USER->id;
    $ev->eventtype = $eventtype;
    $ev->eventtime = $now;
    $ev->metadata  = $checkpointlabels[$checkpoint] ?? ('Foto Verifikasi Otomatis (' . clean_text($checkpoint) . ')');
    $DB->insert_record($eventstable, $ev);

    // Opportunistic cleanup: Purge photos older than 14 days (2 weeks) if not cleaned in the last 6 hours
    $lastcleanup = (int)get_config('local_lumina_cbt', 'last_photo_cleanup');
    if (($now - $lastcleanup) >= 21600) { // 6 hours
        \local_lumina_cbt\cbt_engine::cleanup_expired_photos(14);
    }

    echo json_encode([
        'success'    => true,
        'checkpoint' => $checkpoint,
        'timestamp'  => $now,
        'message'    => 'Foto checkpoint (' . $checkpoint . ') berhasil diabadikan.',
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

    $jpgfile = $camdir . '/session_' . $sessionid . '.jpg';

    if (!file_exists($jpgfile)) {
        echo json_encode([
            'success'     => true,
            'has_frame'   => false,
            'is_live'     => false,
            'message'     => 'Kamera siswa belum aktif atau izin belum diberikan.',
        ]);
        exit;
    }

    $since = optional_param('since', 0, PARAM_INT);
    $mtime = filemtime($jpgfile);

    // Fast Long-Poll: If client provided 'since' and file hasn't updated yet,
    // wait up to 1 second (checking every 80ms) for an incoming frame from student
    if ($since > 0 && $mtime <= $since) {
        $waited = 0;
        while ($waited < 12) { // 12 x 80ms = ~960ms max wait
            usleep(80000); // 80ms
            clearstatcache(true, $jpgfile);
            $mtime = filemtime($jpgfile);
            if ($mtime > $since) {
                break;
            }
            $waited++;
        }
        if ($mtime <= $since) {
            echo json_encode([
                'success'   => true,
                'unchanged' => true,
                'is_live'   => ((time() - $mtime) <= 8),
                'timestamp' => $mtime,
            ]);
            exit;
        }
    }

    $age = time() - $mtime;
    $islive = ($age <= 8);

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

// -------------------------------------------------------------
// 5. VIEW / DOWNLOAD EVIDENCE PHOTO
// -------------------------------------------------------------
if ($action === 'view_evidence') {
    $ts = optional_param('ts', 0, PARAM_INT);
    if ($sessionid <= 0 || $ts <= 0) {
        header('HTTP/1.0 400 Bad Request');
        echo 'Parameter foto bukti tidak valid.';
        exit;
    }

    $evidencedir = $CFG->dataroot . '/cbt_evidence';
    $file = $evidencedir . '/evidence_' . $sessionid . '_' . $ts . '.jpg';

    // If exact timestamp not found, find closest within +/- 5 seconds
    if (!file_exists($file)) {
        $candidates = @glob($evidencedir . '/evidence_' . $sessionid . '_*.jpg');
        if ($candidates) {
            foreach ($candidates as $c) {
                if (preg_match('/evidence_\d+_(\d+)\.jpg$/', $c, $m)) {
                    $candTs = (int)$m[1];
                    if (abs($candTs - $ts) <= 5) {
                        $file = $c;
                        break;
                    }
                }
            }
        }
    }

    if (!file_exists($file)) {
        header('HTTP/1.0 404 Not Found');
        echo 'Foto bukti tidak ditemukan atau sudah dibersihkan.';
        exit;
    }

    $download = optional_param('download', 0, PARAM_INT);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    if ($download) {
        header('Content-Disposition: attachment; filename="bukti_cbt_sesi_' . $sessionid . '_' . $ts . '.jpg"');
    } else {
        header('Content-Disposition: inline; filename="bukti_cbt_sesi_' . $sessionid . '_' . $ts . '.jpg"');
    }
    readfile($file);
    exit;
}

// -------------------------------------------------------------
// 6. PURGE EXPIRED PHOTOS (Older than 14 days / 2 weeks)
// -------------------------------------------------------------
if ($action === 'cleanup_photos') {
    $days = optional_param('days', 14, PARAM_INT);
    $result = \local_lumina_cbt\cbt_engine::cleanup_expired_photos($days);
    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);

