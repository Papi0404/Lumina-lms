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
 * Exam Proctoring & Monitoring Engine for Lumina LMS Core.
 *
 * Implements 3-Warning Protocol, fullscreen lock, blur detection, and Admin Review queue.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

use stdClass;

class exam_monitor {

    /**
     * Start or retrieve an exam session for a student.
     *
     * @param int $courseid
     * @param int $activityid (cmid)
     * @param int $userid
     * @return stdClass
     */
    public static function start_or_get_session(int $courseid, int $activityid, int $userid): stdClass {
        global $DB;

        // Always query the latest session by id DESC
        $sessions = $DB->get_records_select(
            'local_lumina_exam_sessions',
            'courseid = ? AND activityid = ? AND userid = ?',
            [$courseid, $activityid, $userid],
            'id DESC',
            '*',
            0,
            1
        );

        if (!empty($sessions)) {
            return reset($sessions);
        }

        $newsess = new stdClass();
        $newsess->courseid = $courseid;
        $newsess->activityid = $activityid;
        $newsess->userid = $userid;
        $newsess->status = 'in_progress';
        $newsess->warningcount = 0;
        $newsess->score = null;
        $newsess->adminreviewerid = 0;
        $newsess->adminreviewtime = 0;
        $newsess->adminreviewnotes = null;
        $newsess->timestart = time();
        $newsess->timefinish = 0;

        $newsess->id = $DB->insert_record('local_lumina_exam_sessions', $newsess);
        return $newsess;
    }

    /**
     * Log a proctoring violation event and return current warning level and action.
     *
     * @param int $sessionid
     * @param int $userid
     * @param string $eventtype ('blur', 'visibility_hidden', 'mouseleave', 'fullscreen_exit')
     * @param string $metadata
     * @return array
     */
    public static function record_violation(int $sessionid, int $userid, string $eventtype, string $metadata = ''): array {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $now = time();

        // 1. Log event
        $ev = new stdClass();
        $ev->sessionid = $sessionid;
        $ev->userid = $userid;
        $ev->eventtype = clean_text($eventtype);
        $ev->eventtime = $now;
        $ev->metadata = clean_text($metadata);
        $DB->insert_record('local_lumina_exam_events', $ev);

        // If already suspended/under_review, don't re-increment
        if ($session->status === 'under_review' || $session->status === 'invalidated') {
            return [
                'warning_level' => 3,
                'status'        => $session->status,
                'is_suspended'  => true,
                'message'       => 'Ujian Anda telah ditangguhkan untuk pemeriksaan Administrator.',
            ];
        }

        // Increment warning
        $newwarning = min(3, (int)$session->warningcount + 1);
        $session->warningcount = $newwarning;

        $reasonlabel = 'Terdeteksi meninggalkan layar ujian';
        if ($eventtype === 'keyboard_attempt') {
            $reasonlabel = 'Papan ketik (Keyboard) ditekan (Dilarang, hanya mouse yang diizinkan)';
        } else if ($eventtype === 'three_finger_gesture' || $eventtype === 'three_finger_gesture_or_minimize') {
            $reasonlabel = 'Percobaan gesture 3 jari touchpad / meminimalkan jendela ujian';
        } else if ($eventtype === 'trackpad_gesture') {
            $reasonlabel = 'Percobaan gesture zoom / swipe pada trackpad';
        } else if ($eventtype === 'fullscreen_exit') {
            $reasonlabel = 'Keluar dari mode Layar Penuh (Fullscreen)';
        } else if ($eventtype === 'blur') {
            $reasonlabel = 'Jendela browser diminimalkan / beralih aplikasi (Alt+Tab / Gesture)';
        } else if ($eventtype === 'visibility_hidden') {
            $reasonlabel = 'Tab browser disembunyikan / beralih tab lain';
        } else if ($eventtype === 'mouseleave') {
            $reasonlabel = 'Kursor mouse meninggalkan batas layar ujian';
        } else if ($eventtype === 'clipboard_copy') {
            $reasonlabel = 'Percobaan menyalin teks / copy-paste soal';
        }

        // Record warning log
        $w = new stdClass();
        $w->sessionid = $sessionid;
        $w->warninglevel = $newwarning;
        $w->reason = $reasonlabel . (!empty($metadata) ? " ($metadata)" : '');
        $w->issuedat = $now;
        $DB->insert_record('local_lumina_exam_warnings', $w);

        $issuspended = false;
        if ($newwarning >= 3) {
            $session->status = 'under_review';
            $session->timefinish = $now;
            $issuspended = true;
        }

        $DB->update_record('local_lumina_exam_sessions', $session);

        $messages = [
            1 => 'Peringatan 1 dari 3: ' . $reasonlabel . '. Tetap di layar ujian & gunakan mouse!',
            2 => 'Peringatan 2 dari 3: Pelanggaran terdeteksi (' . $reasonlabel . ')! Satu pelanggaran lagi, ujian Anda akan otomatis dikunci & ditangguhkan ke Administrator!',
            3 => 'Peringatan 3 dari 3: Batas pelanggaran tercapai. Ujian Anda telah ditangguhkan dan dikunci untuk peninjauan Administrator.',
        ];

        return [
            'warning_level' => $newwarning,
            'status'        => $session->status,
            'is_suspended'  => $issuspended,
            'message'       => $messages[$newwarning] ?? '',
        ];
    }

