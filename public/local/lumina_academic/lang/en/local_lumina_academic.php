<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Lumina Academic Management';
$string['academic_management'] = 'Academic Management';

// Navigation menus
$string['menu_overview'] = 'Academic Overview';
$string['menu_years'] = 'Academic Years';
$string['menu_classes'] = 'Class Management (Rombel)';
$string['menu_groups'] = 'Flexible Groups';
$string['menu_assignments'] = 'Course Assignment';
$string['menu_promotion'] = 'Student Promotion';
$string['menu_graduation'] = 'Graduation Management';
$string['menu_alumni'] = 'Alumni & Academic Vault';

// Capabilities
$string['lumina_academic:manageyears'] = 'Manage academic years and semesters';
$string['lumina_academic:manageclasses'] = 'Manage formal classes and homeroom teachers';
$string['lumina_academic:assigncourses'] = 'Assign courses to classes and flexible groups';
$string['lumina_academic:promotestudents'] = 'Execute student promotion wizard';
$string['lumina_academic:graduatestudents'] = 'Validate and finalize student graduation';
$string['lumina_academic:managealumni'] = 'Manage alumni accounts and vault access';
$string['lumina_academic:viewalltranscripts'] = 'View all academic records and permanent transcripts';
$string['lumina_academic:correctgrades'] = 'Request and authorize official grade corrections';
$string['lumina_academic:viewhomeroomclass'] = 'View students in assigned homeroom class';
$string['lumina_academic:viewhomeroomreports'] = 'View and export homeroom grade reports';
$string['lumina_academic:manageflexiblegroups'] = 'Create and manage flexible study groups';
$string['lumina_academic:viewmyacademicrecord'] = 'View personal academic record and schedule';
$string['lumina_academic:viewalumnitranscript'] = 'View and download verified alumni transcript';

// General terms
$string['academic_year'] = 'Academic Year';
$string['semester'] = 'Semester';
$string['semester_odd'] = 'Odd (Semester 1)';
$string['semester_even'] = 'Even (Semester 2)';
$string['status'] = 'Status';
$string['status_planning'] = 'Planning';
$string['status_active'] = 'Active';
$string['status_closed'] = 'Closed';
$string['status_archived'] = 'Archived';
$string['is_current'] = 'Current Period';
$string['start_date'] = 'Start Date';
$string['end_date'] = 'End Date';
$string['code'] = 'Code';
$string['name'] = 'Name';
$string['actions'] = 'Actions';
$string['details'] = 'Details';
$string['total_classes'] = 'Total Classes';
$string['total_students'] = 'Total Students';
$string['total_courses'] = 'Total Courses';
$string['total_alumni'] = 'Total Alumni';

// Academic Classes
$string['class_name'] = 'Class Name';
$string['class_code'] = 'Class Code';
$string['grade_level'] = 'Grade Level';
$string['major'] = 'Major / Specialization';
$string['homeroom_teacher'] = 'Homeroom Teacher';
$string['mapped_cohort'] = 'Mapped Moodle Cohort';
$string['create_class'] = 'Create Academic Class';
$string['edit_class'] = 'Edit Class';
$string['add_students'] = 'Add Students';
$string['remove_student'] = 'Remove Student';
$string['class_members'] = 'Class Members';
$string['no_classes'] = 'No academic classes found.';
$string['class_created'] = 'Academic class successfully created.';
$string['class_updated'] = 'Academic class updated successfully.';

// Flexible Groups
$string['flexible_groups'] = 'Flexible Groups';
$string['group_type'] = 'Group Type';
$string['group_type_remedial'] = 'Remedial';
$string['group_type_competition'] = 'Competition / Olympiad';
$string['group_type_project'] = 'Project Team';
$string['group_type_extracurricular'] = 'Extracurricular';
$string['mentor'] = 'Mentor / Supervisor';
$string['create_group'] = 'Create Flexible Group';

// Course Assignment
$string['assign_courses'] = 'Assign Courses to Class';
$string['select_class'] = 'Select Academic Class';
$string['select_courses'] = 'Select Courses';
$string['assigned_courses'] = 'Assigned Courses';
$string['teacher_in_charge'] = 'Subject Teacher';
$string['assignment_success'] = 'Course(s) successfully assigned to the class cohort.';
$string['provenance_cohort'] = 'Class Cohort Sync';
$string['provenance_flex'] = 'Flexible Group Sync';
$string['provenance_manual'] = 'Manual Enrollment';

// Promotion Engine
$string['promotion_wizard'] = 'Student Promotion Wizard';
$string['bulk_promotion'] = 'Bulk Class Promotion';
$string['individual_promotion'] = 'Individual Student Promotion';
$string['source_year'] = 'Source Academic Year';
$string['source_class'] = 'Source Class';
$string['target_year'] = 'Target Academic Year';
$string['target_class'] = 'Target Class';
$string['action_promote'] = 'Promote to Next Class';
$string['action_retain'] = 'Retain in Current Level';
$string['action_transfer'] = 'Transfer to Another Class/Major';
$string['action_graduate'] = 'Mark for Graduation';
$string['preview_promotion'] = 'Preview Promotion';
$string['execute_promotion'] = 'Execute Promotion Batch';
$string['promotion_success'] = 'Promotion batch completed successfully.';
$string['rollback_batch'] = 'Rollback Batch';
$string['batch_rolled_back'] = 'Promotion batch has been safely rolled back.';

// Graduation & Alumni Vault
$string['graduation_management'] = 'Graduation Management';
$string['graduation_year'] = 'Graduation Year';
$string['eligibility_check'] = 'Eligibility Verification';
$string['eligible'] = 'Eligible';
$string['ineligible'] = 'Ineligible';
$string['process_graduation'] = 'Finalize Graduation';
$string['alumni_vault'] = 'Alumni Grade Vault';
$string['search_alumni'] = 'Search Alumni by NIS / NISN / Name';
$string['nis'] = 'NIS (Student ID)';
$string['nisn'] = 'NISN (National ID)';
$string['cumulative_gpa'] = 'Cumulative GPA';
$string['final_predicate'] = 'Final Predicate';
$string['integrity_hash'] = 'SHA-256 Integrity Hash';
$string['download_transcript'] = 'Download Official Transcript (PDF)';
$string['correct_grade'] = 'Request Grade Correction';
$string['grade_corrected'] = 'Grade corrected and new transcript version generated.';

// Errors & Validation
$string['error_year_exists'] = 'An academic year with this code already exists.';
$string['error_class_exists'] = 'A class with this code already exists.';
$string['error_student_already_active'] = 'Student already has an active class membership for this academic year.';
$string['error_invalid_batch'] = 'Invalid or non-existent promotion batch.';
$string['error_no_permission'] = 'You do not have permission to perform this academic management action.';

