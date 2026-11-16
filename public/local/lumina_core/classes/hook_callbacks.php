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
 * Hook callbacks for Lumina Core companion plugin.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

use core\hook\output\before_http_headers;

class hook_callbacks {

    /**
     * Intercept requests to proctored exam activities and redirect to the CBT exam view.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE, $USER, $CFG, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Auto-sync any scheduled CBT exam deadlines to Moodle's calendar {event} table
        \local_lumina_core\exam_monitor::sync_cbt_events();

        $pagetype = $PAGE->pagetype ?? '';
        $urlpath = $PAGE->url ? $PAGE->url->get_path() : '';

        // Universal check: any H5P activity, Moodle Quiz, or question attempt
        $isexamactivity = (
            $pagetype === 'mod-h5pactivity-view' ||
            strpos($urlpath, 'mod/h5pactivity/view.php') !== false ||
            $pagetype === 'mod-quiz-view' ||
            $pagetype === 'mod-quiz-attempt' ||
            strpos($urlpath, 'mod/quiz/view.php') !== false ||
            strpos($urlpath, 'mod/quiz/attempt.php') !== false
        );

        if ($isexamactivity) {
            $cmid = optional_param('id', 0, PARAM_INT);
            if (!$cmid && (strpos($urlpath, 'mod/quiz/attempt.php') !== false || $pagetype === 'mod-quiz-attempt')) {
                $attemptid = optional_param('attempt', 0, PARAM_INT);
                if ($attemptid) {
                    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
                    if ($attempt) {
                        $cmrec = get_coursemodule_from_instance('quiz', $attempt->quiz);
                        if ($cmrec) {
                            $cmid = (int)$cmrec->id;
                        }
                    }
                }
            }

            if ($cmid) {
                $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
                if ($cm && in_array($cm->modname, ['h5pactivity', 'quiz'], true)) {
                    require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');
                    $roleinfo = \theme_lumina_role_resolver::resolve_role($USER);
                    $isstudent = (!empty($roleinfo['is_student']) || !has_capability('moodle/course:manageactivities', $PAGE->context));
                    $action = optional_param('action', '', PARAM_ALPHA);

                    if ($isstudent && empty($action) && !optional_param('noredirect', 0, PARAM_INT)) {
                        $targetUrl = file_exists($CFG->dirroot . '/local/lumina_cbt/exam_view.php')
                            ? '/local/lumina_cbt/exam_view.php'
                            : '/local/lumina_core/exam_view.php';
                        redirect(new \moodle_url($targetUrl, ['cmid' => $cm->id]));
                    }
                }
            }
        }
    }

    /**
     * Parse User Agent into human readable device & browser string.
     *
     * @param string $useragent
     * @return string
     */
    private static function parse_device_info(string $useragent): string {
        $os = 'Perangkat Desktop/Laptop';
        if (preg_match('/windows nt 10/i', $useragent)) {
            $os = 'Windows 10/11';
        } else if (preg_match('/windows/i', $useragent)) {
            $os = 'Windows';
        } else if (preg_match('/macintosh|mac os x/i', $useragent)) {
            $os = 'macOS';
        } else if (preg_match('/android/i', $useragent)) {
            $os = 'Android Mobile';
        } else if (preg_match('/iphone|ipad|ipod/i', $useragent)) {
            $os = 'Apple iOS';
        } else if (preg_match('/linux/i', $useragent)) {
            $os = 'Linux';
        }

        $browser = 'Web Browser';
        if (preg_match('/edg/i', $useragent)) {
            $browser = 'Microsoft Edge';
        } else if (preg_match('/brave/i', $useragent)) {
            $browser = 'Brave';
        } else if (preg_match('/chrome|crios/i', $useragent)) {
            $browser = 'Google Chrome';
        } else if (preg_match('/firefox|fxios/i', $useragent)) {
            $browser = 'Mozilla Firefox';
        } else if (preg_match('/safari/i', $useragent)) {
            $browser = 'Apple Safari';
        }

        return "{$browser} ({$os})";
    }

