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
 * Lumina AI - AJAX endpoint for AI chat.
 *
 * Works independently of theme_lumina. Reads AI provider config from:
 *   1. Plugin settings (Site Admin > Lumina AI)
 *   2. Moodle AI Provider table (mdl_ai_providers)
 *   3. Environment variables (.env / server)
 *
 * @package    local_lumina_ai
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

// Guard: plugin must be enabled.
if (!get_config('local_lumina_ai', 'enabled')) {
    echo json_encode(['status' => 'error', 'reply' => 'Lumina AI is disabled.']);
    exit;
}

require_login(null, false);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

global $USER, $DB, $CFG;

$message    = optional_param('message', '', PARAM_RAW_TRIMMED);
$historyraw = optional_param('history', '[]', PARAM_RAW);

if (empty($message)) {
    echo json_encode(['status' => 'error', 'reply' => 'Pesan tidak boleh kosong.']);
    exit;
}

// Burst protection throttle: minimum 2 seconds between messages
$lastreqtime = (int)get_user_preferences('lumina_ai_last_req_time', 0, $USER->id);
if ((time() - $lastreqtime) < 2) {
    echo json_encode([
        'status' => 'error',
        'reply'  => '⏳ Mohon tunggu 2 detik sebelum mengirim pertanyaan berikutnya.'
    ]);
    exit;
}
set_user_preference('lumina_ai_last_req_time', time(), $USER->id);

// Rate limiting: check requests per hour.
$maxreq = (int)(get_config('local_lumina_ai', 'max_requests_per_hour') ?: 50);
if ($maxreq > 0) {
    $cachekey = 'lumina_ai_ratelimit_' . $USER->id;
    $windowstart = strtotime('-1 hour');
    $count = get_user_preferences('lumina_ai_req_count', 0, $USER->id);
    $lasthour = get_user_preferences('lumina_ai_req_window', 0, $USER->id);
    if ((int)$lasthour < $windowstart) {
        // Reset window.
        set_user_preference('lumina_ai_req_count', 1, $USER->id);
        set_user_preference('lumina_ai_req_window', time(), $USER->id);
    } else {
        $count++;
        if ($count > $maxreq) {
            echo json_encode([
                'status' => 'error',
                'reply'  => "⚠️ Anda telah mencapai batas {$maxreq} pesan per jam. Silakan coba lagi dalam beberapa saat."
            ]);
            exit;
        }
        set_user_preference('lumina_ai_req_count', $count, $USER->id);
    }
}

$history = json_decode($historyraw, true);
if (!is_array($history)) {
    $history = [];
}

// 1. Math quick-eval (AST-safe parser without eval).
$mathclean = preg_replace('/\s+/', '', strtolower($message));
$mathclean = rtrim($mathclean, '=?');
$mathclean = preg_replace('/(berapa|adalah|sama\s*dengan)$/u', '', $mathclean);
$mathclean = rtrim($mathclean, '=?');

if (preg_match('/^[-+*\/%0-9().^]+$/', $mathclean) && preg_match('/[0-9]/', $mathclean) && preg_match('/[-+*\/%^]/', $mathclean)) {
    try {
        $calc = lumina_ai_safe_eval_math($mathclean);
        if ($calc !== null && $calc !== false) {
            echo json_encode(['status' => 'success', 'reply' => "{$message} = **{$calc}**", 'engine' => 'math-eval']);
            exit;
        }
    } catch (\Throwable $e) {}
}

// 2. Gather academic context for user.
$enrolled     = enrol_get_all_users_courses($USER->id, true);
$coursenames  = [];
foreach ($enrolled as $c) {
    $coursenames[] = format_string($c->fullname);
}
$courseliststr = !empty($coursenames) ? implode(', ', $coursenames) : 'Belum ada mata kuliah';

$now      = time();
$lookahead = $now + (30 * DAYSECS);
$pendingtasks = [];
if (!empty($enrolled)) {
    $cids = array_keys($enrolled);
    list($insql, $params) = $DB->get_in_or_equal($cids, SQL_PARAMS_NAMED, 'c');
    $params['now'] = $now;
    $params['lookahead'] = $lookahead;
    $assigns = $DB->get_records_sql(
        "SELECT a.id, a.name, a.duedate, c.fullname as coursename
           FROM {assign} a JOIN {course} c ON c.id = a.course
          WHERE a.course $insql AND a.duedate > :now AND a.duedate < :lookahead
          ORDER BY a.duedate ASC LIMIT 5",
        $params
    );
    foreach ($assigns as $a) {
        $days = (int)ceil(($a->duedate - $now) / DAYSECS);
        $pendingtasks[] = format_string($a->name) . " (" . format_string($a->coursename) . ") - tenggat {$days} hari lagi";
    }
}
$pendingtaskstr = !empty($pendingtasks) ? implode('; ', $pendingtasks) : 'Semua tugas telah diselesaikan / belum ada tugas terbuka';

