<?php
/**
 * BLOOMINOUS - Fraud Activity Helpers (shared)
 *
 * Every check records a fraud ACTIVITY (category + code + reason). On top
 * of those activities this file derives two TRIAGE values:
 *
 *   riskLevel  (critical / high / medium / low)
 *       Rule-based, no invented weights:
 *         critical = hard evidence (banned device)
 *         high     = activities in 2 or more different categories
 *         medium   = activities in exactly 1 category
 *         low      = no activities
 *
 *   riskScore  (0-100 points)
 *       Each activity code is placed in an evidence tier; each tier has a
 *       fixed number of points. The tier placement is argued from the
 *       research basis; the point VALUES per tier are DESIGN PARAMETERS
 *       (initial, to be recalibrated once real outcomes exist).
 *
 * IMPORTANT: neither value restricts anyone. They only decide the ORDER
 * in which admins review accounts and how loud the alert is.
 *
 * This file also holds two pure helpers (no database calls):
 *   - bloom_restriction_state():     restrictions last at most 30 days
 *   - bloom_build_account_history(): the customer's transaction history,
 *                                    shown to admins BEFORE any decision
 *
 * Used by:
 *   - submit_order.php         (records activities at checkout)
 *   - record_email_risk.php    (records an activity at signup)
 *   - includes/set_session.php (asks "is this account flagged?")
 *   - fraud_review.php         (history + admin decisions)
 *
 * SHARED CONTRACT with fraud_analytics.php and fraud_analytics_page.dart:
 *   - category keys in BLOOM_FRAUD_CATEGORIES
 *   - risk level keys: critical, high, medium, low
 *   - customer/order fields: fraudCodes, riskScore, riskLevel, fraudReview
 * Never rename a key; only labels may change.
 */

// Study categories: key => display label.
// "Fake listings" from the study is intentionally NOT here: Bloominous is
// a single-merchant shop, so customers can never create listings.
const BLOOM_FRAUD_CATEGORIES = [
    'account_takeover' => 'Account Theft',
    'payment_fraud'    => 'Payment Fraud',
    'fake_transaction' => 'Fake Transaction',
    'malicious_return' => 'Malicious Return',
];

/*
 * EVIDENCE TIER -> TRIAGE POINTS (design parameters).
 * Higher tier = stronger / more direct evidence in the research basis.
 */
const BLOOM_FRAUD_TIER_POINTS = [
    'very_strong'     => 15,
    'strong'          => 10,
    'moderate_strong' => 8,
    'supporting'      => 6,
    'contextual'      => 3,
];

/*
 * ACTIVITY CODE -> evidence tier + group.
 *
 * "group" stops double counting: codes that describe the SAME kind of
 * signal share a group, and only the highest-scoring code per group
 * counts. Example: velocity_repeat and velocity_rapid are both "velocity",
 * so an account with both still gets velocity points only once.
 *
 * A code missing from this list scores 0 points (it is still recorded and
 * still counts toward the risk LEVEL through its category).
 */
const BLOOM_FRAUD_CODE_RULES = [
    // Very strong / direct
    'banned_device'         => ['tier' => 'very_strong',     'group' => 'device'],
    'price_mismatch'        => ['tier' => 'very_strong',     'group' => 'price'],
    // Strong
    'phone_disposable_voip' => ['tier' => 'strong',          'group' => 'phone_quality'],
    'velocity_repeat'       => ['tier' => 'strong',          'group' => 'velocity'],
    'velocity_rapid'        => ['tier' => 'strong',          'group' => 'velocity'],
    // Moderate-strong
    'ip_tor_abuse'          => ['tier' => 'moderate_strong', 'group' => 'network'],
    'ip_vpn_proxy'          => ['tier' => 'moderate_strong', 'group' => 'network'],
    'geo_mismatch'          => ['tier' => 'moderate_strong', 'group' => 'location'],
    // Supporting (relationship / context signals with common legit causes)
    'phone_reuse'           => ['tier' => 'supporting',      'group' => 'phone_link'],
    'address_reuse'         => ['tier' => 'supporting',      'group' => 'address_link'],
    'email_risk_signup'     => ['tier' => 'supporting',      'group' => 'email'],
];