    /**
     * Instant login security notification hook: sends immediate notification on EVERY user login.
     *
     * @param \core_user\hook\after_login_completed $hook
     */
    public static function after_login_completed(\core_user\hook\after_login_completed $hook): void {
        global $USER, $CFG, $PAGE, $SESSION;

        if (empty($USER) || empty($USER->id) || isguestuser($USER)) {
            return;
        }

        // Avoid duplicate triggers within the same 3 seconds.
        if (!empty($SESSION->lumina_last_login_notif_time) && (time() - $SESSION->lumina_last_login_notif_time) < 3) {
            return;
        }
        $SESSION->lumina_last_login_notif_time = time();

        $loginip = getremoteaddr();
        $useragent = \core_useragent::get_user_agent_string();
        $devicename = self::parse_device_info($useragent);
        $logintime = date('d F Y, H:i:s');
        $userfullname = fullname($USER);
        $username = $USER->username;
        $useremail = $USER->email ?? '';

        $changepasswordlink = (new \moodle_url('/login/change_password.php'))->out(false);

        $html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 540px; padding: 16px; border: 1px solid #E2E8F0; border-radius: 14px; background: #FFFFFF; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">'
            . '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">'
            . '<span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #10B981;"></span>'
            . '<strong style="color: #0F172A; font-size: 15px;">Sesi Masuk Berhasil Terverifikasi</strong>'
            . '</div>'
            . '<p style="color: #475569; font-size: 13px; margin: 0 0 12px 0; line-height: 1.5;">'
            . 'Halo <strong>' . s($userfullname) . '</strong>, akun Lumina LMS Anda baru saja berhasil masuk ke sistem.'
            . '</p>'
            . '<table style="width: 100%; font-size: 13px; color: #334155; border-collapse: collapse; margin-bottom: 12px;">'
            . '<tr><td style="padding: 5px 0; color: #64748B; width: 110px;">👤 <strong>Akun:</strong></td><td style="padding: 5px 0;">' . s($username) . ($useremail ? ' (' . s($useremail) . ')' : '') . '</td></tr>'
            . '<tr><td style="padding: 5px 0; color: #64748B;">🕒 <strong>Waktu:</strong></td><td style="padding: 5px 0;">' . $logintime . ' WIB</td></tr>'
            . '<tr><td style="padding: 5px 0; color: #64748B;">💻 <strong>Perangkat:</strong></td><td style="padding: 5px 0;">' . s($devicename) . '</td></tr>'
            . '<tr><td style="padding: 5px 0; color: #64748B;">🌐 <strong>Alamat IP:</strong></td><td style="padding: 5px 0;"><code>' . s($loginip) . '</code></td></tr>'
            . '</table>'
            . '<div style="padding-top: 10px; border-top: 1px solid #F1F5F9; font-size: 12px; color: #94A3B8;">'
            . 'Jika ini bukan aktivitas Anda, segera <a href="' . $changepasswordlink . '" style="color: #4338CA; font-weight: 600; text-decoration: underline;">amankan akun dan ganti kata sandi</a>.'
            . '</div>'
            . '</div>';

        $eventdata = new \core\message\message();
        $eventdata->courseid          = SITEID;
        $eventdata->component         = 'moodle';
        $eventdata->name              = 'newlogin';
        $eventdata->userfrom          = \core_user::get_noreply_user();
        $eventdata->userto            = $USER;
        $eventdata->notification      = 1;
        $eventdata->subject           = 'Sesi Masuk Baru Berhasil — Lumina LMS';
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml   = $html;
        $eventdata->fullmessage       = "Halo {$userfullname}, akun Anda baru saja masuk dari {$devicename} pada {$logintime} WIB (IP: {$loginip}).";
        $eventdata->smallmessage      = "Masuk berhasil dari {$devicename} pada " . date('H:i') . " WIB";

        try {
            $userpicture = new \user_picture($USER);
            $userpicture->size = 1;
            if ($PAGE) {
                $eventdata->customdata = ['notificationiconurl' => $userpicture->get_url($PAGE)->out(false)];
            }
        } catch (\Throwable $e) {
            // Ignore icon fallback.
        }

        message_send($eventdata);
    }
}
