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
 *       research basis and checked against two public (synthetic) datasets
 *       where a matching column existed; the point VALUES per tier are
 *       DESIGN PARAMETERS, to be recalibrated once BLOOM has its own
 *       reviewed cases.
 *
 *       Sensitivity analysis (bloom_sensitivity.py) on the Sparkov dataset
 *       (1.85M card transactions) and the Kaggle e-commerce dataset (1.5M):
 *         - customer history: rule 13 follows Chen et al. (2024) exactly
 *           (average + 2 standard deviations, last 2 months)
 *         - amount line: top 5% gave the best balance in both datasets
 *         - velocity: 1 repeat in 5 min lift 3.95; 2+ repeats lift 13.45
 *         - distance: not testable (Sparkov does not model it)
 *
 * WHAT THE VALUES DO:
 *   - They decide the ORDER in which admins review accounts and how loud
 *     the alert is.
 *   - ONE customer-facing effect: an account score of
 *     BLOOM_COD_BLOCK_SCORE or more removes Cash on Delivery at checkout
 *     (online payment still works). See bloom_customer_cod_blocked().
 *   - They never block an order and never ban anyone by themselves.
 *
 * Pure helpers in this file (no database calls):
 *   - bloom_restriction_state():          restrictions last at most 30 days
 *   - bloom_build_account_history():      the customer's transaction history,
 *                                         shown to admins BEFORE any decision
 *   - bloom_build_history_baseline():     the customer's own usual spending
 *                                         (last 60 days)            - rule 13
 *   - bloom_history_amount_deviates():    above their own usual range?
 *   - bloom_build_shop_amount_baseline(): the SHOP's usual order sizes
 *                                         (recent orders, top 5%)   - rule 10
 *   - bloom_new_account_high_value():     new account + unusually large order?
 *   - bloom_customer_cod_blocked():       should COD be hidden for this account?
 *   - bloom_normalize_address():          one spelling of an address, so the
 *                                         same place always matches
 *   - bloom_household_doc_id():           address -> known_households doc id
 *   - bloom_household_allows():           was this customer cleared as part
 *                                         of the household at this address?
 *   - bloom_build_address_sharing():      how many accounts share an address,
 *                                         and has it had a successful delivery?
 *   - bloom_address_sharing_is_suspicious(): over the 3-account limit with
 *                                         no successful delivery?
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
 *   - BLOOM_COD_BLOCK_SCORE is mirrored as COD_BLOCK_SCORE in the dashboards
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
    // velocity_rapid (2+ earlier orders within 5 min): Sparkov lift 13.45.
    'velocity_rapid'        => ['tier' => 'strong',          'group' => 'velocity'],
    // Strong (spending behavior). Both share the "spending" group: rule 13
    // needs history and rule 10 needs NO history, so one order can only
    // ever trigger one of them anyway; the group makes that explicit.
    //   history_amount_deviation - Chen et al. (2024), features A6 and
    //       A15/A16: an amount outside the customer's usual range
    //       (2 standard deviations) "represents abnormal customer behavior".
    //   new_account_high_value   - PayMongo "outlier amounts"; Kaggle
    //       e-commerce dataset lift 12.71 (account <=30 days + top-5% amount).
    // Kept at "strong", not "very_strong": a large order can be an honest
    // special occasion, so it is a red flag for review, not proof.
    'history_amount_deviation' => ['tier' => 'strong',       'group' => 'spending'],
    'new_account_high_value'   => ['tier' => 'strong',       'group' => 'spending'],
    // Moderate-strong
    'ip_tor_abuse'          => ['tier' => 'moderate_strong', 'group' => 'network'],
    'ip_vpn_proxy'          => ['tier' => 'moderate_strong', 'group' => 'network'],
    'geo_mismatch'          => ['tier' => 'moderate_strong', 'group' => 'location'],
    // Supporting (relationship / context signals with common legit causes)
    // velocity_repeat (exactly 1 earlier order within 5 min): Sparkov lift
    // 3.95, a real but moderate signal; honest customers sometimes reorder
    // a forgotten item. Shares the "velocity" group with velocity_rapid,
    // so an account still gets velocity points only once (the higher one).
    'velocity_repeat'       => ['tier' => 'supporting',      'group' => 'velocity'],
    'phone_reuse'           => ['tier' => 'supporting',      'group' => 'phone_link'],
    'address_reuse'         => ['tier' => 'supporting',      'group' => 'address_link'],
    'email_risk_signup'     => ['tier' => 'supporting',      'group' => 'email'],
];

