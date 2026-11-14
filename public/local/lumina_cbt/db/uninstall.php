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
 * This routine automatically removes the physical plugin folder from disk upon uninstall,
 * preventing orphaned folders and Moodle Core remove_plugin_folder TypeError crashes on reinstall.
 *
 * @return bool True on success
 */
function xmldb_local_lumina_cbt_uninstall() {
    global $CFG, $DB;

    try {
        unset_all_config_for_plugin('local_lumina_cbt');
    } catch (\Throwable $e) {
        // Silently skip
    }

    // Automatically remove physical plugin folder from disk on uninstall
    $plugindir = $CFG->dirroot . '/local/lumina_cbt';

    register_shutdown_function(function() use ($plugindir, $CFG) {
        try {
            if (is_dir($plugindir)) {
                require_once($CFG->libdir . '/filelib.php');
                if (function_exists('fulldelete')) {
                    fulldelete($plugindir);
                } else {
                    $it = new \RecursiveDirectoryIterator($plugindir, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($files as $file) {
                        if ($file->isDir()) {
                            @rmdir($file->getRealPath());
                        } else {
                            @unlink($file->getRealPath());
                        }
                    }
                    @rmdir($plugindir);
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback if filesystem permissions prevent deletion
        }
    });

    return true;
}
