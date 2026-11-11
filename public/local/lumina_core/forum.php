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
 * Lumina LMS Forum Discussion Hub: Secure, Course-Isolated Discussion Interface.
 *
 * @package    local_lumina_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/forum_manager.php');
require_once($CFG->dirroot . '/theme/lumina/classes/role_resolver.php');

use local_lumina_core\forum_manager;

require_login();

$courseid     = optional_param('courseid', 0, PARAM_INT);
$discussionid = optional_param('discussionid', 0, PARAM_INT);
$action       = optional_param('action', '', PARAM_ALPHANUMEXT);

$roleinfo = theme_lumina_role_resolver::resolve_role($USER);

// Determine accessible courses based on role and enrollment
$accessiblecourses = [];
if ($roleinfo['is_admin']) {
    $accessiblecourses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC');
} else if ($roleinfo['is_teacher']) {
    $tdata = theme_lumina_role_resolver::get_teacher_dashboard_data($USER);
    foreach ($tdata['courses'] as $c) {
        $cid = (int)(is_object($c) ? $c->id : $c['id']);
        $accessiblecourses[$cid] = $c;
    }
} else {
    $accessiblecourses = enrol_get_all_users_courses($USER->id, true);
}

// Fallback course selection with reliable courseid resolution
$validcourseids = [];
foreach ($accessiblecourses as $c) {
    $validcourseids[] = (int)(is_object($c) ? $c->id : $c['id']);
}

if ($courseid > 0 && !in_array((int)$courseid, $validcourseids, true) && !$roleinfo['is_admin']) {
    // Security check: Student cannot tamper courseid to inspect other courses!
    $firstcourse = !empty($accessiblecourses) ? reset($accessiblecourses) : null;
    $courseid = $firstcourse ? (int)(is_object($firstcourse) ? $firstcourse->id : $firstcourse['id']) : 0;
} else if (!$courseid && !empty($accessiblecourses)) {
    $firstcourse = reset($accessiblecourses);
    $courseid = (int)(is_object($firstcourse) ? $firstcourse->id : $firstcourse['id']);
}

$course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : null;
$context = $course ? context_course::instance($course->id) : context_system::instance();

$PAGE->set_url('/local/lumina_core/forum.php', ['courseid' => $courseid, 'discussionid' => $discussionid]);
$PAGE->set_context($context);
$PAGE->set_title('Forum Diskusi & Kolaborasi Kelas');
$PAGE->set_heading($course ? format_string($course->fullname) : 'Forum Diskusi Kelas');

$notice = '';
$noticetype = 'info';

// Process Actions (Create Discussion / Add Reply)
if (data_submitted() && confirm_sesskey()) {
    $targetcid = optional_param('target_courseid', $courseid, PARAM_INT);
    if ($targetcid > 0 && (in_array($targetcid, $validcourseids, true) || $roleinfo['is_admin'])) {
        $courseid = $targetcid;
    }

    if ($action === 'create_discussion' && $courseid > 0) {
        $subject = required_param('subject', PARAM_TEXT);
        $message = required_param('message', PARAM_RAW);

        if (!empty(trim($subject)) && !empty(trim($message))) {
            $newdiscid = forum_manager::create_discussion($courseid, $USER->id, $subject, $message);
            $notice = 'Topik diskusi baru berhasil diterbitkan!';
            $noticetype = 'success';
            $discussionid = $newdiscid;
        }
    } else if ($action === 'add_reply' && $discussionid > 0) {
        $parentid = optional_param('parent_id', 0, PARAM_INT);
        $replymsg = required_param('reply_message', PARAM_RAW);

        if (!empty(trim($replymsg))) {
            forum_manager::add_reply($discussionid, $parentid, $USER->id, $replymsg);
            $notice = 'Tanggapan Anda berhasil dikirim!';
            $noticetype = 'success';
        }
    }
}

// Fetch thread or discussions list
$thread = ($discussionid > 0) ? forum_manager::get_discussion_thread($discussionid) : null;
$discussions = (!$thread && $courseid > 0) ? forum_manager::get_discussions($courseid) : [];

echo $OUTPUT->header();
?>

