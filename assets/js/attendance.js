(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------------------------------------------------------------
     * Session setup: hide subjects that belong to another class.
     *
     * Every subject option carries data-class-id. The server renders them
     * all and re-checks the pairing on submit, so this filter is purely a
     * convenience and the page still works with JavaScript disabled.
     * --------------------------------------------------------------- */
    var setupForm = document.querySelector('form[data-subject-filter]');

    if (setupForm) {
        var classSelect = setupForm.querySelector('select[name="class_id"]');
        var subjectSelect = setupForm.querySelector('select[name="subject_id"]');

        function filterSubjects() {
            if (!classSelect || !subjectSelect) {
                return;
            }

            var wanted = classSelect.value;
            var chosenIsValid = false;

            Array.prototype.forEach.call(subjectSelect.options, function (option) {
                var belongs = option.getAttribute('data-class-id');

                if (belongs === null) {
                    return;
                }

                var visible = belongs === wanted;
                option.hidden = !visible;
                option.disabled = !visible;

                if (visible && option.selected && option.value !== '0') {
                    chosenIsValid = true;
                }
            });

            if (!chosenIsValid) {
                subjectSelect.value = '0';
            }
        }

        if (classSelect && subjectSelect) {
            classSelect.addEventListener('change', filterSubjects);
            filterSubjects();
        }
    }

    /* ---------------------------------------------------------------
     * Marking screen: timer, voice and navigation helpers.
     * --------------------------------------------------------------- */
    var panel = document.querySelector('[data-attendance]');

    if (!panel) {
        return;
    }

    var totalSeconds = parseInt(panel.getAttribute('data-timer-seconds'), 10) || 30;
    var nextUrl      = panel.getAttribute('data-next-url') || '';
    var currentUrl   = panel.getAttribute('data-current-url') || '';

    /* ---------------------------------------------------------------
     * Voice announcements (Web Speech API).
     *
     * Off by default, remembered in localStorage, and skipped entirely on
     * browsers without speechSynthesis. Nothing is sent anywhere: the
     * browser speaks locally. A failed or blocked utterance is swallowed
     * so it can never block attendance marking.
     * --------------------------------------------------------------- */
    var voiceToggle = panel.querySelector('[data-voice-toggle]');
    var voiceHint   = panel.querySelector('[data-voice-hint]');
    var voiceKey    = 'smart_attendance_voice';
    var speechSupported = typeof window.speechSynthesis !== 'undefined'
        && typeof window.SpeechSynthesisUtterance !== 'undefined';

    function readVoicePref() {
        try {
            return window.localStorage.getItem(voiceKey) === 'on';
        } catch (error) {
            return false;
        }
    }

    function writeVoicePref(on) {
        try {
            window.localStorage.setItem(voiceKey, on ? 'on' : 'off');
        } catch (error) {
            /* private mode: the choice simply does not persist */
        }
    }

    function speak(text) {
        if (!speechSupported || !text) {
            return;
        }

        try {
            window.speechSynthesis.cancel();

            var utterance = new window.SpeechSynthesisUtterance(text);
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            utterance.onerror = function () { /* never blocks marking */ };

            window.speechSynthesis.speak(utterance);
        } catch (error) {
            /* speech is a nicety; ignore any failure */
        }
    }

    function announceCurrent() {
        if (!voiceToggle || !voiceToggle.checked || !speechSupported) {
            return;
        }

        var raw = panel.getAttribute('data-voice') || '';
        var parts = raw.split('|');
        var roll = (parts[0] || '').trim();
        var name = (parts[1] || '').trim();

        if (roll || name) {
            speak('Roll ' + roll + '. ' + name);
        }
    }

    if (voiceToggle) {
        if (!speechSupported) {
            voiceToggle.checked = false;
            voiceToggle.disabled = true;

            if (voiceHint) {
                voiceHint.textContent = 'Your browser does not support speech output, so voice announcements are unavailable.';
            }
        } else {
            voiceToggle.checked = readVoicePref();

            voiceToggle.addEventListener('change', function () {
                writeVoicePref(voiceToggle.checked);

                if (!voiceToggle.checked && window.speechSynthesis) {
                    try { window.speechSynthesis.cancel(); } catch (error) { /* ignore */ }
                }

                announceCurrent();
            });

            announceCurrent();
        }
    }

    /* ---------------------------------------------------------------
     * Countdown timer.
     *
     * Rules this implements:
     *   - the limit comes from the session row (default 30 seconds)
     *   - it can be paused and resumed
     *   - reaching zero NEVER marks the student absent; the student is
     *     left not marked, flagged for review, and the screen advances
     *   - a guard flag stops repeated callbacks from skipping students
     *   - nothing here submits a form, so the timer cannot create a
     *     duplicate or wrong attendance record
     * --------------------------------------------------------------- */
    var timerNum  = panel.querySelector('[data-timer-num]');
    var timerRing = panel.querySelector('[data-timer-ring]');
    var timerHint = panel.querySelector('[data-timer-hint]');
    var timerBtn  = panel.querySelector('[data-timer-toggle]');
    var timerBtnText = panel.querySelector('[data-timer-toggle-text]');

    var remaining = totalSeconds;
    var running   = !reduceMotion;
    var expired   = false;
    var ticker    = null;

    function paintTimer() {
        if (timerNum) {
            timerNum.textContent = String(Math.max(0, remaining));
        }

        if (timerRing) {
            var pct = totalSeconds > 0 ? Math.max(0, (remaining / totalSeconds) * 100) : 0;
            timerRing.style.setProperty('--p', pct.toFixed(1));
            timerRing.classList.toggle('is-low', pct <= 25);
        }
    }

    function stopTicker() {
        if (ticker !== null) {
            window.clearInterval(ticker);
            ticker = null;
        }
    }

    function expire() {
        /* One shot only: repeated ticks can never advance twice. */
        if (expired) {
            return;
        }

        expired = true;
        running = false;
        stopTicker();
        paintTimer();

        if (timerHint) {
            timerHint.textContent = 'Time up. This student stays not marked and is flagged for review — moving on.';
        }

        if (timerBtn) {
            timerBtn.disabled = true;
        }

        window.setTimeout(function () {
            if (nextUrl) {
                window.location.assign(nextUrl);
            }
        }, reduceMotion ? 0 : 900);
    }

    function tick() {
        if (!running || expired) {
            return;
        }

        remaining -= 1;

        if (remaining <= 0) {
            remaining = 0;
            paintTimer();
            expire();
            return;
        }

        paintTimer();
    }

    function startTicker() {
        stopTicker();

        if (!running || expired) {
            return;
        }

        ticker = window.setInterval(tick, 1000);
    }

    if (timerBtn) {
        timerBtn.addEventListener('click', function () {
            if (expired) {
                return;
            }

            running = !running;

            if (timerBtnText) {
                timerBtnText.textContent = running ? 'Pause' : 'Resume';
            }

            if (timerHint) {
                timerHint.textContent = running
                    ? 'Each student gets ' + totalSeconds + ' seconds. The timer never marks anyone absent on its own.'
                    : 'Paused. The countdown will pick up where it left off.';
            }

            if (running) {
                startTicker();
            } else {
                stopTicker();
            }
        });
    }

    paintTimer();
    startTicker();

    /* If the teacher navigates or the tab returns, do not keep counting in
       the background past the moment the page is hidden. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden && running && !expired) {
            stopTicker();
        } else if (!document.hidden && running && !expired) {
            startTicker();
        }
    });

    /* ---------------------------------------------------------------
     * Keyboard shortcuts: P / A / R / S pick a status, Left / Right move.
     * Ignored while a field or a button has focus so typing still works.
     * --------------------------------------------------------------- */
    var markForm = document.getElementById('mark-form');

    document.addEventListener('keydown', function (event) {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        var active = document.activeElement;
        var tag = active ? active.tagName.toLowerCase() : '';

        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
            return;
        }

        if (!markForm) {
            return;
        }

        var key = event.key.toLowerCase();
        var value = null;

        if (key === 'p') { value = 'present'; }
        else if (key === 'a') { value = 'absent'; }
        else if (key === 'r') { value = 'review'; }
        else if (key === 's') { value = 'not_marked'; }

        if (value) {
            var button = markForm.querySelector('button[name="status"][value="' + value + '"]');

            if (button && !button.disabled) {
                event.preventDefault();
                button.click();
            }

            return;
        }

        if (event.key === 'ArrowLeft') {
            var prev = document.querySelector('.mark-nav a[href*="pos="]');

            if (prev) {
                event.preventDefault();
                window.location.assign(prev.getAttribute('href'));
            }
        } else if (event.key === 'ArrowRight') {
            var nav = panel.querySelectorAll('.mark-nav a[href*="pos="]');

            if (nav.length > 0) {
                event.preventDefault();
                window.location.assign(nav[nav.length - 1].getAttribute('href'));
            }
        }
    });

    /* ---------------------------------------------------------------
     * Loading indicator while a mark is being saved. The form still
     * submits normally; this only gives immediate feedback and blocks a
     * second accidental double-click.
     * --------------------------------------------------------------- */
    if (markForm) {
        markForm.addEventListener('submit', function (event) {
            if (markForm.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            markForm.dataset.submitting = '1';

            var buttons = markForm.querySelectorAll('button[name="status"]');

            Array.prototype.forEach.call(buttons, function (button) {
                button.disabled = true;
            });

            if (timerBtn) {
                timerBtn.disabled = true;
            }

            panel.classList.add('is-saving');
        });
    }
})();