// Codes that count as hard evidence -> risk level "critical".
const BLOOM_HARD_EVIDENCE_CODES = ['banned_device'];

// Upper bound for the score (current codes can reach at most 84).
const BLOOM_RISK_SCORE_CAP = 100;

// Valid risk levels, lowest to highest.
const BLOOM_RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

// A restriction never lasts longer than this (design parameter).
const BLOOM_RESTRICTION_MAX_DAYS = 30;

// Order statuses used by the account-history calculation.
const BLOOM_COMPLETED_ORDER_STATUSES = ['delivered', 'completed'];
const BLOOM_CANCELLED_ORDER_STATUSES = ['cancelled'];

/**
 * Builds one fraud activity record.
 *
 * $category must be a key from BLOOM_FRAUD_CATEGORIES.
 * $code is a short machine-readable ID for the specific check
 *       (e.g. 'phone_reuse'); it is what the score is computed from.
 * $reason is the human-readable text admins see. Keep existing reason
 *       texts word-for-word: fraud_analytics.php classifies older records
 *       by keywords inside this text.
 */
function bloom_fraud_activity(string $category, string $code, string $reason): array
{
    if (!array_key_exists($category, BLOOM_FRAUD_CATEGORIES)) {
        // Developer mistake (typo in a category), not a customer problem.
        throw new InvalidArgumentException("Unknown fraud category: {$category}");
    }

    return [
        'category' => $category,
        'code' => $code,
        'reason' => $reason,
    ];
}

/**
 * Appends new values to an existing list, removes duplicates, and returns
 * a clean 0,1,2... indexed list (Firestore needs a real list, not a PHP
 * array with gaps in its keys, or it gets stored as a map).
 */
function bloom_merge_unique(array $existing, array $new): array
{
    return array_values(array_unique(array_merge(array_values($existing), $new)));
}

/**
 * Keeps only non-empty strings. Protects the score/level math from
 * nulls or numbers that may sit inside old documents.
 */
function bloom_clean_string_list($list): array
{
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_filter($list, function ($item) {
        return is_string($item) && $item !== '';
    }));
}

/**
 * Triage score from a list of activity codes.
 *
 * Each code is looked up in BLOOM_FRAUD_CODE_RULES. Within one group only
 * the highest points count. The total is capped at BLOOM_RISK_SCORE_CAP.
 * Unknown codes add nothing.
 */
function bloom_fraud_score(array $codes): int
{
    $bestPerGroup = [];

    foreach (array_unique(bloom_clean_string_list($codes)) as $code) {
        $rule = BLOOM_FRAUD_CODE_RULES[$code] ?? null;
        if ($rule === null) {
            continue;
        }

        $points = BLOOM_FRAUD_TIER_POINTS[$rule['tier']] ?? 0;
        $group = $rule['group'];
        $bestPerGroup[$group] = max($bestPerGroup[$group] ?? 0, $points);
    }

    return min(BLOOM_RISK_SCORE_CAP, (int) array_sum($bestPerGroup));
}

/**
 * Option B risk level. Checked top to bottom; the first match wins.
 *
 *   critical = any hard-evidence code
 *   high     = 2+ distinct study categories
 *   medium   = exactly 1 study category
 *   low      = none
 */
function bloom_fraud_risk_level(array $categories, array $codes): string
{
    $cleanCodes = bloom_clean_string_list($codes);
    if (count(array_intersect($cleanCodes, BLOOM_HARD_EVIDENCE_CODES)) > 0) {
        return 'critical';
    }

    $studyCategories = array_unique(array_filter(
        bloom_clean_string_list($categories),
        function ($category) {
            return array_key_exists($category, BLOOM_FRAUD_CATEGORIES);
        }
    ));

    $distinct = count($studyCategories);
    if ($distinct >= 2) {
        return 'high';
    }
    if ($distinct === 1) {
        return 'medium';
    }
    return 'low';
}

