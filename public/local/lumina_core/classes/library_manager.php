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
 * Resource Library Manager for Lumina LMS Core.
 *
 * Supports nested hierarchical folders, subfolders, and multi-format items (H5P, PDF, Page, Docs).
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

use stdClass;

class library_manager {

    /**
     * Ensure default starter folders and items exist for a course or school library.
     *
     * @param int $courseid (0 for School Library)
     * @param int $userid
     * @return void
     */
    public static function ensure_default_structure(int $courseid, int $userid): void {
        global $DB;

        // Persistent check: never re-seed if this course/school library was already seeded!
        $seedkey = 'seeded_lib_' . $courseid;
        if (get_config('local_lumina_core', $seedkey)) {
            return;
        }

        $count = $DB->count_records('local_lumina_lib_folders', ['courseid' => $courseid]);
        if ($count > 0) {
            set_config($seedkey, 1, 'local_lumina_core');
            return;
        }

        // Mark as seeded first so concurrent requests or intentional deletions won't re-seed.
        set_config($seedkey, 1, 'local_lumina_core');

        $now = time();

        if ($courseid === 0) {
            // Seed School Library
            $root1 = new stdClass();
            $root1->courseid = 0;
            $root1->parentid = 0;
            $root1->name = 'Perpustakaan Digital Sekolah';
            $root1->description = 'Kumpulan buku panduan akademik, jurnal, dan referensi umum sekolah.';
            $root1->icon = 'local_library';
            $root1->sortorder = 1;
            $root1->createdby = $userid;
            $root1->timecreated = $now;
            $root1->timemodified = $now;
            $root1id = $DB->insert_record('local_lumina_lib_folders', $root1);

            // Subfolder
            $sub1 = new stdClass();
            $sub1->courseid = 0;
            $sub1->parentid = $root1id;
            $sub1->name = 'Buku Panduan & Kode Etik Akademik';
            $sub1->description = 'Buku panduan mahasiswa dan tata tertib perkuliahan.';
            $sub1->icon = 'folder';
            $sub1->sortorder = 1;
            $sub1->createdby = $userid;
            $sub1->timecreated = $now;
            $sub1->timemodified = $now;
            $sub1id = $DB->insert_record('local_lumina_lib_folders', $sub1);

            // Item inside subfolder
            $item1 = new stdClass();
            $item1->folderid = $sub1id;
            $item1->courseid = 0;
            $item1->title = 'Buku Pedoman Mahasiswa 2026/2027 (PDF)';
            $item1->itemtype = 'pdf';
            $item1->contentref = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';
            $item1->filesize = 1048576;
            $item1->createdby = $userid;
            $item1->timecreated = $now;
            $item1->timemodified = $now;
            $DB->insert_record('local_lumina_lib_items', $item1);

        } else {
            // Seed Course Library
            $course = $DB->get_record('course', ['id' => $courseid]);
            $cname = $course ? $course->shortname : 'Kursus';

            // Folder 1: Modul Teori
            $f1 = new stdClass();
            $f1->courseid = $courseid;
            $f1->parentid = 0;
            $f1->name = 'Modul & Diktat Pembelajaran ' . $cname;
            $f1->description = 'Folder utama bahan ajar perkuliahan teori mingguan.';
            $f1->icon = 'folder';
            $f1->sortorder = 1;
            $f1->createdby = $userid;
            $f1->timecreated = $now;
            $f1->timemodified = $now;
            $f1id = $DB->insert_record('local_lumina_lib_folders', $f1);

            // Subfolder inside Folder 1
            $subf1 = new stdClass();
            $subf1->courseid = $courseid;
            $subf1->parentid = $f1id;
            $subf1->name = 'Bab 1: Konsep Dasar & Arsitektur';
            $subf1->description = 'Sub-materi pengantar dan konsep fondasi.';
            $subf1->icon = 'folder_open';
            $subf1->sortorder = 1;
            $subf1->createdby = $userid;
            $subf1->timecreated = $now;
            $subf1->timemodified = $now;
            $subf1id = $DB->insert_record('local_lumina_lib_folders', $subf1);

            // Items inside Subfolder
            $it1 = new stdClass();
            $it1->folderid = $subf1id;
            $it1->courseid = $courseid;
            $it1->title = 'Diktat Lengkap: Arsitektur Sistem Modern (PDF)';
            $it1->itemtype = 'pdf';
            $it1->contentref = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';
            $it1->filesize = 2097152;
            $it1->createdby = $userid;
            $it1->timecreated = $now;
            $it1->timemodified = $now;
            $DB->insert_record('local_lumina_lib_items', $it1);

            $it2 = new stdClass();
            $it2->folderid = $subf1id;
            $it2->courseid = $courseid;
            $it2->title = 'Latihan Interaktif H5P: Uji Konsep Dasar';
            $it2->itemtype = 'h5p';
            $it2->contentref = 'https://h5p.org/h5p/embed/617';
            $it2->filesize = 524288;
            $it2->createdby = $userid;
            $it2->timecreated = $now;
            $it2->timemodified = $now;
            $DB->insert_record('local_lumina_lib_items', $it2);
        }
    }

