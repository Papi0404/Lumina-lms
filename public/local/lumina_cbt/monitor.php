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
 * Real-Time Proctoring Audit & Anti-Cheat Monitor Dashboard.
 *
 * Live streaming feed of exam infractions, active candidate management,
 * and instant proctor unlock/disqualification suite.
 *
 * @package    local_lumina_cbt
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/cbt_engine.php');

use local_lumina_cbt\cbt_engine;

require_login();

$sysctx = context_system::instance();
$courseid = optional_param('courseid', 0, PARAM_INT);
$course = null;

if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($courseid);
} else {
    $context = $sysctx;
}

$canmonitor = has_capability('local/lumina_cbt:monitor', $context) ||
              has_capability('moodle/grade:viewall', $context) ||
              has_capability('moodle/course:manageactivities', $context) ||
              is_siteadmin();

if (!$canmonitor) {
    throw new moodle_exception('nopermissions', 'error', '', 'Akses Dasbor Pengawasan CBT');
}

$PAGE->set_url(new moodle_url('/local/lumina_cbt/monitor.php', $courseid ? ['courseid' => $courseid] : []));
$PAGE->set_context($context);
$PAGE->set_title(get_string('monitor_dashboard', 'local_lumina_cbt'));
$PAGE->set_heading(get_string('cbt_proctoring', 'local_lumina_cbt'));

$selecteddate = optional_param('date', date('Y-m-d'), PARAM_RAW);
$search = optional_param('search', '', PARAM_RAW_TRIMMED);

$reviewlist = cbt_engine::get_sessions_for_review($selecteddate, $search, $courseid ?: null);
$livefeed = cbt_engine::get_live_audit_feed(0, $selecteddate, $search, 50);

$sessiontable = cbt_engine::get_session_table();
$activecount = $DB->count_records($sessiontable, ['status' => 'in_progress']);
$reviewcount = $DB->count_records($sessiontable, ['status' => 'under_review']);
$completedcount = $DB->count_records($sessiontable, ['status' => 'completed']);

echo $OUTPUT->header();
?>

<style>
.lumina-cbt-monitor-wrap {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #1E293B;
}