    /**
     * Human readable event type labels.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_label(string $eventtype): string {
        $labels = [
            'keyboard_attempt' => 'Papan Ketik Ditekan (Diblokir)',
            'three_finger_gesture' => 'Gesture 3 Jari Touchpad',
            'three_finger_gesture_or_minimize' => 'Percobaan Minimize Layar',
            'trackpad_gesture' => 'Gesture Trackpad / Pinch Zoom',
            'fullscreen_exit' => 'Keluar Layar Penuh (Fullscreen)',
            'blur' => 'Pindah Jendela / Alt+Tab',
            'visibility_hidden' => 'Pindah Tab Browser',
            'mouseleave' => 'Kursor Keluar Layar',
            'clipboard_copy' => 'Percobaan Copy / Salin Teks',
            'exam_submitted' => 'Ujian Dikumpulkan',
        ];
        return $labels[$eventtype] ?? ('Pelanggaran (' . $eventtype . ')');
    }

    /**
     * Badge CSS class for event type.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_badge_class(string $eventtype): string {
        $classes = [
            'keyboard_attempt' => 'bg-danger text-white',
            'three_finger_gesture' => 'bg-danger text-white',
            'three_finger_gesture_or_minimize' => 'bg-danger text-white',
            'fullscreen_exit' => 'bg-danger text-white',
            'trackpad_gesture' => 'bg-warning text-dark',
            'blur' => 'bg-warning text-dark',
            'visibility_hidden' => 'bg-warning text-dark',
            'mouseleave' => 'bg-secondary text-white',
            'clipboard_copy' => 'bg-danger text-white',
        ];
        return $classes[$eventtype] ?? 'bg-secondary text-white';
    }

    /**
     * Material symbol icon for event type.
     *
     * @param string $eventtype
     * @return string
     */
    public static function get_event_icon(string $eventtype): string {
        $icons = [
            'keyboard_attempt' => 'keyboard_off',
            'three_finger_gesture' => 'pan_tool',
            'three_finger_gesture_or_minimize' => 'minimize',
            'trackpad_gesture' => 'pinch',
            'fullscreen_exit' => 'fullscreen_exit',
            'blur' => 'tab_unselected',
            'visibility_hidden' => 'visibility_off',
            'mouseleave' => 'mouse',
            'clipboard_copy' => 'content_copy',
        ];
        return $icons[$eventtype] ?? 'warning';
    }

    /**
     * Get all events for a specific exam session.
     *
     * @param int $sessionid
     * @return array
     */
    public static function get_session_events(int $sessionid): array {
        global $DB;

        $events = $DB->get_records_select(
            'local_lumina_exam_events',
            'sessionid = ? AND eventtype != ?',
            [$sessionid, 'exam_submitted'],
            'id DESC',
            '*',
            0,
            100
        );

        $list = [];
        foreach ($events as $ev) {
            $list[] = [
                'id' => (int)$ev->id,
                'eventtype' => $ev->eventtype,
                'label' => self::get_event_label($ev->eventtype),
                'badge_class' => self::get_event_badge_class($ev->eventtype),
                'icon' => self::get_event_icon($ev->eventtype),
                'time' => userdate($ev->eventtime, '%H:%M:%S WIB'),
                'date' => userdate($ev->eventtime, '%d %b %Y, %H:%M:%S'),
                'metadata' => format_string($ev->metadata ?: '-'),
            ];
        }
        return $list;
    }