<div class="lumina-forum-container py-3">
    <!-- Header Section -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-1 mb-2 d-inline-flex align-items-center gap-1 font-semibold text-secondary">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Dashboard
            </a>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-indigo" style="font-size: 32px;">forum</span>
                Forum Diskusi &amp; Kolaborasi Kelas
            </h2>
            <span class="text-muted small">
                Ruang tanya jawab materi, bertukar gagasan studi kasus, dan kolaborasi terarah antar mahasiswa dan dosen.
            </span>
        </div>

        <?php if ($courseid > 0 && !$thread): ?>
        <div>
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" data-bs-toggle="modal" data-bs-target="#newDiscussionModal">
                <span class="material-symbols-outlined" style="font-size: 18px;">add_comment</span>
                Mulai Topik Diskusi Baru
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($notice): ?>
    <div class="alert alert-<?php echo $noticetype; ?> rounded-2xl alert-dismissible fade show mb-4 shadow-xs" role="alert">
        <div class="d-flex align-items-center gap-2">
            <span class="material-symbols-outlined">check_circle</span>
            <strong><?php echo $notice; ?></strong>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Course Switcher Tabs -->
    <div class="antigravity-glass-card rounded-3xl p-3 mb-4">
        <div class="d-flex align-items-center gap-2 overflow-auto py-1">
            <?php foreach ($accessiblecourses as $c): ?>
                <?php
                $cid = is_object($c) ? $c->id : $c['id'];
                $cname = is_object($c) ? $c->fullname : $c['fullname'];
                $cshort = is_object($c) ? $c->shortname : $c['shortname'];
                $active = ($courseid == $cid && !$thread);
                ?>
                <a href="forum.php?courseid=<?php echo $cid; ?>" class="btn btn-sm rounded-pill px-3 py-2 text-nowrap d-inline-flex align-items-center gap-1 font-semibold <?php echo $active ? 'btn-primary shadow-xs' : 'btn-light border text-secondary'; ?>">
                    <span class="material-symbols-outlined" style="font-size: 16px;">chat</span>
                    <?php echo format_string($cname); ?> (<?php echo format_string($cshort); ?>)
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($thread): ?>
        <!-- ================================================================= -->
        <!-- SINGLE DISCUSSION THREAD VIEW                                     -->
        <!-- ================================================================= -->
        <div class="mb-3">
            <a href="forum.php?courseid=<?php echo $thread['courseid']; ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 font-semibold d-inline-flex align-items-center gap-1">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span>
                Kembali ke Daftar Topik <?php echo $thread['courseshort']; ?>
            </a>
        </div>

        <!-- Original Post -->
        <div class="antigravity-glass-card rounded-3xl p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 44px; height: 44px; font-size: 1.1rem;">
                        <?php echo $thread['author_initial']; ?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark"><?php echo $thread['name']; ?></h4>
                        <span class="text-muted small">
                            Oleh <strong><?php echo $thread['author_name']; ?></strong> &bull; <?php echo $thread['created_str']; ?> (<?php echo $thread['time_ago']; ?>)
                        </span>
                    </div>
                </div>

                <span class="badge bg-primary bg-opacity-15 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 font-semibold">
                    <?php echo $thread['replies_count']; ?> Tanggapan
                </span>
            </div>

            <div class="discussion-body text-dark py-2" style="font-size: 0.95rem; line-height: 1.6;">
                <?php echo $thread['message_html']; ?>
            </div>
        </div>

        <!-- Replies Section -->
        <div class="antigravity-glass-card rounded-3xl p-4 mb-4">
            <h5 class="fw-bold mb-4 pb-2 border-bottom d-flex align-items-center gap-2">
                <span class="material-symbols-outlined text-primary">reply_all</span>
                Tanggapan &amp; Jawaban Diskusi (<?php echo $thread['replies_count']; ?>)
            </h5>

            <?php if (!empty($thread['replies'])): ?>
                <div class="d-flex flex-column gap-3 mb-4">
                    <?php foreach ($thread['replies'] as $reply): ?>
                    <div class="p-3 rounded-2xl border bg-white shadow-xs">
                        <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-indigo bg-opacity-10 text-indigo d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                    <?php echo $reply['author_initial']; ?>
                                </div>
                                <div>
                                    <span class="fw-bold text-dark d-block" style="font-size: 0.88rem;"><?php echo $reply['author_name']; ?></span>
                                    <span class="text-muted small" style="font-size: 0.72rem;"><?php echo $reply['created_str']; ?> (<?php echo $reply['time_ago']; ?>)</span>
                                </div>
                            </div>
                        </div>
                        <div class="reply-content text-dark" style="font-size: 0.9rem; line-height: 1.5;">
                            <?php echo $reply['message_html']; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-muted mb-4">
                    <span class="material-symbols-outlined" style="font-size: 36px;">question_answer</span>
                    <p class="mb-0 mt-1 small">Belum ada tanggapan untuk topik ini. Jadilah yang pertama menjawab!</p>
                </div>
            <?php endif; ?>

            <!-- Quick Reply Box -->
            <div class="p-4 rounded-2xl bg-light border">
                <h6 class="fw-bold mb-2 text-dark">Tulis Tanggapan Anda</h6>
                <form method="post" action="forum.php?courseid=<?php echo $thread['courseid']; ?>&discussionid=<?php echo $thread['id']; ?>&action=add_reply">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="parent_id" value="<?php echo $thread['firstpost_id']; ?>">
                    <div class="mb-3">
                        <textarea name="reply_message" class="form-control rounded-2xl bg-white" rows="3" placeholder="Tuliskan argumen, pertanyaan lanjutan, atau jawaban solusi Anda di sini..." required></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary rounded-pill px-4 py-2 font-semibold shadow-xs">
                            <span class="material-symbols-outlined" style="font-size: 16px; vertical-align: -2px;">send</span>
                            Kirim Tanggapan
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- DISCUSSIONS LIST VIEW                                             -->
        <!-- ================================================================= -->
        <div class="antigravity-glass-card rounded-3xl p-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <span class="fw-bold">Daftar Topik Diskusi</span>
                <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2 py-0-5 small">
                    Total: <?php echo count($discussions); ?> Topik
                </span>
            </div>

            <?php if (!empty($discussions)): ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($discussions as $disc): ?>
                <a href="forum.php?courseid=<?php echo $courseid; ?>&discussionid=<?php echo $disc['id']; ?>" class="text-decoration-none">
                    <div class="p-3-5 rounded-2xl border bg-white transition-all hover-shadow-md d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-start gap-3 overflow-hidden flex-fill">
                            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 40px; height: 40px;">
                                <?php echo $disc['author_initial']; ?>
                            </div>
                            <div class="overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate" style="font-size: 0.95rem;"><?php echo $disc['name']; ?></h6>
                                <p class="text-muted small mb-2 text-truncate" style="font-size: 0.8rem; max-width: 650px;">
                                    <?php echo $disc['message_snip'] ?: 'Klik untuk melihat rincian pertanyaan...'; ?>
                                </p>
                                <div class="d-flex align-items-center gap-2 text-muted small" style="font-size: 0.72rem;">
                                    <span>Ditanyakan oleh <strong><?php echo $disc['author_name']; ?></strong></span>
                                    <span>&bull;</span>
                                    <span><?php echo $disc['time_ago']; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3">
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 font-semibold" style="font-size: 0.75rem;">
                                <?php echo $disc['replies_count']; ?> Balasan
                            </span>
                            <span class="material-symbols-outlined text-muted" style="font-size: 20px;">chevron_right</span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <span class="material-symbols-outlined text-muted" style="font-size: 48px;">chat_bubble_outline</span>
                <h5 class="fw-bold mt-2 mb-1">Belum Ada Topik Diskusi</h5>
                <p class="small mb-3">Jadilah yang pertama memulai diskusi atau mengajukan pertanyaan di kelas ini!</p>
                <button type="button" class="btn btn-outline-primary rounded-pill px-4 py-2 font-semibold" data-bs-toggle="modal" data-bs-target="#newDiscussionModal">
                    Mulai Topik Baru
                </button>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Mulai Topik Diskusi Baru -->