// 3. Build system prompt.
$chattitle    = get_config('local_lumina_ai', 'chatbot_title') ?: 'Lumina AI';
$userrole     = is_siteadmin() ? 'Administrator' : 'Mahasiswa';
$systemprompt = "You are {$chattitle}, an intelligent, empathetic, and knowledgeable academic AI tutor inside Lumina LMS (Moodle).
Current user: {$USER->firstname} {$USER->lastname} (Role: {$userrole})
Enrolled courses: {$courseliststr}
Upcoming deadlines: {$pendingtaskstr}

Guidelines:
1. Always respond naturally and conversationally in the language the user asked in (Indonesian by default, or English).
2. Answer direct questions directly and concisely.
3. Be friendly, helpful, and pedagogically clear like ChatGPT.
4. Use formatted Markdown with bold headings, bullet points, and code blocks when appropriate.
5. If the user asks about their courses or deadlines, provide their real academic context.";

// 4. Resolve API credentials — priority: plugin settings > Moodle AI providers > env vars.
// Automatically sync .env and Moodle settings dynamically.
if (class_exists('\local_lumina_ai\env_sync')) {
    \local_lumina_ai\env_sync::auto_sync();
}

$preferred = get_config('local_lumina_ai', 'preferred_provider') ?: 'auto';

$geminikey = get_config('local_lumina_ai', 'gemini_api_key') ?: getenv('GEMINI_API_KEY') ?: '';
$openaikey = get_config('local_lumina_ai', 'openai_api_key') ?: getenv('OPENAI_API_KEY') ?: '';
$deepseekkey = get_config('local_lumina_ai', 'deepseek_api_key') ?: getenv('DEEPSEEK_API_KEY') ?: '';
$ollamaendpoint = get_config('local_lumina_ai', 'ollama_endpoint') ?: getenv('OLLAMA_ENDPOINT') ?: '';
$geminimodel_configured = '';
$openaimodel_configured = '';

// Also auto-read from Moodle's mdl_ai_providers table (if 'auto' or unset).
if ($preferred === 'auto') {
    try {
        $activeproviders = $DB->get_records('ai_providers', ['enabled' => 1], 'id DESC');
        foreach ($activeproviders as $p) {
            $cfg = json_decode($p->config, true) ?: [];
            $provstr = strtolower(($p->provider ?? '') . ' ' . ($cfg['aiprovider'] ?? '') . ' ' . ($p->name ?? ''));
            if (strpos($provstr, 'gemini') !== false && !empty($cfg['apikey']) && empty($geminikey)) {
                $geminikey = $cfg['apikey'];
            }
            if (strpos($provstr, 'openai') !== false && !empty($cfg['apikey']) && empty($openaikey)) {
                $openaikey = $cfg['apikey'];
            }
            if (strpos($provstr, 'deepseek') !== false && !empty($cfg['apikey']) && empty($deepseekkey)) {
                $deepseekkey = $cfg['apikey'];
            }
            if (strpos($provstr, 'ollama') !== false && !empty($cfg['endpoint']) && empty($ollamaendpoint)) {
                $ollamaendpoint = $cfg['endpoint'];
            }
        }
    } catch (\Throwable $e) {}
}

// Helper: build common messages array for OpenAI-style APIs.
function lumina_ai_build_messages(string $systemprompt, array $history, string $message): array {
    $messages = [['role' => 'system', 'content' => $systemprompt]];
    foreach ($history as $h) {
        if (!empty($h['text']) && !empty($h['role'])) {
            $messages[] = ['role' => ($h['role'] === 'user') ? 'user' : 'assistant', 'content' => $h['text']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];
    return $messages;
}

// Enterprise Session Concurrency Optimization:
// Release session write lock before executing external HTTP/cURL requests.
// Prevents other tabs/requests from the same user from freezing during AI response generation.
if (class_exists('\core\session\manager')) {
    \core\session\manager::write_close();
}

// 5. Engine: Google Gemini.
if (!empty($geminikey) && $preferred !== 'openai' && $preferred !== 'deepseek' && $preferred !== 'ollama') {
    $candidatemodels = array_values(array_filter(array_unique([
        $geminimodel_configured,
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-flash-latest',
        'gemini-3.8-flash',
    ])));

    $contents = [];
    foreach ($history as $h) {
        if (!empty($h['text']) && !empty($h['role'])) {
            $contents[] = [
                'role'  => ($h['role'] === 'user') ? 'user' : 'model',
                'parts' => [['text' => $h['text']]]
            ];
        }
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systemprompt]]],
        'contents'          => $contents,
        'generationConfig'  => ['temperature' => 0.7, 'maxOutputTokens' => 1500],
    ];

    foreach ($candidatemodels as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($geminikey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($res)) {
            $json  = json_decode($res, true);
            $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!empty($reply)) {
                echo json_encode(['status' => 'success', 'reply' => trim($reply), 'engine' => $model]);
                exit;
            }
        }
    }
}