    /**
     * Get live audit feed of all recent violations.
     *
     * @param int $since
     * @return array
     */
    public static function get_live_audit_feed(int $since = 0): array {
        global $DB;

        $params = ['exam_submitted'];
        $where = "ev.eventtype != ?";
        if ($since > 0) {
            $where .= " AND ev.eventtime > ?";
            $params[] = $since;
        }

        $sql = "SELECT ev.id, ev.sessionid, ev.userid, ev.eventtype, ev.eventtime, ev.metadata,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email,
                       s.warningcount, s.status,
                       c.shortname AS courseshortname
                  FROM {local_lumina_exam_events} ev
                  JOIN {user} u ON u.id = ev.userid
                  JOIN {local_lumina_exam_sessions} s ON s.id = ev.sessionid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE $where
              ORDER BY ev.id DESC";

        $records = $DB->get_records_sql($sql, $params, 0, 50);
        $feed = [];
        $sysctx = \context_system::instance();
        foreach ($records as $r) {
            $feed[] = [
                'id' => (int)$r->id,
                'sessionid' => (int)$r->sessionid,
                'student_name' => fullname($r),
                'student_email' => $r->email,
                'course' => format_string($r->courseshortname, true, ['context' => $sysctx]),
                'warning_count' => (int)$r->warningcount,
                'eventtype' => $r->eventtype,
                'label' => self::get_event_label($r->eventtype),
                'icon' => self::get_event_icon($r->eventtype),
                'badge_class' => self::get_event_badge_class($r->eventtype),
                'metadata' => format_string($r->metadata ?: '-', true, ['context' => $sysctx]),
                'time_formatted' => userdate($r->eventtime, '%H:%M:%S WIB'),
                'timestamp' => (int)$r->eventtime,
            ];
        }
        return $feed;
    }

    /**
     * Get all exam sessions flagged for admin review.
     * Shows sessions with 1+ warnings or any logged violations!
     *
     * @return array
     */
    public static function get_sessions_for_review(): array {
        global $DB;

        $sql = "SELECT s.id, s.courseid, s.activityid, s.userid, s.status, s.warningcount,
                       s.score, s.timestart, s.timefinish,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email,
                       c.fullname AS coursename, c.shortname AS courseshortname
                  FROM {local_lumina_exam_sessions} s
                  JOIN {user} u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.status IN ('under_review', 'invalidated', 'approved')
                    OR s.warningcount >= 1
                    OR EXISTS (
                        SELECT 1 FROM {local_lumina_exam_events} ev
                         WHERE ev.sessionid = s.id AND ev.eventtype != 'exam_submitted'
                    )
              ORDER BY s.warningcount DESC, s.id DESC";

        $rows = $DB->get_records_sql($sql);
        $result = [];

        foreach ($rows as $r) {
            $eventcount = $DB->count_records_select('local_lumina_exam_events', 'sessionid = ? AND eventtype != ?', [$r->id, 'exam_submitted']);
            $latestevents = $DB->get_records_select('local_lumina_exam_events', 'sessionid = ? AND eventtype != ?', [$r->id, 'exam_submitted'], 'id DESC', 'eventtype, metadata, eventtime', 0, 1);
            $latestevent = !empty($latestevents) ? reset($latestevents) : null;
            $activityname = $DB->get_field_sql(
                "SELECT COALESCE(h.name, a.name, 'Ujian H5P / Kuis')
                   FROM {course_modules} cm
              LEFT JOIN {h5pactivity} h ON h.id = cm.instance
              LEFT JOIN {assign} a ON a.id = cm.instance
                  WHERE cm.id = ?",
                [$r->activityid]
            );

            $latestlabel = '-';
            $latesttime = '-';
            if ($latestevent) {
                $latestlabel = self::get_event_label($latestevent->eventtype) . (!empty($latestevent->metadata) ? ' (' . $latestevent->metadata . ')' : '');
                $latesttime = userdate($latestevent->eventtime, '%H:%M:%S WIB');
            }

            $sysctx = \context_system::instance();
            $result[] = [
                'session_id'       => $r->id,
                'course_name'      => format_string($r->coursename, true, ['context' => $sysctx]),
                'activity_name'    => format_string($activityname ?: 'Ujian Interaktif H5P', true, ['context' => $sysctx]),
                'student_name'     => fullname($r),
                'student_email'    => $r->email,
                'warning_count'    => (int)$r->warningcount,
                'score'            => $r->score !== null ? (float)$r->score : null,
                'event_count'      => $eventcount,
                'latest_violation' => $latestlabel,
                'latest_time'      => $latesttime,
                'start_time'       => userdate($r->timestart, '%d %b %Y, %H:%M'),
                'finish_time'      => $r->timefinish ? userdate($r->timefinish, '%d %b %Y, %H:%M') : 'Sedang Berlangsung',
                'status'           => $r->status,
                'events'           => self::get_session_events((int)$r->id),
            ];
        }

        return $result;
    }