/**
 * Is this customer's restriction still in force?
 *
 * Returns ['active' => bool, 'expired' => bool]:
 *   - not restricted at all            -> active false, expired false
 *   - restricted, end date in future   -> active true,  expired false
 *   - restricted, end date has passed  -> active false, expired true
 *     (the caller lifts it; no OTP needed after the 30 days)
 *   - restricted, NO end date stored   -> active true,  expired false
 *     (old records; the customer can still lift it through OTP)
 *
 * Every restriction BLOOM writes sets restrictedUntil to at most
 * BLOOM_RESTRICTION_MAX_DAYS ahead, and customers can't edit that field
 * (firestore.rules), so "end date has passed" means "30 days are over".
 */
function bloom_restriction_state(array $customer, ?DateTimeInterface $now = null): array
{
    if (($customer['isRestricted'] ?? false) !== true) {
        return ['active' => false, 'expired' => false];
    }

    $now = $now ?? new DateTimeImmutable('now');
    $until = $customer['restrictedUntil'] ?? null;

    if ($until instanceof DateTimeInterface && $until <= $now) {
        return ['active' => false, 'expired' => true];
    }

    return ['active' => true, 'expired' => false];
}

/**
 * Is this customer account currently "flagged"?
 *
 * Flagged means: an ACTIVE restriction (expired ones don't count),
 * OR at least one fraud activity has ever been recorded on it.
 *
 * LEGACY FALLBACK: accounts that existed before fraud scoring was removed
 * have no fraudCategories field yet, only an old fraudScore. For those
 * accounts only, the old ">= 50" rule still applies, so nobody who was
 * flagged under the old system silently becomes unflagged. (The NEW
 * riskScore is a different field and is never used here: it is for
 * review order only.)
 */
function bloom_customer_is_fraud_flagged(array $customer): bool
{
    if (bloom_restriction_state($customer)['active']) {
        return true;
    }

    $categories = $customer['fraudCategories'] ?? null;
    if (is_array($categories)) {
        return count($categories) > 0;
    }

    return (int) ($customer['fraudScore'] ?? 0) >= 50;
}

/**
 * Fields to store on ONE ORDER document, describing only that order's
 * own activities.
 *
 * Returns codes/score/level even when there are no activities
 * (empty list, 0, 'low'), so every new order carries the same shape.
 */
function bloom_build_fraud_order_fields(array $activities): array
{
    $codes = array_values(array_unique(bloom_clean_string_list(array_column($activities, 'code'))));
    $categories = array_values(array_unique(bloom_clean_string_list(array_column($activities, 'category'))));

    return [
        'fraudCodes' => $codes,
        'riskScore' => bloom_fraud_score($codes),
        'riskLevel' => bloom_fraud_risk_level($categories, $codes),
    ];
}

/**
 * Turns a list of new fraud activities into the fields to update on the
 * customer's document. Returns an empty array when there's nothing new,
 * so callers can simply skip the write.
 *
 * The customer's score and level describe the WHOLE account history:
 * old codes/categories + the new ones, merged.
 *
 * $now is whatever timestamp value the caller's Firestore helper expects
 * (bloom_rest_now() for the REST helpers).
 */
function bloom_build_fraud_customer_update(array $customer, array $activities, $now): array
{
    if (empty($activities)) {
        return [];
    }

    $existingFlags = bloom_clean_string_list($customer['fraudFlags'] ?? []);
    $existingCategories = bloom_clean_string_list($customer['fraudCategories'] ?? []);
    $existingCodes = bloom_clean_string_list($customer['fraudCodes'] ?? []);

    $mergedFlags = bloom_merge_unique($existingFlags, bloom_clean_string_list(array_column($activities, 'reason')));
    $mergedCategories = bloom_merge_unique($existingCategories, bloom_clean_string_list(array_column($activities, 'category')));
    $mergedCodes = bloom_merge_unique($existingCodes, bloom_clean_string_list(array_column($activities, 'code')));

    return [
        'fraudFlags' => $mergedFlags,
        'fraudCategories' => $mergedCategories,
        'fraudCodes' => $mergedCodes,
        'riskScore' => bloom_fraud_score($mergedCodes),
        'riskLevel' => bloom_fraud_risk_level($mergedCategories, $mergedCodes),
        'lastFraudActivityAt' => $now,
    ];
}

