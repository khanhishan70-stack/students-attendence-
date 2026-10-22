/**
 * SMART ATTENDANCE AI - PHASE 6
 * QR Attendance teacher page JavaScript.
 *
 * Handles:
 *   - SVG QR code generation via BaconQRCode (served from PHP endpoint)
 *   - Countdown timer for token refresh
 *   - Live attendance polling
 *   - Refresh/close session actions
 */

(function () {
    'use strict';

    // Configuration injected from PHP
    const configEl = document.getElementById('qr-config');
    if (!configEl) {
        return; // Not on a live QR session page
    }

    let config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        console.error('[qr-attendance] Invalid config JSON', e);
        return;
    }

    const {
        sessionId,
        tokenTtl,
        refreshInterval,
        checkinBaseUrl,
        livePollUrl
    } = config;

    // DOM elements
    const qrSvg = document.getElementById('qr-svg');
    const countdownValue = document.getElementById('countdown-value');
    const liveTbody = document.getElementById('live-tbody');
    const statPresent = document.getElementById('stat-present');
    const statAbsent = document.getElementById('stat-absent');
    const statReview = document.getElementById('stat-review');
    const statNotMarked = document.getElementById('stat-not-marked');
    const btnRefresh = document.getElementById('btn-refresh');
    const btnClose = document.getElementById('btn-close');
    const liveUpdateHint = document.getElementById('live-update-hint');

    let countdownTimer = null;
    let pollTimer = null;
    let currentToken = null;
    let tokenExpiresAt = null;

    // ---- QR CODE GENERATION ----
    // We fetch the QR SVG from a small PHP endpoint that generates it server-side
    // using the BaconQRCode library. This avoids sending raw tokens to the browser
    // and keeps the QR generation logic in one place.
    async function fetchAndRenderQr() {
        try {
            const res = await fetch('qr-token.php?session=' + sessionId, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) {
                throw new Error('QR fetch failed: ' + res.status);
            }
            const data = await res.json();
            if (!data.ok || !data.svg) {
                throw new Error(data.error || 'Invalid QR response');
            }
            // Inject SVG
            qrSvg.innerHTML = data.svg;
            currentToken = data.token; // raw token (only used for checkin URL display if needed)
            tokenExpiresAt = data.expires_at ? new Date(data.expires_at).getTime() : null;
            startCountdown();
        } catch (err) {
            console.error('[qr-attendance] QR fetch error:', err);
            qrSvg.innerHTML = '<text x="110" y="110" text-anchor="middle" fill="#dc2626">Failed to load QR</text>';
            countdownValue.textContent = '—';
        }
    }

    // ---- COUNTDOWN ----
    function startCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
        }
        function tick() {
            if (!tokenExpiresAt) {
                countdownValue.textContent = '—';
                return;
            }
            const remaining = Math.max(0, Math.ceil((tokenExpiresAt - Date.now()) / 1000));
            countdownValue.textContent = remaining;
            if (remaining <= 0) {
                // Token expired - the page will auto-refresh the QR shortly
                // but we don't clear the QR, we just show 0
            }
        }
        tick();
        countdownTimer = setInterval(tick, 1000);
    }

    // ---- LIVE ATTENDANCE POLLING ----
    async function pollLive() {
        try {
            const res = await fetch(livePollUrl, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) {
                if (res.status === 403 || res.status === 404) {
                    // Session closed or no access - stop polling
                    stopPolling();
                    return;
                }
                throw new Error('Poll failed: ' + res.status);
            }
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || 'Poll error');
            }
            renderLive(data);
        } catch (err) {
            console.error('[qr-attendance] Poll error:', err);
            // Don't stop polling on transient errors
        }
    }

    function renderLive(data) {
        // Update summary stats
        if (statPresent) statPresent.textContent = data.summary.present;
        if (statAbsent) statAbsent.textContent = data.summary.absent;
        if (statReview) statReview.textContent = data.summary.review;
        if (statNotMarked) statNotMarked.textContent = data.summary.not_marked;

        // Update student table
        if (liveTbody && data.students) {
            let html = '';
            if (data.students.length === 0) {
                html = '<tr><td colspan="5" class="empty-row">No students enrolled in this class.</td></tr>';
            } else {
                for (const s of data.students) {
                    const badge = statusBadge(s.status);
                    const markedAt = s.marked_at ? formatTime(s.marked_at) : '<span class="muted">—</span>';
                    const sourceBadge = s.source === 'qr'
                        ? '<span class="badge badge-blue">QR</span>'
                        : '<span class="badge badge-grey">Manual</span>';
                    html += `
                        <tr data-student-id="${s.student_id}" class="${s.is_present ? 'is-present' : ''}">
                            <td data-label="Roll">${escapeHtml(s.roll_number)}</td>
                            <td data-label="Name">${escapeHtml(s.full_name)}</td>
                            <td data-label="Status">${badge}</td>
                            <td data-label="Marked at">${markedAt}</td>
                            <td data-label="Source">${sourceBadge}</td>
                        </tr>
                    `;
                }
            }
            liveTbody.innerHTML = html;
        }

        // If session closed, stop polling and update UI
        if (data.session && data.session.status === 'closed') {
            stopPolling();
            if (liveUpdateHint) {
                liveUpdateHint.textContent = 'Session closed. No further updates.';
                liveUpdateHint.style.color = 'var(--color-muted)';
            }
            if (btnRefresh) btnRefresh.disabled = true;
            if (btnClose) btnClose.disabled = true;
            // Refresh the page to show the closed summary view
            setTimeout(() => window.location.reload(), 2000);
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    // ---- HELPERS ----
    function statusBadge(status) {
        const map = {
            present:    '<span class="badge badge-green">Present</span>',
            absent:     '<span class="badge badge-red">Absent</span>',
            review:     '<span class="badge badge-amber">Review</span>',
            not_marked: '<span class="badge badge-grey">Not marked</span>'
        };
        return map[status] || '<span class="badge badge-grey">' + escapeHtml(status) + '</span>';
    }

    function formatTime(iso) {
        const d = new Date(iso);
        if (isNaN(d)) return '<span class="muted">—</span>';
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&')
            .replace(/</g, '<')
            .replace(/>/g, '>')
            .replace(/"/g, '"')
            .replace(/'/g, '&#039;');
    }

    // ---- REFRESH / CLOSE BUTTONS ----
    // These are handled by the forms (POST + PRG), but we can add UX hints
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () {
            this.disabled = true;
            this.innerHTML = '<?= app_icon(\'spark\') ?> Refreshing…';
            // Form submits, page reloads with new token
        });
    }

    // ---- INIT ----
    fetchAndRenderQr();
    pollLive();
    pollTimer = setInterval(pollLive, 5000); // 5 second polling

    // Cleanup on page unload
    window.addEventListener('beforeunload', stopPolling);

    // Re-fetch QR when token is about to expire (a bit before the countdown hits 0)
    // The PHP endpoint issues a new token and revokes the old one.
    setInterval(() => {
        if (tokenExpiresAt && (tokenExpiresAt - Date.now()) < 3000) {
            fetchAndRenderQr();
        }
    }, 2000);

})();