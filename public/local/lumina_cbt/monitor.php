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
 * Real-Time CBT Proctoring & Anti-Cheat Audit Dashboard.
 *
 * Live streaming feed of infractions, real-time candidate management,
 * course & activity dynamic filtering, and instant lock/unlock/invalidation actions.
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
$activityid = optional_param('activityid', 0, PARAM_INT);
$filterscope = optional_param('filterscope', 'violations_only', PARAM_ALPHAEXT);
$selecteddate = optional_param('date', date('Y-m-d'), PARAM_RAW);
$selecteddateto = optional_param('dateto', '', PARAM_RAW);
$rangepreset = optional_param('preset', '', PARAM_ALPHAEXT);
$search = optional_param('search', '', PARAM_RAW_TRIMMED);

// Auto-determine preset if empty
if (empty($rangepreset)) {
    if ($selecteddate === 'all') {
        $rangepreset = 'all';
    } else if (empty($selecteddateto) || $selecteddateto === $selecteddate) {
        if ($selecteddate === date('Y-m-d')) {
            $rangepreset = 'today';
        } else if ($selecteddate === date('Y-m-d', strtotime('-1 day'))) {
            $rangepreset = 'yesterday';
        } else {
            $rangepreset = 'custom';
        }
    } else if ($selecteddate === date('Y-m-d', strtotime('-6 days')) && $selecteddateto === date('Y-m-d')) {
        $rangepreset = 'last7';
    } else if ($selecteddate === date('Y-m-d', strtotime('-29 days')) && $selecteddateto === date('Y-m-d')) {
        $rangepreset = 'last30';
    } else if ($selecteddate === date('Y-m-01') && $selecteddateto === date('Y-m-d')) {
        $rangepreset = 'this_month';
    } else {
        $rangepreset = 'custom';
    }
}

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

$PAGE->set_url(new moodle_url('/local/lumina_cbt/monitor.php', array_filter([
    'courseid'    => $courseid,
    'activityid'  => $activityid,
    'filterscope' => $filterscope,
    'date'        => $selecteddate,
    'dateto'      => $selecteddateto,
    'preset'      => $rangepreset,
    'search'      => $search,
])));
$PAGE->set_context($context);
$PAGE->set_title(get_string('monitor_dashboard', 'local_lumina_cbt'));
$PAGE->set_heading(get_string('cbt_proctoring', 'local_lumina_cbt'));

// Retrieve courses list for filter dropdown
if (is_siteadmin()) {
    $courseslist = $DB->get_records_select('course', 'id != ?', [SITEID], 'fullname ASC', 'id, fullname, shortname');
} else {
    $courseslist = enrol_get_all_users_courses($USER->id, true);
}

// Retrieve activities list if course selected
$activitieslist = [];
if ($courseid > 0) {
    $sql_act = "SELECT cm.id, COALESCE(q.name, h.name, a.name, cm.id) AS name, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {quiz} q ON q.id = cm.instance AND m.name = 'quiz'
             LEFT JOIN {h5pactivity} h ON h.id = cm.instance AND m.name = 'h5pactivity'
             LEFT JOIN {assign} a ON a.id = cm.instance AND m.name = 'assign'
                 WHERE cm.course = ? AND m.name IN ('quiz', 'h5pactivity', 'assign') AND cm.deletioninprogress = 0
              ORDER BY cm.id ASC";
    $activitieslist = $DB->get_records_sql($sql_act, [$courseid]);
}

// Compute metrics aggregated across the selected range
$metrics = cbt_engine::get_range_metrics($selecteddate, $selecteddateto, $courseid ?: null, $activityid ?: null, $search);

$reviewlist = cbt_engine::get_sessions_for_review($selecteddate, $search, $courseid ?: null, $activityid ?: null, $filterscope, $selecteddateto);
$livefeed = cbt_engine::get_live_audit_feed(0, $selecteddate, $search, 50, $courseid ?: null, $activityid ?: null, $selecteddateto);

$activecount     = $metrics['active_count'];
$reviewcount     = $metrics['locked_count'];
$completedcount  = $metrics['clean_count'];
$totalviolations = $metrics['total_violations'];

echo $OUTPUT->header();
?>

