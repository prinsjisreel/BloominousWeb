/**
 * BLOOMINOUS - Restriction notice + appeal (website checkout)
 *
 * A restricted customer cannot order with ANY payment method. This script
 * shows them why, and gives them a way back: a clickable "Appeal this
 * restriction" link ABOVE the restriction message. The link opens a small
 * window where they write their appeal. The server (fraud_appeal.php) saves
 * it, notifies the super admin, and an admin decides in the Fraud Activity
 * Log. The server also refuses restricted orders by itself (submit_order.php),
 * so this script only EXPLAINS and offers the appeal; it is not the lock.
 *
 * HOW TO USE IT on templates/checkout.php (three small steps):
 *
 *   1. An empty box where the notice should appear, near the top:
 *        <div id="restrictionNotice"></div>
 *
 *   2. Load the script (after Firebase is loaded and the customer is signed in):
 *        <script src="../assets/script/restriction_appeal.js"></script>
 *
 *   3. Start it once the page is ready. lockSelectors = the payment options
 *      and the place-order button, which are greyed out while restricted:
 *        BloomAppeal.init({
 *          mount: '#restrictionNotice',
 *          lockSelectors: ['#placeOrderBtn', 'input[name="paymentMethod"]']
 *        });
 *
 *   And where checkout.php handles the reply of submit_order.php:
 *        if (result.code === 'RESTRICTED') {
 *          BloomAppeal.showRestriction(result.message);
 *          return;
 *        }
 *
 * The endpoint address is worked out from this script's own location
 * (assets/script/ -> fraud_appeal.php in the site root), so it works from any
 * page folder. Everything the customer or the server types is inserted with
 * textContent, never as HTML.
 */