// Codes that count as hard evidence -> risk level "critical".
const BLOOM_HARD_EVIDENCE_CODES = ['banned_device'];

// Upper bound for the score (current codes can reach at most 94).
const BLOOM_RISK_SCORE_CAP = 100;

// Valid risk levels, lowest to highest.
const BLOOM_RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

// A restriction never lasts longer than this (design parameter).
const BLOOM_RESTRICTION_MAX_DAYS = 30;

// Order statuses used by the history calculations.
const BLOOM_COMPLETED_ORDER_STATUSES = ['delivered', 'completed'];
const BLOOM_CANCELLED_ORDER_STATUSES = ['cancelled'];

/*
 * RULE 13 - CUSTOMER'S OWN HISTORY (returning customers).
 * Source: Chen, Lee, Huang & Peng (2024), "Credit Card Fraud Detection via
 * Intelligent Sampling and Self-supervised Learning", ACM TIST 15(2), Art. 35.
 *
 *   BLOOM_HISTORY_WINDOW_DAYS     = 60  SOURCED (p. 35:8): features use "the
 *                                       user's past transactions within two
 *                                       months".
 *   BLOOM_HISTORY_STD_DEVIATIONS  = 2   SOURCED (p. 35:10, A15/A16): checks
 *                                       "whether the current transaction
 *                                       amount ... is within 2 standard
 *                                       deviation of the amount spent"; if
 *                                       not, it "represents abnormal customer
 *                                       behavior". BLOOM is a single merchant,
 *                                       so "spent for the merchant" = the
 *                                       customer's previous BLOOM orders.
 *   BLOOM_HISTORY_MIN_ORDERS      = 3   DESIGN PARAMETER: a standard
 *                                       deviation from only 1-2 orders is too
 *                                       unstable to call anything "unusual".
 *                                       Customers with fewer orders fall to
 *                                       rule 10 instead.
 *
 * BLOOM adaptations (state these in the paper):
 *   - Chen et al. use this as a feature inside a machine-learning model;
 *     BLOOM uses it as a stand-alone yes/no rule.
 *   - Only the UPPER side is checked (unusually small orders cost a single
 *     flower shop nothing).
 *   - "Standard deviation" is the sample standard deviation (n - 1), the
 *     usual choice when estimating from a small set of orders.
 */
const BLOOM_HISTORY_WINDOW_DAYS = 60;
const BLOOM_HISTORY_STD_DEVIATIONS = 2;
const BLOOM_HISTORY_MIN_ORDERS = 3;

/*
 * RULE 10 - NEW ACCOUNT + UNUSUALLY LARGE ORDER (no history yet).
 *   BLOOM_NEW_ACCOUNT_MAX_DAYS     = 30   DATA-INFORMED: in the Kaggle
 *                                         e-commerce dataset, accounts up
 *                                         to 30 days old had the same fraud
 *                                         lift as accounts up to 7 days
 *                                         (4.46 vs 4.44) while covering 4x
 *                                         more fraud cases.
 *   BLOOM_SHOP_AMOUNT_PERCENTILE   = 0.95 DATA-TESTED: "unusually large"
 *                                         = above the shop's top 5% of
 *                                         recent order totals. Compared with
 *                                         top 10% and top 1%, top 5% gave the
 *                                         best balance in both datasets
 *                                         (Sparkov: 75.8% of fraud caught
 *                                         while flagging 5% of purchases).
 *   BLOOM_SHOP_BASELINE_ORDERS     = 200  DESIGN PARAMETER: how many recent
 *                                         shop orders define "usual".
 *   BLOOM_SHOP_BASELINE_MIN_ORDERS = 20   DESIGN PARAMETER: below this the
 *                                         shop has too few orders for a
 *                                         top-5% line to mean anything, so
 *                                         the rule stays silent.
 */
