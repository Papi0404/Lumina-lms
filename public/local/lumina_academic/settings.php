<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/lumina_academic:manageclasses', context_system::instance())) {
    // Top-Level Root Admin Category (independent, not under plugins or data sync)
    $ADMIN->add('root', new admin_category(
        'lumina_academic_root',
        get_string('academic_management', 'local_lumina_academic')
    ));

    // 1. Academic Overview
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_overview',
        get_string('menu_overview', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/overview.php'),
        'moodle/site:config'
    ));

    // 2. Academic Years
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_years',
        get_string('menu_years', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/years.php'),
        'local/lumina_academic:manageyears'
    ));

    // 3. Class Management (Rombel)
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_classes',
        get_string('menu_classes', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/classes.php'),
        'local/lumina_academic:manageclasses'
    ));

    // 4. Flexible Groups
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_groups',
        get_string('menu_groups', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/groups.php'),
        'local/lumina_academic:manageflexiblegroups'
    ));

    // 5. Course Assignment
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_assignments',
        get_string('menu_assignments', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/assignments.php'),
        'local/lumina_academic:assigncourses'
    ));

    // 6. Student Promotion
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_promotion',
        get_string('menu_promotion', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/promotion.php'),
        'local/lumina_academic:promotestudents'
    ));

    // 7. Graduation Management
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_graduation',
        get_string('menu_graduation', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/graduation.php'),
        'local/lumina_academic:graduatestudents'
    ));

    // 8. Alumni & Academic Records (Vault)
    $ADMIN->add('lumina_academic_root', new admin_externalpage(
        'local_lumina_academic_alumni',
        get_string('menu_alumni', 'local_lumina_academic'),
        new moodle_url('/local/lumina_academic/alumni.php'),
        'local/lumina_academic:managealumni'
    ));
}
