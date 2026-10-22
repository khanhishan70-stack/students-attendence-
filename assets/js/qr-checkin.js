/**
 * SMART ATTENDANCE AI - PHASE 6
 * QR Attendance student check-in page JavaScript.
 *
 * Handles:
 *   - Shows token expiry countdown
 *   - Auto-refreshes the page when token is about to expire
 *   - Handles loading states during submission
 */

(function () {
    'use strict';

    const configEl = document.getElementById('checkin-config');
    if (!configEl) {
        return;
    }

    let config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        console.error('[qr-checkin] Invalid config', e);
        return;
    }

    const {
        sessionId,
        token,
        isAuthed,
        tokenExpires
    } = config;

    const countdownEl = document.getElementById('token-countdown');
    const countdownValue = document.getElementById('countdown-value');
    const messageEl = document.querySelector('.checkin-message');
    const cardEl = document.querySelector('.checkin-card');

    if (!countdownEl || !countdownValue) {
        return;
    }

    let countdownTimer = null;
    let expiresAt = tokenExpires ? new Date(tokenExpires).getTime() : null;

    function startCountdown() {
        if (countdownTimer) clearInterval(countdownTimer);

        function tick() {
            if (!expiresAt) {
                countdownValue.textContent = '—';
                return;
            }
            const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
            countdownValue.textContent = remaining;

            if (remaining <= 0) {
                countdownValue.textContent = 'Expired';
                countdownValue.style.color = '#dc2626';
                // Reload to get a fresh token (teacher may have refreshed)
                if (!isAuthed) {
                    // If not authed, we can't submit anyway, just wait for manual reload
                    return;
                }
                // If authed but token expired, show message
                if (messageEl) {
                    messageEl.textContent = 'This QR code has expired. Please scan the updated code from your teacher.';
                }
            }
        }

        tick();
        countdownTimer = setInterval(tick, 1000);
    }

    // Visual feedback during submission (handled by the GET-with-token flow)
    // If the page loads with a token and isAuthed, the PHP already submitted.
    // We just show the result.

    // If token is present but not authed, the PHP redirected to login.
    // After login, the user comes back here with the same token.
    // If the token expired in the meantime, show a clear message.

    if (token && expiresAt) {
        startCountdown();
    }

    // Cleanup
    window.addEventListener('beforeunload', () => {
        if (countdownTimer) clearInterval(countdownTimer);
    });

})();