<style>
/* Universal Theme Neutrality & Spacing */
.lumina-cbt-monitor-wrap {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #1E293B;
    padding-top: 1.75rem !important;
    margin-top: 0.5rem !important;
    max-width: 1440px;
    margin-left: auto;
    margin-right: auto;
    position: relative;
    z-index: 1;
}

/* Ensure no navbar clipping across Moon, Boost, and child themes */
#page.drawers .lumina-cbt-monitor-wrap,
#page-content .lumina-cbt-monitor-wrap,
.moon-layout-content .lumina-cbt-monitor-wrap {
    padding-top: 2rem !important;
}

.cbt-header-banner {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 4px 16px rgba(15,23,42,0.03);
    margin-bottom: 1.25rem;
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
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.cbt-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15,23,42,0.06);
}
.cbt-stat-val {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.1;
    margin-top: 4px;
}
.cbt-stat-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.05em;
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

.cbt-preset-btn {
    transition: all 0.15s ease;
    font-size: 0.78rem;
    font-weight: 600;
}
</style>

<div class="lumina-cbt-monitor-wrap py-2">
    <!-- Header Banner -->
    <div class="cbt-header-banner">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="cbt-live-pulse"></span>
                    <span class="text-success fw-bold small text-uppercase tracking-wider">Live Proctoring Active</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-0.5 small" id="cbt-active-range-badge">
                        📅 <?php echo s($metrics['range_label']); ?>
                    </span>
                </div>
                <h2 class="fw-bold mb-1" style="color: #0F172A;">
                    🛡️ <?php echo get_string('monitor_dashboard', 'local_lumina_cbt'); ?>
                </h2>
                <div class="text-muted small">
                    Pengawasan langsung real-time: deteksi tab, gesture touchpad, shortcut, dan rekapitulasi data pelanggaran berbasis rentang kalender.
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Audio Chime Toggle -->
                <label class="btn btn-sm btn-light border d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2 cursor-pointer m-0 shadow-xs" style="background: #ffffff;">
                    <input type="checkbox" id="cbt-sound-toggle" checked style="accent-color: #4338CA;">
                    <span>🔊 Alarm Suara</span>
                </label>

                <?php if ($courseid > 0): ?>
                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $courseid; ?>" class="btn btn-sm btn-light border rounded-pill px-3 py-2 fw-semibold shadow-xs" style="background: #ffffff;">
                    &larr; Kembali ke Kelas
                </a>
                <?php else: ?>
                <a href="<?php echo $CFG->wwwroot; ?>/my/" class="btn btn-sm btn-light border rounded-pill px-3 py-2 fw-semibold shadow-xs" style="background: #ffffff;">
                    &larr; Dashboard
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats Cards (Aggregated for Selected Date Range) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Peserta Ujian Aktif</div>
                    <div class="cbt-stat-val text-primary" id="cbt-stat-active"><?php echo $activecount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.85;">✍️</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Terkunci / Ditangguhkan</div>
                    <div class="cbt-stat-val text-danger" id="cbt-stat-review"><?php echo $reviewcount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.85;">🔒</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Lolos Bersih (0 Pelanggaran)</div>
                    <div class="cbt-stat-val text-success" id="cbt-stat-completed"><?php echo $completedcount; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.85;">✓</div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="cbt-stat-card">
                <div>
                    <div class="cbt-stat-label">Total Pelanggaran Terdeteksi</div>
                    <div class="cbt-stat-val text-warning" id="cbt-stat-violations"><?php echo $totalviolations; ?></div>
                </div>
                <div style="font-size: 28px; opacity: 0.85;">🚨</div>
            </div>
        </div>
    </div>

    <!-- Real-time Filter & Date Range Calendar Toolbar -->
    <div class="cbt-panel p-3 mb-4">
        <form method="get" id="cbt-filter-form">
            <!-- Row 1: Course, Activity, Scope, Search -->
            <div class="row g-2 align-items-center mb-3">
                <!-- Course Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1 fw-bold">Kelas / Kursus:</label>
                    <select name="courseid" id="cbt-filter-course" class="form-select form-select-sm" onchange="document.getElementById('cbt-filter-form').submit();">
                        <option value="0">Semua Kelas</option>
                        <?php foreach ($courseslist as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php echo ($courseid == $c->id ? 'selected' : ''); ?>>
                                <?php echo format_string($c->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Activity Filter -->
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1 fw-bold">Ujian / Aktivitas:</label>
                    <select name="activityid" id="cbt-filter-activity" class="form-select form-select-sm" onchange="document.getElementById('cbt-filter-form').submit();">
                        <option value="0">Semua Ujian</option>
                        <?php foreach ($activitieslist as $a): ?>
                            <?php $badge = ($a->modname === 'quiz' ? '📝 Quiz' : ($a->modname === 'h5pactivity' ? '🎮 H5P' : '📋 Tugas')); ?>
                            <option value="<?php echo $a->id; ?>" <?php echo ($activityid == $a->id ? 'selected' : ''); ?>>
                                <?php echo format_string($a->name) . ' (' . $badge . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Scope Filter -->
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1 fw-bold">Tampilan:</label>
                    <select name="filterscope" id="cbt-filter-scope" class="form-select form-select-sm" onchange="document.getElementById('cbt-filter-form').submit();">
                        <option value="violations_only" <?php echo ($filterscope === 'violations_only' ? 'selected' : ''); ?>>⚠️ Perlu Ditindak</option>
                        <option value="all" <?php echo ($filterscope === 'all' ? 'selected' : ''); ?>>📋 Semua Peserta</option>
                    </select>
                </div>

                <!-- Search -->
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1 fw-bold">Cari Siswa:</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" id="cbt-filter-search" class="form-control" placeholder="Nama siswa atau email..." value="<?php echo s($search); ?>" onkeydown="if(event.key==='Enter'){document.getElementById('cbt-filter-form').submit();}">
                        <button type="submit" class="btn btn-primary px-3 fw-semibold">Cari</button>
                        <?php if (!empty($search)): ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('cbt-filter-search').value=''; document.getElementById('cbt-filter-form').submit();" title="Reset Cari">✕</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Row 2: Date Range Picker & Quick Presets -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <!-- Left: Custom Date Range Inputs -->
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="small fw-bold text-muted d-inline-flex align-items-center gap-1">
                        <span class="material-symbols-outlined text-primary" style="font-size: 18px;">date_range</span>
                        Rentang Kalender:
                    </span>
                    <div class="d-inline-flex align-items-center gap-1 bg-light border rounded-pill px-2.5 py-1">
                        <span class="small text-muted fw-semibold ps-1">Dari:</span>
                        <input type="date" name="date" id="cbt-filter-date" class="form-control form-control-sm border-0 bg-transparent px-1 py-0 fw-semibold text-primary" style="width: 135px; outline: none; box-shadow: none;" value="<?php echo ($selecteddate === 'all' ? '' : s($selecteddate)); ?>">
                        <span class="text-muted small">&mdash;</span>
                        <span class="small text-muted fw-semibold">Sampai:</span>
                        <input type="date" name="dateto" id="cbt-filter-dateto" class="form-control form-control-sm border-0 bg-transparent px-1 py-0 fw-semibold text-primary" style="width: 135px; outline: none; box-shadow: none;" value="<?php echo ($selecteddateto === 'all' ? '' : s($selecteddateto)); ?>">
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 py-0.5 fw-bold shadow-xs ms-1" title="Terapkan Rentang Kalender">
                            Terapkan
                        </button>
                    </div>
                </div>

                <!-- Right: Quick Preset Buttons -->
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <div class="btn-group btn-group-sm rounded-pill border p-0.5 bg-light" role="group">
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'today' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('today')">
                            Hari Ini
                        </button>
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'yesterday' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('yesterday')">
                            Kemarin
                        </button>
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'last7' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('last7')">
                            7 Hari Terakhir
                        </button>
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'last30' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('last30')">
                            30 Hari Terakhir
                        </button>
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'this_month' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('this_month')">
                            Bulan Ini
                        </button>
                        <button type="button" class="btn cbt-preset-btn rounded-pill px-2.5 py-1 <?php echo ($rangepreset === 'all' ? 'btn-primary text-white shadow-xs' : 'btn-light text-secondary border-0'); ?>" onclick="cbtSetDatePreset('all')">
                            Semua Riwayat
                        </button>
                    </div>
                </div>
                <input type="hidden" name="preset" id="cbt-range-preset" value="<?php echo s($rangepreset); ?>">
            </div>

            <!-- Active Range Indicator Summary -->
            <div class="d-flex align-items-center justify-content-between pt-2 mt-2 border-top flex-wrap gap-2 small">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fw-semibold">
                        📅 Periode: <strong id="cbt-summary-range"><?php echo s($metrics['range_label']); ?></strong>
                    </span>
                    <span class="badge bg-warning bg-opacity-15 text-dark border border-warning border-opacity-50 rounded-pill px-3 py-1 fw-semibold">
                        🚨 Total Pelanggaran dalam Rentang: <strong id="cbt-summary-violations"><?php echo $totalviolations; ?></strong>
                    </span>
                    <?php if (!empty($search)): ?>
                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-3 py-1 fw-semibold">
                        Filter Siswa: "<strong><?php echo s($search); ?></strong>"
                    </span>
                    <?php endif; ?>
                </div>
                <span class="text-muted">
                    Ditemukan <strong id="cbt-summary-sessions"><?php echo count($reviewlist); ?></strong> sesi peserta &bull; <strong id="cbt-summary-events"><?php echo count($livefeed); ?></strong> aktivitas live
                </span>
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
                            🎉 Belum ada pelanggaran terdeteksi pada filter ini.
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
                        <span id="cbt-roster-title">
                            <?php echo ($filterscope === 'violations_only' ? 'Peserta Perlu Peninjauan (Melanggar)' : 'Daftar Semua Peserta Ujian'); ?>
                        </span>
                    </div>
                    <span class="badge bg-primary rounded-pill" id="cbt-candidate-count"><?php echo count($reviewlist); ?> sesi</span>
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
                                        <div style="font-size: 28px; margin-bottom: 6px;">🎉</div>
                                        <strong>Tidak ada sesi yang memerlukan tindakan.</strong><br>
                                        <span class="small">Peserta dengan 0 pelanggaran otomatis tervalidasi dan nilainya masuk langsung ke Gradebook.</span>
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
                                            <?php elseif ($item['status'] === 'not_started'): ?>
                                                <span class="badge bg-secondary text-white">Belum Mengerjakan</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary text-white">Sedang Mengerjakan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small fw-bold text-dark"><?php echo s($item['activity_name']); ?></div>
                                            <div class="text-muted small"><?php echo s($item['course_name']); ?></div>
                                            <?php if ($item['status'] === 'invalidated'): ?>
                                                <div class="mt-1">
                                                    <span class="badge bg-danger text-white">🚫 NILAI DIBATALKAN</span>
                                                    <div class="text-danger small fw-bold">Skor: 0.00 (Diskualifikasi)</div>
                                                </div>
                                            <?php elseif ($item['score'] !== null): ?>
                                                <div class="fw-bold text-success mt-1">Nilai: <?php echo number_format($item['score'], 2); ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small mt-1"><em>Belum dinilai</em></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="cbtViewAudit(<?php echo $item['session_id']; ?>, '<?php echo addslashes(s($item['student_name'])); ?>')">
                                                    Log
                                                </button>
                                                <?php if ($item['status'] === 'invalidated'): ?>
                                                    <button type="button" class="btn btn-warning btn-sm" title="Pulihkan Nilai yang Dibatalkan" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'restore')">
                                                        🔄 Pulihkan
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" title="Beri Kesempatan Ulang / Reset Ujian" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'reset')">
                                                        🔓 Reset
                                                    </button>
                                                <?php else: ?>
                                                    <?php if ($item['status'] === 'under_review' || $item['warning_count'] > 0): ?>
                                                        <button type="button" class="btn btn-success btn-sm" title="Buka Kunci & Reset 0/3" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'reset')">
                                                            🔓 Unlock
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-danger btn-sm" title="Batalkan Nilai / Diskualifikasi Peserta" onclick="cbtResolve(<?php echo $item['session_id']; ?>, 'invalidated')">
                                                        🚫 Batalkan Nilai
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

