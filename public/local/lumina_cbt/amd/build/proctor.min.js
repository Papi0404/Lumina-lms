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
            sesskey: '',
            ajaxUrl: M.cfg.wwwroot + '/local/lumina_cbt/ajax_events.php',
            maxWarnings: 3,
            strictFullscreen: true,
            blockKeyboard: true,
            suppressAi: true
        },

        state: {
            warnings: 0,
            isLocked: false,
            isFullscreen: false,
            audioCtx: null,
            lastBlurTime: 0
        },

        init: function(opts) {
            this.config = Object.assign(this.config, opts || {});
            this.initAudio();
            this.suppressAiWidgets();
            this.bindSecurityEvents();
            this.createStrikeHud();

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

        bindSecurityEvents: function() {
            var self = this;

            // 1. Right Click / Context Menu
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                self.recordViolation('devtools', 'Percobaan klik kanan / context menu');
                return false;
            }, true);

            // 2. Clipboard Operations (Copy, Cut, Paste)
            ['copy', 'cut', 'paste'].forEach(function(evt) {
                document.addEventListener(evt, function(e) {
                    e.preventDefault();
                    self.recordViolation('clipboard_copy', 'Percobaan operasi clipboard: ' + evt);
                    return false;
                }, true);
            });

            // 3. Window Blur / Tab Switching
            window.addEventListener('blur', function() {
                var now = Date.now();
                if (now - self.state.lastBlurTime > 1500 && !self.state.isLocked) {
                    self.state.lastBlurTime = now;
                    self.recordViolation('blur', 'Beralih jendela / Alt+Tab / Minimize');
                }
            });

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden' && !self.state.isLocked) {
                    self.recordViolation('visibility_hidden', 'Beralih tab browser');
                }
            });

            // 4. Fullscreen State Watcher
            ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(function(evt) {
                document.addEventListener(evt, function() {
                    var inFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
                    self.state.isFullscreen = inFs;
                    if (!inFs && self.config.strictFullscreen && !self.state.isLocked) {
                        self.recordViolation('fullscreen_exit', 'Keluar dari mode layar penuh (Fullscreen)');
                        self.showFullscreenOverlay();
                    }
                });
            });

            // 5. Trackpad / Multi-Touch Gesture Detection
            window.addEventListener('wheel', function(e) {
                if (e.ctrlKey) {
                    e.preventDefault();
                    self.recordViolation('trackpad_gesture', 'Gesture pinch-zoom pada trackpad');
                }
            }, { passive: false });

            window.addEventListener('touchstart', function(e) {
                if (e.touches && e.touches.length >= 3) {
                    e.preventDefault();
                    self.recordViolation('three_finger_gesture', 'Sapuan 3 jari pada touchpad / layar sentuh');
                }
            }, { passive: false });

            // 6. Keyboard Trapping
            window.addEventListener('keydown', function(e) {
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
            document.body.appendChild(hud);
        },

        updateStrikeHud: function(level) {
            this.state.warnings = level;
            var text = document.getElementById('lumina-cbt-hud-text');
            var dot  = document.getElementById('lumina-cbt-status-dot');
            if (!text || !dot) return;

            if (level === 0) {
                text.innerText = '🛡️ CBT Proctor Aktif (0/' + this.config.maxWarnings + ')';
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
                    if (data.is_suspended || data.warning_level >= self.config.maxWarnings) {
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
                                '<button onclick="window.location.reload();" style="background:#4338CA;color:#fff;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:0.95rem;cursor:pointer;">Periksa Status Buka Kunci</button>' +
                                '</div>';
            overlay.style.display = 'flex';
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
                                  '<div style="width:64px;height:64px;background:#EEF2FF;color:#4338CA;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:32px;margin-bottom:16px;">🛡️</div>' +
                                  '<h3 style="font-size:1.3rem;font-weight:700;margin-bottom:10px;">Masuk Mode Layar Penuh</h3>' +
                                  '<p style="color:#94A3B8;font-size:0.92rem;line-height:1.5;margin-bottom:24px;">Ujian ini diawasi dengan sistem proctoring anti-curang. Anda diwajibkan berada di mode layar penuh selama pengerjaan.</p>' +
                                  '<button id="lumina-cbt-enter-fs-btn" style="background:#4338CA;color:#fff;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:0.95rem;cursor:pointer;box-shadow:0 4px 12px rgba(67,56,202,0.4);">Mulai Ujian & Masuk Layar Penuh</button>' +
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
        }
    };

    return Proctor;
});
