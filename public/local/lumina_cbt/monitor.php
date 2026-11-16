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

// Defensive sanitization of date parameters (prevent "dd/mm/yyyy" or invalid strings from breaking DateTime)
if ($selecteddate === 'dd/mm/yyyy' || empty($selecteddate)) {
    if (!empty($search)) {
        $selecteddate = 'all';
    } else {
        $selecteddate = date('Y-m-d');
    }
} else if ($selecteddate !== 'all' && strtotime($selecteddate) === false) {
    $selecteddate = date('Y-m-d');
}

if (!empty($selecteddateto) && $selecteddateto !== 'all') {
    if ($selecteddateto === 'dd/mm/yyyy' || strtotime($selecteddateto) === false) {
        $selecteddateto = '';
    }
}

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
                                            <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                                <span class="fw-bold text-dark"><?php echo s($item['student_name']); ?></span>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success rounded-pill px-2 py-0.5 cbt-cam-badge" id="cbt-cam-badge-<?php echo $item['session_id']; ?>" style="font-size: 0.68rem; display: none;">
                                                    <span class="cbt-live-pulse" style="width: 5px; height: 5px; margin-right: 2px;"></span>LIVE CAM
                                                </span>
                                            </div>
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
                                            <div class="d-inline-flex align-items-center gap-1 flex-wrap justify-content-end">
                                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-2.5 py-1 fw-semibold shadow-xs d-inline-flex align-items-center gap-1"
                                                        title="Pantau Kamera Siswa secara Senyap (Stealth Videocall)"
                                                        onclick="cbtOpenLiveCam(<?php echo $item['session_id']; ?>, '<?php echo addslashes(s($item['student_name'])); ?>', '<?php echo addslashes(s($item['student_email'])); ?>', '<?php echo addslashes(s($item['activity_name'])); ?>', '<?php echo addslashes(s($item['course_name'])); ?>', <?php echo (int)$item['warning_count']; ?>, '<?php echo $item['status']; ?>')">
                                                    <span>📹</span>
                                                    <span>Pantau Kamera</span>
                                                </button>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1" onclick="cbtViewAudit(<?php echo $item['session_id']; ?>, '<?php echo addslashes(s($item['student_name'])); ?>')">
                                                        <span>📋</span><span>Log &amp; Foto</span>
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

<!-- Audit Trail & Photo Evidence Modal -->
<div class="modal fade" id="cbtAuditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white px-4 py-3 border-0">
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size: 22px;">📋</span>
                    <div>
                        <h5 class="modal-title fw-bold mb-0 text-white" id="cbtAuditModalTitle">Riwayat Audit &amp; Foto Bukti</h5>
                        <div class="small text-muted" id="cbtAuditModalSub">Catatan pelanggaran dan tangkapan foto kamera siswa</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="cbtHideModal('cbtAuditModal')"></button>
            </div>

            <!-- Tab Switcher Navigation -->
            <div class="px-4 pt-3 border-bottom bg-light">
                <ul class="nav nav-pills gap-2 mb-0 pb-3" id="cbtAuditTabNav">
                    <li class="nav-item">
                        <button class="nav-link active rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5 small" id="cbtAuditTabBtnEvents" type="button" onclick="cbtSwitchAuditTab('events')">
                            <span>📋</span>
                            <span>Riwayat Pelanggaran &amp; Log</span>
                            <span class="badge bg-secondary rounded-pill" id="cbtAuditEventsCount">0</span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5 small" id="cbtAuditTabBtnPhotos" type="button" onclick="cbtSwitchAuditTab('photos')">
                            <span>📸</span>
                            <span>Galeri Foto Bukti Kamera</span>
                            <span class="badge bg-primary rounded-pill" id="cbtAuditPhotosCount">0</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="modal-body p-4" style="max-height: 72vh; overflow-y: auto;">
                <!-- Tab Pane 1: Events Log List -->
                <div id="cbtAuditEventsContainer">
                    <div class="text-center py-4 text-muted">Memuat data log audit...</div>
                </div>

                <!-- Tab Pane 2: Photo Evidence Gallery -->
                <div id="cbtAuditPhotosContainer" style="display: none;">
                    <div class="text-center py-4 text-muted">Memuat galeri foto...</div>
                </div>
            </div>

            <div class="modal-footer bg-light px-4 py-2.5 border-top d-flex justify-content-between align-items-center">
                <span class="text-muted small">Semua foto bukti diabadikan secara otomatis dan manual dengan stempel waktu resmi.</span>
                <button type="button" class="btn btn-secondary rounded-pill px-4 btn-sm" onclick="cbtHideModal('cbtAuditModal')">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Photo Evidence Lightbox Modal (Zoom & Fullscreen Inspection) -->
