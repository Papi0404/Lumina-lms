\<?php
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
 * Admin settings and Site Administration navigation for local_lumina_cbt.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_lumina_cbt_cat', get_string('pluginname', 'local_lumina_cbt')));

    // 1. Live Proctoring Monitor Link
    $ADMIN->add('local_lumina_cbt_cat', new admin_externalpage(
        'local_lumina_cbt_monitor',
        get_string('monitor_dashboard', 'local_lumina_cbt'),
        new moodle_url('/local/lumina_cbt/monitor.php'),
        'local/lumina_cbt:monitor'
    ));

    // 2. Settings Page
    $settings = new admin_settingpage('local_lumina_cbt_settings', get_string('settings', 'local_lumina_cbt'));

    // Universal Auto-Enforcement
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/universal_mode',
        get_string('universal_mode', 'local_lumina_cbt'),
        get_string('universal_mode_desc', 'local_lumina_cbt'),
        1
    ));

    // Strike limit
    $settings->add(new admin_setting_configselect(
        'local_lumina_cbt/max_warnings',
        get_string('max_warnings', 'local_lumina_cbt'),
        get_string('max_warnings_desc', 'local_lumina_cbt'),
        3,
        [1 => '1 Peringatan', 2 => '2 Peringatan', 3 => '3 Peringatan (Standar)', 5 => '5 Peringatan']
    ));

    // Fullscreen enforcement
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/enable_fullscreen',
        get_string('enable_fullscreen', 'local_lumina_cbt'),
        get_string('enable_fullscreen_desc', 'local_lumina_cbt'),
        1
    ));

    // Block Clipboard
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/block_clipboard',
        get_string('block_clipboard', 'local_lumina_cbt'),
        get_string('block_clipboard_desc', 'local_lumina_cbt'),
        1
    ));

    // Trap Gestures
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/block_touchpad_gestures',
        get_string('block_touchpad_gestures', 'local_lumina_cbt'),
        get_string('block_touchpad_gestures_desc', 'local_lumina_cbt'),
        1
    ));

    // Block Keyboard (MCQ only)
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/block_keyboard',
        get_string('block_keyboard', 'local_lumina_cbt'),
        get_string('block_keyboard_desc', 'local_lumina_cbt'),
        0
    ));

    // Suppress AI
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/suppress_ai',
        get_string('suppress_ai', 'local_lumina_cbt'),
        get_string('suppress_ai_desc', 'local_lumina_cbt'),
        1
    ));

    $ADMIN->add('local_lumina_cbt_cat', $settings);
}
