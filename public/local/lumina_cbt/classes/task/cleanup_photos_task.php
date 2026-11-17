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

namespace local_lumina_cbt\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../cbt_engine.php');

/**
 * Scheduled task to automatically purge CBT verification photos older than 14 days (2 weeks).
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_photos_task extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_photos', 'local_lumina_cbt');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $res = \local_lumina_cbt\cbt_engine::cleanup_expired_photos(14);
        mtrace('[Lumina CBT] Auto-cleanup completed: Purged ' . ($res['total_deleted'] ?? 0) . ' files, freed ' . ($res['freed_kb'] ?? 0) . ' KB (Cutoff: ' . ($res['cutoff_date'] ?? '-') . ')');
    }
}
