/**
 * BLOOMINOUS - Online Payment (GCash / Maya) client helper
 *
 * Used by templates/checkout.php AFTER submit_order.php returns
 * { success: true, orderId }. This file never touches prices or marks
 * anything paid — it only asks our server for a PayMongo page and
 * sends the customer there.
 *
 * Exposes:
 *   window.bloomIsOnlinePayment(method)                -> true for gcash / maya
 *   window.bloomStartOnlinePayment({ orderId, paymentMethod, idToken })
 */
(function () {
    const ONLINE_METHODS = ['gcash', 'maya'];

    // "GCash" -> "gcash", "PayMaya" -> "maya" (mirrors PaymentHelper::normalizeMethod)
    function normalizeMethod(method) {
        const m = String(method || '').trim().toLowerCase();
        return m === 'paymaya' ? 'maya' : m;
    }

    window.bloomIsOnlinePayment = function (method) {
        return ONLINE_METHODS.includes(normalizeMethod(method));
    };

    window.bloomStartOnlinePayment = async function ({ orderId, paymentMethod, idToken } = {}) {
        const method = normalizeMethod(paymentMethod);

        if (!orderId) {
            throw new Error('Missing order reference.');
        }
        if (!ONLINE_METHODS.includes(method)) {
            throw new Error('Please choose GCash or Maya for online payment.');
        }

        // Prefer the token checkout.php already has; otherwise ask Firebase Auth.
        let token = idToken;
        if (!token && window.firebase && firebase.auth && firebase.auth().currentUser) {
            token = await firebase.auth().currentUser.getIdToken();
        }
        if (!token) {
            throw new Error('Your session expired. Please sign in again to pay.');
        }

        // checkout.php lives in /templates, the endpoint lives in the web root.
        const endpoint = window.BLOOM_PAYMENT_ENDPOINT || '../create_payment_session.php';

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ orderId: orderId, paymentMethod: method })
        });

        // A PHP fatal returns HTML, not JSON — don't let .json() crash us.
        let data = {};
        try {
            data = await response.json();
        } catch (_) {
            data = {};
        }

        if (data.code === 'ALREADY_PAID') {
            window.location.href = 'success.php?order_id=' + encodeURIComponent(orderId);
            return;
        }

        if (!response.ok || !data.success || !data.checkoutUrl) {
            throw new Error(data.message || 'Could not start your GCash/Maya payment. Please try again.');
        }

        // Leave our site for the PayMongo-hosted GCash/Maya page.
        window.location.assign(data.checkoutUrl);
    };
})();