    /**
     * Get folders within a parent folder in a course.
     *
     * @param int $courseid
     * @param int $parentid
     * @return array
     */
    public static function get_folders(int $courseid, int $parentid = 0): array {
        global $DB;

        $folders = $DB->get_records('local_lumina_lib_folders', [
            'courseid' => $courseid,
            'parentid' => $parentid,
        ], 'sortorder ASC, id ASC');

        $result = [];
        foreach ($folders as $f) {
            $subcount = $DB->count_records('local_lumina_lib_folders', ['parentid' => $f->id]);
            $itemcount = $DB->count_records('local_lumina_lib_items', ['folderid' => $f->id]);

            $result[] = [
                'id'          => $f->id,
                'courseid'    => $f->courseid,
                'parentid'    => $f->parentid,
                'name'        => format_string($f->name),
                'description' => $f->description,
                'icon'        => $f->icon ?: 'folder',
                'subcount'    => $subcount,
                'itemcount'   => $itemcount,
                'total_count' => $subcount + $itemcount,
            ];
        }

        return $result;
    }

    /**
     * Get items inside a folder.
     *
     * @param int $folderid
     * @return array
     */
    public static function get_items(int $folderid): array {
        global $DB;

        $items = $DB->get_records('local_lumina_lib_items', ['folderid' => $folderid], 'id ASC');
        $result = [];

        $iconmap = [
            'pdf'  => 'picture_as_pdf',
            'h5p'  => 'interactive_subtitles',
            'page' => 'menu_book',
            'doc'  => 'description',
            'link' => 'open_in_new',
        ];

        $badgemap = [
            'pdf'  => 'bg-danger text-danger bg-opacity-15 border border-danger border-opacity-25',
            'h5p'  => 'bg-primary text-primary bg-opacity-15 border border-primary border-opacity-25',
            'page' => 'bg-success text-success bg-opacity-15 border border-success border-opacity-25',
            'doc'  => 'bg-info text-info bg-opacity-15 border border-info border-opacity-25',
            'link' => 'bg-secondary text-secondary bg-opacity-15 border',
        ];

        $typemap = [
            'pdf'  => 'Dokumen PDF',
            'h5p'  => 'Interaktif H5P',
            'page' => 'Modul Pembelajaran',
            'doc'  => 'Berkas Dokumen',
            'link' => 'Tautan Eksternal',
        ];

        foreach ($items as $it) {
            $type = $it->itemtype;
            $result[] = [
                'id'          => $it->id,
                'folderid'    => $it->folderid,
                'courseid'    => $it->courseid,
                'title'       => format_string($it->title),
                'itemtype'    => $type,
                'type_label'  => $typemap[$type] ?? strtoupper($type),
                'icon'        => $iconmap[$type] ?? 'article',
                'badge_class' => $badgemap[$type] ?? 'bg-light text-dark',
                'url'         => $it->contentref,
                'is_h5p'      => ($type === 'h5p'),
                'is_pdf'      => ($type === 'pdf'),
                'is_link'     => ($type === 'link'),
                'size_str'    => ($it->filesize > 0 && function_exists('display_size')) ? display_size($it->filesize) : '',
            ];
        }

        return $result;
    }