// Quick Preset Date Selector
function cbtSetDatePreset(preset) {
    var dateInput = document.getElementById('cbt-filter-date');
    var datetoInput = document.getElementById('cbt-filter-dateto');
    var presetInput = document.getElementById('cbt-range-preset');

    function fmt(d) {
        var month = '' + (d.getMonth() + 1);
        var day = '' + d.getDate();
        var year = d.getFullYear();
        if (month.length < 2) month = '0' + month;
        if (day.length < 2) day = '0' + day;
        return [year, month, day].join('-');
    }

    var now = new Date();

    if (preset === 'today') {
        var str = fmt(now);
        dateInput.value = str;
        datetoInput.value = str;
    } else if (preset === 'yesterday') {
        var yest = new Date(now.getTime() - 86400000);
        var str = fmt(yest);
        dateInput.value = str;
        datetoInput.value = str;
    } else if (preset === 'last7') {
        var past = new Date(now.getTime() - (6 * 86400000));
        dateInput.value = fmt(past);
        datetoInput.value = fmt(now);
    } else if (preset === 'last30') {
        var past = new Date(now.getTime() - (29 * 86400000));
        dateInput.value = fmt(past);
        datetoInput.value = fmt(now);
    } else if (preset === 'this_month') {
        var first = new Date(now.getFullYear(), now.getMonth(), 1);
        dateInput.value = fmt(first);
        datetoInput.value = fmt(now);
    } else if (preset === 'all') {
        dateInput.value = 'all';
        datetoInput.value = 'all';
    }

    if (presetInput) {
        presetInput.value = preset;
    }

    document.getElementById('cbt-filter-form').submit();
}

