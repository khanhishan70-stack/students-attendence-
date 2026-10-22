(function () {
    'use strict';

    document.documentElement.classList.add('js-ready');

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
})();