// 6. Engine: DeepSeek.
if (!empty($deepseekkey) && $preferred !== 'gemini' && $preferred !== 'openai' && $preferred !== 'ollama') {
    $messages = lumina_ai_build_messages($systemprompt, $history, $message);
    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $deepseekkey],
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'deepseek-chat', 'messages' => $messages, 'temperature' => 0.7]),
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && !empty($res)) {
        $json  = json_decode($res, true);
        $reply = $json['choices'][0]['message']['content'] ?? null;
        if (!empty($reply)) {
            echo json_encode(['status' => 'success', 'reply' => trim($reply), 'engine' => 'deepseek']);
            exit;
        }
    }
}

// 7. Engine: OpenAI.
if (!empty($openaikey) && $preferred !== 'gemini' && $preferred !== 'deepseek' && $preferred !== 'ollama') {
    $messages = lumina_ai_build_messages($systemprompt, $history, $message);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $openaikey],
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'gpt-4o-mini', 'messages' => $messages, 'temperature' => 0.7]),
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && !empty($res)) {
        $json  = json_decode($res, true);
        $reply = $json['choices'][0]['message']['content'] ?? null;
        if (!empty($reply)) {
            echo json_encode(['status' => 'success', 'reply' => trim($reply), 'engine' => 'openai']);
            exit;
        }
    }
}

// 8. Engine: Ollama (local).
if ($preferred !== 'gemini' && $preferred !== 'deepseek' && $preferred !== 'openai') {
    $ollamahosts = array_filter(array_unique([
        $ollamaendpoint,
        'http://127.0.0.1:11434',
        'http://host.docker.internal:11434',
    ]));
    foreach ($ollamahosts as $host) {
        $ch = curl_init(rtrim($host, '/') . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'  => 'llama3.3',
                'prompt' => $message,
                'system' => $systemprompt,
                'stream' => false,
            ]),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($res)) {
            $json = json_decode($res, true);
            if (!empty($json['response'])) {
                echo json_encode(['status' => 'success', 'reply' => trim($json['response']), 'engine' => 'ollama']);
                exit;
            }
        }
    }
}

// 9. Built-in fallback engine (no external API needed).
$reply = lumina_ai_academic_response($message, $USER, $enrolled, $pendingtasks);
echo json_encode(['status' => 'success', 'reply' => $reply, 'engine' => 'lumina-builtin']);
exit;

/**
 * Built-in conversational academic fallback when no AI provider is available.
 */
function lumina_ai_academic_response(string $msg, $user, array $enrolled, array $pendingtasks): string {
    $q    = mb_strtolower(trim($msg));
    $name = s($user->firstname ?: 'Rekan');

    if (preg_match('/^(halo|hai|hi|hello|selamat|assalamu)/i', $q)) {
        return "Halo **{$name}**! 👋 Saya **Lumina AI**, asisten akademik personal Anda.\n\nSaya siap membantu Anda dengan:\n- 💡 **Menjelaskan materi kuliah** & konsep sulit\n- 📝 **Membantu pengerjaan tugas** & koding\n- 📅 **Memeriksa jadwal & tenggat waktu tugas**\n\nApa yang ingin kita bahas hari ini?";
    }

    if ((strpos($q, 'kuliah') !== false || strpos($q, 'mata kuliah') !== false) && (strpos($q, 'apa saja') !== false || strpos($q, 'daftar') !== false || strpos($q, 'saya') !== false)) {
        if (empty($enrolled)) {
            return "Saat ini Anda belum terdaftar di mata kuliah aktif.";
        }
        $out = "Berikut daftar **Mata Kuliah Aktif** Anda:\n\n";
        $i = 1;
        foreach ($enrolled as $c) {
            $out .= "{$i}. 📘 **" . format_string($c->fullname) . "** (" . format_string($c->shortname) . ")\n";
            $i++;
        }
        return $out;
    }

    if (strpos($q, 'tugas') !== false || strpos($q, 'tenggat') !== false || strpos($q, 'deadline') !== false) {
        if (empty($pendingtasks)) {
            return "🎉 Tidak ada tugas atau kuis yang tertunda dalam 30 hari ke depan!";
        }
        $out = "📅 **Tenggat Tugas & Kuis Mendatang:**\n\n";
        foreach ($pendingtasks as $task) {
            $out .= "- ⏳ {$task}\n";
        }
        return $out;
    }

    return "Pertanyaan yang menarik mengenai **" . s($msg) . "**!\n\nSilakan tanyakan materi kuliah, konsep teori, atau masalah koding yang sedang Anda pelajari, dan saya akan menjelaskannya langkah demi langkah. Untuk jawaban yang lebih mendalam, pastikan admin telah mengkonfigurasi API key AI di pengaturan plugin Lumina AI.";
}