// Dynamic Activity dropdown when course changes
document.getElementById('cbt-filter-course').addEventListener('change', function() {
    var cid = parseInt(this.value, 10);
    var actSelect = document.getElementById('cbt-filter-activity');
    actSelect.innerHTML = '<option value="0">Memuat ujian...</option>';

    if (cid <= 0) {
        actSelect.innerHTML = '<option value="0">Semua Ujian</option>';
        fetchLiveData(true);
        return;
    }

    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php?action=get_activities&courseid=' + cid + '&sesskey=<?php echo sesskey(); ?>')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        actSelect.innerHTML = '<option value="0">Semua Ujian</option>';
        if (data.success && data.activities) {
            data.activities.forEach(function(a) {
                var opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name;
                actSelect.appendChild(opt);
            });
        }
        fetchLiveData(true);
    })
    .catch(function() {
        actSelect.innerHTML = '<option value="0">Semua Ujian</option>';
        fetchLiveData(true);
    });
});

// Trigger live update on filter controls change
['cbt-filter-activity', 'cbt-filter-scope', 'cbt-filter-date', 'cbt-filter-dateto'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
        el.addEventListener('change', function() {
            fetchLiveData(true);
        });
    }
});

var searchTimeout = null;
document.getElementById('cbt-filter-search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        fetchLiveData(true);
    }, 400);
});

