<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

/**
 * Check if the Lumina Academic tables are installed in the database.
 *
 * @return bool
 */
function local_lumina_academic_is_installed(): bool {
    global $DB;
    $dbman = $DB->get_manager();
    return $dbman->table_exists('lumina_academic_years');
}

/**
 * Extend navigation hook: Adds top primary navigation menu in Moodle 4/5.
 *
 * @param global_navigation $navigation
 */
function local_lumina_academic_extend_navigation(global_navigation $navigation) {
    global $USER, $PAGE;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $sysctx = context_system::instance();
    $canmanage = is_siteadmin() ||
                 has_capability('local/lumina_academic:manageclasses', $sysctx) ||
                 has_capability('local/lumina_academic:manageyears', $sysctx) ||
                 has_capability('local/lumina_academic:assigncourses', $sysctx) ||
                 has_capability('local/lumina_academic:promotestudents', $sysctx) ||
                 has_capability('local/lumina_academic:managealumni', $sysctx) ||
                 has_capability('moodle/site:config', $sysctx);

    if ($canmanage) {
        $overviewurl = new \moodle_url('/local/lumina_academic/overview.php');

        // Add to Primary Navigation bar (Top bar alongside Home, Dashboard, Site administration)
        try {
            if (isset($PAGE->primarynav) && method_exists($PAGE->primarynav, 'add')) {
                if (!$PAGE->primarynav->find('lumina_academic_primary', \navigation_node::TYPE_CUSTOM)) {
                    $node = $PAGE->primarynav->add(
                        '🎓 Manajemen Akademik',
                        $overviewurl,
                        \navigation_node::TYPE_CUSTOM,
                        null,
                        'lumina_academic_primary'
                    );
                    if ($node) {
                        $node->showinflatnavigation = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback gracefully
        }
    }
}
