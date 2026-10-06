/**
 * BLOOMINOUS - Guest Gate (shared storefront helper)
 *
 * Guests may BROWSE freely, but account-only actions (add to cart,
 * open cart/checkout, view My Orders) send them to the login page first.
 * Before leaving, we remember WHAT they were trying to do (the "intent"),
 * so shop.php can finish that action automatically right after login.
 *
 * Follows the same pattern as device_fingerprint.js: plain script,
 * no dependencies, exposes functions on `window`.
 *
 *   window.bloomShowToast(message)          -> small floating notice
 *   window.bloomRequireLogin(intent, msg)   -> save intent, notify, go to login
 *   window.bloomConsumeLoginIntent()        -> read + delete saved intent (or null)
 */
(function () {
    'use strict';

    // sessionStorage key where the pending action is remembered.
    const INTENT_KEY = 'bloom_login_intent';

    // Old intents are ignored, so a guest who wandered off for an hour
    // doesn't get a surprise item in their cart later.
    const INTENT_MAX_AGE_MS = 15 * 60 * 1000; // 15 minutes

    // Delay before redirecting, so the guest can read the toast.
    const REDIRECT_DELAY_MS = 1200;

    // Login page path, relative to /templates/ (where shop.php and
    // landing_page.php live). A page can override it by setting
    // window.BLOOM_LOGIN_URL before calling bloomRequireLogin().
    function getLoginUrl() {
        return window.BLOOM_LOGIN_URL || '../index.php';
    }

    // Small floating message at the bottom of the screen.
    // Inline styles on purpose: this helper must look the same on
    // every page, whatever CSS that page happens to load.
    window.bloomShowToast = function (message) {
        const old = document.getElementById('bloom-gate-toast');
        if (old) old.remove();

        const toast = document.createElement('div');
        toast.id = 'bloom-gate-toast';
        toast.setAttribute('role', 'status');
        toast.textContent = message; // textContent = never treated as HTML
        toast.style.cssText = [
            'position:fixed',
            'left:50%',
            'bottom:32px',
            'transform:translateX(-50%)',
            'background:#363949',
            'color:#fff',
            'padding:14px 26px',
            'border-radius:999px',
            'font-family:Poppins, sans-serif',
            'font-size:13px',
            'font-weight:700',
            'box-shadow:0 15px 35px rgba(0,0,0,0.2)',
            'z-index:9999',
            'max-width:90vw',
            'text-align:center'
        ].join(';');

        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    };

    // Called when a guest tries an account-only action.
    // intent: e.g. { action: 'add_to_cart', productId: 'abc', productName: 'Rose Box' }
    //         or { action: 'view_orders' }, or null to just send them to login.
    window.bloomRequireLogin = function (intent, message) {
        if (intent && typeof intent === 'object') {
            try {
                const record = Object.assign({}, intent, { savedAt: Date.now() });
                sessionStorage.setItem(INTENT_KEY, JSON.stringify(record));
            } catch (e) {
                // Private mode / storage full: login still works, we just
                // can't resume the action afterwards.
                console.warn('Could not save login intent:', e);
            }
        }

        window.bloomShowToast(message || 'Please log in to continue.');
        setTimeout(() => {
            window.location.href = getLoginUrl();
        }, REDIRECT_DELAY_MS);
    };

    // Called by shop.php after login. Returns the saved intent ONCE,
    // then deletes it, so it can never run twice.
    window.bloomConsumeLoginIntent = function () {
        let raw = null;
        try {
            raw = sessionStorage.getItem(INTENT_KEY);
            sessionStorage.removeItem(INTENT_KEY);
        } catch (e) {
            return null;
        }

        if (!raw) return null;

        try {
            const intent = JSON.parse(raw);
            if (!intent || typeof intent.action !== 'string') return null;
            if (!intent.savedAt || Date.now() - intent.savedAt > INTENT_MAX_AGE_MS) return null;
            return intent;
        } catch (e) {
            return null; // corrupted value: ignore it
        }
    };
})();