<div class="modal fade" id="newDiscussionModal" tabindex="-1" aria-labelledby="newDiscussionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-3xl border-0 shadow">
            <form method="post" action="forum.php?courseid=<?php echo $courseid; ?>&action=create_discussion">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <div class="modal-header border-bottom px-4 pt-4">
                    <h5 class="modal-title fw-bold" id="newDiscussionModalLabel">Mulai Topik Diskusi Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if (count($accessiblecourses) > 1): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Pilih Kelas / Mata Kuliah</label>
                        <select name="target_courseid" class="form-select rounded-2xl">
                            <?php foreach ($accessiblecourses as $ac): ?>
                                <?php
                                $acid = is_object($ac) ? $ac->id : $ac['id'];
                                $acname = is_object($ac) ? $ac->fullname : $ac['fullname'];
                                $acshort = is_object($ac) ? $ac->shortname : $ac['shortname'];
                                ?>
                                <option value="<?php echo $acid; ?>" <?php echo ($acid == $courseid) ? 'selected' : ''; ?>>
                                    <?php echo format_string($acname); ?> (<?php echo format_string($acshort); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="target_courseid" value="<?php echo $courseid; ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Judul Pertanyaan / Pokok Diskusi</label>
                        <input type="text" name="subject" class="form-control rounded-2xl" placeholder="Contoh: Bagaimana cara optimasi responsive grid di mobile?" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Uraian / Pesan Diskusi</label>
                        <textarea name="message" class="form-control rounded-2xl" rows="5" placeholder="Tuliskan pertanyaan detail, potongan kode, atau materi yang ingin didiskusikan..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-semibold shadow-xs">Terbitkan Diskusi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();