const BLOOM_NEW_ACCOUNT_MAX_DAYS = 30;
const BLOOM_SHOP_AMOUNT_PERCENTILE = 0.95;
const BLOOM_SHOP_BASELINE_ORDERS = 200;
const BLOOM_SHOP_BASELINE_MIN_ORDERS = 20;

/*
 * COD GATING - perishable-goods protection.
 * A refused COD bouquet cannot be resold, so accounts at or above this
 * account score must pay online (GCash/Maya). The order itself is never
 * blocked. DESIGN PARAMETER; mirrored as COD_BLOCK_SCORE in the dashboards.
 */
const BLOOM_COD_BLOCK_SCORE = 50;

/*
 * KNOWN HOUSEHOLDS - shared-address false alarms.
 * Family members who live together and order to the same address trigger
 * address_reuse, even though nothing is wrong. When an admin marks such an
 * account as a False Alarm and ticks "Same household" (fraud_review.php),
 * the account's uid is added to known_households/{address id}.allowedUids.
 * submit_order.php then skips address_reuse for that uid at that address.
 *
 * Same idea as banned_devices.allowedUids: the decision is per customer,
 * made by a person, and every OTHER account using the address is still
 * checked. No account count or threshold is guessed.
 *
 * Server-only collection: written through the REST helpers (service
 * account). firestore.rules must not allow client reads or writes.
 */
const BLOOM_KNOWN_HOUSEHOLDS_COLLECTION = 'known_households';

/*
 * SHARED ADDRESS RULE (address_reuse) - when does sharing become a sign?
 *
 *   BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT = 3  DESIGN PARAMETER: up to 3
 *       different accounts may order to one address without any sign
 *       (a family, roommates). The 4th account onward records
 *       address_reuse. Note for the paper: the average Filipino household
 *       is 4.1 people (PSA 2020 Census), so large families can pass 3;
 *       the two exceptions below exist for exactly that case.
 *
 * Exceptions (no sign even above the limit):
 *   1. TRUSTED HOUSEHOLD - the address already has at least one SUCCESSFUL
 *      order (status in BLOOM_COMPLETED_ORDER_STATUSES: the bouquet was
 *      delivered and accepted). A real delivery to that door is proof the
 *      household exists. A fraud ring would have to buy and accept a real
 *      order first, which costs them money.
 *   2. KNOWN HOUSEHOLD - an admin cleared this customer for the address
 *      (fraud_review.php, False Alarm + "Same household").
 *
 * Every other sign (device, phone, network, spending, speed, distance)
 * still runs for these customers.
 */
const BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT = 3;

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
 * Has an admin cleared this account as a false alarm, with no NEW
 * activity recorded since? Same rule as isDismissed() in
 * fraud_analytics.php, so the dashboard and checkout always agree.
 */
function bloom_customer_cleared_by_review(array $customer): bool
{
    $review = $customer['fraudReview'] ?? null;
    if (!is_array($review) || ($review['decision'] ?? null) !== 'false_alarm') {
        return false;
    }

    $reviewedAt = $review['reviewedAt'] ?? null;
    if (!($reviewedAt instanceof DateTimeInterface)) {
        return false;
    }

    $lastActivity = $customer['lastFraudActivityAt'] ?? null;
    if (!($lastActivity instanceof DateTimeInterface)) {
        return true;
    }

    return $reviewedAt >= $lastActivity;
}

/**
 * Should Cash on Delivery be hidden for this account?
 *
 * Yes when ANY of these is true:
 *   - the account is banned (isBanned)
 *   - a restriction is ACTIVE right now (expired ones don't count)
 *   - the account score (or a legacy fraudScore) is BLOOM_COD_BLOCK_SCORE
 *     or more, UNLESS an admin cleared it as a false alarm and nothing new
 *     was recorded since
 *
 * Blacklisted (status 'blocked') accounts never reach this check:
 * submit_order.php refuses them earlier.
 */