// Main Live Data Fetcher
function fetchLiveData(resetFeed) {
    var cid = document.getElementById('cbt-filter-course').value;
    var aid = document.getElementById('cbt-filter-activity').value;
    var scope = document.getElementById('cbt-filter-scope').value;
    var search = document.getElementById('cbt-filter-search').value;
    var date = document.getElementById('cbt-filter-date').value;
    var datetoEl = document.getElementById('cbt-filter-dateto');
    var dateto = datetoEl ? datetoEl.value : '';

    var sinceParam = resetFeed ? 0 : cbtLatestTimestamp;

    var url = '<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php?action=feed' +
              '&since=' + sinceParam +
              '&courseid=' + encodeURIComponent(cid) +
              '&activityid=' + encodeURIComponent(aid) +
              '&filterscope=' + encodeURIComponent(scope) +
              '&search=' + encodeURIComponent(search) +
              '&date=' + encodeURIComponent(date) +
              '&dateto=' + encodeURIComponent(dateto) +
              '&sesskey=<?php echo sesskey(); ?>';

    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (!data.success) return;

        // Update stats (Aggregated over selected range)
        if (data.active_count !== undefined) {
            document.getElementById('cbt-stat-active').innerText = data.active_count;
        }
        if (data.review_count !== undefined) {
            document.getElementById('cbt-stat-review').innerText = data.review_count;
        }
        if (data.clean_count !== undefined) {
            document.getElementById('cbt-stat-completed').innerText = data.clean_count;
        }
        if (data.violations_count !== undefined) {
            document.getElementById('cbt-stat-violations').innerText = data.violations_count;
            var summaryViolations = document.getElementById('cbt-summary-violations');
            if (summaryViolations) summaryViolations.innerText = data.violations_count;
        }
        if (data.range_label !== undefined) {
            var activeBadge = document.getElementById('cbt-active-range-badge');
            if (activeBadge) activeBadge.innerText = '📅 ' + data.range_label;
            var summaryRange = document.getElementById('cbt-summary-range');
            if (summaryRange) summaryRange.innerText = data.range_label;
        }

        // Update Live Infraction Feed
        var listEl = document.getElementById('cbt-live-feed-list');
        if (resetFeed) {
            listEl.innerHTML = '';
            if (!data.feed || data.feed.length === 0) {
                listEl.innerHTML = '<div class="p-4 text-center text-muted small" id="cbt-empty-feed-msg">🎉 Belum ada pelanggaran terdeteksi pada filter ini.</div>';
            }
        }

        if (data.feed && data.feed.length > 0) {
            if (!resetFeed) playChime();
            var emptyEl = document.getElementById('cbt-empty-feed-msg');
            if (emptyEl) emptyEl.style.display = 'none';

            data.feed.forEach(function(ev) {
                var item = document.createElement('div');
                item.className = 'cbt-feed-item';
                item.style.background = '#FEF2F2';
                item.innerHTML = '<span style="font-size: 20px;">🚨</span>' +
                                 '<div class="w-100">' +
                                 '<div class="d-flex justify-content-between align-items-center mb-1">' +
                                 '<span class="fw-bold" style="font-size: 0.88rem; color: #0F172A;">' + escapeHtml(ev.student_name) + '</span>' +
                                 '<span class="text-muted" style="font-size: 0.75rem;">' + escapeHtml(ev.time_formatted) + '</span>' +
                                 '</div>' +
                                 '<div class="mb-1"><span class="' + ev.badge_class + '" style="font-size: 0.75rem;">' + escapeHtml(ev.label) + '</span></div>' +
                                 '<div class="text-muted" style="font-size: 0.78rem;">' + escapeHtml(ev.course) + ' &bull; ' + escapeHtml(ev.metadata) + '</div>' +
                                 '</div>';
                listEl.insertBefore(item, listEl.firstChild);
            });

            document.getElementById('cbt-feed-count').innerText = listEl.querySelectorAll('.cbt-feed-item').length + ' event';
            var summaryEvents = document.getElementById('cbt-summary-events');
            if (summaryEvents) summaryEvents.innerText = listEl.querySelectorAll('.cbt-feed-item').length;
        }

        if (data.server_time) {
            cbtLatestTimestamp = data.server_time;
        }

        // Update Candidate Roster Table
        if (data.candidates) {
            renderCandidatesTable(data.candidates);
            var summarySessions = document.getElementById('cbt-summary-sessions');
            if (summarySessions) summarySessions.innerText = data.candidates.length;
        }
    })
    .catch(function(e){});
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function renderCandidatesTable(list) {
    var tbody = document.getElementById('cbt-candidates-body');
    document.getElementById('cbt-candidate-count').innerText = list.length + ' sesi';

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">' +
                          '<div style="font-size: 28px; margin-bottom: 6px;">🎉</div>' +
                          '<strong>Tidak ada sesi yang memerlukan tindakan.</strong><br>' +
                          '<span class="small">Peserta dengan 0 pelanggaran otomatis tervalidasi dan nilainya masuk langsung ke Gradebook.</span>' +
                          '</td></tr>';
        return;
    }

    var html = '';
    list.forEach(function(item) {
        var wcount = parseInt(item.warning_count, 10);
        var badgeClass = 'badge-strike-' + Math.min(3, wcount);

        var statusBadge = '';
        if (item.status === 'under_review') {
            statusBadge = '<span class="badge bg-danger text-white">🔒 TERKUNCI</span>';
        } else if (item.status === 'completed') {
            statusBadge = '<span class="badge bg-success text-white">✓ Selesai</span>';
        } else if (item.status === 'approved') {
            statusBadge = '<span class="badge bg-info text-white">✓ Disetujui</span>';
        } else if (item.status === 'invalidated') {
            statusBadge = '<span class="badge bg-dark text-white">✕ Dibatalkan</span>';
        } else if (item.status === 'not_started') {
            statusBadge = '<span class="badge bg-secondary text-white">Belum Mengerjakan</span>';
        } else {
            statusBadge = '<span class="badge bg-primary text-white">Sedang Mengerjakan</span>';
        }

        var scoreHtml = '';
        if (item.status === 'invalidated') {
            scoreHtml = '<div class="mt-1"><span class="badge bg-danger text-white">🚫 NILAI DIBATALKAN</span><div class="text-danger small fw-bold">Skor: 0.00 (Diskualifikasi)</div></div>';
        } else if (item.score !== null) {
            scoreHtml = '<div class="fw-bold text-success mt-1">Nilai: ' + parseFloat(item.score).toFixed(2) + '</div>';
        } else {
            scoreHtml = '<div class="text-muted small mt-1"><em>Belum dinilai</em></div>';
        }

        var actions = '<div class="btn-group btn-group-sm">' +
                      '<button type="button" class="btn btn-outline-primary btn-sm" onclick="cbtViewAudit(' + item.session_id + ', \'' + escapeHtml(item.student_name).replace(/'/g, "\\'") + '\')">Log</button>';

        if (item.status === 'invalidated') {
            actions += '<button type="button" class="btn btn-warning btn-sm" title="Pulihkan Nilai yang Dibatalkan" onclick="cbtResolve(' + item.session_id + ', \'restore\')">🔄 Pulihkan</button>';
            actions += '<button type="button" class="btn btn-outline-secondary btn-sm" title="Beri Kesempatan Ulang / Reset Ujian" onclick="cbtResolve(' + item.session_id + ', \'reset\')">🔓 Reset</button>';
        } else {
            if (item.status === 'under_review' || wcount > 0) {
                actions += '<button type="button" class="btn btn-success btn-sm" title="Buka Kunci & Reset 0/3" onclick="cbtResolve(' + item.session_id + ', \'reset\')">🔓 Unlock</button>';
            }
            actions += '<button type="button" class="btn btn-danger btn-sm" title="Batalkan Nilai / Diskualifikasi Peserta" onclick="cbtResolve(' + item.session_id + ', \'invalidated\')">🚫 Batalkan Nilai</button>';
        }
        actions += '</div>';

        html += '<tr id="session-row-' + item.session_id + '">' +
                '<td>' +
                '<div class="fw-bold text-dark">' + escapeHtml(item.student_name) + '</div>' +
                '<div class="text-muted small">' + escapeHtml(item.student_email) + '</div>' +
                '<span class="badge bg-light text-secondary border mt-1" style="font-size: 0.7rem;">' + escapeHtml(item.role_label) + '</span>' +
                '</td>' +
                '<td>' +
                '<div class="mb-1"><span class="' + badgeClass + '">' + wcount + ' / 3 Peringatan</span></div>' +
                statusBadge +
                '</td>' +
                '<td>' +
                '<div class="small fw-bold text-dark">' + escapeHtml(item.activity_name) + '</div>' +
                '<div class="text-muted small">' + escapeHtml(item.course_name) + '</div>' +
                scoreHtml +
                '</td>' +
                '<td class="text-end">' + actions + '</td>' +
                '</tr>';
    });
    tbody.innerHTML = html;
}