.cbt-stat-card {
    background: #FFFFFF;
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    border: 1px solid #E2E8F0;
    box-shadow: 0 4px 12px rgba(15,23,42,0.03);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.cbt-stat-val {
    font-size: 1.85rem;
    font-weight: 800;
    line-height: 1.1;
    margin-top: 4px;
}
.cbt-stat-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.cbt-live-pulse {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #10B981;
    display: inline-block;
    box-shadow: 0 0 0 rgba(16, 185, 129, 0.6);
    animation: cbtPulse 1.8s infinite;
}
@keyframes cbtPulse {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.cbt-panel {
    background: #FFFFFF;
    border-radius: 16px;
    border: 1px solid #E2E8F0;
    box-shadow: 0 4px 12px rgba(15,23,42,0.03);
    overflow: hidden;
}
.cbt-panel-header {
    padding: 1rem 1.25rem;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 700;
    font-size: 0.95rem;
}

.cbt-feed-item {
    padding: 12px 16px;
    border-bottom: 1px solid #F1F5F9;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: background 0.15s ease;
}
.cbt-feed-item:hover {
    background: #F8FAFC;
}
.cbt-feed-item:last-child {
    border-bottom: none;
}

.cbt-table th {
    background: #F8FAFC;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid #E2E8F0;
}
.cbt-table td {
    padding: 0.95rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #F1F5F9;
    font-size: 0.88rem;
}

.badge-strike-0 { background: #DCFCE7; color: #166534; font-weight: 600; padding: 4px 10px; border-radius: 999px; }
.badge-strike-1 { background: #FEF3C7; color: #92400E; font-weight: 600; padding: 4px 10px; border-radius: 999px; }
.badge-strike-2 { background: #FFEDD5; color: #9A3412; font-weight: 600; padding: 4px 10px; border-radius: 999px; }
.badge-strike-3 { background: #FEE2E2; color: #991B1B; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
</style>

<div class="lumina-cbt-monitor-wrap py-2">
    <!-- Header Banner -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="cbt-live-pulse"></span>
                <span class="text-success fw-bold small text-uppercase tracking-wider">Live Proctoring Active</span>
            </div>
            <h2 class="fw-bold mb-1" style="color: #0F172A;">
                🛡️ <?php echo get_string('monitor_dashboard', 'local_lumina_cbt'); ?>
            </h2>
            <div class="text-muted small">
                Pengawasan langsung real-time: mendeteksi perpindahan tab, gesture touchpad, shortcut DevTools, dan status kunci ujian.
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Audio Chime Toggle -->
            <label class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2 cursor-pointer m-0">
                <input type="checkbox" id="cbt-sound-toggle" checked style="accent-color: #4338CA;">
                <span>🔊 Alarm Suara</span>
            </label>

            <?php if ($courseid > 0): ?>
            <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $courseid; ?>" class="btn btn-sm btn-light border rounded-pill px-3 py-2">
                &larr; Kembali ke Kelas
            </a>
            <?php else: ?>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-2">
                &larr; Dashboard
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Peserta Ujian Aktif</div>
                    <div class="cbt-stat-val text-primary" id="cbt-stat-active"><?php echo $activecount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.8;">✍️</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Terkunci / Ditangguhkan</div>
                    <div class="cbt-stat-val text-danger" id="cbt-stat-review"><?php echo $reviewcount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.8;">🔒</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Selesai Dikumpulkan</div>
                    <div class="cbt-stat-val text-success"><?php echo $completedcount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.8;">✓</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Total Pelanggaran Hari Ini</div>
                    <div class="cbt-stat-val text-warning" id="cbt-stat-violations"><?php echo count($livefeed); ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.8;">🚨</div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="cbt-panel p-3 mb-4">
        <form method="get" class="row g-2 align-items-center">
            <?php if ($courseid > 0): ?>
                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari nama peserta / email / ujian..." value="<?php echo s($search); ?>">
            </div>
            <div class="col-md-4">
                <input type="date" name="date" class="form-control form-control-sm" value="<?php echo s($selecteddate); ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100 font-weight-bold">Filter</button>
                <a href="<?php echo new moodle_url('/local/lumina_cbt/monitor.php', $courseid ? ['courseid' => $courseid] : []); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- Main Live Split: Stream vs Candidate Roster -->
    <div class="row g-4">
        <!-- Left: Live Infraction Feed (Real-Time Ticker) -->
        <div class="col-lg-5">
            <div class="cbt-panel">
                <div class="cbt-panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <span>📡</span>
                        <span><?php echo get_string('live_feed', 'local_lumina_cbt'); ?></span>
                    </div>
                    <span class="badge bg-light text-secondary border" id="cbt-feed-count"><?php echo count($livefeed); ?> event</span>
                </div>
                <div id="cbt-live-feed-list" style="max-height: 580px; overflow-y: auto;">
                    <?php if (empty($livefeed)): ?>
                        <div class="p-4 text-center text-muted small" id="cbt-empty-feed-msg">
                            🎉 Belum ada pelanggaran terdeteksi pada rentang waktu ini.
                        </div>
                    <?php else: ?>
                        <?php foreach ($livefeed as $ev): ?>
                            <div class="cbt-feed-item">
                                <span style="font-size: 20px;"><?php echo ($ev['eventtype'] === 'keyboard_attempt' ? '⌨️' : ($ev['eventtype'] === 'fullscreen_exit' ? '⛶' : '⚠️')); ?></span>
                                <div class="w-100">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold" style="font-size: 0.88rem; color: #0F172A;"><?php echo s($ev['student_name']); ?></span>
                                        <span class="text-muted" style="font-size: 0.75rem;"><?php echo s($ev['time_formatted']); ?></span>
                                    </div>
                                    <div class="mb-1">
                                        <span class="<?php echo $ev['badge_class']; ?>" style="font-size: 0.75rem;"><?php echo s($ev['label']); ?></span>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.78rem;">
                                        <?php echo s($ev['course']); ?> &bull; <?php echo s($ev['metadata']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Active Candidates & Review Roster -->
        <div class="col-lg-7">
            <div class="cbt-panel">
                <div class="cbt-panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <span>👥</span>
                        <span><?php echo get_string('active_candidates', 'local_lumina_cbt'); ?></span>
                    </div>
                    <span class="badge bg-primary rounded-pill"><?php echo count($reviewlist); ?> sesi</span>
                </div>

                <div class="table-responsive" style="max-height: 580px; overflow-y: auto;">
                    <table class="table cbt-table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Peserta Ujian</th>
                                <th>Status &amp; Pelanggaran</th>
                                <th>Ujian / Nilai</th>
                                <th class="text-end">Tindakan Pengawas</th>
                            </tr>
                        </thead>
                        <tbody id="cbt-candidates-body">
                            <?php if (empty($reviewlist)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Tidak ada sesi ujian yang cocok dengan kriteria filter.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reviewlist as $item): ?>
                                    <tr id="session-row-<?php echo $item['session_id']; ?>">
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo s($item['student_name']); ?></div>
                                            <div class="text-muted small"><?php echo s($item['student_email']); ?></div>
                                            <span class="badge bg-light text-secondary border mt-1" style="font-size: 0.7rem;">
                                                <?php echo s($item['role_label']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="mb-1">
                                                <?php
                                                $wcount = $item['warning_count'];
                                                $badgeClass = 'badge-strike-' . min(3, $wcount);
                                                ?>
                                                <span class="<?php echo $badgeClass; ?>">
                                                    <?php echo $wcount; ?> / 3 Peringatan
                                                </span>
                                            </div>
                                            <?php if ($item['status'] === 'under_review'): ?>
                                                <span class="badge bg-danger text-white">🔒 TERKUNCI</span>
                                            <?php elseif ($item['status'] === 'completed'): ?>
                                                <span class="badge bg-success text-white">✓ Selesai</span>
                                            <?php elseif ($item['status'] === 'approved'): ?>
                                                <span class="badge bg-info text-white">✓ Disetujui</span>
                                            <?php elseif ($item['status'] === 'invalidated'): ?>
                                                <span class="badge bg-dark text-white">✕ Dibatalkan</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary text-white">Sedang Mengerjakan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-bold text-dark"><?php echo s($item['activity_name']); ?></div>
                                            <div class="text-muted small"><?php echo s($item['course_name']); ?></div>
                                            <?php if ($item['score'] !== null): ?>
                                                <div class="fw-bold text-primary mt-1">Nilai: <?php echo number_format($item['score'], 2); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="cbtViewAudit(<?php echo $item['session_id']; ?>, '<?php echo addslashes(s($item['student_name'])); ?>')">
                                                    Log
                                                </button>
                                                <?php if ($item['status'] === 'under_review' || $item['warning_count'] > 0): ?>
                                                    <button type="button" class="btn btn-success btn-sm" title="Buka Kunci & Reset 0/3" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'reset')">
                                                        🔓 Unlock
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($item['status'] === 'under_review'): ?>
                                                    <button type="button" class="btn btn-danger btn-sm" title="Batalkan Ujian" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'invalidated')">
                                                        ✕
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Audit Trail Modal -->
<div class="modal fade" id="cbtAuditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white rounded-top-4">
                <h5 class="modal-title fw-bold" id="cbtAuditModalTitle">Riwayat Audit Peserta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="cbtAuditModalBody">
                <div class="text-center py-4 text-muted">Memuat data log audit...</div>
            </div>
        </div>
    </div>
</div>

<script>
var cbtLatestTimestamp = <?php echo time(); ?>;
var cbtSoundEnabled = true;

document.getElementById('cbt-sound-toggle').addEventListener('change', function() {
    cbtSoundEnabled = this.checked;
});

function playChime() {
    if (!cbtSoundEnabled) return;
    try {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        var ctx = new AudioCtx();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.3);
        gain.gain.setValueAtTime(0.25, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.3);
    } catch(e){}
}

// Polling live feed every 2.5 seconds
setInterval(function() {
    var url = '<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php?action=feed&since=' + cbtLatestTimestamp + '&sesskey=<?php echo sesskey(); ?>';
    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success && data.feed && data.feed.length > 0) {
            playChime();
            var listEl = document.getElementById('cbt-live-feed-list');
            var emptyEl = document.getElementById('cbt-empty-feed-msg');
            if (emptyEl) emptyEl.style.display = 'none';

            data.feed.forEach(function(ev) {
                var item = document.createElement('div');
                item.className = 'cbt-feed-item';
                item.style.background = '#FEF2F2';
                item.innerHTML = '<span style="font-size: 20px;">🚨</span>' +
                                 '<div class="w-100">' +
                                 '<div class="d-flex justify-content-between align-items-center mb-1">' +
                                 '<span class="fw-bold" style="font-size: 0.88rem; color: #0F172A;">' + ev.student_name + '</span>' +
                                 '<span class="text-muted" style="font-size: 0.75rem;">' + ev.time_formatted + '</span>' +
                                 '</div>' +
                                 '<div class="mb-1"><span class="' + ev.badge_class + '" style="font-size: 0.75rem;">' + ev.label + '</span></div>' +
                                 '<div class="text-muted" style="font-size: 0.78rem;">' + ev.course + ' &bull; ' + ev.metadata + '</div>' +
                                 '</div>';
                listEl.insertBefore(item, listEl.firstChild);
            });

            cbtLatestTimestamp = data.server_time || Math.floor(Date.now() / 1000);
            document.getElementById('cbt-stat-active').innerText = data.active_count;
            document.getElementById('cbt-stat-review').innerText = data.review_count;
        }
    })
    .catch(function(e){});
}, 2500);

// Resolve candidate session
function cbtResolve(sessionId, decision) {
    var promptMsg = (decision === 'reset') ? 'Beri kesempatan ulang & reset peringatan menjadi 0/3?' : 'Konfirmasi tindakan untuk sesi ini?';
    if (!confirm(promptMsg)) return;

    var form = new FormData();
    form.append('action', 'resolve');
    form.append('session_id', sessionId);
    form.append('decision', decision);
    form.append('sesskey', '<?php echo sesskey(); ?>');

    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php', {
        method: 'POST',
        body: form
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            alert(data.message || 'Tindakan berhasil diproses!');
            window.location.reload();
        } else {
            alert(data.error || 'Gagal memproses tindakan.');
        }
    });
}

// View audit modal
function cbtViewAudit(sessionId, name) {
    document.getElementById('cbtAuditModalTitle').innerText = 'Riwayat Audit: ' + name;
    var body = document.getElementById('cbtAuditModalBody');
    body.innerHTML = '<div class="text-center py-4 text-muted">Memuat data log audit...</div>';

    var modal = new bootstrap.Modal(document.getElementById('cbtAuditModal'));
    modal.show();

    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php?action=session_events&session_id=' + sessionId + '&sesskey=<?php echo sesskey(); ?>')
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success && data.events && data.events.length > 0) {
            var html = '<div class="list-group list-group-flush">';
            data.events.forEach(function(ev) {
                html += '<div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">' +
                        '<div>' +
                        '<div class="fw-bold small">' + ev.label + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">' + ev.metadata + '</div>' +
                        '</div>' +
                        '<span class="badge bg-light text-secondary border">' + ev.time + '</span>' +
                        '</div>';
            });
            html += '</div>';
            body.innerHTML = html;
        } else {
            body.innerHTML = '<div class="text-center py-4 text-success font-semibold">Tidak ada riwayat pelanggaran pada sesi ini.</div>';
        }
    });
}
</script>

<?php
echo $OUTPUT->footer();