/**
 * True when an order document carries any recorded fraud activity.
 * Checks the structured field first, then the older text fields.
 */
function bloom_order_is_flagged(array $order): bool
{
    foreach (['fraudActivities', 'fraudCategories', 'fraudFlags'] as $field) {
        if (is_array($order[$field] ?? null) && count($order[$field]) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * The customer's transaction history, computed from their orders.
 * Shown to the admin BEFORE any manual decision, so a decision is never
 * made from one flagged order alone.
 *
 * $orderRows        rows from bloom_firestore_query_rest('orders', 'user_id', $uid)
 *                   (each row has the order fields under 'data')
 * $accountCreatedAt when the login account was created (Firebase Auth),
 *                   or null if unknown
 *
 * historyProfile:
 *   no_orders  - the account has never ordered
 *   cold_start - has orders, but none completed yet ("new" account with
 *                limited or no history)
 *   returning  - at least one completed order on record
 *
 * Plain counting and averaging only. No weights, no thresholds, and it
 * never changes the score or level.
 */
function bloom_build_account_history(array $orderRows, ?DateTimeInterface $accountCreatedAt, ?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');

    $total = 0;
    $completed = 0;
    $cancelled = 0;
    $open = 0;
    $flagged = 0;
    $completedSpend = 0.0;
    $firstOrderAt = null;
    $lastOrderAt = null;
    $latestOrderTotal = null;

    foreach ($orderRows as $row) {
        $order = is_array($row['data'] ?? null) ? $row['data'] : [];
        $total++;

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $amount = is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;

        if (in_array($status, BLOOM_COMPLETED_ORDER_STATUSES, true)) {
            $completed++;
            $completedSpend += $amount;
        } elseif (in_array($status, BLOOM_CANCELLED_ORDER_STATUSES, true)) {
            $cancelled++;
        } else {
            $open++;
        }

        if (bloom_order_is_flagged($order)) {
            $flagged++;
        }

        $placedAt = $order['createdAt'] ?? $order['timestamp'] ?? null;
        if ($placedAt instanceof DateTimeInterface) {
            if ($firstOrderAt === null || $placedAt < $firstOrderAt) {
                $firstOrderAt = $placedAt;
            }
            if ($lastOrderAt === null || $placedAt > $lastOrderAt) {
                $lastOrderAt = $placedAt;
                $latestOrderTotal = round($amount, 2);
            }
        }
    }

    $accountAgeDays = null;
    if ($accountCreatedAt !== null) {
        $seconds = $now->getTimestamp() - $accountCreatedAt->getTimestamp();
        $accountAgeDays = max(0, (int) floor($seconds / 86400));
    }

    if ($total === 0) {
        $profile = 'no_orders';
    } elseif ($completed === 0) {
        $profile = 'cold_start';
    } else {
        $profile = 'returning';
    }

    $toIso = function (?DateTimeInterface $date): ?string {
        return $date ? $date->format(DATE_ATOM) : null;
    };

    return [
        'historyProfile' => $profile,
        'accountCreatedAt' => $toIso($accountCreatedAt),
        'accountAgeDays' => $accountAgeDays,
        'totalOrders' => $total,
        'completedOrders' => $completed,
        'cancelledOrders' => $cancelled,
        'openOrders' => $open,
        'flaggedOrders' => $flagged,
        'flaggedOrderPercent' => $total > 0 ? (int) round(($flagged / $total) * 100) : null,
        'firstOrderAt' => $toIso($firstOrderAt),
        'lastOrderAt' => $toIso($lastOrderAt),
        'completedSpend' => round($completedSpend, 2),
        'averageCompletedOrder' => $completed > 0 ? round($completedSpend / $completed, 2) : null,
        'latestOrderTotal' => $latestOrderTotal,
    ];
}