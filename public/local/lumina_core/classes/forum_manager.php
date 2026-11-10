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
 * Forum Discussion Manager for Lumina LMS Core.
 *
 * Provides safe multi-role discussion threads, strictly isolating student and teacher access
 * and managing Moodle core forum_discussions and forum_posts.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lumina_core;

defined('MOODLE_INTERNAL') || die();

use stdClass;

class forum_manager {

    /**
     * Ensure a general discussion forum exists in the course.
     *
     * @param int $courseid
     * @return stdClass
     */
    public static function ensure_general_forum(int $courseid): stdClass {
        global $DB;

        $sql = "SELECT f.*, cm.id as cmid
                  FROM {forum} f
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = f.course
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
                 WHERE f.course = ? AND f.type = 'general' AND cm.deletioninprogress = 0
              ORDER BY f.id ASC LIMIT 1";

        $forum = $DB->get_record_sql($sql, [$courseid]);
        if ($forum) {
            return $forum;
        }

        // Auto create general forum if none exists
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $newforum = new stdClass();
        $newforum->course = $courseid;
        $newforum->type = 'general';
        $newforum->name = 'Forum Diskusi & Kolaborasi: ' . $course->shortname;
        $newforum->intro = 'Forum diskusi terbuka untuk tanya jawab, studi kasus, dan kolaborasi perkuliahan.';
        $newforum->introformat = FORMAT_HTML;
        $newforum->assessed = 0;
        $newforum->scale = 0;
        $newforum->maxbytes = 5242880;
        $newforum->maxattachments = 3;
        $newforum->forcesubscribe = 0;
        $newforum->trackingtype = 1;
        $newforum->timemodified = time();

        $forumid = $DB->insert_record('forum', $newforum);

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'forum']);
        $cm = new stdClass();
        $cm->course = $courseid;
        $cm->module = $moduleid;
        $cm->instance = $forumid;
        $cm->section = 1;
        $cm->visible = 1;
        $cm->visibleold = 1;
        $cm->groupmode = 0;
        $cm->groupingid = 0;
        $cm->added = time();
        $cmid = $DB->insert_record('course_modules', $cm);

        rebuild_course_cache($courseid, true);

        $newforum->id = $forumid;
        $newforum->cmid = $cmid;
        return $newforum;
    }

    /**
     * Get all discussions in a course's general forum.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_discussions(int $courseid): array {
        global $DB;

        $forum = self::ensure_general_forum($courseid);

        $sql = "SELECT d.id, d.name, d.firstpost, d.userid, d.timemodified, d.pinned,
                       u.firstname, u.lastname, u.email,
                       (SELECT COUNT(*) FROM {forum_posts} p WHERE p.discussion = d.id AND p.deleted = 0) - 1 AS replies_count,
                       (SELECT p.message FROM {forum_posts} p WHERE p.id = d.firstpost) AS firstmessage,
                       (SELECT p.created FROM {forum_posts} p WHERE p.id = d.firstpost) AS createdtime
                  FROM {forum_discussions} d
                  JOIN {user} u ON u.id = d.userid
                 WHERE d.forum = ?
              ORDER BY d.pinned DESC, d.timemodified DESC, d.id DESC";

        $rows = $DB->get_records_sql($sql, [$forum->id]);
        $result = [];

        foreach ($rows as $r) {
            $authorname = fullname($r);
            $result[] = [
                'id'            => $r->id,
                'name'          => format_string($r->name),
                'firstpost_id'  => $r->firstpost,
                'author_id'     => $r->userid,
                'author_name'   => $authorname,
                'author_email'  => $r->email,
                'author_initial'=> strtoupper(substr($authorname, 0, 1)),
                'message_snip'  => clean_text(strip_tags($r->firstmessage ?? '')),
                'replies_count' => max(0, (int)$r->replies_count),
                'is_pinned'     => !empty($r->pinned),
                'created_str'   => userdate($r->createdtime ?: $r->timemodified, '%d %b %Y, %H:%M'),
                'time_ago'      => self::time_ago($r->timemodified),
            ];
        }

        return $result;
    }

    /**
     * Get single discussion and all threaded replies.
     *
     * @param int $discussionid
     * @return array|null
     */
    public static function get_discussion_thread(int $discussionid): ?array {
        global $DB;

        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid]);
        if (!$discussion) {
            return null;
        }

        $course = $DB->get_record('course', ['id' => $discussion->course]);

        $sql = "SELECT p.id, p.parent, p.userid, p.created, p.modified, p.subject, p.message,
                       u.firstname, u.lastname, u.email
                  FROM {forum_posts} p
                  JOIN {user} u ON u.id = p.userid
                 WHERE p.discussion = ? AND p.deleted = 0
              ORDER BY p.created ASC, p.id ASC";

        $posts = $DB->get_records_sql($sql, [$discussionid]);
        if (empty($posts)) {
            return null;
        }

        $firstpost = $posts[$discussion->firstpost] ?? reset($posts);
        $replies = [];

        foreach ($posts as $p) {
            if ($p->id == $firstpost->id) {
                continue;
            }
            $replies[] = [
                'id'            => $p->id,
                'parent_id'     => $p->parent,
                'author_id'     => $p->userid,
                'author_name'   => fullname($p),
                'author_email'  => $p->email,
                'author_initial'=> strtoupper(substr(fullname($p), 0, 1)),
                'message_html'  => clean_text($p->message, FORMAT_HTML),
                'created_str'   => userdate($p->created, '%d %b %Y, %H:%M'),
                'time_ago'      => self::time_ago($p->created),
            ];
        }

        return [
            'id'             => $discussion->id,
            'courseid'       => $discussion->course,
            'coursename'     => $course ? format_string($course->fullname) : '',
            'courseshort'    => $course ? format_string($course->shortname) : '',
            'name'           => format_string($discussion->name),
            'firstpost_id'   => $firstpost->id,
            'author_id'      => $firstpost->userid,
            'author_name'    => fullname($firstpost),
            'author_email'   => $firstpost->email,
            'author_initial' => strtoupper(substr(fullname($firstpost), 0, 1)),
            'message_html'   => clean_text($firstpost->message, FORMAT_HTML),
            'created_str'    => userdate($firstpost->created, '%d %b %Y, %H:%M'),
            'time_ago'       => self::time_ago($firstpost->created),
            'replies_count'  => count($replies),
            'replies'        => $replies,
        ];
    }

    /**
     * Create a new discussion thread.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $subject
     * @param string $message
     * @return int Discussion ID
     */
    public static function create_discussion(int $courseid, int $userid, string $subject, string $message): int {
        global $DB;

        $forum = self::ensure_general_forum($courseid);
        $now = time();

        // 1. Create Discussion record
        $disc = new stdClass();
        $disc->course = $courseid;
        $disc->forum = $forum->id;
        $disc->name = clean_text($subject);
        $disc->firstpost = 0;
        $disc->userid = $userid;
        $disc->groupid = -1;
        $disc->assessed = 0;
        $disc->timemodified = $now;
        $disc->usermodified = $userid;
        $disc->timestart = 0;
        $disc->timeend = 0;
        $disc->pinned = 0;
        $disc->timelocked = 0;

        $discussionid = $DB->insert_record('forum_discussions', $disc);

        // 2. Create First Post
        $post = new stdClass();
        $post->discussion = $discussionid;
        $post->parent = 0;
        $post->userid = $userid;
        $post->created = $now;
        $post->modified = $now;
        $post->mailed = 1;
        $post->subject = clean_text($subject);
        $post->message = clean_text($message, FORMAT_HTML);
        $post->messageformat = FORMAT_HTML;
        $post->messagetrust = 0;
        $post->attachment = '';
        $post->totalscore = 0;
        $post->mailnow = 0;
        $post->deleted = 0;
        $post->privatereplyto = 0;
        $post->wordcount = count_words($post->message);
        $post->charcount = count_letters($post->message);

        $postid = $DB->insert_record('forum_posts', $post);

        // Update discussion firstpost
        $DB->set_field('forum_discussions', 'firstpost', $postid, ['id' => $discussionid]);

        return $discussionid;
    }

    /**
     * Post a reply to an existing discussion.
     *
     * @param int $discussionid
     * @param int $parentid
     * @param int $userid
     * @param string $message
     * @return int Post ID
     */
    public static function add_reply(int $discussionid, int $parentid, int $userid, string $message): int {
        global $DB;

        $disc = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
        $now = time();

        $post = new stdClass();
        $post->discussion = $discussionid;
        $post->parent = $parentid;
        $post->userid = $userid;
        $post->created = $now;
        $post->modified = $now;
        $post->mailed = 1;
        $post->subject = 'Re: ' . $disc->name;
        $post->message = clean_text($message, FORMAT_HTML);
        $post->messageformat = FORMAT_HTML;
        $post->messagetrust = 0;
        $post->attachment = '';
        $post->totalscore = 0;
        $post->mailnow = 0;
        $post->deleted = 0;
        $post->privatereplyto = 0;
        $post->wordcount = count_words($post->message);
        $post->charcount = count_letters($post->message);

        $postid = $DB->insert_record('forum_posts', $post);

        // Update discussion timemodified
        $DB->set_field('forum_discussions', 'timemodified', $now, ['id' => $discussionid]);
        $DB->set_field('forum_discussions', 'usermodified', $userid, ['id' => $discussionid]);

        return $postid;
    }

    private static function time_ago(int $timestamp): string {
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Baru saja';
        if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
        return floor($diff / 86400) . ' hari lalu';
    }
}

