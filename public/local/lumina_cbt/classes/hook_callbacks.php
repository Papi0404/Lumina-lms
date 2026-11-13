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

namespace local_lumina_cbt;

defined('MOODLE_INTERNAL') || die();

/**
 * Modern PSR-14 Hook callbacks for Lumina CBT & Proctoring.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Inject universal CBT proctoring into footer.
     *
     * @param \core\hook\output\before_standard_footer_html_generation $hook
     */
    public static function before_standard_footer_html_generation($hook): void {
        local_lumina_cbt_before_footer();
    }

    /**
     * Inject CBT Proctor & Audit tab into course secondary navigation menu.
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function secondary_extend($hook): void {
        global $COURSE, $PAGE;

        if (!$COURSE || $COURSE->id == SITEID) {
            return;
        }

        $context = \context_course::instance($COURSE->id, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
                      has_capability('moodle/grade:viewall', $context) ||
                      has_capability('moodle/course:manageactivities', $context) ||
                      is_siteadmin();

        if ($canmonitor) {
            $secondarynav = $hook->get_navigation();
            $url = new \moodle_url('/local/lumina_cbt/monitor.php', ['courseid' => $COURSE->id]);
            $secondarynav->add(
                get_string('course_cbt_menu', 'local_lumina_cbt'),
                $url,
                \navigation_node::TYPE_SETTING,
                null,
                'lumina_cbt_secondary_monitor',
                new \pix_icon('i/shield', '')
            );
        }
    }
}
