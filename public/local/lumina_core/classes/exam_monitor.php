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

        $session = $DB->get_record('local_lumina_exam_sessions', [
            'courseid'   => $courseid,
            'activityid' => $activityid,
            'userid'     => $userid,
        ], '*', IGNORE_MULTIPLE);

        if ($session) {
            return $session;
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

        // Record warning log
        $w = new stdClass();
        $w->sessionid = $sessionid;
        $w->warninglevel = $newwarning;
        $w->reason = 'Terdeteksi meninggalkan layar ujian: ' . $eventtype;
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
            1 => 'Peringatan 1 dari 3: Jangan meninggalkan layar ujian! Setiap perpindahan jendela tercatat dalam sistem.',
            2 => 'Peringatan 2 dari 3: Pelanggaran terdeteksi! Satu kali lagi meninggalkan layar, ujian Anda akan otomatis ditangguhkan ke Administrator!',
            3 => 'Peringatan 3 dari 3: Ujian Anda telah ditangguhkan dan dikunci. Nilai Anda diserahkan ke Administrator untuk ditinjau.',
        ];

        return [
            'warning_level' => $newwarning,
            'status'        => $session->status,
            'is_suspended'  => $issuspended,
            'message'       => $messages[$newwarning] ?? '',
        ];
    }

    /**
     * Get all exam sessions flagged for admin review.
     *
     * @return array
     */
    public static function get_sessions_for_review(): array {
        global $DB;

        $sql = "SELECT s.id, s.courseid, s.activityid, s.userid, s.status, s.warningcount,
                       s.score, s.timestart, s.timefinish,
                       u.firstname, u.lastname, u.email,
                       c.fullname AS coursename, c.shortname AS courseshortname
                  FROM {local_lumina_exam_sessions} s
                  JOIN {user} u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.status = 'under_review'
              ORDER BY s.timefinish DESC, s.id DESC";

        $rows = $DB->get_records_sql($sql);
        $result = [];

        foreach ($rows as $r) {
            $eventcount = $DB->count_records('local_lumina_exam_events', ['sessionid' => $r->id]);
            $activityname = $DB->get_field_sql(
                "SELECT COALESCE(h.name, a.name, 'Ujian H5P / Kuis')
                   FROM {course_modules} cm
              LEFT JOIN {h5pactivity} h ON h.id = cm.instance
              LEFT JOIN {assign} a ON a.id = cm.instance
                  WHERE cm.id = ?",
                [$r->activityid]
            );

            $result[] = [
                'session_id'    => $r->id,
                'course_name'   => format_string($r->coursename),
                'activity_name' => format_string($activityname ?: 'Ujian Interaktif H5P'),
                'student_name'  => fullname($r),
                'student_email' => $r->email,
                'warning_count' => $r->warningcount,
                'event_count'   => $eventcount,
                'start_time'    => userdate($r->timestart, '%d %b %Y, %H:%M'),
                'finish_time'   => $r->timefinish ? userdate($r->timefinish, '%d %b %Y, %H:%M') : '-',
                'status'        => $r->status,
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
        $session->status = in_array($decision, ['approved', 'invalidated'], true) ? $decision : 'under_review';
        $session->adminreviewerid = $adminid;
        $session->adminreviewtime = time();
        $session->adminreviewnotes = clean_text($notes);

        if ($decision === 'invalidated') {
            $session->score = 0.00;
        }

        return $DB->update_record('local_lumina_exam_sessions', $session);
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
}

