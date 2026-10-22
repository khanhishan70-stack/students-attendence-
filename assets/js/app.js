(function () {
    'use strict';

    document.documentElement.classList.add('js-ready');

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------------------------------------------------------------
     * Landing page: sticky header state and section highlighting
     * --------------------------------------------------------------- */
    var header = document.querySelector('.site-header');
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('.site-nav a'));
    var sections = navLinks
        .map(function (link) {
            var id = link.getAttribute('href');
            return id && id.charAt(0) === '#' ? document.querySelector(id) : null;
        })
        .filter(Boolean);

    function updateHeader() {
        if (!header) {
            return;
        }
        header.classList.toggle('is-scrolled', window.scrollY > 8);
    }

    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });

    function setActive(id) {
        navLinks.forEach(function (link) {
            link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
        });
    }

    if ('IntersectionObserver' in window && sections.length > 0) {
        var visible = new Map();

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    visible.set(entry.target.id, entry.isIntersecting ? entry.intersectionRatio : 0);
                });

                var bestId = null;
                var bestRatio = 0;

                visible.forEach(function (ratio, id) {
                    if (ratio > bestRatio) {
                        bestRatio = ratio;
                        bestId = id;
                    }
                });

                if (bestId) {
                    setActive(bestId);
                }
            },
            {
                rootMargin: '-30% 0px -45% 0px',
                threshold: [0, 0.15, 0.35, 0.6, 0.9]
            }
        );

        sections.forEach(function (section) {
            observer.observe(section);
        });
    }

    /* ---------------------------------------------------------------
     * Phase 3: mobile navigation drawer
     * --------------------------------------------------------------- */
    var drawer = document.querySelector('.app-sidebar');
    var toggle = document.querySelector('[data-nav-toggle]');
    var closers = Array.prototype.slice.call(document.querySelectorAll('[data-nav-close]'));
    var drawerOpen = false;

    function focusableInDrawer() {
        if (!drawer) {
            return [];
        }
        return Array.prototype.slice.call(
            drawer.querySelectorAll('a[href], button:not([disabled])')
        ).filter(function (element) {
            return element.offsetParent !== null;
        });
    }

    function openDrawer() {
        if (!drawer || !toggle || drawerOpen) {
            return;
        }
        drawerOpen = true;
        document.body.classList.add('nav-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close navigation menu');

        var first = focusableInDrawer()[0];
        if (first) {
            first.focus();
        }
    }

    function closeDrawer(returnFocus) {
        if (!drawer || !toggle || !drawerOpen) {
            return;
        }
        drawerOpen = false;
        document.body.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation menu');

        if (returnFocus !== false) {
            toggle.focus();
        }
    }

    if (drawer && toggle) {
        toggle.addEventListener('click', function () {
            if (drawerOpen) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });

        closers.forEach(function (closer) {
            closer.addEventListener('click', function () {
                closeDrawer(false);
            });
        });

        drawer.addEventListener('click', function (event) {
            if (event.target.closest('a[href]')) {
                closeDrawer(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!drawerOpen) {
                return;
            }

            if (event.key === 'Escape') {
                closeDrawer();
                return;
            }

            if (event.key === 'Tab') {
                var items = focusableInDrawer();
                if (items.length === 0) {
                    return;
                }

                var first = items[0];
                var last = items[items.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1024 && drawerOpen) {
                closeDrawer(false);
            }
        });
    }

    /* ---------------------------------------------------------------
     * Phase 3: restrained count-up for the dashboard statistic cards
     * --------------------------------------------------------------- */
    function formatNumber(value, decimals) {
        return Number(value).toFixed(decimals);
    }

    function decimalsOf(text) {
        var dot = text.indexOf('.');
        return dot === -1 ? 0 : text.length - dot - 1;
    }

    var counters = Array.prototype.slice.call(document.querySelectorAll('[data-count]'));

    counters.forEach(function (element) {
        var raw = element.getAttribute('data-count');

        if (raw === '' || raw === null) {
            return;
        }

        var target = parseFloat(raw);
        var suffix = element.getAttribute('data-suffix') || '';
        var original = element.textContent.trim();
        var decimals = decimalsOf(original);

        if (isNaN(target) || reduceMotion) {
            element.textContent = formatNumber(target, decimals) + suffix;
            return;
        }

        var duration = 650;
        var start = null;

        function step(timestamp) {
            if (start === null) {
                start = timestamp;
            }

            var progress = Math.min((timestamp - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            element.textContent = formatNumber(target * eased, decimals) + suffix;

            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                element.textContent = formatNumber(target, decimals) + suffix;
            }
        }

        element.textContent = formatNumber(0, decimals) + suffix;
        window.requestAnimationFrame(step);
    });
})();
