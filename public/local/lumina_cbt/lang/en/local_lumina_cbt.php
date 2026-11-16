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

/**
 * English strings for local_lumina_cbt.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Lumina CBT & Anti-Cheat Proctoring Suite';
$string['cbt_proctoring'] = 'CBT Proctoring & Live Audit';
$string['monitor_dashboard'] = 'Live Proctoring Monitor';
$string['review_queue'] = 'CBT Audit & Review Queue';
$string['settings'] = 'Proctoring Settings';

// Capabilities
$string['lumina_cbt:monitor'] = 'View live proctoring stream & candidate activity';
$string['lumina_cbt:review'] = 'Review, unlock, approve or disqualify exam sessions';
$string['lumina_cbt:takeexam'] = 'Take Computer-Based Tests under proctoring lockdown';
$string['lumina_cbt:configure'] = 'Configure Lumina CBT proctoring settings';

// General Settings
$string['universal_mode'] = 'Universal CBT Auto-Enforcement';
$string['universal_mode_desc'] = 'When enabled, any student attempting a Quiz (mod_quiz), H5P activity, or assigned assessment will automatically have strict CBT anti-cheat lockdown enforced.';
$string['max_warnings'] = 'Strike Limit Before Lockout';
$string['max_warnings_desc'] = 'Number of infraction warnings allowed before the candidate is automatically suspended and locked out (default: 3).';
$string['enable_fullscreen'] = 'Enforce Strict Fullscreen';
$string['enable_fullscreen_desc'] = 'Require candidates to enter and remain in fullscreen mode throughout the exam.';
$string['block_clipboard'] = 'Block Copy, Cut & Paste';
$string['block_clipboard_desc'] = 'Disable clipboard operations and context menus during exams.';
$string['block_touchpad_gestures'] = 'Trap Trackpad/Touchpad Gestures';
$string['block_touchpad_gestures_desc'] = 'Detect and intercept 3-finger / 4-finger swipes and window-minimize gestures.';
$string['block_keyboard'] = 'Lockdown Keyboard (MCQ Mode)';
$string['block_keyboard_desc'] = 'Allow only mouse interactions for multiple-choice questions to prevent shortcut cheating.';
$string['suppress_ai'] = 'Suppress AI Chatbots & Overlays';
$string['suppress_ai_desc'] = 'Automatically hide and disable all AI tutors and floating widgets during exams.';
$string['sound_alerts'] = 'Live Infraction Sound Chimes';
$string['sound_alerts_desc'] = 'Play an audible warning chime on the proctor dashboard whenever a candidate commits an infraction.';

// Infraction Event Types
$string['event_keyboard_attempt'] = 'Keyboard Pressed (Blocked)';
$string['event_three_finger_gesture'] = '3-Finger Touchpad Swipe';
$string['event_three_finger_gesture_or_minimize'] = 'Window Minimize Attempt';
$string['event_trackpad_gesture'] = 'Trackpad Gesture / Pinch Zoom';
$string['event_fullscreen_exit'] = 'Exited Fullscreen Mode';
$string['event_blur'] = 'Window Switched / Alt+Tab';
$string['event_visibility_hidden'] = 'Switched Browser Tab';
$string['event_mouseleave'] = 'Mouse Left Exam Boundary';
$string['event_clipboard_copy'] = 'Text Copy / Cut Attempt';
$string['event_devtools'] = 'Developer Tools (F12) Attempt';
$string['event_exam_submitted'] = 'Exam Submitted';
$string['event_exam_invalidated'] = 'Exam Disqualified by Proctor';
$string['event_exam_reset'] = 'Session Reset & Unlocked (0/3)';
$string['event_exam_approved'] = 'Exam Approved by Proctor';

// Proctoring Messages & Warnings
$string['warning_1'] = 'Warning 1 of 3: Infraction detected ({$a}). Remain inside the exam window!';
$string['warning_2'] = 'Warning 2 of 3: Critical Infraction ({$a})! One more violation will instantly lock your exam and notify proctors!';
$string['warning_3'] = 'Warning 3 of 3: Violation threshold reached. Your exam has been suspended and locked for Proctor Review.';
$string['locked_out_title'] = 'Exam Suspended - Under Proctor Review';
$string['locked_out_desc'] = 'Your exam session has been locked due to repeated proctoring violations. Please contact your invigilator or administrator to request an unlock.';

// UI Navigation & Labels
$string['course_cbt_menu'] = '🛡️ CBT Proctor & Audit';
$string['live_feed'] = 'Live Infraction Stream';
$string['active_candidates'] = 'Active Exam Candidates';
$string['unlocked_success'] = 'Candidate session successfully unlocked. Strikes reset to 0/3.';
$string['reset_success'] = 'Candidate session successfully unlocked. Strikes reset to 0/3.';
$string['approved_success'] = 'Exam session approved successfully.';
$string['invalidated_success'] = 'Exam attempt disqualified and score set to 0.';
$string['restore_success'] = 'Exam attempt and score have been successfully restored.';
$string['enter_fullscreen_btn'] = 'Enter Fullscreen & Begin Exam';
$string['submit_exam'] = 'Submit Exam';
$string['flag_doubtful'] = 'Flag Doubtful';
$string['question_palette'] = 'Question Navigator';

// Per-Quiz CBT Configuration
$string['cbt_settings_header'] = 'Lumina CBT Anti-Cheat Mode';
$string['cbt_enable'] = 'Allow CBT Mode';
$string['cbt_enable_help'] = 'If enabled (Yes), this exam automatically runs in a secure fullscreen CBT environment with real-time proctoring (detecting tab switching, minimizing, keyboard shortcuts, touchpad gestures). If disabled (No), the exam runs as a standard Moodle quiz.';
$string['status_not_started'] = 'Not Attempted';
$string['status_in_progress'] = 'In Progress';