function bloom_customer_cod_blocked(array $customer, bool $restrictionActive): bool
{
    if (($customer['isBanned'] ?? false) === true || $restrictionActive) {
        return true;
    }

    $score = $customer['riskScore'] ?? $customer['fraudScore'] ?? 0;
    $score = is_numeric($score) ? (int) $score : 0;

    if ($score < BLOOM_COD_BLOCK_SCORE) {
        return false;
    }

    return !bloom_customer_cleared_by_review($customer);
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
 * One spelling of a delivery address: lower case, single spaces, no
 * spaces at the ends. "Blk 5  Lot 2, Imus" and "blk 5 lot 2, imus" become
 * the same text, so they match.
 *
 * MUST stay identical to the formula orders were saved with (the
 * normalizedAddress field), or old orders stop matching new ones.
 */
function bloom_normalize_address(string $address): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $address)));
}

/**
 * Document id for an address in known_households.
 * An address can contain "/" (forbidden in Firestore ids) and can be
 * long, so it is turned into a fixed 64-character SHA-256 fingerprint.
 * The same address always gives the same id.
 */
function bloom_household_doc_id(string $normalizedAddress): string
{
    return hash('sha256', $normalizedAddress);
}

/**
 * Was this customer cleared by an admin as part of the household at this
 * address? $householdDoc is the known_households document, or null when
 * the address has never been marked.
 */
function bloom_household_allows(?array $householdDoc, string $uid): bool
{
    if ($householdDoc === null) {
        return false;
    }
    $allowedUids = bloom_clean_string_list($householdDoc['allowedUids'] ?? []);
    return in_array($uid, $allowedUids, true);
}

/**
 * Describes who has ordered to one delivery address.
 *
 * $addressRows  rows from bloom_firestore_query_rest('orders',
 *               'normalizedAddress', $normalizedAddress). The order being
 *               placed right now is NOT in this list yet.
 * $uid          the customer placing the order now.
 *
 * Returns:
 *   accountCount      different accounts at this address, INCLUDING the
 *                     current customer
 *   otherAccounts     different accounts at this address, NOT counting
 *                     the current customer
 *   successfulOrders  orders to this address that were delivered/completed
 *                     (any account)
 *   trustedHousehold  true when successfulOrders >= 1
 *   overLimit         true when accountCount > BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT
 *   limit             the limit used (saved on the order for admins)
 */
function bloom_build_address_sharing(array $addressRows, string $uid): array
{
    $accounts = [$uid => true];
    $successfulOrders = 0;

    foreach ($addressRows as $row) {
        $order = is_array($row['data'] ?? null) ? $row['data'] : [];

        $owner = $order['user_id'] ?? null;
        if (is_string($owner) && $owner !== '') {
            $accounts[$owner] = true;
        }

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, BLOOM_COMPLETED_ORDER_STATUSES, true)) {
            $successfulOrders++;
        }
    }

    $accountCount = count($accounts);

    return [
        'accountCount' => $accountCount,
        'otherAccounts' => $accountCount - 1,
        'successfulOrders' => $successfulOrders,
        'trustedHousehold' => $successfulOrders > 0,
        'overLimit' => $accountCount > BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT,
        'limit' => BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT,
    ];
}

/**
 * Should this shared address be recorded as address_reuse?
 * (Before the admin "known household" check, which needs a database read
 * and is done by submit_order.php only when this returns true.)
 *
 * True only when ALL of these hold:
 *   - another account has ordered to this address
 *   - more accounts share it than BLOOM_ADDRESS_SHARED_ACCOUNT_LIMIT
 *   - no order to this address has ever been delivered successfully
 */
