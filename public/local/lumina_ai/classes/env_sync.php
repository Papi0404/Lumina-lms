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

namespace local_lumina_ai;

/**
 * Dynamic .env & Moodle AI Key Synchronizer.
 *
 * Keeps /var/www/html/moodle/.env in 2-way dynamic sync with Moodle:
 *   - When an admin saves or enters API keys in Moodle (via Lumina AI settings
 *     or Moodle Core AI Providers table mdl_ai_providers), this class writes
 *     the keys to .env automatically.
 *   - When .env has keys, it injects them into Moodle configuration and runtime environment.
 *   - Ensures that the AI key ALWAYS works without manual server restart.
 *
 * @package    local_lumina_ai
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class env_sync {

    /**
     * Get absolute path to the .env file.
     *
     * @return string
     */
    public static function get_env_path(): string {
        global $CFG;
        $candidates = [
            $CFG->dirroot . '/.env',
            dirname($CFG->dirroot) . '/.env',
            '/var/www/html/moodle/.env',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                return $c;
            }
        }
        return '/var/www/html/moodle/.env';
    }

    /**
     * Read .env into key-value map.
     *
     * @return array<string, string>
     */
    public static function read_env_map(): array {
        $envpath = self::get_env_path();
        if (!file_exists($envpath)) {
            return [];
        }
        $map = [];
        $lines = file($envpath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                $map[$key] = $val;
            }
        }
        return $map;
    }

    /**
     * Write or update multiple keys in .env preserving comments and other settings.
     *
     * @param array<string, string> $keyvalues Key-value pairs to set.
     * @return bool True if written successfully.
     */
    public static function write_env_keys(array $keyvalues): bool {
        $envpath = self::get_env_path();
        $lines = file_exists($envpath) ? file($envpath, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $modified = false;
        $remaining = $keyvalues;

        // Pass 1: update existing keys in place.
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (strpos($trimmed, '=') !== false) {
                list($k, $v) = explode('=', $trimmed, 2);
                $k = trim($k);
                if (array_key_exists($k, $remaining)) {
                    $newval = (string)$remaining[$k];
                    if (trim($v) !== $newval) {
                        $lines[$idx] = "{$k}={$newval}";
                        $modified = true;
                    }
                    unset($remaining[$k]);
                }
            }
        }

        // Pass 2: append any keys that were not yet in the file.
        if (!empty($remaining)) {
            if (!empty($lines) && trim(end($lines)) !== '') {
                $lines[] = '';
            }
            foreach ($remaining as $k => $v) {
                $lines[] = "{$k}={$v}";
                $modified = true;
            }
        }

        // If file content changed, save it with lock.
        if ($modified || !file_exists($envpath)) {
            $content = implode("\n", $lines) . "\n";
            $res = @file_put_contents($envpath, $content, LOCK_EX);
            if ($res !== false) {
                @chmod($envpath, 0666);
            }
        }

        // Always update runtime environment for current PHP request.
        foreach ($keyvalues as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = (string)$v;
        }

        return true;
    }

    /**
     * Perform bidirectional sync between Moodle (mdl_ai_providers, mdl_config_plugins) and .env.
     *
     * @return array Resolved active AI keys.
     */
    public static function auto_sync(): array {
        global $DB;

        $envmap = self::read_env_map();

        // 1. Collect keys from Moodle local_lumina_ai plugin settings.
        $moodlekeys = [
            'GEMINI_API_KEY'   => (string)(get_config('local_lumina_ai', 'gemini_api_key') ?: ''),
            'OPENAI_API_KEY'   => (string)(get_config('local_lumina_ai', 'openai_api_key') ?: ''),
            'DEEPSEEK_API_KEY' => (string)(get_config('local_lumina_ai', 'deepseek_api_key') ?: ''),
            'OLLAMA_ENDPOINT'  => (string)(get_config('local_lumina_ai', 'ollama_endpoint') ?: ''),
        ];

        // 2. Collect keys from Moodle core AI providers table (mdl_ai_providers).
        if ($DB) {
            try {
                $providers = $DB->get_records('ai_providers', ['enabled' => 1], 'id DESC');
                foreach ($providers as $p) {
                    $cfg = json_decode($p->config ?? '', true) ?: [];
                    $provstr = strtolower(($p->provider ?? '') . ' ' . ($cfg['aiprovider'] ?? '') . ' ' . ($p->name ?? ''));

                    if (empty($moodlekeys['GEMINI_API_KEY']) && strpos($provstr, 'gemini') !== false && !empty($cfg['apikey'])) {
                        $moodlekeys['GEMINI_API_KEY'] = (string)$cfg['apikey'];
                    }
                    if (empty($moodlekeys['OPENAI_API_KEY']) && strpos($provstr, 'openai') !== false && !empty($cfg['apikey'])) {
                        $moodlekeys['OPENAI_API_KEY'] = (string)$cfg['apikey'];
                    }
                    if (empty($moodlekeys['DEEPSEEK_API_KEY']) && strpos($provstr, 'deepseek') !== false && !empty($cfg['apikey'])) {
                        $moodlekeys['DEEPSEEK_API_KEY'] = (string)$cfg['apikey'];
                    }
                    if (empty($moodlekeys['OLLAMA_ENDPOINT']) && strpos($provstr, 'ollama') !== false && !empty($cfg['endpoint'])) {
                        $moodlekeys['OLLAMA_ENDPOINT'] = (string)$cfg['endpoint'];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
        }

        // 3. Resolve final values prioritizing non-empty values.
        $keys_to_write_to_env = [];
        $keys_to_write_to_moodle = [];

        foreach (['GEMINI_API_KEY', 'OPENAI_API_KEY', 'DEEPSEEK_API_KEY', 'OLLAMA_ENDPOINT'] as $k) {
            $val_moodle = trim($moodlekeys[$k] ?? '');
            $val_env    = trim($envmap[$k] ?? '');

            if (!empty($val_moodle) && $val_moodle !== $val_env) {
                // Moodle has a key (or newer key) -> sync into .env!
                $keys_to_write_to_env[$k] = $val_moodle;
                $final_val = $val_moodle;
            } else if (!empty($val_env)) {
                // .env has key -> sync into Moodle if Moodle is empty!
                if (empty($val_moodle)) {
                    $keys_to_write_to_moodle[$k] = $val_env;
                }
                $final_val = $val_env;
            } else {
                $final_val = '';
            }

            // Put into current environment.
            if (!empty($final_val)) {
                putenv("{$k}={$final_val}");
                $_ENV[$k] = $final_val;
            }
        }

        // Write to .env if needed.
        if (!empty($keys_to_write_to_env)) {
            self::write_env_keys($keys_to_write_to_env);
        }

        // Write to Moodle config_plugins if needed.
        if (!empty($keys_to_write_to_moodle)) {
            $fieldmap = [
                'GEMINI_API_KEY'   => 'gemini_api_key',
                'OPENAI_API_KEY'   => 'openai_api_key',
                'DEEPSEEK_API_KEY' => 'deepseek_api_key',
                'OLLAMA_ENDPOINT'  => 'ollama_endpoint',
            ];
            foreach ($keys_to_write_to_moodle as $k => $val) {
                $settingname = $fieldmap[$k] ?? null;
                if ($settingname) {
                    set_config($settingname, $val, 'local_lumina_ai');
                }
            }
        }

        return [
            'gemini'   => getenv('GEMINI_API_KEY') ?: '',
            'openai'   => getenv('OPENAI_API_KEY') ?: '',
            'deepseek' => getenv('DEEPSEEK_API_KEY') ?: '',
            'ollama'   => getenv('OLLAMA_ENDPOINT') ?: '',
        ];
    }
}

