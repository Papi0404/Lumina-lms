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

namespace theme_lumina;

/**
 * Dynamic .env & Moodle AI Key Synchronizer for Theme Lumina.
 *
 * @package    theme_lumina
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class env_sync {

    /**
     * Delegate to local_lumina_ai\env_sync if available, or run standalone sync.
     *
     * @return array
     */
    public static function auto_sync(): array {
        if (class_exists('\local_lumina_ai\env_sync')) {
            return \local_lumina_ai\env_sync::auto_sync();
        }
        return [];
    }
}

