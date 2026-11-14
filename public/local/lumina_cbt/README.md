# Lumina CBT & Anti-Cheat Proctoring Suite (`local_lumina_cbt`)

[![Moodle Compatibility](https://img.shields.io/badge/Moodle-4.0%20to%205.3%2B-blue.svg)](https://moodle.org)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-green.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Enterprise Ready](https://img.shields.io/badge/Architecture-Enterprise%20Ready-purple.svg)]()

**Lumina CBT & Anti-Cheat Proctoring Suite** is a self-contained, high-performance Computer-Based Testing (CBT) and live invigilation engine for Moodle. It transforms any assessment (Native Moodle Quiz `mod_quiz`, Interactive H5P `mod_h5pactivity`, or Assignment tasks `mod_assign`) into an iron-clad, secure, cheat-resistant exam environment with a real-time admin monitoring command center.

---

## 🌟 Key Features

### 1. Student Exam Lockdown Engine
* **Strict Fullscreen Trap:** Enforces full-screen mode before exam questions are rendered. Any exit attempt triggers instant security strikes.
* **Window Blur & Alt+Tab Detection:** Detects application switching, background tab switching (`Page Visibility API`), and focus losses in real-time.
* **Touchpad & Gesture Trapping:** Intercepts 3-finger / 4-finger swipes, pinch-to-zoom, and OS-level window minimize actions.
* **Clipboard & Context Menu Lock:** Completely disables right-click context menus, Developer Tools shortcuts (`F12`, `Ctrl+Shift+I`), copy, cut, and paste.
* **Progressive 3-Strike Warning System:**
  * **Warning 1 & 2:** Audio-visual warning HUD with dynamic alerts and violation timestamps.
  * **Warning 3:** Instant exam suspension and screen freeze for proctor review.
* **Dynamic Point Weighting (Weighted Scoring):**
  * Automatically respects native question point values (`maxmark` / `defaultmark` in Quiz, and `weight` in H5P).
  * Calculates proportional final scores accurately:
    $$\text{Final Score} = \left(\frac{\text{Earned Points}}{\text{Total Max Points}}\right) \times 100$$
  * Renders point badges transparently on candidate headers (e.g., `Soal Nomor 1 dari 10 • Bobot: 10 Poin`).
* **Zero Client-Side Answer Leakage:** Answer keys (`correct_index`) are filtered server-side before sending questions to the candidate's browser, preventing inspection via DevTools or Network tabs.
* **AI Suppression Engine:** Automatically hides floating AI assistants, chat bubbles, and external script widgets during active exams.

### 2. Proctor Live Audit & Command Center (`/local/lumina_cbt/monitor.php`)
* **Real-Time Infraction Feed:** Live polling stream (2.5s interval) displaying violations, timestamps, student names, and classes with audible chimes.
* **Real-Time Active Candidate Roster:**
  * Shows candidates actively taking tests (`🟢 Sedang Mengerjakan`).
  * **Instant Lockdown Action (`🚫 Batalkan Nilai`):** Instantly disqualifies candidates for cheating, locks their screen with a red violation overlay, zeroes out their grade in Gradebook, and boots them from the exam.
  * **One-Click Restore (`🔄 Pulihkan Nilai`):** Allows invigilators to restore disqualified attempts and recover previous scores.
  * **Emergency Unlock (`🔓 Unlock / Reset`):** Clears strikes back to 0/3 and unlocks suspended exams without losing student progress.
* **Exception-Based Proctoring:** Honest candidates finishing with 0 violations pass automatically to Gradebook and don't clutter the admin review queue.
* **Detailed Forensic Modal:** Inspect complete infraction histories, timestamps, and reason logs for any candidate.

### 3. Enterprise Compatibility & Resilience
* **Universal Multi-Version Support (Moodle 4.0 - 5.3+):**
  * Modern PSR-14 Hooks (`\core\hook\navigation\primary_extend`) for Moodle 4.4+.
  * Direct `$PAGE->primarynav` and `$navigation` integration for Moodle 4.0 - 4.3 and Classic themes.
  * Smart Client-Side DOM Injector fallback that automatically detects and injects `🛡️ CBT & Audit` on any theme (Boost, Classic, Moove, Adaptable, Trema, Obin, etc.).
* **Automatic Upstream Bug Immunity:** Defensively defines core calendar constants (`ASSIGN_EVENT_TYPE_DUE`, etc.) in `after_config` and `lib.php`, preventing upstream PHP 8+ crashes on Moodle Dashboard (`/my/`).
* **Zero Core Modifications:** 100% self-contained inside `local/lumina_cbt/`. Safe for standard upstream Moodle upgrades.

---

## 🚀 Installation

### Method 1: Web Interface (Recommended)
1. Log in to your Moodle instance as an Administrator.
2. Navigate to **Site administration > Plugins > Install plugins**.
3. Drag and drop `local_lumina_cbt.zip` into the package box.
4. Click **Install plugin from the ZIP file**.
5. Follow the on-screen prompts and click **Upgrade Moodle database now**.

### Method 2: Command Line (CLI / Git)
1. Extract or clone this plugin into your Moodle root directory under `local/lumina_cbt`:
   ```bash
   cd /path/to/moodle/local
   unzip /path/to/local_lumina_cbt.zip
   ```
2. Run database migration and purge caches:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
   ```

---

## ⚙️ Configuration

Navigate to **Site administration > Plugins > Local plugins > Lumina CBT & Anti-Cheat Suite**:
* **Universal CBT Mode:** Enable/disable automatic enforcement on all Quiz and H5P activities.
* **Strike Limit Before Lockout:** Set maximum allowed warnings before suspension (Default: `3`).
* **Enforce Strict Fullscreen:** Toggle mandatory fullscreen enforcement.
* **Block Keyboard (MCQ Mode):** Allow mouse-only interactions for pure multiple-choice assessments.
* **Live Infraction Sound Chimes:** Enable/disable sound notifications on the proctor dashboard.

---

## 🗄️ Database Tables

| Table Name | Description |
|---|---|
| `mdl_local_lumina_cbt_sessions` | Tracks exam attempts, status, scores, reviewer notes, and timestamps. |
| `mdl_local_lumina_cbt_events` | Granular audit trail of all infractions and administrative actions with indexed `eventtime`. |
| `mdl_local_lumina_cbt_warnings` | Official strikes issued to candidates during sessions. |

---

## 🔒 Security & Capabilities

| Capability | Archetypes | Purpose |
|---|---|---|
| `local/lumina_cbt:monitor` | Teacher, Editing Teacher, Manager | View live stream and candidate status |
| `local/lumina_cbt:review` | Editing Teacher, Manager | Unlock, approve, or invalidate sessions |
| `local/lumina_cbt:takeexam` | Student | Take exams under CBT proctoring lockdown |
| `local/lumina_cbt:configure`| Manager | Manage site-wide CBT security settings |

---

## 📄 License
This plugin is licensed under the **GNU General Public License v3 or later (GPLv3+)**.  
Copyright &copy; 2026 Lumina LMS. Developed by Januar Zidane Tinendeng.
