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
 * Navigation extension hook if needed.
 *
 * @param global_navigation $navigation
 */
function local_lumina_academic_extend_navigation(global_navigation $navigation) {
    global $USER, $PAGE;
    // Student or alumni personal academic transcript view link if appropriate
}