<div class="modal fade" id="cbtPhotoLightboxModal" tabindex="-1" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-2xl rounded-4 overflow-hidden" style="background: #0B132B; color: #fff;">
            <div class="modal-header border-0 px-4 py-3" style="background: #0F172A; border-bottom: 1px solid rgba(255,255,255,0.1) !important;">
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size: 24px;">📸</span>
                    <div>
                        <h5 class="modal-title fw-bold mb-0 text-white" id="cbtLightboxTitle">Foto Bukti Pengawas</h5>
                        <div class="small text-muted" id="cbtLightboxSubtitle">Waktu Pengambilan</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="cbtHideModal('cbtPhotoLightboxModal')"></button>
            </div>
            <div class="modal-body p-3 text-center" style="background: #020617;">
                <img id="cbtLightboxImg" src="" alt="Foto Bukti" class="img-fluid rounded-3 shadow-lg" style="max-height: 65vh; width: auto; object-fit: contain;">
            </div>
            <div class="modal-footer border-0 px-4 py-3 d-flex justify-content-between align-items-center" style="background: #0F172A; border-top: 1px solid rgba(255,255,255,0.08) !important;">
                <div class="small text-muted" id="cbtLightboxMeta">-</div>
                <div class="d-flex gap-2">
                    <a id="cbtLightboxDownload" href="#" class="btn btn-primary rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1" target="_blank" download>
                        <span>⬇️</span> Unduh Foto Bukti
                    </a>
                    <button type="button" class="btn btn-outline-light rounded-pill px-3 py-1.5" onclick="cbtHideModal('cbtPhotoLightboxModal')">Tutup</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stealth Live Camera Videocall Proctoring Modal -->