function bloom_address_sharing_is_suspicious(array $sharing): bool
{
    return ($sharing['otherAccounts'] ?? 0) > 0
        && ($sharing['overLimit'] ?? false) === true
        && ($sharing['trustedHousehold'] ?? false) !== true;
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
 * Whole days between account creation and now, or null if unknown.
 * Shared by the admin history panel and rule 10, so both always show
 * the same age for the same account.
 */
function bloom_account_age_days(?DateTimeInterface $accountCreatedAt, ?DateTimeInterface $now = null): ?int
{
    if ($accountCreatedAt === null) {
        return null;
    }
    $now = $now ?? new DateTimeImmutable('now');
    $seconds = $now->getTimestamp() - $accountCreatedAt->getTimestamp();
    return max(0, (int) floor($seconds / 86400));
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
        'accountAgeDays' => bloom_account_age_days($accountCreatedAt, $now),
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

/**
 * Sample standard deviation (divides by n - 1). Returns null for fewer
 * than 2 values, because "spread" needs at least two numbers.
 */
function bloom_sample_std_dev(array $values): ?float
{
    $count = count($values);
    if ($count < 2) {
        return null;
    }
    $mean = array_sum($values) / $count;
    $squaredGaps = 0.0;
    foreach ($values as $value) {
        $squaredGaps += ((float) $value - $mean) ** 2;
    }
    return sqrt($squaredGaps / ($count - 1));
}

/**
 * RULE 13: the customer's "normal" spending, from their own previous
 * orders inside the last BLOOM_HISTORY_WINDOW_DAYS days.
 *
 * $orderRows  rows from bloom_firestore_query_rest('orders', 'user_id', $uid).
 *             The order being placed right now is NOT in this list yet.
 *
 * Counted:  not cancelled, placed inside the window, positive total_price.
 * Skipped:  cancelled orders, orders with no timestamp, zero/odd totals.
 *
 * Only DESCRIBES the history; bloom_history_amount_deviates() decides.
 */
function bloom_build_history_baseline(array $orderRows, ?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $windowStart = (new DateTimeImmutable())
        ->setTimestamp($now->getTimestamp())
        ->modify('-' . BLOOM_HISTORY_WINDOW_DAYS . ' days');

    $totals = [];
    $lastOrderAt = null;
    $lastTotal = null;

    foreach ($orderRows as $row) {
        $order = is_array($row['data'] ?? null) ? $row['data'] : [];

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, BLOOM_CANCELLED_ORDER_STATUSES, true)) {
            continue;
        }

        $placedAt = $order['createdAt'] ?? $order['timestamp'] ?? null;
        if (!($placedAt instanceof DateTimeInterface) || $placedAt < $windowStart) {
            continue;
        }

        $amount = $order['total_price'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            continue;
        }
        $amount = round((float) $amount, 2);

        $totals[] = $amount;

        if ($lastOrderAt === null || $placedAt > $lastOrderAt) {
            $lastOrderAt = $placedAt;
            $lastTotal = $amount;
        }
    }

    $count = count($totals);
    $average = $count > 0 ? array_sum($totals) / $count : null;
    $stdDev = bloom_sample_std_dev($totals);
    $hasEnough = $count >= BLOOM_HISTORY_MIN_ORDERS;

    // The "usual range" ceiling: average + 2 standard deviations.
    $upperLimit = ($hasEnough && $average !== null && $stdDev !== null)
        ? round($average + BLOOM_HISTORY_STD_DEVIATIONS * $stdDev, 2)
        : null;

    return [
        'windowDays' => BLOOM_HISTORY_WINDOW_DAYS,
        'orderCount' => $count,
        'hasEnoughHistory' => $hasEnough,
        'minTotal' => $count > 0 ? min($totals) : null,
        'maxTotal' => $count > 0 ? max($totals) : null,
        'averageTotal' => $average !== null ? round($average, 2) : null,
        'stdDev' => $stdDev !== null ? round($stdDev, 2) : null,
        'stdDeviations' => BLOOM_HISTORY_STD_DEVIATIONS,
        'upperLimit' => $upperLimit,
        'lastTotal' => $lastTotal,
    ];
}

