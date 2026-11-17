/**
 * Universal Anti-Cheat Proctoring Engine for Lumina CBT Suite.
 *
 * @module     local_lumina_cbt/proctor
 * @copyright  2026 Lumina LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(ajax, notification) {
    'use strict';

    var Proctor = {
        config: {
            sessionId: 0,
            cmid: 0,
            courseId: 0,
            courseUrl: '',
            sesskey: '',
            ajaxUrl: M.cfg.wwwroot + '/local/lumina_cbt/ajax_events.php',
            cameraAjaxUrl: M.cfg.wwwroot + '/local/lumina_cbt/ajax_camera.php',
            maxWarnings: 3,
            strictFullscreen: true,
            blockKeyboard: true,
            suppressAi: true,
            enableCamera: true,
            cameraInterval: 350,
            isSimulation: false
        },

        state: {
            warnings: 0,
            isLocked: false,
            isDisqualified: false,
            isFullscreen: false,
            audioCtx: null,
            lastBlurTime: 0,
            heartbeatTimer: null,
            cameraStream: null,
            cameraTimer: null,
            cameraCanvas: null,
            cameraVideo: null,
            isCameraActive: false,
            checkpoints: {
                start: false,
                '5min': false,
                submit: false
            },
            checkpointTimers: [],
            isSubmitting: false
        },

        setSubmitting: function(val) {
            this.state.isSubmitting = !!val;
        },

        init: function(opts) {
            this.config = Object.assign(this.config, opts || {});
            window.LuminaCBTProctor = this;
            if (!this.config.courseUrl) {
                this.config.courseUrl = this.config.courseId ? (M.cfg.wwwroot + '/course/view.php?id=' + this.config.courseId) : (M.cfg.wwwroot + '/my/');
            }
            this.initAudio();
            this.suppressAiWidgets();
            this.bindSecurityEvents();
            this.createStrikeHud();
            this.startHeartbeat();

            if (this.config.enableCamera !== false) {
                this.initCamera();
            }

            if (this.config.strictFullscreen) {
                this.promptFullscreen();
            }

            console.log('[Lumina CBT Proctor] Anti-Cheat Engine Active. Target Session: ' + this.config.sessionId);
        },

        initAudio: function() {
            try {
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                if (AudioContext) {
                    this.state.audioCtx = new AudioContext();
                }
            } catch (e) {}
        },

        playWarningChime: function() {
            if (!this.state.audioCtx) return;
            try {
                if (this.state.audioCtx.state === 'suspended') {
                    this.state.audioCtx.resume();
                }
                var ctx = this.state.audioCtx;
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(580, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(220, ctx.currentTime + 0.35);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch (e) {}
        },

        suppressAiWidgets: function() {
            if (!this.config.suppressAi) return;
            var hideAi = function() {
                var selectors = [
                    '#lumina-ai-launcher',
                    '#lumina-ai-chat-window',
                    '.lumina-ai-chatbot-widget',
                    '[data-region="lumina-ai-chat"]',
                    '.ai-chatbot-button',
                    '#chatgpt-sidebar',
                    '#copilot-sidebar'
                ];
                selectors.forEach(function(sel) {
                    var els = document.querySelectorAll(sel);
                    els.forEach(function(el) {
                        el.style.setProperty('display', 'none', 'important');
                        el.style.setProperty('pointer-events', 'none', 'important');
                    });
                });
            };
            hideAi();
            setInterval(hideAi, 1500);
        },

        startHeartbeat: function() {
            var self = this;
            if (self.state.heartbeatTimer) clearInterval(self.state.heartbeatTimer);

            self.state.heartbeatTimer = setInterval(function() {
                if (self.state.isDisqualified) return;

                var url = self.config.ajaxUrl + '?action=heartbeat&session_id=' + self.config.sessionId +
                          '&cmid=' + self.config.cmid + '&sesskey=' + (self.config.sesskey || M.cfg.sesskey);

                fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (data.status === 'invalidated' || data.is_disqualified) {
                            self.showDisqualifiedLockdown(data.message);
                        } else if (data.status === 'under_review' || data.is_suspended) {
                            self.showLockoutScreen(data.message);
                        } else if (data.status === 'in_progress') {
                            if (self.state.isLocked && data.warning_level === 0) {
                                self.hideLockoutScreen();
                            }
                            self.updateStrikeHud(data.warning_level || 0);
                        }
                    }
                })
                .catch(function(e) {});
            }, 3000);
        },

        bindSecurityEvents: function() {
            var self = this;

            // 1. Right Click / Context Menu
            document.addEventListener('contextmenu', function(e) {
                if (self.state.isDisqualified) return;
                e.preventDefault();
                self.recordViolation('devtools', 'Percobaan klik kanan / context menu');
                return false;
            }, true);

            // 2. Clipboard Operations (Copy, Cut, Paste)
            ['copy', 'cut', 'paste'].forEach(function(evt) {
                document.addEventListener(evt, function(e) {
                    if (self.state.isDisqualified) return;
                    e.preventDefault();
                    self.recordViolation('clipboard_copy', 'Percobaan operasi clipboard: ' + evt);
                    return false;
                }, true);
            });

            // 3. Window Blur / Tab Switching
            window.addEventListener('blur', function() {
                if (self.state.isDisqualified || self.state.isSubmitting) return;
                var now = Date.now();
                if (now - self.state.lastBlurTime > 1500 && !self.state.isLocked) {
                    self.state.lastBlurTime = now;
                    self.recordViolation('blur', 'Beralih jendela / Alt+Tab / Minimize');
                }
            });

            document.addEventListener('visibilitychange', function() {
                if (self.state.isDisqualified || self.state.isSubmitting) return;
                if (document.visibilityState === 'hidden' && !self.state.isLocked) {
                    self.recordViolation('visibility_hidden', 'Beralih tab browser');
                }
            });

            // 4. Fullscreen State Watcher
            ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(function(evt) {
                document.addEventListener(evt, function() {
                    if (self.state.isDisqualified || self.state.isSubmitting) return;
                    var inFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
                    self.state.isFullscreen = inFs;
                    if (!inFs && self.config.strictFullscreen && !self.state.isLocked && !self.state.isSubmitting) {
                        self.recordViolation('fullscreen_exit', 'Keluar dari mode layar penuh (Fullscreen)');
                        self.showFullscreenOverlay();
                    }
                });
            });

            // 5. Trackpad / Multi-Touch Gesture Detection
            window.addEventListener('wheel', function(e) {
                if (self.state.isDisqualified) return;
                if (e.ctrlKey) {
                    e.preventDefault();
                    self.recordViolation('trackpad_gesture', 'Gesture pinch-zoom pada trackpad');
                }
            }, { passive: false });

            window.addEventListener('touchstart', function(e) {
                if (self.state.isDisqualified) return;
                if (e.touches && e.touches.length >= 3) {
                    e.preventDefault();
                    self.recordViolation('three_finger_gesture', 'Sapuan 3 jari pada touchpad / layar sentuh');
                }
            }, { passive: false });

            // 6. Keyboard Trapping
            window.addEventListener('keydown', function(e) {
                if (self.state.isDisqualified) return;
                var k = e.key || '';
                var isDevtools = (k === 'F12' || (e.ctrlKey && e.shiftKey && (k === 'I' || k === 'J' || k === 'C')) || (e.ctrlKey && (k === 'u' || k === 'U' || k === 'p' || k === 'P')));
                if (isDevtools) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.recordViolation('devtools', 'Percobaan jalan pintas DevTools: ' + k);
                    return false;
                }

                if (self.config.blockKeyboard && !isDevtools) {
                    var allowed = ['Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '];
                    if (allowed.indexOf(k) === -1 && !e.target.matches('input[type="text"], textarea')) {
                        e.preventDefault();
                        self.recordViolation('keyboard_attempt', 'Tombol keyboard ditekan: ' + k);
                        return false;
                    }
                }
            }, true);
        },

        createStrikeHud: function() {
            var hud = document.getElementById('lumina-cbt-strike-hud');
            if (hud) return;

            hud = document.createElement('div');
            hud.id = 'lumina-cbt-strike-hud';
            hud.style.cssText = 'position:fixed;top:12px;right:16px;z-index:999999;display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:24px;font-family:system-ui,-apple-system,sans-serif;font-size:0.85rem;font-weight:600;background:rgba(15,23,42,0.88);color:#fff;backdrop-filter:blur(8px);box-shadow:0 8px 24px rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.15);';
            hud.innerHTML = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10B981;" id="lumina-cbt-status-dot"></span>' +
                            '<span id="lumina-cbt-hud-text">🛡️ CBT Proctor Aktif (0/3)</span>';

            if (this.config.isSimulation) {
                hud.style.background = 'rgba(217,119,6,0.9)';
                hud.innerHTML = '<span id="lumina-cbt-hud-text">🛡️ Simulasi Pengajar Aktif (0/3 — Aman)</span>';
            }

            document.body.appendChild(hud);
        },

        updateStrikeHud: function(level) {
            this.state.warnings = level;
            var text = document.getElementById('lumina-cbt-hud-text');
            var dot  = document.getElementById('lumina-cbt-status-dot');
            if (!text || !dot) return;

            if (level === 0) {
                var hudLabel = this.config.isSimulation
                    ? '🛡️ Simulasi Pengajar (' + level + '/' + this.config.maxWarnings + ' — Aman)'
                    : '🛡️ CBT Proctor Aktif (' + level + '/' + this.config.maxWarnings + ')';
                text.innerText = hudLabel;
                dot.style.background = '#10B981';
            } else if (level === 1) {
                text.innerText = '⚠️ Peringatan 1/' + this.config.maxWarnings;
                dot.style.background = '#F59E0B';
            } else if (level === 2) {
                text.innerText = '🚨 PERINGATAN 2/' + this.config.maxWarnings;
                dot.style.background = '#F97316';
            } else {
                text.innerText = '🔒 Ujian Ditangguhkan (' + level + '/' + this.config.maxWarnings + ')';
                dot.style.background = '#EF4444';
            }
        },

        recordViolation: function(eventType, metadata) {
            var self = this;
            if (self.state.isDisqualified || self.state.isSubmitting) return;
            self.playWarningChime();

            var form = new FormData();
            form.append('action', 'record_violation');
            form.append('session_id', self.config.sessionId);
            form.append('cmid', self.config.cmid);
            form.append('event_type', eventType);
            form.append('metadata', metadata || '');
            form.append('sesskey', self.config.sesskey || M.cfg.sesskey);

            fetch(self.config.ajaxUrl, {
                method: 'POST',
                body: form
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    self.updateStrikeHud(data.warning_level);
                    if (self.config.isSimulation || data.is_simulation) {
                        // Safe simulation mode: only show informational toast, never lockout
                        self.showSimulationToast(data.message, data.warning_level);
                    } else if (data.status === 'invalidated') {
                        self.showDisqualifiedLockdown(data.message);
                    } else if (data.is_suspended || data.warning_level >= self.config.maxWarnings) {
                        self.showLockoutScreen(data.message);
                    } else {
                        self.showWarningToast(data.message, data.warning_level);
                    }
                }
            })
            .catch(function(err) {
                console.error('[Lumina CBT] Infraction beacon error:', err);
            });
        },

        showWarningToast: function(msg, level) {
            var toast = document.getElementById('lumina-cbt-warning-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'lumina-cbt-warning-toast';
                toast.style.cssText = 'position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:9999999;max-width:550px;width:90%;padding:14px 20px;border-radius:12px;font-family:system-ui,sans-serif;font-size:0.95rem;font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,0.3);transition:all 0.3s ease;';
                document.body.appendChild(toast);
            }

            var bgColor = (level === 1) ? '#FEF3C7' : '#FFEDD5';
            var textColor = (level === 1) ? '#92400E' : '#9A3412';
            var borderColor = (level === 1) ? '#F59E0B' : '#EA580C';

            toast.style.background = bgColor;
            toast.style.color = textColor;
            toast.style.border = '2px solid ' + borderColor;
            toast.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">' +
                              '<span style="font-size:1.5rem;">⚠️</span>' +
                              '<div>' + (msg || 'Pelanggaran terdeteksi! Harap tetap berada di dalam jendela ujian.') + '</div>' +
                              '</div>';
            toast.style.display = 'block';

            setTimeout(function() {
                toast.style.display = 'none';
            }, 5000);
        },

        showSimulationToast: function(msg, level) {
            var toast = document.getElementById('lumina-cbt-sim-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'lumina-cbt-sim-toast';
                toast.style.cssText = 'position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:9999999;max-width:620px;width:92%;padding:14px 20px;border-radius:12px;font-family:system-ui,sans-serif;font-size:0.92rem;font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,0.25);transition:all 0.3s ease;background:#FEF3C7;border:2px solid #F59E0B;color:#78350F;';
                document.body.appendChild(toast);
            }
            toast.innerHTML = '<div style="display:flex;align-items:flex-start;gap:12px;">' +
                              '<span style="font-size:1.6rem;flex-shrink:0;">🛡️</span>' +
                              '<div><div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:3px;color:#B45309;font-weight:800;">MODE SIMULASI PENGAJAR &amp; ADMIN (SESI AMAN)</div>' +
                              '<div>' + (msg || 'Pelanggaran simulasi terdeteksi. Pada peserta didik asli, sistem akan membekukan ujian.') + '</div>' +
                              '</div></div>';
            toast.style.display = 'block';
            setTimeout(function() {
                toast.style.display = 'none';
            }, 6000);
        },

        showLockoutScreen: function(msg) {
            this.state.isLocked = true;
            var overlay = document.getElementById('lumina-cbt-lockout-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'lumina-cbt-lockout-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.96);z-index:99999999;display:flex;align-items:center;justify-content:center;color:#fff;font-family:system-ui,sans-serif;backdrop-filter:blur(16px);';
                document.body.appendChild(overlay);
            }

            overlay.innerHTML = '<div style="max-width:520px;width:90%;background:#1E293B;padding:36px;border-radius:20px;text-align:center;box-shadow:0 20px 48px rgba(0,0,0,0.5);border:1px solid rgba(239,68,68,0.4);">' +
                                '<div style="width:72px;height:72px;background:rgba(239,68,68,0.15);color:#EF4444;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:20px;">🔒</div>' +
                                '<h2 style="font-size:1.45rem;font-weight:700;margin-bottom:12px;color:#F8FAFC;">Ujian Ditangguhkan</h2>' +
                                '<p style="color:#94A3B8;font-size:0.95rem;line-height:1.6;margin-bottom:24px;">' +
                                (msg || 'Anda telah mencapai batas 3 kali pelanggaran. Sesi ujian Anda telah dikunci demi integritas akademik.') +
                                '</p>' +
                                '<div style="background:#0F172A;padding:16px;border-radius:12px;font-size:0.88rem;color:#CBD5E1;margin-bottom:24px;border:1px solid #334155;">' +
                                'Silakan hubungi Pengawas Ujian atau Administrator di ruangan Anda untuk melakukan verifikasi dan pembukaan kunci (Unlock).' +
                                '</div>' +
                                '<button onclick="window.location.reload();" style="background:var(--cbt-primary);color:#fff;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:0.95rem;cursor:pointer;">Periksa Status Buka Kunci</button>' +
                                '</div>';
            overlay.style.display = 'flex';
        },

        hideLockoutScreen: function() {
            this.state.isLocked = false;
            var overlay = document.getElementById('lumina-cbt-lockout-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        },

        showDisqualifiedLockdown: function(msg) {
            var self = this;
            self.state.isDisqualified = true;
            self.state.isLocked = true;
            if (self.state.heartbeatTimer) clearInterval(self.state.heartbeatTimer);

            // Disable all forms and inputs
            document.querySelectorAll('input, button, select, textarea').forEach(function(el) {
                el.disabled = true;
            });

            self.playWarningChime();

            var overlay = document.getElementById('lumina-cbt-disqualified-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'lumina-cbt-disqualified-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:#0B132B;z-index:999999999;display:flex;align-items:center;justify-content:center;color:#fff;font-family:system-ui,sans-serif;padding:20px;';
                document.body.appendChild(overlay);
            }

            var returnUrl = self.config.courseUrl || (M.cfg.wwwroot + '/my/');
            overlay.innerHTML = '<div style="max-width:540px;width:100%;background:#1E293B;padding:40px 32px;border-radius:24px;text-align:center;box-shadow:0 25px 60px rgba(0,0,0,0.7);border:2px solid #EF4444;">' +
                                '<div style="width:80px;height:80px;background:rgba(239,68,68,0.15);color:#EF4444;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:40px;margin-bottom:20px;border:1px solid rgba(239,68,68,0.3);">🚫</div>' +
                                '<h2 style="font-size:1.6rem;font-weight:800;margin-bottom:12px;color:#F8FAFC;">Akses Ujian Dibekukan</h2>' +
                                '<div style="background:rgba(239,68,68,0.12);border-left:4px solid #EF4444;padding:16px 18px;border-radius:8px;text-align:left;color:#FCA5A5;font-size:0.95rem;line-height:1.6;margin-bottom:24px;">' +
                                (msg || 'Anda ujiannya telah dibekukan karna melanggar integritas, silahkan hubungi admin.') +
                                '</div>' +
                                '<p style="color:#94A3B8;font-size:0.9rem;margin-bottom:28px;">Sesi ujian ini telah dibatalkan oleh pengawas. Nilai didiskualifikasi (0.00).</p>' +
                                '<a href="' + returnUrl + '" style="display:inline-block;background:#EF4444;color:#fff;text-decoration:none;padding:12px 32px;border-radius:12px;font-weight:700;font-size:0.95rem;box-shadow:0 6px 20px rgba(239,68,68,0.4);">Keluar dari Ujian</a>' +
                                '</div>';
            overlay.style.display = 'flex';

            // Auto redirect after 8 seconds
            setTimeout(function() {
                window.location.href = returnUrl;
            }, 8000);
        },

        promptFullscreen: function() {
            var self = this;
            var inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
            if (inFs) return;

            var modal = document.getElementById('lumina-cbt-fs-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'lumina-cbt-fs-modal';
                modal.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.92);z-index:9999999;display:flex;align-items:center;justify-content:center;color:#fff;font-family:system-ui,sans-serif;backdrop-filter:blur(10px);';
                modal.innerHTML = '<div style="max-width:480px;width:90%;background:#1E293B;padding:32px;border-radius:16px;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.1);">' +
                                  '<div style="width:64px;height:64px;background:var(--cbt-primary-surface,#EEF2FF);color:var(--cbt-primary);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:32px;margin-bottom:16px;">🛡️</div>' +
                                  '<h3 style="font-size:1.3rem;font-weight:700;margin-bottom:10px;">Masuk Mode Layar Penuh</h3>' +
                                  '<p style="color:#94A3B8;font-size:0.92rem;line-height:1.5;margin-bottom:24px;">Ujian ini diawasi dengan sistem proctoring anti-curang. Anda diwajibkan berada di mode layar penuh selama pengerjaan.</p>' +
                                  '<button id="lumina-cbt-enter-fs-btn" style="background:var(--cbt-primary);color:#fff;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:0.95rem;cursor:pointer;box-shadow:0 4px 12px rgba(67,56,202,0.4);">Mulai Ujian & Masuk Layar Penuh</button>' +
                                  '</div>';
                document.body.appendChild(modal);

                document.getElementById('lumina-cbt-enter-fs-btn').addEventListener('click', function() {
                    self.requestFullscreen();
                    modal.style.display = 'none';
                });
            }
        },

        requestFullscreen: function() {
            var el = document.documentElement;
            if (el.requestFullscreen) {
                el.requestFullscreen().catch(function(){});
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            } else if (el.mozRequestFullScreen) {
                el.mozRequestFullScreen();
            } else if (el.msRequestFullscreen) {
                el.msRequestFullscreen();
            }
        },

        showFullscreenOverlay: function() {
            var self = this;
            var overlay = document.getElementById('lumina-cbt-fs-return-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'lumina-cbt-fs-return-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(239,68,68,0.94);z-index:9999998;display:flex;align-items:center;justify-content:center;color:#fff;font-family:system-ui,sans-serif;backdrop-filter:blur(8px);';
                overlay.innerHTML = '<div style="max-width:440px;width:90%;text-align:center;">' +
                                    '<div style="font-size:48px;margin-bottom:14px;">⚠️</div>' +
                                    '<h2 style="font-size:1.4rem;font-weight:700;margin-bottom:10px;">Layar Penuh Terputus!</h2>' +
                                    '<p style="font-size:0.95rem;line-height:1.5;margin-bottom:20px;opacity:0.95;">Pelanggaran telah dicatat oleh sistem pengawas. Klik tombol di bawah untuk kembali ke mode layar penuh.</p>' +
                                    '<button id="lumina-cbt-return-fs-btn" style="background:#fff;color:#DC2626;border:none;padding:12px 28px;border-radius:10px;font-weight:700;font-size:0.95rem;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,0.25);">Kembali ke Layar Penuh</button>' +
                                    '</div>';
                document.body.appendChild(overlay);

                document.getElementById('lumina-cbt-return-fs-btn').addEventListener('click', function() {
                    self.requestFullscreen();
                    overlay.style.display = 'none';
                });
            } else {
                overlay.style.display = 'flex';
            }
        },

        initCamera: function() {
            var self = this;
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                console.warn('[Lumina CBT] MediaDevices API not supported on this device/browser.');
                return;
            }

            navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 480 },
                    height: { ideal: 360 },
                    facingMode: 'user'
                },
                audio: false
            })
            .then(function(stream) {
                self.state.cameraStream = stream;
                self.state.isCameraActive = true;
                self.createCameraHud(stream);
                self.startCameraStreamer();

                // Checkpoint 1: Auto-capture photo at exam start (after 3.5s camera stabilization)
                var startTimer = setTimeout(function() {
                    self.captureCheckpointPhoto('start');
                }, 3500);

                // Checkpoint 2: Auto-capture photo after 5 minutes (300 seconds)
                var midTimer = setTimeout(function() {
                    self.captureCheckpointPhoto('5min');
                }, 300000);

                self.state.checkpointTimers.push(startTimer, midTimer);
                console.log('[Lumina CBT] Live camera & 3-point automated checkpoints initialized.');
            })
            .catch(function(err) {
                console.warn('[Lumina CBT] Camera permission denied or device not found:', err);
                self.recordViolation('camera_blocked', 'Akses kamera tidak diizinkan atau kamera tidak terdeteksi: ' + (err.name || 'Error'));
                self.showCameraAlert();
            });
        },

        createCameraHud: function(stream) {
            var box = document.getElementById('lumina-cbt-cam-box');
            if (box) return;

            box = document.createElement('div');
            box.id = 'lumina-cbt-cam-box';
            box.style.cssText = 'position:fixed;bottom:16px;right:16px;width:150px;height:112px;border-radius:12px;overflow:hidden;z-index:999997;border:2px solid var(--cbt-primary);box-shadow:0 8px 24px rgba(0,0,0,0.35);background:#0F172A;transition:all 0.2s ease;';

            var video = document.createElement('video');
            video.id = 'lumina-cbt-cam-video';
            video.autoplay = true;
            video.playsInline = true;
            video.muted = true;
            video.style.cssText = 'width:100%;height:100%;object-fit:cover;transform:scaleX(-1);display:block;';
            video.srcObject = stream;

            var badge = document.createElement('div');
            badge.style.cssText = 'position:absolute;top:6px;left:6px;background:rgba(15,23,42,0.85);color:#10B981;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;display:flex;align-items:center;gap:5px;font-family:system-ui,sans-serif;letter-spacing:0.04em;pointer-events:none;';
            badge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#10B981;display:inline-block;"></span><span>Kamera Aktif</span>';

            box.appendChild(video);
            box.appendChild(badge);
            document.body.appendChild(box);

            this.state.cameraVideo = video;
        },

        startCameraStreamer: function() {
            var self = this;
            if (self.state.cameraTimer) clearInterval(self.state.cameraTimer);

            var canvas = document.createElement('canvas');
            canvas.width = 280;
            canvas.height = 210;
            var ctx = canvas.getContext('2d');
            self.state.cameraCanvas = canvas;

            var isPushing = false;

            self.state.cameraTimer = setInterval(function() {
                if (!self.state.isCameraActive || self.state.isDisqualified || isPushing) return;
                var video = self.state.cameraVideo;
                if (!video || video.readyState < 2) return;

                try {
                    ctx.drawImage(video, 0, 0, 280, 210);
                    var frameData = canvas.toDataURL('image/jpeg', 0.45);

                    var form = new FormData();
                    form.append('action', 'push_frame');
                    form.append('session_id', self.config.sessionId);
                    form.append('cmid', self.config.cmid);
                    form.append('frame', frameData);
                    form.append('sesskey', self.config.sesskey || M.cfg.sesskey);

                    isPushing = true;
                    fetch(self.config.cameraAjaxUrl, {
                        method: 'POST',
                        body: form
                    })
                    .catch(function(){})
                    .finally(function() {
                        isPushing = false;
                    });
                } catch(e) {
                    isPushing = false;
                }
            }, self.config.cameraInterval || 350);
        },

        captureCheckpointPhoto: function(checkpoint) {
            var self = this;
            if (!self.state.isCameraActive) return;
            if (self.state.checkpoints && self.state.checkpoints[checkpoint]) return;

            var video = self.state.cameraVideo;
            if (!video || video.readyState < 2) return;

            try {
                var snapCanvas = document.createElement('canvas');
                snapCanvas.width = 480;
                snapCanvas.height = 360;
                var snapCtx = snapCanvas.getContext('2d');
                snapCtx.drawImage(video, 0, 0, 480, 360);
                var frameData = snapCanvas.toDataURL('image/jpeg', 0.70);

                var form = new FormData();
                form.append('action', 'checkpoint_photo');
                form.append('checkpoint', checkpoint);
                form.append('session_id', self.config.sessionId);
                form.append('cmid', self.config.cmid);
                form.append('frame', frameData);
                form.append('sesskey', self.config.sesskey || M.cfg.sesskey);

                fetch(self.config.cameraAjaxUrl, {
                    method: 'POST',
                    body: form
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.success) {
                        if (!self.state.checkpoints) self.state.checkpoints = {};
                        self.state.checkpoints[checkpoint] = true;
                        console.log('[Lumina CBT] Auto-checkpoint photo saved successfully:', checkpoint);
                    }
                })
                .catch(function(err) {
                    console.warn('[Lumina CBT] Checkpoint photo dispatch error:', err);
                });
            } catch (e) {
                console.warn('[Lumina CBT] Could not capture checkpoint photo:', e);
            }
        },

        showCameraAlert: function() {
            var toast = document.createElement('div');
            toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#FEF2F2;border:2px solid #EF4444;color:#991B1B;padding:12px 20px;border-radius:12px;font-weight:700;font-size:0.9rem;z-index:9999999;box-shadow:0 10px 25px rgba(0,0,0,0.25);font-family:system-ui,sans-serif;';
            toast.innerHTML = '⚠️ <strong>Kamera Wajib Aktif:</strong> Ujian CBT ini mewajibkan kamera web menyala untuk pengawasan pengawas.';
            document.body.appendChild(toast);
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 8000);
        }
    };

    return Proctor;
});