<div class="modal fade" id="cbtLiveCamModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-2xl rounded-4 overflow-hidden" style="background: #0B132B; color: #F8FAFC;">
            <!-- Modal Top Bar -->
            <div class="modal-header border-0 px-4 py-3" style="background: #0F172A; border-bottom: 1px solid rgba(255,255,255,0.08) !important;">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span style="font-size: 24px;">📹</span>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold mb-0 text-white" id="cbtCamModalStudentName">Memuat Kamera Siswa...</h5>
                            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-30 rounded-pill px-2.5 py-0.5 small d-inline-flex align-items-center gap-1">
                                <span class="cbt-live-pulse" style="width: 7px; height: 7px;"></span>
                                <span>Mode Senyap (Stealth)</span>
                            </span>
                        </div>
                        <div class="small text-muted" id="cbtCamModalSub">
                            Siswa sedang dipantau tanpa notifikasi atau perubahan di layar ujian mereka.
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="cbtCycleCandidate(-1)" title="Peserta Sebelumnya">
                        &larr; Prev
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="cbtCycleCandidate(1)" title="Peserta Berikutnya">
                        Next &rarr;
                    </button>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close" onclick="cbtCloseLiveCam()"></button>
                </div>
            </div>

            <!-- Modal Body: High-tech CCTV Viewport & Side Control Deck -->
            <div class="modal-body p-0">
                <div class="row g-0">
                    <!-- Left Column: Video Screen with Security OSD -->
                    <div class="col-lg-8 position-relative d-flex align-items-center justify-content-center p-3" style="background: #020617; min-height: 480px;">
                        <!-- OSD: Top Left -->
                        <div class="position-absolute top-0 start-0 m-3 d-flex align-items-center gap-2" style="z-index: 10;">
                            <span class="badge bg-danger rounded-pill px-2.5 py-1 fw-bold tracking-wider d-inline-flex align-items-center gap-1 shadow-sm" id="cbtCamLiveBadge">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: cbtPulse 1s infinite;"></span>
                                LIVE STREAM
                            </span>
                            <span class="badge bg-dark bg-opacity-75 text-light border border-secondary rounded-pill px-2.5 py-1 small font-monospace" id="cbtCamFps">
                                30 FPS • 1.0s LATENCY
                            </span>
                        </div>

                        <!-- OSD: Top Right Security Watermark -->
                        <div class="position-absolute top-0 end-0 m-3 text-end d-none d-sm-block" style="z-index: 10;">
                            <div class="small fw-bold text-white text-opacity-75 tracking-wider font-monospace" id="cbtCamOsdId">PROCTOR-CAM</div>
                            <div class="text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em;">LUMINA CBT AUDIT CCTV</div>
                        </div>

                        <!-- The Live Frame Video Element -->
                        <div class="position-relative w-100 h-100 d-flex align-items-center justify-content-center" style="max-height: 520px;">
                            <img id="cbtCamVideoFeed" src="" alt="Live Video Feed" class="img-fluid rounded-3 shadow-lg" style="max-height: 480px; width: auto; object-fit: contain; display: none; border: 1px solid rgba(255,255,255,0.1);">
                            
                            <!-- Standby / Offline Placeholder -->
                            <div id="cbtCamPlaceholder" class="text-center p-5">
                                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <h6 class="fw-bold text-light mb-1" id="cbtCamPlaceholderTitle">Menghubungkan ke Kamera Peserta...</h6>
                                <p class="small text-muted mb-0" id="cbtCamPlaceholderText">
                                    Menunggu transmisi frame video real-time dari browser siswa...
                                </p>
                            </div>
                        </div>

                        <!-- OSD: Bottom Live Clock & Hash -->
                        <div class="position-absolute bottom-0 start-0 end-0 p-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(to top, rgba(2,6,23,0.95), transparent); z-index: 10;">
                            <div class="font-monospace small fw-bold" id="cbtCamLiveTime" style="color: #34D399;">
                                2026-11-16 00:00:00 WIB
                            </div>
                            <div class="text-muted small d-none d-sm-block font-monospace" style="font-size: 0.72rem;">
                                STEALTH END-TO-END STREAM
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Candidate Dossier & Action Deck -->
                    <div class="col-lg-4 p-4 d-flex flex-column justify-content-between border-start" style="background: #0F172A; border-color: rgba(255,255,255,0.08) !important;">
                        <div>
                            <!-- Student Info Card -->
                            <div class="p-3 rounded-3 mb-3" style="background: #1E293B; border: 1px solid rgba(255,255,255,0.08);">
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 44px; height: 44px; background: #4338CA; font-size: 1.1rem;" id="cbtCamStudentAvatar">
                                        S
                                    </div>
                                    <div style="min-width: 0;">
                                        <div class="fw-bold text-white text-truncate" id="cbtCamStudentFullName">Nama Siswa</div>
                                        <div class="text-muted small text-truncate" id="cbtCamStudentEmail">email@domain.com</div>
                                    </div>
                                </div>
                                <div class="small pt-2 border-top border-secondary border-opacity-25 text-light">
                                    <div class="text-truncate"><strong>Ujian:</strong> <span id="cbtCamActivityName">-</span></div>
                                    <div class="text-truncate"><strong>Kelas:</strong> <span id="cbtCamCourseName">-</span></div>
                                </div>
                            </div>

                            <!-- Integrity Status & Strike Gauge -->
                            <div class="p-3 rounded-3 mb-3" style="background: #1E293B; border: 1px solid rgba(255,255,255,0.08);">
                                <div class="small text-muted mb-1 fw-bold text-uppercase tracking-wider">Status Integritas Siswa</div>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-bold text-white" id="cbtCamStrikeText">0 / 3 Peringatan</span>
                                    <span class="badge bg-success" id="cbtCamStatusBadge">✓ Sesi Aman</span>
                                </div>
                                <div class="progress" style="height: 6px; background: #334155;">
                                    <div class="progress-bar bg-success" id="cbtCamProgressBar" role="progressbar" style="width: 0%"></div>
                                </div>
                            </div>

                            <!-- Stealth Notice Alert -->
                            <div class="p-3 rounded-3 mb-3 text-start small" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); color: #A7F3D0;">
                                <div class="fw-bold mb-1 d-flex align-items-center gap-1">
                                    <span>🛡️</span>
                                    <span>Pengawasan Senyap Aktif</span>
                                </div>
                                <span>Siswa tidak menerima notifikasi pop-up atau perubahan tampilan apa pun saat Anda memantau video ini.</span>
                            </div>
                        </div>

                        <!-- Quick Proctor Action Buttons -->
                        <div class="pt-3 border-top border-secondary border-opacity-25 d-grid gap-2">
                            <button type="button" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2 fw-semibold rounded-pill shadow-xs" onclick="cbtCaptureEvidenceCurrent()">
                                <span>📸</span>
                                <span>Ambil Bukti Foto (Snapshot)</span>
                            </button>

                            <button type="button" class="btn btn-outline-info d-flex align-items-center justify-content-center gap-2 py-1.5 fw-semibold rounded-pill small" onclick="cbtViewCurrentAuditAndPhotos('photos')">
                                <span>📂</span>
                                <span>Buka Galeri Foto Bukti Sesi Ini</span>
                            </button>

                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-success flex-fill rounded-pill py-1.5 small fw-semibold" onclick="cbtCamResolve('reset')" title="Reset Peringatan 0/3">
                                    🔓 Reset / Unlock
                                </button>
                                <button type="button" class="btn btn-outline-danger flex-fill rounded-pill py-1.5 small fw-semibold" onclick="cbtCamResolve('invalidated')" title="Diskualifikasi & Batalkan Nilai">
                                    🚫 Batalkan Nilai
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
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

        // Instant camera status indicator sync (zero extra HTTP requests)
        if (data.active_cameras) {
            document.querySelectorAll('.cbt-cam-badge').forEach(function(el){ el.style.display = 'none'; });
            for (var sid in data.active_cameras) {
                var el = document.getElementById('cbt-cam-badge-' + sid);
                if (el) el.style.display = 'inline-flex';
            }
        }
    })
    .catch(function(e){});
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function renderCandidatesTable(list) {
    currentCandidatesList = list;
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

        var safeName = escapeHtml(item.student_name).replace(/'/g, "\\'");
        var safeEmail = escapeHtml(item.student_email).replace(/'/g, "\\'");
        var safeAct = escapeHtml(item.activity_name).replace(/'/g, "\\'");
        var safeCourse = escapeHtml(item.course_name).replace(/'/g, "\\'");

        var camBtn = '<button type="button" class="btn btn-primary btn-sm rounded-pill px-2.5 py-1 fw-semibold shadow-xs d-inline-flex align-items-center gap-1" ' +
                     'title="Pantau Kamera Siswa secara Senyap (Stealth Videocall)" ' +
                     'onclick="cbtOpenLiveCam(' + item.session_id + ', \'' + safeName + '\', \'' + safeEmail + '\', \'' + safeAct + '\', \'' + safeCourse + '\', ' + wcount + ', \'' + item.status + '\')">' +
                     '<span>📹</span><span>Pantau Kamera</span></button>';

        var actions = '<div class="d-inline-flex align-items-center gap-1 flex-wrap justify-content-end">' +
                      camBtn +
                      '<div class="btn-group btn-group-sm">' +
                      '<button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1" onclick="cbtViewAudit(' + item.session_id + ', \'' + safeName + '\')"><span>📋</span><span>Log &amp; Foto</span></button>';

        if (item.status === 'invalidated') {
            actions += '<button type="button" class="btn btn-warning btn-sm" title="Pulihkan Nilai yang Dibatalkan" onclick="cbtResolve(' + item.session_id + ', \'restore\')">🔄 Pulihkan</button>';
            actions += '<button type="button" class="btn btn-outline-secondary btn-sm" title="Beri Kesempatan Ulang / Reset Ujian" onclick="cbtResolve(' + item.session_id + ', \'reset\')">🔓 Reset</button>';
        } else {
            if (item.status === 'under_review' || wcount > 0) {
                actions += '<button type="button" class="btn btn-success btn-sm" title="Buka Kunci & Reset 0/3" onclick="cbtResolve(' + item.session_id + ', \'reset\')">🔓 Unlock</button>';
            }
            actions += '<button type="button" class="btn btn-danger btn-sm" title="Batalkan Nilai / Diskualifikasi Peserta" onclick="cbtResolve(' + item.session_id + ', \'invalidated\')">🚫 Batalkan Nilai</button>';
        }
        actions += '</div></div>';

        html += '<tr id="session-row-' + item.session_id + '">' +
                '<td>' +
                '<div class="d-flex align-items-center gap-1.5 flex-wrap">' +
                '<span class="fw-bold text-dark">' + escapeHtml(item.student_name) + '</span>' +
                '<span class="badge bg-success bg-opacity-10 text-success border border-success rounded-pill px-2 py-0.5 cbt-cam-badge" id="cbt-cam-badge-' + item.session_id + '" style="font-size: 0.68rem; display: none;">' +
                '<span class="cbt-live-pulse" style="width: 5px; height: 5px; margin-right: 2px;"></span>LIVE CAM</span>' +
                '</div>' +
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

// Polling live feed and active candidates every 2 seconds
setInterval(function() {
    fetchLiveData(false);
}, 2000);

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

// Universal Modal Utility (Works with Bootstrap 5, jQuery, or Vanilla Fallback)
function cbtShowModal(modalEl) {
    if (typeof modalEl === 'string') modalEl = document.getElementById(modalEl);
    if (!modalEl) return;

    if (window.bootstrap && window.bootstrap.Modal) {
        try {
            var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            inst.show();
            return;
        } catch(e) {}
    }

    if (window.jQuery && typeof jQuery(modalEl).modal === 'function') {
        try {
            jQuery(modalEl).modal('show');
            return;
        } catch(e) {}
    }

    // Direct DOM fallback (works 100% on any theme)
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
    modalEl.setAttribute('aria-modal', 'true');

    var backdrop = document.getElementById('cbt-custom-modal-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'cbt-custom-modal-backdrop';
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);z-index:1050;';
        backdrop.onclick = function() {
            cbtHideModal(modalEl);
            if (modalEl.id === 'cbtLiveCamModal') cbtCloseLiveCam();
        };
        document.body.appendChild(backdrop);
    } else {
        backdrop.style.display = 'block';
    }
    document.body.classList.add('modal-open');
}

function cbtHideModal(modalEl) {
    if (typeof modalEl === 'string') modalEl = document.getElementById(modalEl);
    if (!modalEl) return;

    if (window.bootstrap && window.bootstrap.Modal) {
        try {
            var inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
        } catch(e) {}
    }

    if (window.jQuery && typeof jQuery(modalEl).modal === 'function') {
        try {
            jQuery(modalEl).modal('hide');
        } catch(e) {}
    }

    modalEl.classList.remove('show');
    modalEl.style.display = 'none';
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.removeAttribute('aria-modal');

    var backdrop = document.getElementById('cbt-custom-modal-backdrop');
    if (backdrop) {
        backdrop.style.display = 'none';
    }
    document.body.classList.remove('modal-open');
}

// -------------------------------------------------------------
// View audit modal & Photo Evidence Gallery
// -------------------------------------------------------------
var cbtActiveAuditSessionId = null;
var cbtActiveAuditStudentName = '';
var cbtActiveAuditTab = 'events';

function cbtSwitchAuditTab(tab) {
    cbtActiveAuditTab = tab;
    var btnEvents = document.getElementById('cbtAuditTabBtnEvents');
    var btnPhotos = document.getElementById('cbtAuditTabBtnPhotos');
    var paneEvents = document.getElementById('cbtAuditEventsContainer');
    var panePhotos = document.getElementById('cbtAuditPhotosContainer');

    if (tab === 'photos') {
        if (btnPhotos) btnPhotos.classList.add('active');
        if (btnEvents) btnEvents.classList.remove('active');
        if (panePhotos) panePhotos.style.display = 'block';
        if (paneEvents) paneEvents.style.display = 'none';
    } else {
        if (btnEvents) btnEvents.classList.add('active');
        if (btnPhotos) btnPhotos.classList.remove('active');
        if (paneEvents) paneEvents.style.display = 'block';
        if (panePhotos) panePhotos.style.display = 'none';
    }
}

function cbtOpenPhotoLightbox(imgUrl, studentName, timeStr, label, downloadUrl) {
    var imgEl = document.getElementById('cbtLightboxImg');
    if (imgEl) imgEl.src = imgUrl;
    var titleEl = document.getElementById('cbtLightboxTitle');
    if (titleEl) titleEl.innerText = (label || 'Foto Bukti Siswa') + ' - ' + (studentName || 'Peserta');
    var subEl = document.getElementById('cbtLightboxSubtitle');
    if (subEl) subEl.innerText = 'Waktu Rekam: ' + (timeStr || '-');
    var metaEl = document.getElementById('cbtLightboxMeta');
    if (metaEl) metaEl.innerText = 'Format: JPEG • Terenkripsi di Server CBT Moodle';
    var dlBtn = document.getElementById('cbtLightboxDownload');
    if (dlBtn) dlBtn.href = downloadUrl || imgUrl;
    cbtShowModal('cbtPhotoLightboxModal');
}

function cbtViewCurrentAuditAndPhotos(initialTab) {
    if (!cbtCurrentCamSession) return;
    cbtViewAudit(cbtCurrentCamSession.id, cbtCurrentCamSession.name, initialTab || 'photos');
}

function cbtViewAudit(sessionId, name, initialTab) {
    cbtActiveAuditSessionId = sessionId;
    cbtActiveAuditStudentName = name;

    var titleEl = document.getElementById('cbtAuditModalTitle');
    if (titleEl) titleEl.innerText = 'Riwayat Audit & Foto Bukti: ' + name;
    var subEl = document.getElementById('cbtAuditModalSub');
    if (subEl) subEl.innerText = 'Sesi #' + sessionId + ' • Data audit tersinkronisasi';

    var eventsContainer = document.getElementById('cbtAuditEventsContainer');
    var photosContainer = document.getElementById('cbtAuditPhotosContainer');
    var badgeEvents = document.getElementById('cbtAuditEventsCount');
    var badgePhotos = document.getElementById('cbtAuditPhotosCount');

    if (eventsContainer) {
        eventsContainer.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Memuat data log audit...</div>';
    }
    if (photosContainer) {
        photosContainer.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Memuat galeri foto bukti...</div>';
    }
    if (badgeEvents) badgeEvents.innerText = '0';
    if (badgePhotos) badgePhotos.innerText = '0';

    cbtSwitchAuditTab(initialTab || 'events');
    cbtShowModal('cbtAuditModal');

    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_monitor.php?action=session_events&session_id=' + sessionId + '&sesskey=<?php echo sesskey(); ?>')
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (!data.success) {
            if (eventsContainer) eventsContainer.innerHTML = '<div class="alert alert-danger">Gagal memuat log audit.</div>';
            if (photosContainer) photosContainer.innerHTML = '<div class="alert alert-danger">Gagal memuat foto bukti.</div>';
            return;
        }

        var events = data.events || [];
        var photos = data.photos || [];

        if (badgeEvents) badgeEvents.innerText = events.length;
        if (badgePhotos) badgePhotos.innerText = photos.length;

        // Render Events Tab
        if (eventsContainer) {
            if (events.length > 0) {
                var evHtml = '<div class="list-group list-group-flush gap-2">';
                events.forEach(function(ev) {
                    var photoSnippet = '';
                    if (ev.has_photo && ev.photo_url) {
                        var safePhotoUrl = ev.photo_url.replace(/"/g, '&quot;');
                        var safePhotoDl = (ev.photo_download_url || ev.photo_url).replace(/"/g, '&quot;');
                        var safeName = escapeHtml(name).replace(/'/g, "\\'");
                        var safeLabel = escapeHtml(ev.label).replace(/'/g, "\\'");
                        var safeDate = escapeHtml(ev.date).replace(/'/g, "\\'");

                        photoSnippet = '<div class="mt-2.5 p-2 rounded-3 bg-light border d-flex align-items-center gap-3" style="max-width: 440px;">' +
                            '<img src="' + safePhotoUrl + '" alt="Bukti Foto" class="rounded shadow-xs border" style="width: 72px; height: 50px; object-fit: cover; cursor: pointer;" onclick="cbtOpenPhotoLightbox(\'' + safePhotoUrl + '\', \'' + safeName + '\', \'' + safeDate + '\', \'' + safeLabel + '\', \'' + safePhotoDl + '\')">' +
                            '<div class="flex-grow-1 small">' +
                                '<div class="fw-bold text-dark d-flex align-items-center gap-1"><span>📸</span><span>Tangkapan Kamera Bukti</span></div>' +
                                '<div class="text-muted" style="font-size: 0.72rem;">Terekam pada saat kejadian</div>' +
                                '<div class="mt-1 d-flex gap-2">' +
                                    '<button type="button" class="btn btn-outline-primary btn-xs rounded-pill px-2 py-0.5 fw-semibold" style="font-size:0.75rem;" onclick="cbtOpenPhotoLightbox(\'' + safePhotoUrl + '\', \'' + safeName + '\', \'' + safeDate + '\', \'' + safeLabel + '\', \'' + safePhotoDl + '\')">🔍 Buka Foto</button>' +
                                    '<a href="' + safePhotoDl + '" class="btn btn-outline-secondary btn-xs rounded-pill px-2 py-0.5" style="font-size:0.75rem;" target="_blank" download>⬇️ Unduh</a>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    }

                    evHtml += '<div class="list-group-item px-3 py-3 rounded-3 border mb-2 shadow-xs bg-white">' +
                        '<div class="d-flex justify-content-between align-items-start gap-2">' +
                            '<div>' +
                                '<div class="d-flex align-items-center gap-2 mb-1">' +
                                    '<span class="badge ' + (ev.badge_class || 'bg-secondary') + ' rounded-pill px-2.5 py-1 small">' +
                                        (ev.icon || '⚠️') + ' ' + escapeHtml(ev.label) +
                                    '</span>' +
                                    '<span class="text-muted small">' + escapeHtml(ev.date) + '</span>' +
                                '</div>' +
                                '<div class="text-dark small mt-1">' + escapeHtml(ev.metadata) + '</div>' +
                                photoSnippet +
                            '</div>' +
                            '<span class="badge bg-light text-secondary border font-monospace">' + escapeHtml(ev.time) + '</span>' +
                        '</div>' +
                    '</div>';
                });
                evHtml += '</div>';
                eventsContainer.innerHTML = evHtml;
            } else {
                eventsContainer.innerHTML = '<div class="text-center py-5 text-success"><div style="font-size: 38px;">🎉</div><div class="fw-bold mt-2">Tidak ada riwayat pelanggaran pada sesi ini.</div><div class="text-muted small">Siswa mengerjakan ujian dengan tertib dan mematuhi seluruh protokol integritas.</div></div>';
            }
        }

        // Render Photos Gallery Tab
        if (photosContainer) {
            if (photos.length > 0) {
                var phHtml = '<div class="row g-3">';
                photos.forEach(function(ph, idx) {
                    var safeUrl = ph.url.replace(/"/g, '&quot;');
                    var safeDl = ph.download_url.replace(/"/g, '&quot;');
                    var safeName = escapeHtml(name).replace(/'/g, "\\'");
                    var safeTime = escapeHtml(ph.time_formatted).replace(/'/g, "\\'");
                    var photoLabel = 'Bukti Foto #' + (photos.length - idx);

                    phHtml += '<div class="col-sm-6 col-md-4 col-lg-3">' +
                        '<div class="card h-100 border rounded-3 shadow-xs overflow-hidden">' +
                            '<div class="position-relative bg-dark" style="height: 150px; overflow: hidden; cursor: pointer;" onclick="cbtOpenPhotoLightbox(\'' + safeUrl + '\', \'' + safeName + '\', \'' + safeTime + '\', \'' + photoLabel + '\', \'' + safeDl + '\')">' +
                                '<img src="' + safeUrl + '" alt="Bukti Foto" class="w-100 h-100" style="object-fit: cover; transition: transform 0.2s;" onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
                                '<span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-light rounded-pill px-2 py-0.5 font-monospace" style="font-size: 0.68rem;">' +
                                    escapeHtml(ph.time_short) +
                                '</span>' +
                            '</div>' +
                            '<div class="card-body p-2.5 d-flex flex-column justify-content-between">' +
                                '<div class="mb-2">' +
                                    '<div class="fw-bold text-dark small text-truncate" title="' + photoLabel + '">📸 ' + photoLabel + '</div>' +
                                    '<div class="text-muted" style="font-size: 0.72rem;">' + escapeHtml(ph.time_formatted) + '</div>' +
                                    '<div class="text-muted" style="font-size: 0.7rem;">Ukuran: ' + ph.filesize_kb + ' KB</div>' +
                                '</div>' +
                                '<div class="d-flex gap-1.5">' +
                                    '<button type="button" class="btn btn-primary btn-sm flex-fill rounded-pill py-1 px-2 small fw-semibold" style="font-size:0.75rem;" onclick="cbtOpenPhotoLightbox(\'' + safeUrl + '\', \'' + safeName + '\', \'' + safeTime + '\', \'' + photoLabel + '\', \'' + safeDl + '\')">' +
                                        '🔍 Perbesar' +
                                    '</button>' +
                                    '<a href="' + safeDl + '" class="btn btn-outline-secondary btn-sm rounded-pill py-1 px-2.5 small" title="Unduh Foto Bukti Asli" target="_blank" download>' +
                                        '⬇️' +
                                    '</a>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                phHtml += '</div>';
                photosContainer.innerHTML = phHtml;
            } else {
                photosContainer.innerHTML = '<div class="text-center py-5 text-muted">' +
                    '<div style="font-size: 42px;">📷</div>' +
                    '<div class="fw-bold mt-2 text-dark">Belum Ada Foto Bukti Tersimpan</div>' +
                    '<div class="small text-muted mt-1" style="max-width: 400px; margin: 0 auto;">Foto bukti akan otomatis disimpan ketika siswa melakukan pelanggaran, atau saat pengawas menekan tombol <strong>Ambil Bukti Foto (Snapshot)</strong> di jendela Pantau Kamera.</div>' +
                '</div>';
            }
        }
    })
    .catch(function(err) {
        if (eventsContainer) eventsContainer.innerHTML = '<div class="alert alert-danger">Koneksi terputus saat mengambil data audit.</div>';
        if (photosContainer) photosContainer.innerHTML = '<div class="alert alert-danger">Koneksi terputus saat mengambil galeri foto bukti.</div>';
    });
}

// -------------------------------------------------------------
// Live Stealth Camera Proctoring Module
// -------------------------------------------------------------
var cbtCurrentCamSession = null;
var cbtCamStreaming = false;
var cbtLastFrameTime = 0;
var cbtIsCamFetching = false;
var currentCandidatesList = <?php echo json_encode(array_values($reviewlist)); ?>;

function cbtOpenLiveCam(sessionId, name, email, activity, course, warnings, status) {
    cbtCurrentCamSession = {
        id: sessionId,
        name: name,
        email: email,
        activity: activity,
        course: course,
        warnings: warnings,
        status: status
    };

    document.getElementById('cbtCamModalStudentName').innerText = name;
    document.getElementById('cbtCamStudentFullName').innerText = name;
    document.getElementById('cbtCamStudentEmail').innerText = email;
    document.getElementById('cbtCamActivityName').innerText = activity;
    document.getElementById('cbtCamCourseName').innerText = course;
    document.getElementById('cbtCamStudentAvatar').innerText = (name ? name.charAt(0).toUpperCase() : 'S');
    document.getElementById('cbtCamOsdId').innerText = 'PROCTOR-CAM-' + sessionId;

    // Status gauge
    var strikeText = warnings + ' / 3 Peringatan';
    document.getElementById('cbtCamStrikeText').innerText = strikeText;
    var pbar = document.getElementById('cbtCamProgressBar');
    var sbadge = document.getElementById('cbtCamStatusBadge');

    if (warnings === 0) {
        pbar.className = 'progress-bar bg-success';
        pbar.style.width = '5%';
        sbadge.className = 'badge bg-success';
        sbadge.innerText = '✓ Sesi Bersih';
    } else if (warnings === 1) {
        pbar.className = 'progress-bar bg-warning';
        pbar.style.width = '33%';
        sbadge.className = 'badge bg-warning text-dark';
        sbadge.innerText = '⚠️ Peringatan 1';
    } else if (warnings === 2) {
        pbar.className = 'progress-bar bg-warning';
        pbar.style.width = '66%';
        sbadge.className = 'badge bg-warning text-dark';
        sbadge.innerText = '🚨 Peringatan 2';
    } else {
        pbar.className = 'progress-bar bg-danger';
        pbar.style.width = '100%';
        sbadge.className = 'badge bg-danger';
        sbadge.innerText = '🔒 Ditangguhkan';
    }

    // Reset video screen
    var vImg = document.getElementById('cbtCamVideoFeed');
    var placeholder = document.getElementById('cbtCamPlaceholder');
    vImg.style.display = 'none';
    placeholder.style.display = 'block';
    document.getElementById('cbtCamPlaceholderTitle').innerText = 'Menghubungkan ke Kamera Peserta...';
    document.getElementById('cbtCamPlaceholderText').innerText = 'Menunggu transmisi frame video real-time dari browser siswa...';

    cbtShowModal('cbtLiveCamModal');

    // Launch continuous adaptive low-latency stream loop
    cbtCamStreaming = true;
    cbtLastFrameTime = 0;
    cbtIsCamFetching = false;
    cbtStreamNextFrame();
}

function cbtStreamNextFrame() {
    if (!cbtCamStreaming || !cbtCurrentCamSession || cbtIsCamFetching) return;

    var sid = cbtCurrentCamSession.id;
    cbtIsCamFetching = true;

    var url = '<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_camera.php?action=get_frame&session_id=' + sid + 
              '&since=' + cbtLastFrameTime + 
              '&sesskey=<?php echo sesskey(); ?>';

    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (!cbtCamStreaming || !cbtCurrentCamSession || cbtCurrentCamSession.id !== sid) return;

        var vImg = document.getElementById('cbtCamVideoFeed');
        var placeholder = document.getElementById('cbtCamPlaceholder');
        var fpsEl = document.getElementById('cbtCamFps');
        var liveTimeEl = document.getElementById('cbtCamLiveTime');
        var liveBadge = document.getElementById('cbtCamLiveBadge');

        if (data.success) {
            if (data.unchanged) {
                if (data.is_live) {
                    liveBadge.className = 'badge bg-danger rounded-pill px-2.5 py-1 fw-bold tracking-wider d-inline-flex align-items-center gap-1 shadow-sm';
                    liveBadge.innerHTML = '<span style="width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: cbtPulse 1s infinite;"></span> LIVE STREAM';
                }
            } else if (data.has_frame && data.frame) {
                vImg.src = data.frame;
                vImg.style.display = 'block';
                placeholder.style.display = 'none';
                cbtLastFrameTime = data.timestamp || 0;

                if (data.is_live) {
                    liveBadge.className = 'badge bg-danger rounded-pill px-2.5 py-1 fw-bold tracking-wider d-inline-flex align-items-center gap-1 shadow-sm';
                    liveBadge.innerHTML = '<span style="width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: cbtPulse 1s infinite;"></span> LIVE STREAM';
                    fpsEl.innerText = 'ULTRA-LOW LATENCY (' + (data.age_seconds || 0) + 's)';
                } else {
                    liveBadge.className = 'badge bg-secondary rounded-pill px-2.5 py-1 fw-bold tracking-wider';
                    liveBadge.innerText = 'STANDBY (' + data.age_seconds + 's LALU)';
                    fpsEl.innerText = 'FRAME TERSIMPAN';
                }

                var now = new Date();
                liveTimeEl.innerText = now.getFullYear() + '-' +
                                      String(now.getMonth() + 1).padStart(2, '0') + '-' +
                                      String(now.getDate()).padStart(2, '0') + ' ' +
                                      now.toTimeString().split(' ')[0] + '.' +
                                      String(now.getMilliseconds()).padStart(3, '0') + ' WIB';
            } else {
                vImg.style.display = 'none';
                placeholder.style.display = 'block';
                document.getElementById('cbtCamPlaceholderTitle').innerText = 'Kamera Belum Aktif';
                document.getElementById('cbtCamPlaceholderText').innerText = data.message || 'Siswa belum mengizinkan akses kamera atau sesi belum dimulai.';
            }
        }
    })
    .catch(function(){})
    .finally(function() {
        cbtIsCamFetching = false;
        if (cbtCamStreaming) {
            // Adaptive loop: short delay (60ms) before requesting the next updated frame
            setTimeout(cbtStreamNextFrame, 60);
        }
    });
}

function cbtCloseLiveCam() {
    cbtCamStreaming = false;
    cbtIsCamFetching = false;
    cbtCurrentCamSession = null;
    var vImg = document.getElementById('cbtCamVideoFeed');
    if (vImg) vImg.src = '';
    cbtHideModal('cbtLiveCamModal');
}

function cbtCycleCandidate(delta) {
    if (!currentCandidatesList || currentCandidatesList.length <= 1) return;
    var currentIdx = -1;
    for (var i = 0; i < currentCandidatesList.length; i++) {
        if (cbtCurrentCamSession && currentCandidatesList[i].session_id === cbtCurrentCamSession.id) {
            currentIdx = i;
            break;
        }
    }
    var nextIdx = currentIdx + delta;
    if (nextIdx < 0) nextIdx = currentCandidatesList.length - 1;
    if (nextIdx >= currentCandidatesList.length) nextIdx = 0;

    var nextItem = currentCandidatesList[nextIdx];
    cbtOpenLiveCam(
        nextItem.session_id,
        nextItem.student_name,
        nextItem.student_email,
        nextItem.activity_name,
        nextItem.course_name,
        parseInt(nextItem.warning_count, 10),
        nextItem.status
    );
}

function cbtCaptureEvidenceCurrent() {
    if (!cbtCurrentCamSession) return;
    var sid = cbtCurrentCamSession.id;

    var form = new FormData();
    form.append('action', 'save_evidence');
    form.append('session_id', sid);
    form.append('sesskey', '<?php echo sesskey(); ?>');

    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_camera.php', {
        method: 'POST',
        body: form
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            alert('📸 SUKSES: ' + (data.message || 'Foto bukti pengawas berhasil diabadikan ke dalam audit log.'));
            fetchLiveData(true);
        } else {
            alert(data.error || 'Gagal menyimpan foto bukti.');
        }
    });
}

function cbtCamResolve(decision) {
    if (!cbtCurrentCamSession) return;
    var sid = cbtCurrentCamSession.id;
    cbtResolve(sid, decision);
}

function cbtSyncActiveCameras() {
    fetch('<?php echo $CFG->wwwroot; ?>/local/lumina_cbt/ajax_camera.php?action=get_active_cameras&sesskey=<?php echo sesskey(); ?>')
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (!data.success || !data.active) return;
        document.querySelectorAll('.cbt-cam-badge').forEach(function(el){ el.style.display = 'none'; });
        for (var sid in data.active) {
            var el = document.getElementById('cbt-cam-badge-' + sid);
            if (el) el.style.display = 'inline-flex';
        }
    })
    .catch(function(){});
}

// Initial active camera sync
setTimeout(cbtSyncActiveCameras, 800);
</script>

<?php
echo $OUTPUT->footer();