/**
 * RULE 13 decision: is this order ABOVE the customer's own usual range?
 *
 *   - fewer than BLOOM_HISTORY_MIN_ORDERS orders in the window -> false
 *     (no pattern to compare; rule 10 handles these customers)
 *   - otherwise true only when the total is above
 *     average + BLOOM_HISTORY_STD_DEVIATIONS x standard deviation
 *     (Chen et al., 2024, features A15/A16)
 *
 * Only the upper side is checked: an unusually SMALL order carries no
 * money risk for a single flower shop.
 */
function bloom_history_amount_deviates(array $baseline, float $currentTotal): bool
{
    if (($baseline['hasEnoughHistory'] ?? false) !== true) {
        return false;
    }

    $upperLimit = $baseline['upperLimit'] ?? null;
    if (!is_numeric($upperLimit) || (float) $upperLimit <= 0) {
        return false;
    }

    return $currentTotal > (float) $upperLimit;
}

/**
 * Percentile with linear interpolation (the same method pandas uses by
 * default, so BLOOM's cut-off matches the dataset analysis).
 * $p is 0..1. Returns null for an empty list.
 */
function bloom_percentile(array $values, float $p): ?float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    $count = count($values);
    if ($count === 0) {
        return null;
    }

    sort($values, SORT_NUMERIC);
    $p = max(0.0, min(1.0, $p));
    $position = ($count - 1) * $p;
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    $fraction = $position - $lower;

    return (float) $values[$lower] + ((float) $values[$upper] - (float) $values[$lower]) * $fraction;
}

/**
 * RULE 10: the SHOP's usual order sizes, from recent order rows.
 *
 * $orderRows  rows with the order fields under 'data' (status, total_price),
 *             newest first, from any customer.
 *
 * Cancelled and zero/odd totals are skipped. 'threshold' is the
 * BLOOM_SHOP_AMOUNT_PERCENTILE line (top 5%), or null when the shop has
 * fewer than BLOOM_SHOP_BASELINE_MIN_ORDERS usable orders.
 */
function bloom_build_shop_amount_baseline(array $orderRows): array
{
    $totals = [];

    foreach ($orderRows as $row) {
        $order = is_array($row['data'] ?? null) ? $row['data'] : [];

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, BLOOM_CANCELLED_ORDER_STATUSES, true)) {
            continue;
        }

        $amount = $order['total_price'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            continue;
        }
        $totals[] = round((float) $amount, 2);
    }

    $count = count($totals);
    $hasEnough = $count >= BLOOM_SHOP_BASELINE_MIN_ORDERS;
    $threshold = $hasEnough ? bloom_percentile($totals, BLOOM_SHOP_AMOUNT_PERCENTILE) : null;

    return [
        'orderCount' => $count,
        'percentile' => BLOOM_SHOP_AMOUNT_PERCENTILE,
        'hasEnoughOrders' => $hasEnough,
        'threshold' => $threshold !== null ? round($threshold, 2) : null,
    ];
}

/**
 * RULE 10 decision: a NEW account, with NO usable history of its own,
 * placing an order above the shop's top-5% line?
 *
 * Every part must be true:
 *   - account age is known and at most BLOOM_NEW_ACCOUNT_MAX_DAYS
 *   - the customer does NOT have enough history for rule 13
 *   - the shop has enough orders to draw a top-5% line
 *   - this order's total is above that line
 *
 * A missing piece (unknown age, too few shop orders) means "no flag":
 * the rule fails quiet instead of guessing.
 */
function bloom_new_account_high_value(?int $accountAgeDays, array $historyBaseline, array $shopBaseline, float $currentTotal): bool
{
    if ($accountAgeDays === null || $accountAgeDays > BLOOM_NEW_ACCOUNT_MAX_DAYS) {
        return false;
    }
    if (($historyBaseline['hasEnoughHistory'] ?? false) === true) {
        return false;
    }
    if (($shopBaseline['hasEnoughOrders'] ?? false) !== true) {
        return false;
    }

    $threshold = $shopBaseline['threshold'] ?? null;
    if (!is_numeric($threshold) || (float) $threshold <= 0) {
        return false;
    }

    return $currentTotal > (float) $threshold;
}