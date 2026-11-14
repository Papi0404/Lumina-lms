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
 * Version details for Lumina CBT & Anti-Cheat Proctoring Suite.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Defensive definitions for Moodle calendar constants across all Moodle versions & PHP 8+
if (!defined('ASSIGN_EVENT_TYPE_DUE')) {
    define('ASSIGN_EVENT_TYPE_DUE', 'due');
}
if (!defined('ASSIGN_EVENT_TYPE_GRADINGDUE')) {
    define('ASSIGN_EVENT_TYPE_GRADINGDUE', 'gradingdue');
}
if (!defined('ASSIGN_EVENT_TYPE_OPEN')) {
    define('ASSIGN_EVENT_TYPE_OPEN', 'open');
}
if (!defined('ASSIGN_EVENT_TYPE_CLOSE')) {
    define('ASSIGN_EVENT_TYPE_CLOSE', 'close');
}
if (!defined('ASSIGN_EVENT_TYPE_EXTENSION')) {
    define('ASSIGN_EVENT_TYPE_EXTENSION', 'extension');
}

$plugin->version   = 2026111401;
$plugin->requires  = 2022041900; // Universally compatible with Moodle 4.0 - 5.3+
$plugin->component = 'local_lumina_cbt';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = 'v1.3.1 (Fixed secondary_extend navigation & clean uninstallation)';