// Polling live feed and active candidates every 2.5 seconds
setInterval(function() {
    fetchLiveData(false);
}, 2500);

// Resolve candidate session
function cbtResolve(sessionId, decision) {
    var promptMsg = '';
    if (decision === 'reset') {
        promptMsg = 'Beri kesempatan ulang & buka kunci (reset peringatan 0/3 dan hapus nilai sementara)?';
    } else if (decision === 'invalidated') {
        promptMsg = 'PERINGATAN: BATALKAN NILAI peserta ini?\n\nNilai akan dianulir menjadi 0.00 (didiskualifikasi) di Gradebook, dan akses ujian siswa akan dibekukan seketika.';
    } else if (decision === 'restore') {
        promptMsg = 'PULIHKAN NILAI:\n\nBatalkan status diskualifikasi dan pulihkan kembali nilai ujian peserta ini ke Gradebook?';
    } else {
        promptMsg = 'Setujui dan proses nilai sesi ini?';
    }

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
            fetchLiveData(true);
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
                        '<div class="fw-bold small">' + escapeHtml(ev.label) + '</div>' +
                        '<div class="text-muted" style="font-size:0.75rem;">' + escapeHtml(ev.metadata) + '</div>' +
                        '</div>' +
                        '<span class="badge bg-light text-secondary border">' + escapeHtml(ev.time) + '</span>' +
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
