/**
 * BLOOMINOUS - Sales Anomalies Detection Engine
 *
 * This is the shared "brain" behind the Sales Anomalies Detection module.
 * It does NOT decide what happens when something is flagged (block the
 * sale? just log it?) — that decision lives in whichever screen calls it
 * (pos_terminal.php for new sales, order_details.php for voids/refunds).
 * This file only answers: "is this specific action unusual, and how bad?"
 *
 * Every check function returns a plain object shaped like:
 *   { flagged: false }                                        // nothing wrong
 *   { flagged: true, type, severity, detail }                 // something wrong
 * severity is always one of: 'low' | 'medium' | 'critical'
 * (this mirrors the three risk tiers from the spec doc).
 */
window.SalesAnomalies = (function () {

    // Fallback thresholds used ONLY if nothing is configured yet in
    // Firestore at settings/anomaly_config. An admin can override every
    // one of these live from the Sales Anomalies dashboard — no redeploy
    // needed.
    const DEFAULT_CONFIG = {
        avgMultiplier: 5,              // txn >= 5x the branch's recent average -> medium
        criticalMultiplier: 10,        // txn >= 10x the branch's recent average -> critical
        minBaselineTransactions: 5,    // need at least this many past sales to trust "the average"
        fallbackHighValueThreshold: 5000, // used only when there isn't enough history yet
        voidWindowHours: 24,           // rolling window for counting one cashier's voids/refunds
        voidCountMedium: 2,            // >= this many voids in the window -> medium
        voidCountCritical: 4,          // >= this many voids in the window -> critical
        discountMediumPercent: 15,     // manual discount % at/above this -> medium
        discountCriticalPercent: 30,   // manual discount % at/above this -> critical
        storeOpenTime: '08:00',        // "HH:MM", 24h format under the hood — the UI shows a normal time picker
        storeCloseTime: '20:00'
    };

    const SEVERITY_RANK = { low: 1, medium: 2, critical: 3 };

    let cachedConfig = null;

    /**
     * Pulls settings/anomaly_config from Firestore once per page load and
     * caches it. Missing fields fall back to DEFAULT_CONFIG individually,
     * so a partially-filled config doc is fine.
     */
    async function getConfig() {
        if (cachedConfig) return cachedConfig;
        try {
            const doc = await db.collection('settings').doc('anomaly_config').get();
            cachedConfig = doc.exists ? Object.assign({}, DEFAULT_CONFIG, doc.data()) : Object.assign({}, DEFAULT_CONFIG);
        } catch (e) {
            console.warn('SalesAnomalies: could not load anomaly_config, using defaults.', e);
            cachedConfig = Object.assign({}, DEFAULT_CONFIG);
        }
        return cachedConfig;
    }

    // Lets the dashboard force a fresh read after saving new thresholds.
    function invalidateConfigCache() {
        cachedConfig = null;
    }

    /* ------------------------------------------------------------------ *
     * CHECK 1 — Statistical Threshold Spike
     * "a single walk-in order totaling ₱50,000 when the average is ₱1,500"
     * ------------------------------------------------------------------ */
    async function checkValueSpike(branchId, transactionTotal) {
        try {
            const cfg = await getConfig();

            // IMPORTANT: this query intentionally uses only ONE equality
            // filter (branchId) plus orderBy on a different field
            // (createdAt). That combination is covered by Firestore's
            // automatic single-field indexes.
            //
            // An earlier version also filtered .where('type','==','POS'),
            // which turns this into TWO equality filters + orderBy — and
            // Firestore requires a manually-created composite index for
            // that combination (that's the "query requires an index"
            // error). Rather than making every deployment go create one
            // in the Firebase console, we fetch a slightly larger batch
            // and filter for POS sales client-side instead.
            const snap = await db.collection('orders')
                .where('branchId', '==', branchId)
                .orderBy('createdAt', 'desc')
                .limit(80)
                .get();

            const totals = [];
            snap.forEach(d => {
                const data = d.data();
                if (data.type !== 'POS') return;
                const v = data.total_amount;
                if (typeof v === 'number' && v > 0) totals.push(v);
            });

            // Not enough history to compute a trustworthy average yet (e.g. a
            // brand-new branch). Fall back to a flat peso threshold instead of
            // silently skipping the check.
            if (totals.length < cfg.minBaselineTransactions) {
                if (transactionTotal >= cfg.fallbackHighValueThreshold) {
                    return {
                        flagged: true,
                        type: 'value_spike',
                        severity: 'medium',
                        detail: `Transaction ₱${transactionTotal.toFixed(2)} exceeds the flat baseline threshold of ₱${cfg.fallbackHighValueThreshold.toFixed(2)} (only ${totals.length} past sales on record, not enough to compute a branch average yet).`
                    };
                }
                return { flagged: false };
            }

            const avg = totals.reduce((a, b) => a + b, 0) / totals.length;
            const ratio = avg > 0 ? transactionTotal / avg : Infinity;

            if (ratio >= cfg.criticalMultiplier) {
                return {
                    flagged: true,
                    type: 'value_spike',
                    severity: 'critical',
                    detail: `Transaction ₱${transactionTotal.toFixed(2)} is ${ratio.toFixed(1)}x this branch's recent average ticket (₱${avg.toFixed(2)}).`
                };
            }
            if (ratio >= cfg.avgMultiplier) {
                return {
                    flagged: true,
                    type: 'value_spike',
                    severity: 'medium',
                    detail: `Transaction ₱${transactionTotal.toFixed(2)} is ${ratio.toFixed(1)}x this branch's recent average ticket (₱${avg.toFixed(2)}).`
                };
            }
            return { flagged: false };
        } catch (e) {
            // Fail OPEN, not closed: a broken index/permission/network
            // hiccup in the anomaly checker must never be able to block a
            // legitimate cash-register sale. Log it so it's visible in
            // devtools, but let the transaction proceed unflagged.
            console.warn('SalesAnomalies.checkValueSpike failed, skipping this check.', e);
            return { flagged: false };
        }
    }

    /* ------------------------------------------------------------------ *
     * CHECK 2 — Off-Hours Activity
     * ------------------------------------------------------------------ */
    async function checkOffHours() {
        try {
            const cfg = await getConfig();
            const now = new Date();

            // Convert "HH:MM" strings and the current time into
            // minutes-since-midnight so we can compare them as plain
            // numbers, down to the minute (not just the hour).
            const [openH, openM] = cfg.storeOpenTime.split(':').map(Number);
            const [closeH, closeM] = cfg.storeCloseTime.split(':').map(Number);
            const openMinutes = openH * 60 + openM;
            const closeMinutes = closeH * 60 + closeM;
            const nowMinutes = now.getHours() * 60 + now.getMinutes();

            // Assumes a same-day schedule (e.g. 8:00 AM - 8:00 PM), which
            // covers every normal retail case. Overnight hours (open
            // before midnight, close after) aren't handled here.
            const isOffHours = nowMinutes < openMinutes || nowMinutes >= closeMinutes;

            if (isOffHours) {
                return {
                    flagged: true,
                    type: 'off_hours',
                    // Off-hours alone is Low risk per the spec — it's logged
                    // silently, not blocked, unless it stacks with something
                    // worse (handled by worstSeverity()).
                    severity: 'low',
                    detail: `Recorded at ${now.toLocaleTimeString()}, outside configured store hours (${cfg.storeOpenTime}–${cfg.storeCloseTime}).`
                };
            }
            return { flagged: false };
        } catch (e) {
            console.warn('SalesAnomalies.checkOffHours failed, skipping this check.', e);
            return { flagged: false };
        }
    }

    /* ------------------------------------------------------------------ *
     * CHECK 3 — Excessive Discounts
     * ------------------------------------------------------------------ */
    async function checkDiscount(discountPercent) {
        try {
            if (!discountPercent || discountPercent <= 0) return { flagged: false };
            const cfg = await getConfig();

            if (discountPercent >= cfg.discountCriticalPercent) {
                return {
                    flagged: true,
                    type: 'excessive_discount',
                    severity: 'critical',
                    detail: `Manual discount of ${discountPercent}% exceeds the critical threshold (${cfg.discountCriticalPercent}%).`
                };
            }
            if (discountPercent >= cfg.discountMediumPercent) {
                return {
                    flagged: true,
                    type: 'excessive_discount',
                    severity: 'medium',
                    detail: `Manual discount of ${discountPercent}% exceeds the authorized threshold (${cfg.discountMediumPercent}%).`
                };
            }
            return { flagged: false };
        } catch (e) {
            console.warn('SalesAnomalies.checkDiscount failed, skipping this check.', e);
            return { flagged: false };
        }
    }

    /* ------------------------------------------------------------------ *
     * CHECK 4 — Frequent Void/Cancellation Spikes
     * Counts how many voids/refunds THIS staff account has initiated in
     * the rolling window, independent of which order is being voided now.
     * ------------------------------------------------------------------ */
    async function checkVoidFrequency(cashierEmail) {
        try {
            if (!cashierEmail) return { flagged: false };
            const cfg = await getConfig();
            const since = new Date(Date.now() - cfg.voidWindowHours * 3600 * 1000);

            // Same reasoning as checkValueSpike: use only ONE equality
            // filter (initiatedByEmail) + orderBy on a different field
            // (timestamp) so this stays covered by Firestore's automatic
            // indexes. The old version added a second condition
            // (.where('timestamp','>=',since)) which combines an equality
            // filter with a range filter on a different field — Firestore
            // requires a composite index for that too. We filter the
            // window client-side instead.
            const snap = await db.collection('voidRefunds')
                .where('initiatedByEmail', '==', cashierEmail)
                .orderBy('timestamp', 'desc')
                .limit(50)
                .get();

            let count = 0;
            snap.forEach(d => {
                const ts = d.data().timestamp;
                if (ts && ts.toDate && ts.toDate() >= since) count++;
            });

            if (count >= cfg.voidCountCritical) {
                return {
                    flagged: true,
                    type: 'void_spike',
                    severity: 'critical',
                    count,
                    detail: `${count} voids/refunds initiated by this account in the last ${cfg.voidWindowHours}h (critical limit: ${cfg.voidCountCritical}).`
                };
            }
            if (count >= cfg.voidCountMedium) {
                return {
                    flagged: true,
                    type: 'void_spike',
                    severity: 'medium',
                    count,
                    detail: `${count} voids/refunds initiated by this account in the last ${cfg.voidWindowHours}h (review limit: ${cfg.voidCountMedium}).`
                };
            }
            return { flagged: false, count };
        } catch (e) {
            console.warn('SalesAnomalies.checkVoidFrequency failed, skipping this check.', e);
            return { flagged: false };
        }
    }

    /**
     * Given an array of check results (some flagged, some not), returns
     * the single worst severity among the flagged ones, or null if none
     * were flagged. This is what actually decides which gate the cashier
     * sees: null -> proceed, 'low' -> silent log, 'medium' -> justification
     * note required, 'critical' -> manager PIN required.
     */
    function worstSeverity(results) {
        let worst = null;
        results.forEach(r => {
            if (r.flagged && (!worst || SEVERITY_RANK[r.severity] > SEVERITY_RANK[worst])) {
                worst = r.severity;
            }
        });
        return worst;
    }

    /**
     * Writes one Firestore document per triggered anomaly to the
     * `salesAnomalies` collection. This collection is what the dashboard
     * reads — nothing here ever mutates the underlying order/void record.
     */
    async function logAnomaly(opts) {
        return db.collection('salesAnomalies').add({
            type: opts.type,
            severity: opts.severity,
            detail: opts.detail,
            branchId: opts.branchId || window.currentBranch || null,
            cashierEmail: opts.cashierEmail || window.currentUserEmail || null,
            cashierName: opts.cashierName || window.currentUserName || null,
            orderId: opts.orderId || null,
            invoiceId: opts.invoiceId || null,
            justificationNote: opts.justificationNote || null,
            overriddenBy: opts.overriddenBy || null,
            status: opts.overriddenBy ? 'overridden' : (opts.justificationNote ? 'justified' : 'logged'),
            timestamp: firebase.firestore.FieldValue.serverTimestamp()
        });
    }

    /**
     * Confirms a manager's email+PIN against the `users` collection.
     * This is the exact same check order_details.php already used for its
     * Void/Refund approval gate — pulled out here so both the POS Critical
     * gate and the Void frequency Critical gate share one implementation
     * instead of two copies that could drift apart.
     * Returns the normalized manager email on success, or null on failure.
     */
    async function verifyManagerPin(email, pin) {
        if (!email || !pin) return null;
        const normalizedEmail = email.trim().toLowerCase();
        const snap = await db.collection('users')
            .where('email', '==', normalizedEmail)
            .where('role', 'in', ['admin', 'super-admin'])
            .get();
        const match = snap.docs.find(d => d.data().password === pin);
        return match ? normalizedEmail : null;
    }

    return {
        getConfig,
        invalidateConfigCache,
        checkValueSpike,
        checkOffHours,
        checkDiscount,
        checkVoidFrequency,
        worstSeverity,
        logAnomaly,
        verifyManagerPin,
        DEFAULT_CONFIG
    };
})();