<?php
// This file is part of Lumina CBT & Proctoring Plugin.
//
// Lumina LMS - Computer Based Testing & Real-Time Proctoring Audit
// Developed for universal compatibility across all Moodle versions (4.0 - 5.3+).

defined('MOODLE_INTERNAL') || die();

/**
 * Custom uninstallation cleanup for local_lumina_cbt.
 *
 * Moodle core automatically drops tables declared in db/install.xml
 * and purges capabilities declared in db/access.php.
 * This hook handles extra environment sanitization.
 *
 * @return bool True on success
 */
function xmldb_local_lumina_cbt_uninstall() {
    global $DB;

    // 1. Purge all plugin config keys
    unset_all_config_for_plugin('local_lumina_cbt');

    // 2. Clean up any log references if available
    try {
        if ($DB->get_manager()->table_exists('logstore_standard_log')) {
            $DB->delete_records('logstore_standard_log', ['component' => 'local_lumina_cbt']);
        }
    } catch (\Throwable $e) {
        // Silently skip if table is locked or partitioned
    }

    // 3. Purge related caches
    try {
        \cache_helper::purge_all();
    } catch (\Throwable $e) {
        // Silently skip if cache backend is busy
    }

    return true;
}