/**
 * Enterprise-grade AST-safe mathematical expression evaluator.
 * Uses Shunting-yard algorithm and RPN evaluation without eval() or shell execution.
 *
 * @param string $expr Mathematical expression to evaluate
 * @return int|float|null Result or null if invalid
 */
function lumina_ai_safe_eval_math(string $expr) {
    $tokens = [];
    $len = strlen($expr);
    $i = 0;
    while ($i < $len) {
        $c = $expr[$i];
        if (ctype_space($c)) {
            $i++;
            continue;
        }
        if (ctype_digit($c) || $c === '.') {
            $num = '';
            while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                $num .= $expr[$i];
                $i++;
            }
            $tokens[] = ['type' => 'number', 'val' => (float)$num];
            continue;
        }
        if (in_array($c, ['+', '-', '*', '/', '%', '^', '(', ')'])) {
            if ($c === '-' || $c === '+') {
                $prev = empty($tokens) ? null : end($tokens);
                if ($prev === null || $prev['type'] === 'operator' || ($prev['type'] === 'paren' && $prev['val'] === '(')) {
                    $tokens[] = ['type' => 'operator', 'val' => ($c === '-' ? 'u-' : 'u+')];
                    $i++;
                    continue;
                }
            }
            $tokens[] = [
                'type' => ($c === '(' || $c === ')') ? 'paren' : 'operator',
                'val'  => $c
            ];
            $i++;
            continue;
        }
        return null;
    }

    $precedence = [
        'u-' => 4, 'u+' => 4,
        '^'  => 3,
        '*'  => 2, '/'  => 2, '%'  => 2,
        '+'  => 1, '-'  => 1,
    ];
    $assoc = [
        'u-' => 'R', 'u+' => 'R',
        '^'  => 'R',
        '*'  => 'L', '/'  => 'L', '%'  => 'L',
        '+'  => 'L', '-'  => 'L',
    ];

    $output = [];
    $opstack = [];

    foreach ($tokens as $t) {
        if ($t['type'] === 'number') {
            $output[] = $t;
        } elseif ($t['type'] === 'operator') {
            $op1 = $t['val'];
            while (!empty($opstack)) {
                $top = end($opstack);
                if ($top['type'] === 'operator') {
                    $op2 = $top['val'];
                    $p1 = $precedence[$op1] ?? 0;
                    $p2 = $precedence[$op2] ?? 0;
                    $a1 = $assoc[$op1] ?? 'L';
                    if (($a1 === 'L' && $p1 <= $p2) || ($a1 === 'R' && $p1 < $p2)) {
                        $output[] = array_pop($opstack);
                        continue;
                    }
                }
                break;
            }
            $opstack[] = $t;
        } elseif ($t['type'] === 'paren') {
            if ($t['val'] === '(') {
                $opstack[] = $t;
            } else {
                while (!empty($opstack) && end($opstack)['val'] !== '(') {
                    $output[] = array_pop($opstack);
                }
                if (empty($opstack)) {
                    return null;
                }
                array_pop($opstack);
            }
        }
    }

    while (!empty($opstack)) {
        $top = array_pop($opstack);
        if ($top['type'] === 'paren') {
            return null;
        }
        $output[] = $top;
    }

    $evalstack = [];
    foreach ($output as $item) {
        if ($item['type'] === 'number') {
            $evalstack[] = $item['val'];
        } elseif ($item['type'] === 'operator') {
            $op = $item['val'];
            if ($op === 'u-') {
                if (empty($evalstack)) return null;
                $a = array_pop($evalstack);
                $evalstack[] = -$a;
            } elseif ($op === 'u+') {
                if (empty($evalstack)) return null;
            } else {
                if (count($evalstack) < 2) return null;
                $b = array_pop($evalstack);
                $a = array_pop($evalstack);
                switch ($op) {
                    case '+': $evalstack[] = $a + $b; break;
                    case '-': $evalstack[] = $a - $b; break;
                    case '*': $evalstack[] = $a * $b; break;
                    case '/': 
                        if ($b == 0) return null;
                        $evalstack[] = $a / $b; 
                        break;
                    case '%': 
                        if ($b == 0) return null;
                        $evalstack[] = fmod($a, $b); 
                        break;
                    case '^': $evalstack[] = pow($a, $b); break;
                    default: return null;
                }
            }
        }
    }

    if (count($evalstack) !== 1) return null;
    $res = $evalstack[0];
    return is_finite($res) ? (round($res, 6) == (int)$res ? (int)$res : round($res, 4)) : null;
}


