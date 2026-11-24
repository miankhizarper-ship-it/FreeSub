/* ==========================================================================
   FreeSub — front-end behaviour (vanilla JS, no build step)
   - Lucide icon hydration
   - Sticky header state + scroll reveal
   - Live subdomain availability search (debounced, aborts stale requests)
   - Password visibility toggle + strength meter
   - Confirm dialogs marked with data-confirm
   ========================================================================== */
(function () {
    'use strict';

    var doc = document;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------------------------- Icons --------------------------------- */
    function renderIcons(root) {
        if (!window.lucide || typeof window.lucide.createIcons !== 'function') {
            return;
        }

        window.lucide.createIcons({
            attrs: { 'stroke-width': 1.8, 'aria-hidden': 'true' },
            root: root || doc
        });
    }

    /* ---------------------------- Header -------------------------------- */
    function initHeader() {
        var header = doc.querySelector('[data-site-header]');

        if (!header) {
            return;
        }

        var update = function () {
            header.classList.toggle('is-scrolled', window.scrollY > 8);
        };

        update();
        window.addEventListener('scroll', update, { passive: true });
    }

    /* ---------------------------- Reveal -------------------------------- */
    function initReveal() {
        var elements = doc.querySelectorAll('.reveal');

        if (!elements.length || reduceMotion || !('IntersectionObserver' in window)) {
            elements.forEach(function (el) { el.classList.add('is-visible'); });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        elements.forEach(function (el) { observer.observe(el); });
    }

    /* ---------------------------- Search -------------------------------- */
    /**
     * Wire one availability search.
     *
     * Factored out because it is used twice: the landing-page search and the
     * dashboard's reserve form. Both must debounce and abort stale requests, and
     * both must let the server — never the browser — decide availability.
     */
    function bindAvailabilitySearch(options) {
        var form = options.form;
        var input = options.input;
        var select = options.select || null;
        var results = options.results;
        var status = options.status || null;
        var endpoint = options.endpoint || 'api/check.php';
        var debounce = typeof options.debounce === 'number' ? options.debounce : 420;
        // The landing-page form is a search widget, so its submit is swallowed.
        // The dashboard form's submit button is the real reservation action and
        // must reach the server untouched.
        var interceptSubmit = options.interceptSubmit !== false;
        var timer = null;
        var controller = null;
        var lastQuery = '';

        function icon(name) {
            return '<i data-lucide="' + name + '"></i>';
        }

        function setStatus(message, tone) {
            if (!status) {
                return;
            }

            status.textContent = message || '';
            status.className = 'search-hint' + (tone ? ' text-' + tone : '');
        }

        function renderLoading() {
            results.innerHTML = '<div class="search-result-card is-neutral" role="status">' +
                '<span class="search-result-icon">' + icon('loader-circle') + '</span>' +
                '<div class="search-result-body"><div class="search-result-title">Checking availability…</div>' +
                '<div class="search-result-message">Talking to the registry, one moment.</div></div></div>';
            renderIcons(results);
        }

        function renderResult(payload) {
            // Four visual states, because they mean four different things to the
            // person reading them: free, blocked by policy/typo, taken, and
            // "we could not check" (retryable — never shown as taken, and never
            // as available).
            var state = payload.status === 'available' ? 'is-available'
                : (payload.status === 'invalid' || payload.status === 'unavailable' || payload.status === 'reserved'
                    ? 'is-neutral'
                    : (payload.status === 'unknown' ? 'is-neutral' : 'is-taken'));
            var iconName = payload.status === 'available' ? 'check-circle-2'
                : (payload.status === 'invalid' || payload.status === 'reserved' ? 'info'
                    : (payload.status === 'unknown' ? 'wifi-off' : 'x-circle'));
            var fqdn = payload.fqdn || payload.label || '';

            var html = '<div class="search-result-card ' + state + '" role="status" aria-live="polite">' +
                '<span class="search-result-icon">' + icon(iconName) + '</span>' +
                '<div class="search-result-body">' +
                '<div class="search-result-title">' + escapeHtml(fqdn) + '</div>' +
                '<div class="search-result-message">' + escapeHtml(payload.message || '') + '</div>';

            if (payload.available && payload.reserve_url) {
                html += '<div class="mt-2"><a class="btn btn-primary btn-sm" href="' + escapeAttr(payload.reserve_url) + '">' +
                    'Claim this name ' + icon('arrow-right') + '</a></div>';
            }

            if (!payload.available && Array.isArray(payload.suggestions) && payload.suggestions.length) {
                html += '<div class="search-suggestions">';
                payload.suggestions.forEach(function (suggestion) {
                    html += '<button type="button" class="chip" data-suggestion="' + escapeAttr(suggestion) + '">' +
                        icon('sparkles') + escapeHtml(suggestion) + '</button>';
                });
                html += '</div>';
            }

            html += '</div></div>';
            results.innerHTML = html;
            renderIcons(results);

            results.querySelectorAll('[data-suggestion]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    input.value = chip.getAttribute('data-suggestion');
                    input.focus();
                    run(true);
                });
            });
        }

        function renderError(message) {
            results.innerHTML = '<div class="search-result-card is-neutral" role="alert">' +
                '<span class="search-result-icon">' + icon('wifi-off') + '</span>' +
                '<div class="search-result-body"><div class="search-result-title">Search unavailable</div>' +
                '<div class="search-result-message">' + escapeHtml(message) + '</div></div></div>';
            renderIcons(results);
        }

        function run(immediate) {
            var label = (input.value || '').trim();
            var domainId = select ? select.value : '';
            var query = label + '|' + domainId;

            if (label.length < 1) {
                results.innerHTML = '';
                setStatus('Type a name to check availability.');
                lastQuery = '';
                return;
            }

            if (!immediate && query === lastQuery) {
                return;
            }

            lastQuery = query;

            if (timer) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }

                controller = ('AbortController' in window) ? new AbortController() : null;
                renderLoading();
                setStatus('');

                var url = endpoint + '?label=' + encodeURIComponent(label) + (domainId ? '&domain_id=' + encodeURIComponent(domainId) : '') + '&format=json';

                fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller ? controller.signal : undefined
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('The server responded with ' + response.status + '.');
                        }
                        return response.json();
                    })
                    .then(function (payload) { renderResult(payload); })
                    .catch(function (error) {
                        if (error && error.name === 'AbortError') {
                            return;
                        }
                        renderError(error && error.message ? error.message : 'Please try again in a moment.');
                    });
            }, immediate ? 0 : 420);
        }

        input.addEventListener('input', function () { run(false); });
        if (select) {
            select.addEventListener('change', function () { run(true); });
        }
        if (interceptSubmit) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                run(true);
            });
        }

        if (input.value.trim() !== '') {
            run(true);
        }
    }

    /** The landing-page search (larger, its own results container). */
    function initSearch() {
        var form = doc.querySelector('[data-subdomain-search]');

        if (!form) {
            return;
        }

        bindAvailabilitySearch({
            form: form,
            input: form.querySelector('input[name="label"]'),
            select: form.querySelector('select[name="domain_id"]'),
            results: doc.querySelector('[data-search-results]'),
            status: doc.querySelector('[data-search-status]'),
            endpoint: form.getAttribute('data-endpoint')
        });
    }

    /**
     * The dashboard reserve form: checks the typed label and shows the verdict
     * inline. It never enables or disables submission based on the answer — the
     * server re-checks everything on POST, so a stale "available" cannot be used
     * to book a taken name.
     */
    function initReserveSearch() {
        var form = doc.querySelector('[data-reserve-form]');

        if (!form) {
            return;
        }

        var input = form.querySelector('[data-availability-input]');
        var results = form.querySelector('[data-availability-results]');

        if (!input || !results) {
            return;
        }

        bindAvailabilitySearch({
            form: form,
            input: input,
            select: form.querySelector('[data-availability-domain]'),
            results: results,
            debounce: 350,
            interceptSubmit: false
        });
    }

    /* ---------------------------- Password ------------------------------ */
    // There is no password field in the application any more: credentials are
    // collected by Clerk's own components, which own their input behaviour.
    // The shared helpers below are kept only for admin forms that may add a
    // reveal toggle later.
    function initPasswords() {
        doc.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = doc.getElementById(button.getAttribute('data-password-toggle'));

                if (!target) {
                    return;
                }

                var show = target.type === 'password';
                target.type = show ? 'text' : 'password';
                button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                button.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>';
                renderIcons(button);
            });
        });
    }

    /* ---------------------------- Confirm ------------------------------- */
    function initConfirms() {
        doc.addEventListener('click', function (event) {
            var target = event.target.closest('[data-confirm]');

            if (!target) {
                return;
            }

            var message = target.getAttribute('data-confirm') || 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }

    /* ------------------------ Dashboard: submit ------------------------- */
    /**
     * Double-submit guard for the dashboard's mutating forms.
     *
     * This is a UX affordance only. Every one of these forms is POST + CSRF and
     * the server re-reads the state before acting, so a duplicate submission
     * (or a request replayed by hand) is still refused where it matters — the
     * guard just stops a slow network from producing "unknown action" noise.
     */
    function initBusyForms() {
        doc.querySelectorAll('form[data-busy-form]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('[data-busy-button]') || form.querySelector('button[type="submit"]');

                if (!button || button.classList.contains('is-busy')) {
                    return;
                }

                button.classList.add('is-busy');
                button.setAttribute('aria-disabled', 'true');

                // A disabled button would not submit its own name; keep it
                // enabled so the browser still sends the form, but stop a
                // second click landing another request.
                if (!button.querySelector('.btn-spinner')) {
                    var spinner = doc.createElement('span');
                    spinner.className = 'btn-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    button.insertBefore(spinner, button.firstChild);
                }
            });

            // Restoring from the back/forward cache must not leave a dead
            // button behind.
            window.addEventListener('pageshow', function () {
                form.querySelectorAll('.is-busy').forEach(function (button) {
                    button.classList.remove('is-busy');
                    button.removeAttribute('aria-disabled');
                });
            });
        });
    }

    /* --------------------------- Dashboard: copy ------------------------ */
    /**
     * Copy a hostname to the clipboard.
     *
     * Falls back to selecting the text when the Clipboard API is unavailable
     * (older Safari, insecure context), and always reports through an
     * aria-live region rather than relying on the button changing colour.
     */
    function initCopyButtons() {
        doc.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-copy') || '';
                var targetId = button.getAttribute('data-copy-status');
                var status = targetId ? doc.getElementById(targetId) : null;

                function report(message) {
                    if (status) {
                        status.textContent = message;
                    }
                }

                if (!value) {
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function () {
                        report('Copied ' + value);
                    }).catch(function () {
                        report('Could not copy — select the text to copy it.');
                    });

                    return;
                }

                report('Press and hold the hostname to copy it.');
            });
        });
    }

    /* ---------------------------- Helpers ------------------------------- */
    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#96;');
    }

    /* ---------------------------- Boot ---------------------------------- */
    function boot() {
        renderIcons();
        initHeader();
        initReveal();
        initSearch();
        initReserveSearch();
        initPasswords();
        initConfirms();
        initBusyForms();
        initCopyButtons();
    }

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