(function () {
    'use strict';

    // assets/script/restriction_appeal.js -> ../../fraud_appeal.php
    var ENDPOINT = (function () {
        try {
            var src = document.currentScript && document.currentScript.src;
            return src ? new URL('../../fraud_appeal.php', src).href : 'fraud_appeal.php';
        } catch (e) {
            return 'fraud_appeal.php';
        }
    })();

    var BRAND = '#ec4899';

    var state = {
        mount: null,
        lockSelectors: [],
        message: null,
        status: null,
        onStatus: null
    };

    /* =====================================================================
     * 1. SMALL HELPERS
     * =================================================================== */

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        }
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function formatDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function injectStyles() {
        if (document.getElementById('bloomAppealStyles')) return;
        var css = [
            '.bloom-appeal-notice{background:#fef2f2;border:1px solid #fecaca;border-radius:16px;padding:14px 16px;margin:0 0 16px;font-family:inherit;color:#1f2937}',
            '.bloom-appeal-title{font-size:14px;font-weight:900;color:#b91c1c;margin:0 0 8px}',
            '.bloom-appeal-link{display:inline-block;font-size:14px;font-weight:800;color:' + BRAND + ';text-decoration:underline;cursor:pointer;background:none;border:none;padding:4px 0;font-family:inherit}',
            '.bloom-appeal-status{font-size:13px;font-weight:700;margin:0 0 4px}',
            '.bloom-appeal-small{font-size:12px;color:#6b7280;margin:0 0 4px}',
            '.bloom-appeal-message{font-size:13px;line-height:1.5;margin:6px 0 0}',
            '.bloom-appeal-overlay{position:fixed;inset:0;background:rgba(20,20,20,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px}',
            '.bloom-appeal-modal{background:#fff;color:#1f2937;border-radius:20px;padding:22px;max-width:480px;width:100%;box-shadow:0 30px 60px rgba(0,0,0,.25);font-family:inherit}',
            '.bloom-appeal-modal h3{font-size:18px;font-weight:900;margin:0 0 6px}',
            '.bloom-appeal-modal p{font-size:13px;color:#6b7280;line-height:1.5;margin:0 0 12px}',
            '.bloom-appeal-modal textarea{width:100%;min-height:130px;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;font-size:14px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box}',
            '.bloom-appeal-modal textarea:focus{border-color:#f9a8d4}',
            '.bloom-appeal-count{font-size:11px;color:#9ca3af;text-align:right;margin:4px 0 8px}',
            '.bloom-appeal-error{font-size:12px;font-weight:700;color:#b91c1c;min-height:1em;margin:0 0 8px}',
            '.bloom-appeal-actions{display:flex;gap:8px;justify-content:flex-end}',
            '.bloom-appeal-btn{border:none;border-radius:999px;padding:10px 18px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit}',
            '.bloom-appeal-btn.primary{background:' + BRAND + ';color:#fff}',
            '.bloom-appeal-btn.ghost{background:#f3f4f6;color:#374151}',
            '.bloom-appeal-btn:disabled{opacity:.45;cursor:not-allowed}',
            '.bloom-locked{opacity:.5;cursor:not-allowed}'
        ].join('');
        var style = el('style', { id: 'bloomAppealStyles' });
        style.textContent = css;
        document.head.appendChild(style);
    }

    /* =====================================================================
     * 2. TALKING TO THE SERVER (fraud_appeal.php)
     * =================================================================== */

    // Waits until Firebase knows who is signed in (it may still be loading
    // when the page first runs), then resolves with the user, or null.
    function waitForUser() {
        return new Promise(function (resolve) {
            if (typeof firebase === 'undefined' || !firebase.auth) { resolve(null); return; }
            var current = firebase.auth().currentUser;
            if (current) { resolve(current); return; }
            var unsubscribe = firebase.auth().onAuthStateChanged(function (user) {
                unsubscribe();
                resolve(user);
            });
        });
    }

    function appealError(message, code, extra) {
        var err = new Error(message);
        err.code = code || null;
        err.extra = extra || {};
        return err;
    }

    // POSTs to fraud_appeal.php with the customer's Firebase ID token.
    // Resolves with the JSON body; throws an Error with .code otherwise.
    async function call(payload) {
        var user = await waitForUser();
        if (!user) throw appealError('Please sign in to continue.', 'NO_SESSION');
        var token = await user.getIdToken();

        var response;
        try {
            response = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify(payload)
            });
        } catch (e) {
            throw appealError('Could not reach the server. Please check your connection and try again.', 'NETWORK');
        }

        var data;
        try {
            data = await response.json();
        } catch (e) {
            throw appealError('The server returned an unexpected response. Please try again.', 'BAD_RESPONSE');
        }

        if (!data || data.success !== true) {
            throw appealError((data && data.message) || 'The request could not be completed.', data && data.code, data || {});
        }
        return data;
    }

    function getStatus() {
        return call({ action: 'status' });
    }

    /* =====================================================================
     * 3. LOCKING THE PAYMENT OPTIONS (cosmetic: the server is the real lock)
     * =================================================================== */

    function setLocked(locked) {
        state.lockSelectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (node) {
                if (locked) {
                    if (node.dataset.bloomWasDisabled === undefined) {
                        node.dataset.bloomWasDisabled = node.disabled ? '1' : '0';
                    }
                    node.disabled = true;
                    node.setAttribute('aria-disabled', 'true');
                    node.classList.add('bloom-locked');
                } else if (node.dataset.bloomWasDisabled !== undefined) {
                    node.disabled = node.dataset.bloomWasDisabled === '1';
                    delete node.dataset.bloomWasDisabled;
                    node.removeAttribute('aria-disabled');
                    node.classList.remove('bloom-locked');
                }
            });
        });
    }

    /* =====================================================================
     * 4. THE NOTICE (appeal link ABOVE the message)
     * =================================================================== */

    function render() {
        var status = state.status;
        var mount = state.mount;
        if (!mount) return;
        mount.textContent = '';

        if (!status || !status.restricted) {
            setLocked(false);
            return;
        }
        setLocked(true);

        var box = el('div', { 'class': 'bloom-appeal-notice', role: 'alert' });
        box.appendChild(el('p', { 'class': 'bloom-appeal-title' }, 'Account restricted'));

        // ---- the appeal line, ABOVE the message ----
        var appeal = status.appeal || null;
        if (status.blockedBy === 'pending') {
            box.appendChild(el('p', { 'class': 'bloom-appeal-status' }, 'Appeal sent. Waiting for an admin to review it.'));
        } else {
            if (appeal && appeal.status === 'denied') {
                var when = status.blockedBy === 'cooldown' && status.canAppealAt
                    ? ' You can appeal again on ' + formatDate(status.canAppealAt) + '.'
                    : '';
                box.appendChild(el('p', { 'class': 'bloom-appeal-small' }, 'Your last appeal was not approved.' + when));
            }
            if (status.blockedBy !== 'cooldown') {
                var link = el('button', { type: 'button', 'class': 'bloom-appeal-link' }, 'Appeal this restriction');
                link.addEventListener('click', function () { openModal(status); });
                box.appendChild(link);
            }
        }

        // ---- the restriction message ----
        var until = status.restrictedUntil ? ' until ' + formatDate(status.restrictedUntil) : '';
        var message = state.message ||
            ('Your account is currently restricted, so ordering is paused for all payment methods' + until +
             '. You can send an appeal for an admin to review.');
        box.appendChild(el('p', { 'class': 'bloom-appeal-message' }, message));

        mount.appendChild(box);
    }

    /* =====================================================================
     * 5. THE APPEAL WINDOW
     * =================================================================== */

    function openModal(status) {
        if (document.getElementById('bloomAppealOverlay')) return;

        var min = status.messageMinChars || 20;
        var max = status.messageMaxChars || 1000;

        var overlay = el('div', { 'class': 'bloom-appeal-overlay', id: 'bloomAppealOverlay' });
        var modal = el('div', { 'class': 'bloom-appeal-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bloomAppealHeading' });
        modal.appendChild(el('h3', { id: 'bloomAppealHeading' }, 'Appeal this restriction'));
        modal.appendChild(el('p', null, 'Tell us what happened and why your account should be unrestricted. An admin will read it and decide.'));

        var textarea = el('textarea', { maxlength: String(max), placeholder: 'Write your appeal here (at least ' + min + ' characters)...' });
        var count = el('div', { 'class': 'bloom-appeal-count' }, '0 / ' + max + ' (min ' + min + ')');
        var error = el('div', { 'class': 'bloom-appeal-error' });
        var actions = el('div', { 'class': 'bloom-appeal-actions' });
        var cancel = el('button', { type: 'button', 'class': 'bloom-appeal-btn ghost' }, 'Cancel');
        var send = el('button', { type: 'button', 'class': 'bloom-appeal-btn primary' }, 'Send appeal');
        send.disabled = true;
        actions.appendChild(cancel);
        actions.appendChild(send);

        [textarea, count, error, actions].forEach(function (n) { modal.appendChild(n); });
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        textarea.focus();

        var busy = false;

        function close() {
            if (busy) return;
            document.removeEventListener('keydown', onKey);
            overlay.remove();
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        cancel.addEventListener('click', close);

        function refreshControls() {
            var length = textarea.value.trim().length;
            count.textContent = length + ' / ' + max + (length < min ? ' (min ' + min + ')' : '');
            send.disabled = busy || length < min || length > max;
        }
        textarea.addEventListener('input', refreshControls);

        send.addEventListener('click', async function () {
            if (send.disabled) return;
            busy = true;
            error.textContent = '';
            send.textContent = 'Sending...';
            refreshControls();
            try {
                await call({ action: 'submit', message: textarea.value.trim() });
                busy = false;
                close();
                await refresh(); // the notice now says "Appeal sent..."
            } catch (err) {
                busy = false;
                send.textContent = 'Send appeal';
                // "Already sent" is not a failure for the customer.
                if (err.code === 'ALREADY_PENDING') {
                    close();
                    await refresh();
                    return;
                }
                error.textContent = err.message;
                refreshControls();
            }
        });
    }

    /* =====================================================================
     * 6. PUBLIC API
     * =================================================================== */

    // Reads the status from the server and redraws the notice + lock.
    async function refresh() {
        try {
            state.status = await getStatus();
        } catch (err) {
            // Could not load (offline, signed out...): leave the page as it
            // is. The server still refuses restricted orders.
            if (window.console) console.warn('BloomAppeal: could not load status:', err.message);
            return null;
        }
        render();
        if (typeof state.onStatus === 'function') state.onStatus(state.status);
        return state.status;
    }

    window.BloomAppeal = {
        /**
         * Start the notice. Options:
         *   mount         CSS selector or element where the notice is drawn
         *   lockSelectors CSS selectors of the payment options + order button
         *   message       optional text to show under the appeal link
         *   onStatus      optional function(status) called after every refresh
         */
        init: function (options) {
            options = options || {};
            injectStyles();
            state.mount = typeof options.mount === 'string' ? document.querySelector(options.mount) : options.mount;
            state.lockSelectors = Array.isArray(options.lockSelectors) ? options.lockSelectors : [];
            state.message = options.message || null;
            state.onStatus = options.onStatus || null;
            return refresh();
        },

        /** Call when submit_order.php answers RESTRICTED. */
        showRestriction: function (message) {
            if (message) state.message = String(message);
            return refresh();
        },

        refresh: refresh,
        getStatus: getStatus
    };
})();