    /**
     * Admin review resolution: Approve or Invalidate session.
     *
     * @param int $sessionid
     * @param int $adminid
     * @param string $decision ('approved' | 'invalidated')
     * @param string $notes
     * @return bool
     */
    public static function resolve_session(int $sessionid, int $adminid, string $decision, string $notes = ''): bool {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        if ($decision === 'reset') {
            // Admin ACC & Reset Ujian: Kembalikan sesi ke status in_progress dengan peringatan 0/3
            $session->status = 'in_progress';
            $session->warningcount = 0;
            $session->score = null;
            $session->timefinish = 0;
            $session->adminreviewerid = $adminid;
            $session->adminreviewtime = time();
            $session->adminreviewnotes = clean_text($notes ?: 'Di-ACC & di-reset oleh Admin agar murid dapat mengerjakan ulang.');

            // Hapus log pelanggaran dan peringatan lama agar murid mulai dengan 0/3 peringatan
            $DB->delete_records('local_lumina_exam_warnings', ['sessionid' => $sessionid]);
            $DB->delete_records('local_lumina_exam_events', ['sessionid' => $sessionid]);

            $res = $DB->update_record('local_lumina_exam_sessions', $session);
            self::sync_grade_to_gradebook((int)$session->activityid, (int)$session->userid, null);
            return $res;
        }

        $session->status = in_array($decision, ['approved', 'invalidated'], true) ? $decision : 'under_review';
        $session->adminreviewerid = $adminid;
        $session->adminreviewtime = time();
        $session->adminreviewnotes = clean_text($notes);

        if ($decision === 'approved') {
            $session->warningcount = 0; // Cleared and approved by admin
            // Restore legitimate submitted score if current score is null or 0.00
            if ($session->score === null || (float)$session->score <= 0) {
                $submitevent = $DB->get_record('local_lumina_exam_events', [
                    'sessionid' => $sessionid,
                    'eventtype' => 'exam_submitted',
                ]);
                if ($submitevent && !empty($submitevent->metadata)) {
                    $meta = json_decode($submitevent->metadata, true);
                    if (isset($meta['score'])) {
                        $session->score = number_format((float)$meta['score'], 2, '.', '');
                    }
                }
            }
        } else if ($decision === 'invalidated') {
            $session->score = 0.00;
        }

        $res = $DB->update_record('local_lumina_exam_sessions', $session);
        if ($session->score !== null) {
            self::sync_grade_to_gradebook((int)$session->activityid, (int)$session->userid, (float)$session->score);
        }
        return $res;
    }

