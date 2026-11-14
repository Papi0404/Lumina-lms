<?php
// This file is part of Lumina CBT & Proctoring Plugin.
//
// Lumina LMS - Computer Based Testing & Real-Time Proctoring Audit
// Developed for universal compatibility across all Moodle versions (4.0 - 5.3+).

defined('MOODLE_INTERNAL') || die();

/**
 * Custom uninstallation cleanup for local_lumina_cbt.
 *
 * Moodle core automatically drops tables declared in db/install.xml,
 * purges capabilities in db/access.php, and removes configuration keys.
 *
 * @return bool True on success
 */
function xmldb_local_lumina_cbt_uninstall() {
    global $DB;

    try {
        unset_all_config_for_plugin('local_lumina_cbt');
    } catch (\Throwable $e) {
        // Silently skip
    }

    return true;
}
