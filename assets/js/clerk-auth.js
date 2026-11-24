/**
 * FreeSub — Clerk browser integration (vanilla JS, no build step).
 *
 * Responsibilities, in order:
 *
 *   1. Load Clerk's official browser SDK from the instance's frontend API host
 *      (the host is derived server-side from the publishable key).
 *   2. Mount Clerk's own sign-in / sign-up components. Every credential
 *      interaction — e-mail + password, Google OAuth, e-mail verification
 *      codes, password reset, MFA — is owned by Clerk. FreeSub never renders a
 *      password field.
 *   3. When Clerk reports an active session, ask it for a token minted from our
 *      `freesub_handoff` JWT template and exchange it (same-origin fetch) for a
 *      single-use handoff token from /clerk-bridge.php.
 *   4. Submit that token through a CSRF-protected form to /auth/handoff.php,
 *      which starts the PHP session.
 *
 * The page never stores tokens in localStorage, never posts a token in a URL and
 * never decides who the user is: PHP re-verifies everything.
 */
(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-clerk-auth-script]');
    var version = (script && script.getAttribute('data-clerk-js-version')) || '6';

    var root = document.querySelector('[data-clerk-auth]');

    if (!root) {
        return;
    }

    var configNode = root.querySelector('[data-clerk-config]');
    var config = {};

    try {
        config = JSON.parse(configNode ? configNode.textContent : '{}');
    } catch (error) {
        config = {};
    }

    var loading = root.querySelector('[data-clerk-loading]');
    var fallback = root.querySelector('[data-clerk-fallback]');
    var signInMount = root.querySelector('[data-clerk-sign-in]');
    var signUpMount = root.querySelector('[data-clerk-sign-up]');
    var handoffForm = root.querySelector('[data-clerk-handoff]');
    var tokenInput = root.querySelector('[data-clerk-token]');
    var continueButton = root.querySelector('[data-clerk-continue]');
    var errorBox = root.querySelector('[data-clerk-error="live"]');
    var errorText = root.querySelector('[data-clerk-error-message]');
    var retryButton = root.querySelector('[data-clerk-retry]');

    var handoffStarted = false;

    function showError(message) {
        if (!errorBox || !errorText) {
            return;
        }

        errorText.textContent = message;
        errorBox.classList.remove('d-none');

        if (typeof window.lucide !== 'undefined' && window.lucide.createIcons) {
            window.lucide.createIcons();
        }
    }

    function clearError() {
        if (errorBox) {
            errorBox.classList.add('d-none');
        }
    }

    function setLoading(isLoading) {
        if (loading) {
            loading.classList.toggle('d-none', !isLoading);
        }
    }

    function showFallback() {
        setLoading(false);

        if (fallback) {
            fallback.classList.remove('d-none');
        }
    }

    /** Build the SDK URL the same way Clerk's documented script tag does. */
    function clerkScriptUrl() {
        var host = config.frontendApi;

        if (!host) {
            return null;
        }

        return 'https://' + host + '/npm/@clerk/clerk-js@' + encodeURIComponent(version) + '/dist/clerk.browser.js';
    }

    function loadScript(url) {
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-clerk-sdk]');

            if (existing) {
                existing.addEventListener('load', function () { resolve(); });
                existing.addEventListener('error', function () { reject(new Error('clerk script failed')); });
                return;
            }

            var element = document.createElement('script');
            element.src = url;
            element.async = true;
            element.crossOrigin = 'anonymous';
            element.setAttribute('data-clerk-sdk', '1');
            element.setAttribute('data-clerk-publishable-key', config.publishableKey || '');
            element.addEventListener('load', function () { resolve(); });
            element.addEventListener('error', function () { reject(new Error('clerk script failed')); });
            document.head.appendChild(element);
        });
    }

    function bootClerk() {
        setLoading(true);
        clearError();

        if (fallback) {
            fallback.classList.add('d-none');
        }

        var url = clerkScriptUrl();

        if (!url) {
            showError('Sign-in is not configured. Please contact support.');
            showFallback();
            return Promise.resolve();
        }

        return loadScript(url)
            .then(function () {
                if (!window.Clerk) {
                    throw new Error('clerk global missing');
                }

                return window.Clerk.load({
                    signInUrl: config.signInUrl,
                    signUpUrl: config.signUpUrl
                });
            })
            .then(function () {
                mountComponents();
                attachSessionWatcher();
                setLoading(false);
            })
            .catch(function () {
                showFallback();
            });
    }

    function mountComponents() {
        var clerk = window.Clerk;

        if (!clerk) {
            return;
        }

        if (signInMount && signInMount.getAttribute('hidden') === null && !signInMount.childElementCount) {
            clerk.mountSignIn(signInMount, {
                appearance: { variables: { colorPrimary: '#0d6efd' } }
            });
        }

        if (signUpMount && signUpMount.getAttribute('hidden') === null && !signUpMount.childElementCount) {
            clerk.mountSignUp(signUpMount, {
                appearance: { variables: { colorPrimary: '#0d6efd' } }
            });
        }
    }

    /**
     * Clerk fires this for every state change: OAuth return, e-mail code
     * verification, existing session restored from its cookie, sign-out.
     */
    function attachSessionWatcher() {
        var clerk = window.Clerk;

        if (!clerk) {
            return;
        }

        clerk.addListener(function (resources) {
            var session = resources && resources.session ? resources.session : clerk.session;

            if (session && session.status === 'active') {
                beginHandoff(session);
            }
        });

        if (clerk.session && clerk.session.status === 'active') {
            beginHandoff(clerk.session);
        }
    }

    function beginHandoff(session) {
        if (handoffStarted || !handoffForm || !tokenInput) {
            return;
        }

        handoffStarted = true;

        if (continueButton) {
            continueButton.disabled = true;
        }

        session
            .getToken({ template: config.handoffTemplate || 'freesub_handoff', skipCache: true })
            .then(function (token) {
                if (!token) {
                    throw new HandoffError(
                        'template_missing',
                        'Your Clerk instance is missing the "freesub_handoff" JWT template. ' +
                        'Ask an administrator to create it, then reload this page.'
                    );
                }

                return fetch(config.bridgeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        channel: config.channel,
                        state: config.state,
                        handoffToken: token
                    })
                });
            })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'The server sent an unreadable response.' };
                });
            })
            .then(function (payload) {
                if (!payload || payload.ok !== true || !payload.token) {
                    throw new HandoffError(
                        (payload && payload.error) || 'handoff_failed',
                        (payload && payload.message) || 'Sign-in could not be completed. Please try again.'
                    );
                }

                tokenInput.value = payload.token;
                handoffForm.classList.remove('d-none');

                // Submit the CSRF-protected form: PHP performs the final checks
                // and starts the session.
                handoffForm.submit();
            })
            .catch(function (error) {
                handoffStarted = false;

                if (continueButton) {
                    continueButton.disabled = false;
                }

                showError(error && error.message ? error.message : 'Sign-in could not be completed. Please try again.');
            });
    }

    function HandoffError(code, message) {
        this.code = code;
        this.message = message;
    }

    HandoffError.prototype = Object.create(Error.prototype);

    if (retryButton) {
        retryButton.addEventListener('click', function () {
            window.location.reload();
        });
    }

    // Signing out must clear the Clerk session too, otherwise the next visit
    // would silently sign the browser back in. /logout.php lands here with
    // ?signed_out=1 after the PHP session has already been destroyed.
    var parameters = new URLSearchParams(window.location.search);

    if (parameters.get('signed_out') === '1') {
        loadScript(clerkScriptUrl())
            .then(function () {
                if (window.Clerk) {
                    return window.Clerk.load({ signInUrl: config.signInUrl, signUpUrl: config.signUpUrl });
                }

                return null;
            })
            .then(function () {
                if (window.Clerk && window.Clerk.session) {
                    return window.Clerk.signOut();
                }

                return null;
            })
            .catch(function () {
                // Nothing to do: the PHP session is already gone.
            });

        return;
    }

    bootClerk();
})();
