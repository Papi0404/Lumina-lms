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
    // 1. Add CBT Category & Submenu directly to root (appears in Site Administration -> General tab)
    $ADMIN->add('root', new admin_category('local_lumina_cbt_general_cat', '🛡️ CBT Proctoring & Live Audit'));

    // Live Proctoring Monitor Link under General
    $ADMIN->add('local_lumina_cbt_general_cat', new admin_externalpage(
        'local_lumina_cbt_monitor_general',
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
        0
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

    // Automated 3-Point Checkpoint Photos (Start, 5 Min, Submit)
    $settings->add(new admin_setting_configcheckbox(
        'local_lumina_cbt/checkpoint_photos',
        get_string('checkpoint_photos', 'local_lumina_cbt'),
        get_string('checkpoint_photos_desc', 'local_lumina_cbt'),
        1
    ));

    // Photo Retention Period (Purge photos older than 14 days / 2 weeks)
    $settings->add(new admin_setting_configselect(
        'local_lumina_cbt/photo_retention_days',
        get_string('photo_retention_days', 'local_lumina_cbt'),
        get_string('photo_retention_days_desc', 'local_lumina_cbt'),
        14,
        [
            7  => '7 Hari (1 Minggu)',
            14 => '14 Hari (2 Minggu - Rekomendasi)',
            30 => '30 Hari (1 Bulan)',
            60 => '60 Hari (2 Bulan)',
        ]
    ));

    $ADMIN->add('local_lumina_cbt_general_cat', $settings);
}