    /**
     * Save student's in-progress answers and doubtful states to server.
     *
     * @param int $sessionid
     * @param int $userid
     * @param array $answers
     * @param array $doubtful
     * @return bool
     */
    public static function save_progress(int $sessionid, int $userid, array $answers, array $doubtful): bool {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid, 'userid' => $userid], '*', MUST_EXIST);
        if ($session->status === 'completed' || $session->status === 'under_review') {
            return false;
        }

        $payload = json_encode([
            'progress' => true,
            'answers'  => $answers,
            'doubtful' => $doubtful,
            'updated'  => time(),
        ]);

        $session->adminreviewnotes = $payload;
        return $DB->update_record('local_lumina_exam_sessions', $session);
    }

    /**
     * Retrieve question set for a given course module (CBT exam).
     *
     * @param int $cmid
     * @return array
     */
    public static function get_exam_questions(int $cmid): array {
        global $DB;

        // 1. If it's a native Moodle Quiz (mod_quiz)
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if ($cm && $cm->modname === 'quiz') {
            try {
                $sql = "SELECT q.id, q.questiontext, q.qtype, qs.slot
                          FROM {quiz_slots} qs
                          JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                          JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                          JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                          JOIN {question} q ON q.id = qv.questionid
                         WHERE qs.quizid = ?
                      ORDER BY qs.slot ASC";
                $quizq = $DB->get_records_sql($sql, [$cm->instance]);
                if (!empty($quizq)) {
                    $extracted = [];
                    $slotnum = 1;
                    foreach ($quizq as $qq) {
                        $answers = $DB->get_records('question_answers', ['question' => $qq->id], 'id ASC');
                        $opts = [];
                        $correctidx = 0;
                        $aidx = 0;
                        foreach ($answers as $ans) {
                            if ($ans->fraction > 0) {
                                $correctidx = $aidx;
                            }
                            $opts[] = [
                                'index' => $aidx,
                                'label' => chr(65 + $aidx),
                                'text'  => strip_tags($ans->answer),
                            ];
                            $aidx++;
                        }
                        $extracted[] = [
                            'id'            => $qq->id,
                            'number'        => $slotnum,
                            'question'      => strip_tags($qq->questiontext),
                            'options'       => $opts,
                            'correct_index' => $correctidx,
                        ];
                        $slotnum++;
                    }
                    if (!empty($extracted)) {
                        return $extracted;
                    }
                }
            } catch (\Throwable $qe) {
                debugging('Error loading quiz questions: ' . $qe->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'sortorder', false);
            if (!empty($files)) {
                $f = reset($files);
                $content = $f->get_content();
                $tmp = tempnam(sys_get_temp_dir(), 'h5pq_');
                file_put_contents($tmp, $content);
                $zip = new \ZipArchive();
                if ($zip->open($tmp) === true) {
                    $json = $zip->getFromName('content/content.json');
                    if (!$json) {
                        $json = $zip->getFromName('content.json');
                    }
                    if ($json) {
                        $data = json_decode($json, true);
                        if (!empty($data['questions'])) {
                            $extracted = [];
                            foreach ($data['questions'] as $idx => $q) {
                                $params = $q['params'] ?? [];
                                $qtext = strip_tags($params['question'] ?? ('Soal ' . ($idx + 1)));
                                $opts = [];
                                $correctidx = 0;
                                foreach ($params['answers'] ?? [] as $aidx => $ans) {
                                    $iscorrect = !empty($ans['correct']);
                                    if ($iscorrect) {
                                        $correctidx = $aidx;
                                    }
                                    $opts[] = [
                                        'index' => $aidx,
                                        'label' => chr(65 + $aidx),
                                        'text'  => strip_tags($ans['text'] ?? ''),
                                    ];
                                }
                                $extracted[] = [
                                    'id'            => $idx + 1,
                                    'number'        => $idx + 1,
                                    'question'      => $qtext,
                                    'options'       => $opts,
                                    'correct_index' => $correctidx,
                                ];
                            }
                            $zip->close();
                            @unlink($tmp);
                            return $extracted;
                        }
                    }
                    $zip->close();
                }
                @unlink($tmp);
            }
        }

        return self::get_fallback_questions();
    }

    /**
     * Fallback standard 20 questions for PHP & MySQL.
     *
     * @return array
     */
    public static function get_fallback_questions(): array {
        return [
            ['id' => 1, 'number' => 1, 'question' => '1. Apa fungsi utama PHP dalam pengembangan web?', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Mengatur tampilan perangkat keras'],
                ['index' => 1, 'label' => 'B', 'text' => 'Memproses logika di sisi server'],
                ['index' => 2, 'label' => 'C', 'text' => 'Menggantikan sistem operasi'],
                ['index' => 3, 'label' => 'D', 'text' => 'Mengelola koneksi Wi-Fi'],
            ]],
            ['id' => 2, 'number' => 2, 'question' => '2. Kode PHP biasanya diawali dengan tag ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => '<script>'],
                ['index' => 1, 'label' => 'B', 'text' => '<?php'],
                ['index' => 2, 'label' => 'C', 'text' => '<php>'],
                ['index' => 3, 'label' => 'D', 'text' => '<code php>'],
            ]],
            ['id' => 3, 'number' => 3, 'question' => '3. Variabel dalam PHP diawali dengan simbol ...', 'correct_index' => 2, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => '#'],
                ['index' => 1, 'label' => 'B', 'text' => '@'],
                ['index' => 2, 'label' => 'C', 'text' => '$'],
                ['index' => 3, 'label' => 'D', 'text' => '&'],
            ]],
            ['id' => 4, 'number' => 4, 'question' => '4. Manakah contoh deklarasi variabel PHP yang benar?', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => "nama = 'Budi';"],
                ['index' => 1, 'label' => 'B', 'text' => "\$nama = 'Budi';"],
                ['index' => 2, 'label' => 'C', 'text' => "var nama = 'Budi';"],
                ['index' => 3, 'label' => 'D', 'text' => 'string $nama;'],
            ]],
            ['id' => 5, 'number' => 5, 'question' => '5. Operator untuk membandingkan nilai dan tipe data secara ketat di PHP adalah ...', 'correct_index' => 2, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => '='],
                ['index' => 1, 'label' => 'B', 'text' => '=='],
                ['index' => 2, 'label' => 'C', 'text' => '==='],
                ['index' => 3, 'label' => 'D', 'text' => '!='],
            ]],
            ['id' => 6, 'number' => 6, 'question' => '6. Fungsi echo dalam PHP digunakan untuk ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Menghapus variabel'],
                ['index' => 1, 'label' => 'B', 'text' => 'Menampilkan output'],
                ['index' => 2, 'label' => 'C', 'text' => 'Membuat database'],
                ['index' => 3, 'label' => 'D', 'text' => 'Menghentikan server'],
            ]],
            ['id' => 7, 'number' => 7, 'question' => '7. Struktur percabangan PHP yang tepat adalah ...', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'if ... else'],
                ['index' => 1, 'label' => 'B', 'text' => 'repeat ... until'],
                ['index' => 2, 'label' => 'C', 'text' => 'select ... case only'],
                ['index' => 3, 'label' => 'D', 'text' => 'foreach ... else'],
            ]],
            ['id' => 8, 'number' => 8, 'question' => '8. Perulangan yang cocok untuk menelusuri setiap elemen array adalah ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'switch'],
                ['index' => 1, 'label' => 'B', 'text' => 'foreach'],
                ['index' => 2, 'label' => 'C', 'text' => 'try'],
                ['index' => 3, 'label' => 'D', 'text' => 'return'],
            ]],
            ['id' => 9, 'number' => 9, 'question' => '9. Fungsi count($data) digunakan untuk ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Mengurutkan array'],
                ['index' => 1, 'label' => 'B', 'text' => 'Menghitung jumlah elemen'],
                ['index' => 2, 'label' => 'C', 'text' => 'Menghapus array'],
                ['index' => 3, 'label' => 'D', 'text' => 'Mengubah array menjadi string'],
            ]],
            ['id' => 10, 'number' => 10, 'question' => '10. Apa kepanjangan SQL?', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Structured Query Language'],
                ['index' => 1, 'label' => 'B', 'text' => 'System Question Logic'],
                ['index' => 2, 'label' => 'C', 'text' => 'Sequential Query Link'],
                ['index' => 3, 'label' => 'D', 'text' => 'Standard Quick Language'],
            ]],
            ['id' => 11, 'number' => 11, 'question' => '11. Perintah SQL untuk mengambil data dari tabel adalah ...', 'correct_index' => 2, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'GET'],
                ['index' => 1, 'label' => 'B', 'text' => 'OPEN'],
                ['index' => 2, 'label' => 'C', 'text' => 'SELECT'],
                ['index' => 3, 'label' => 'D', 'text' => 'FETCHTABLE'],
            ]],
            ['id' => 12, 'number' => 12, 'question' => '12. Perintah untuk menambahkan baris baru ke tabel adalah ...', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'INSERT INTO'],
                ['index' => 1, 'label' => 'B', 'text' => 'ALTER TABLE'],
                ['index' => 2, 'label' => 'C', 'text' => 'UPDATE'],
                ['index' => 3, 'label' => 'D', 'text' => 'CREATE USER'],
            ]],
            ['id' => 13, 'number' => 13, 'question' => '13. Klausa WHERE pada SQL berfungsi untuk ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Mengurutkan hasil'],
                ['index' => 1, 'label' => 'B', 'text' => 'Memfilter baris berdasarkan kondisi'],
                ['index' => 2, 'label' => 'C', 'text' => 'Menghapus tabel'],
                ['index' => 3, 'label' => 'D', 'text' => 'Menggabungkan database'],
            ]],
            ['id' => 14, 'number' => 14, 'question' => '14. Perintah untuk mengubah data yang sudah ada adalah ...', 'correct_index' => 2, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'CHANGE'],
                ['index' => 1, 'label' => 'B', 'text' => 'REPLACE TABLE'],
                ['index' => 2, 'label' => 'C', 'text' => 'UPDATE'],
                ['index' => 3, 'label' => 'D', 'text' => 'MODIFY ROW'],
            ]],
            ['id' => 15, 'number' => 15, 'question' => '15. Primary key berfungsi untuk ...', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Mengenkripsi seluruh tabel'],
                ['index' => 1, 'label' => 'B', 'text' => 'Mengidentifikasi setiap baris secara unik'],
                ['index' => 2, 'label' => 'C', 'text' => 'Menyimpan file gambar'],
                ['index' => 3, 'label' => 'D', 'text' => 'Mengurutkan database otomatis'],
            ]],
            ['id' => 16, 'number' => 16, 'question' => '16. Foreign key digunakan untuk ...', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Menghubungkan relasi antar tabel'],
                ['index' => 1, 'label' => 'B', 'text' => 'Mengganti nama database'],
                ['index' => 2, 'label' => 'C', 'text' => 'Menghapus indeks'],
                ['index' => 3, 'label' => 'D', 'text' => 'Membuat backup otomatis'],
            ]],
            ['id' => 17, 'number' => 17, 'question' => '17. Manakah yang termasuk DBMS relasional?', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'MySQL'],
                ['index' => 1, 'label' => 'B', 'text' => 'Photoshop'],
                ['index' => 2, 'label' => 'C', 'text' => 'Figma'],
                ['index' => 3, 'label' => 'D', 'text' => 'Apache HTTP Server'],
            ]],
            ['id' => 18, 'number' => 18, 'question' => '18. Apa tujuan prepared statement pada PHP-MySQL?', 'correct_index' => 1, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Mempercantik antarmuka'],
                ['index' => 1, 'label' => 'B', 'text' => 'Mengurangi risiko SQL injection'],
                ['index' => 2, 'label' => 'C', 'text' => 'Mempercepat koneksi internet'],
                ['index' => 3, 'label' => 'D', 'text' => 'Mengubah PHP menjadi Java'],
            ]],
            ['id' => 19, 'number' => 19, 'question' => '19. Metode HTTP yang lazim digunakan untuk mengirim data formulir adalah ...', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'POST'],
                ['index' => 1, 'label' => 'B', 'text' => 'TRACE'],
                ['index' => 2, 'label' => 'C', 'text' => 'HEAD'],
                ['index' => 3, 'label' => 'D', 'text' => 'OPTIONS'],
            ]],
            ['id' => 20, 'number' => 20, 'question' => '20. Apa kegunaan session pada aplikasi web?', 'correct_index' => 0, 'options' => [
                ['index' => 0, 'label' => 'A', 'text' => 'Menyimpan status pengguna antar-request'],
                ['index' => 1, 'label' => 'B', 'text' => 'Menghapus cache browser'],
                ['index' => 2, 'label' => 'C', 'text' => 'Mengganti alamat IP'],
                ['index' => 3, 'label' => 'D', 'text' => 'Membuat file CSS'],
            ]],
        ];
    }

    /**
     * Submit an exam attempt, calculate score, and mark session completed.
     *
     * @param int $sessionid
     * @param int $userid
     * @param array $useranswers [question_number => selected_option_index]
     * @return array
     */
    public static function submit_exam_attempt(int $sessionid, int $userid, array $useranswers): array {
        global $DB;

        $session = $DB->get_record('local_lumina_exam_sessions', ['id' => $sessionid, 'userid' => $userid], '*', MUST_EXIST);
        $questions = self::get_exam_questions((int)$session->activityid);
        $total = count($questions);
        if ($total === 0) {
            $total = 20;
        }

        $correct = 0;
        $wrong = 0;
        $unanswered = 0;

        foreach ($questions as $q) {
            $qnum = (int)$q['number'];
            if (!isset($useranswers[$qnum]) || $useranswers[$qnum] === null || $useranswers[$qnum] === '') {
                $unanswered++;
            } else if ((int)$useranswers[$qnum] === (int)$q['correct_index']) {
                $correct++;
            } else {
                $wrong++;
            }
        }

        $finalscore = round(($correct / $total) * 100, 2);
        $now = time();

        $session->score = $finalscore;
        $session->timefinish = $now;
        if ($session->status !== 'under_review') {
            $session->status = 'completed';
        }
        $DB->update_record('local_lumina_exam_sessions', $session);

        // Instantly push grade to Moodle Gradebook so it appears in student dashboard & teacher grading
        self::sync_grade_to_gradebook((int)$session->activityid, $userid, $finalscore);

        // Record submission event
        $ev = new \stdClass();
        $ev->sessionid = $sessionid;
        $ev->userid = $userid;
        $ev->eventtype = 'exam_submitted';
        $ev->eventtime = $now;
        $ev->metadata = json_encode([
            'score' => $finalscore,
            'correct' => $correct,
            'wrong' => $wrong,
            'unanswered' => $unanswered,
            'total' => $total,
        ]);
        $DB->insert_record('local_lumina_exam_events', $ev);

        return [
            'success'       => true,
            'session_id'    => $sessionid,
            'status'        => $session->status,
            'score'         => $finalscore,
            'correct'       => $correct,
            'wrong'         => $wrong,
            'unanswered'    => $unanswered,
            'total'         => $total,
            'warning_count' => (int)$session->warningcount,
            'is_suspended'  => ($session->status === 'under_review'),
            'submitted_at'  => userdate($now, '%d %b %Y, %H:%M WIB'),
        ];
    }

    /**
     * Automatically sync all H5P and CBT exam scheduled dates to Moodle's calendar {event} table.
     */
    public static function sync_cbt_events(): void {
        global $DB;

        try {
            // Find all h5pactivity course modules
            $sql = "SELECT cm.id AS cmid, cm.course, cm.instance, cm.completionexpected, cm.availability, h.name
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {h5pactivity} h ON h.id = cm.instance
                     WHERE m.name = 'h5pactivity'
                       AND cm.deletioninprogress = 0";
            $cms = $DB->get_records_sql($sql);

            foreach ($cms as $item) {
                $targetdate = (int)$item->completionexpected;

                // If completionexpected is 0, extract from availability
                if ($targetdate <= 0 && !empty($item->availability)) {
                    $avail = json_decode($item->availability, true);
                    if (!empty($avail['c'])) {
                        foreach ($avail['c'] as $cond) {
                            if (($cond['type'] ?? '') === 'date' && !empty($cond['t'])) {
                                $targetdate = (int)$cond['t'];
                                if (($cond['d'] ?? '') === '<') {
                                    break; // Prefer closing deadline
                                }
                            }
                        }
                    }
                }

                if ($targetdate > 0) {
                    $eventname = format_string($item->name) . ' is due';
                    $existing = $DB->get_record('event', ['modulename' => 'h5pactivity', 'instance' => $item->instance]);

                    if (!$existing) {
                        $ev = new \stdClass();
                        $ev->name = $eventname;
                        $ev->description = '<p>Ujian CBT Terpantau: ' . format_string($item->name) . '</p>';
                        $ev->format = FORMAT_HTML;
                        $ev->courseid = (int)$item->course;
                        $ev->groupid = 0;
                        $ev->userid = 0;
                        $ev->repeatid = 0;
                        $ev->component = '';
                        $ev->modulename = 'h5pactivity';
                        $ev->instance = (int)$item->instance;
                        $ev->type = 0;
                        $ev->eventtype = 'due';
                        $ev->timestart = $targetdate;
                        $ev->timeduration = 0;
                        $ev->visible = 1;
                        $ev->sequence = 1;
                        $ev->timemodified = time();
                        $DB->insert_record('event', $ev);
                    } else {
                        if ($existing->timestart != $targetdate || $existing->name !== $eventname) {
                            $existing->timestart = $targetdate;
                            $existing->name = $eventname;
                            $existing->timemodified = time();
                            $DB->update_record('event', $existing);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('Error syncing CBT calendar events: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Push or update exam score to Moodle's gradebook.
     *
     * @param int $activityid (cmid)
     * @param int $userid
     * @param float|null $score (null to reset/delete grade)
     */
    public static function sync_grade_to_gradebook(int $activityid, int $userid, ?float $score): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        try {
            $cm = get_coursemodule_from_id('', $activityid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }

            if ($score === null) {
                // Delete or reset grade
                $grade = ['userid' => $userid, 'delete' => 1];
            } else {
                $grade = new \stdClass();
                $grade->userid = $userid;
                $grade->rawgrade = (float)$score;
                $grade->finalgrade = (float)$score;
                $grade->dategraded = time();
                $grade->datesubmitted = time();
            }

            grade_update('mod/' . $cm->modname, $cm->course, 'mod', $cm->modname, $cm->instance, 0, $grade);
        } catch (\Throwable $e) {
            debugging('Error syncing CBT grade to gradebook: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