    /**
     * Get folder breadcrumb path.
     *
     * @param int $folderid
     * @return array
     */
    public static function get_breadcrumbs(int $folderid): array {
        global $DB;

        $crumbs = [];
        $curr = $folderid;

        while ($curr > 0) {
            $f = $DB->get_record('local_lumina_lib_folders', ['id' => $curr]);
            if (!$f) {
                break;
            }
            array_unshift($crumbs, [
                'id'   => $f->id,
                'name' => format_string($f->name),
            ]);
            $curr = $f->parentid;
        }

        return $crumbs;
    }

    /**
     * Delete a folder and all its contents (recursive).
     *
     * @param int $folderid
     * @return bool
     */
    public static function delete_folder(int $folderid): bool {
        global $DB;

        try {
            $folder = $DB->get_record('local_lumina_lib_folders', ['id' => $folderid]);
            if (!$folder) {
                // If folder doesn't exist, treat as success (already deleted).
                return true;
            }

            // Recursively delete subfolders.
            $subfolders = $DB->get_records('local_lumina_lib_folders', ['parentid' => $folderid]);
            if (!empty($subfolders)) {
                foreach ($subfolders as $sub) {
                    self::delete_folder((int)$sub->id);
                }
            }

            // Delete items in this folder.
            $items = $DB->get_records('local_lumina_lib_items', ['folderid' => $folderid]);
            if (!empty($items)) {
                foreach ($items as $it) {
                    self::delete_item((int)$it->id);
                }
            }

            // Delete the folder itself.
            $DB->delete_records('local_lumina_lib_folders', ['id' => $folderid]);
            return true;
        } catch (\Throwable $e) {
            debugging('Error deleting folder ' . $folderid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Delete a single item.
     *
     * @param int $itemid
     * @return bool
     */
    public static function delete_item(int $itemid): bool {
        global $DB;

        try {
            $it = $DB->get_record('local_lumina_lib_items', ['id' => $itemid]);
            if (!$it) {
                // If item doesn't exist, treat as success (already deleted).
                return true;
            }

            if (!empty($it->fileid)) {
                try {
                    $fs = get_file_storage();
                    $file = $fs->get_file_by_id($it->fileid);
                    if ($file) {
                        $file->delete();
                    }
                } catch (\Throwable $fe) {
                    // Ignore missing file storage record.
                }
            }

            $DB->delete_records('local_lumina_lib_items', ['id' => $itemid]);
            return true;
        } catch (\Throwable $e) {
            debugging('Error deleting item ' . $itemid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Update folder details.
     *
     * @param int $folderid
     * @param string $name
     * @param string $desc
     * @return bool
     */
    public static function update_folder(int $folderid, string $name, string $desc = ''): bool {
        global $DB;

        try {
            $f = $DB->get_record('local_lumina_lib_folders', ['id' => $folderid]);
            if (!$f) {
                return false;
            }

            $f->name = clean_text($name);
            $f->description = clean_text($desc);
            $f->timemodified = time();

            return $DB->update_record('local_lumina_lib_folders', $f);
        } catch (\Throwable $e) {
            debugging('Error updating folder ' . $folderid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Update item details.
     *
     * @param int $itemid
     * @param string $title
     * @param string $type
     * @param string $url
     * @return bool
     */
    public static function update_item(int $itemid, string $title, string $type, string $url): bool {
        global $DB;

        try {
            $it = $DB->get_record('local_lumina_lib_items', ['id' => $itemid]);
            if (!$it) {
                return false;
            }

            $it->title = clean_text($title);
            $it->itemtype = in_array($type, ['pdf', 'h5p', 'doc', 'link', 'page'], true) ? $type : 'link';
            if (!empty($url)) {
                $it->contentref = clean_text($url);
            }
            $it->timemodified = time();

            return $DB->update_record('local_lumina_lib_items', $it);
        } catch (\Throwable $e) {
            debugging('Error updating item ' . $itemid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
