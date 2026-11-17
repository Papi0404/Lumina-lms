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

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers for the Site administration outline (/admin/search.php).
 *
 * @package    theme_lumina
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_admin {

    /**
     * The current user's admin bookmarks, resolved to label + link.
     *
     * Reproduces the resolution in block_admin_bookmarks: the bookmarks live in
     * the 'admin_bookmarks' user preference as a comma-separated list of admin
     * tree keys, each located in the admin root and turned into the same URL
     * the block would use (settings page, external page, or category). Keys that
     * no longer resolve are skipped so a stale preference never produces a dead chip.
     *
     * @return array[] List of ['label' => string, 'url' => string]
     */
    public static function bookmarks(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $pref = get_user_preferences('admin_bookmarks', '');
        if ($pref === '') {
            return [];
        }

        $root = admin_get_root(false, false);
        $out = [];

        foreach (explode(',', $pref) as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $node = $root->locate($key);
            if ($node instanceof \admin_settingpage) {
                $url = new \moodle_url('/admin/settings.php', ['section' => $key]);
            } else if ($node instanceof \admin_externalpage) {
                $url = new \moodle_url($node->url);
            } else if ($node instanceof \admin_category) {
                $url = new \moodle_url('/admin/category.php', ['category' => $key]);
            } else {
                continue;
            }

            $out[] = [
                'label' => (string) $node->visiblename,
                'url'   => $url->out(false),
            ];
        }

        return $out;
    }
}
