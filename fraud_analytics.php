<?php
/* BLOOMINOUS - Fraud Activity Log
 *
 * Every recorded fraud signal is grouped under the fraud-activity
 * categories from the study used as this module's basis (account theft,
 * payment fraud, fake transactions, malicious returns).
 *
 * TRIAGE (written by includes/fraud_activity.php on the server):
 *   - Risk level (critical/high/medium/low): rule-based, from categories.
 *   - Triage score (0-100, shown as a bar): evidence-tiered points.
 * Both decide REVIEW ORDER. The one customer-facing effect: an account
 * score of COD_BLOCK_SCORE (50) or more turns Cash on Delivery off at
 * checkout (same rule as bloom_customer_cod_blocked() in PHP). The page
 * shows that state on each card so staff see what the customer sees.
 *
 * "How this works" guide: a plain-language explanation for staff and
 * non-technical readers. Every number in it must match
 * includes/fraud_activity.php; update both together.
 *
 * REVIEW + DECISION (inside each account's Fraud Activity Log):
 *   1. Transaction History, computed on the server (fraud_review.php):
 *      account age, order counts, flagged %, spend, new vs returning.
 *   2. Admin Decision, at the END of the log, after the evidence:
 *        Confirm Fraud     -> account blacklisted + its devices banned
 *        Mark False Alarm  -> restriction lifted (if any); account leaves
 *                             High Priority until new activity appears
 *        + "Same household" -> (false alarm only, shown when the account
 *                             has address_reuse) sends markHousehold, so
 *                             fraud_review.php stops flagging the shared
 *                             address for THIS account only
 *      Both need a written reason, an up-to-date history, and a
 *      confirmation. The server re-checks everything.
 *
 * RESTRICTION APPEALS (panel above the account list):
 *   A restricted customer cannot order with ANY payment method. They send an
 *   appeal from the checkout (web + app) to fraud_appeal.php, which saves it
 *   in fraud_appeals/{uid} and notifies the super admin. This page listens to
 *   the pending appeals live, shows them in the "Restriction Appeals" panel,
 *   and an admin decides in the Appeal window:
 *        Lift Restriction  -> fraud_appeal.php (decision: approve)
 *        Keep Restriction  -> fraud_appeal.php (decision: deny; the customer
 *                             can appeal again after 24 hours)
 *   Each needs a written reason, saved in the admin audit log.
 *
 * The file name stays fraud_analytics.php on purpose so existing sidebar
 * links and bookmarks keep working.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Not logged in as management at all -> back to login.
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// Admin-only page, same rule as override_codes.php and manage_accounts.php.
// Checked BEFORE header.php prints any HTML, because a header("Location")
// redirect only works while nothing has been sent to the browser yet.
$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'super-admin') {
    header("Location: admin.php");
    exit();
}

// The "How the Fraud Activity Log Works" guide is written for the super
// admin, who owns the fraud rules and answers for them (thesis panel,
// owner, audits). Regular admins still use the page to review accounts,
// but the guide banner and modal are not printed for them at all.
$isSuperAdmin = ($user_role === 'super-admin');

include 'templates/header.php';
?>
<style>
    /* Account status / risk level badges */
    .risk-badge { font-size: 0.65rem; font-weight: 900; padding: 6px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 1px; display: inline-flex; align-items: center; gap: 6px; }
    .risk-low { background: #e8f8f0; color: #14532d; border: 1px solid #bbf7d0; }
    .risk-medium { background: #fffbeb; color: #78350f; border: 1px solid #fef3c7; }
    .risk-high { background: #fef2f2; color: #7f1d1d; border: 1px solid #fee2e2; }
    .risk-blocked { background: #111827; color: #ffffff; border: 1px solid #374151; }
    .lvl-critical { background: #7f1d1d; color: #ffffff; border: 1px solid #991b1b; }

    /* Small score pill (used on individual orders in the log) */
    .score-pill { font-size: 0.65rem; font-weight: 900; padding: 6px 12px; border-radius: 20px; background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; display: inline-flex; align-items: center; gap: 6px; }
    .score-pill.unscored { font-weight: 700; font-style: italic; color: #9ca3af; }

    /* Triage score bar (cards + modal summary) */
    .score-block { width: 100%; min-width: 220px; }
    .score-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; gap: 10px; }
    .score-label { font-size: 0.6rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; }
    .score-value { font-size: 1.1rem; font-weight: 900; color: #1f2937; }
    .score-value small { font-size: 0.65rem; font-weight: 700; color: #9ca3af; }
    .score-value.unscored { font-size: 0.75rem; font-weight: 700; font-style: italic; color: #9ca3af; }
    .score-track { height: 8px; background: #f3f4f6; border-radius: 999px; overflow: hidden; }
    .score-fill { height: 100%; width: 0; border-radius: 999px; background: #d1d5db; transition: width 0.4s ease; }
    .fill-critical { background: #7f1d1d; }
    .fill-high { background: #ef4444; }
    .fill-medium { background: #f59e0b; }
    .fill-low { background: #10b981; }
    .score-note { font-size: 0.62rem; color: #9ca3af; margin-top: 5px; }

    .fraud-card { background: var(--surface); border: 1px solid var(--border-color); padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.01); transition: all 0.3s ease; }
    .card-review-line { font-size: 0.7rem; font-weight: 700; color: #6b7280; margin-top: 4px; }

    /* Fraud activity category chips (one color per study category) */
    .cat-chip { font-size: 0.65rem; font-weight: 800; padding: 4px 10px; border-radius: 12px; display: inline-flex; align-items: center; gap: 5px; border: 1px solid transparent; white-space: nowrap; }
    .cat-account_takeover { background: #f5f3ff; color: #5b21b6; border-color: #ddd6fe; }
    .cat-payment_fraud { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
    .cat-fake_transaction { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .cat-malicious_return { background: #f0fdfa; color: #115e59; border-color: #99f6e4; }
    .cat-account_action { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
    .cat-other { background: #f9fafb; color: #6b7280; border-color: #e5e7eb; }

    /* Filter chips above the grid */
    .filter-chip { font-size: 0.7rem; font-weight: 800; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--border-color); background: var(--surface); color: #6b7280; cursor: pointer; transition: 0.15s; font-family: inherit; }
    .filter-chip:hover { border-color: #f9a8d4; }
    .filter-chip.active { background: #ec4899; border-color: #ec4899; color: #ffffff; }

    /* "Open log" block on each card is a button, not static text */
    .audit-trail-btn {
        width: 100%; text-align: left; cursor: pointer;
        background: #f9fafb; padding: 0.75rem; border-radius: 12px; border: 1px solid var(--border-color);
        font-family: inherit; transition: 0.15s;
    }
    .audit-trail-btn:hover { background: #f3f4f6; border-color: #e5e7eb; }
    .audit-trail-btn .view-hint { font-size: 0.65rem; font-weight: 800; color: #7380ec; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Fraud Activity Log modal */
    #fraudHistoryOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #fraudHistoryOverlay.open { display: flex; }
    #fraudHistoryModal { background: var(--surface); color: var(--text-main); border-radius: 24px; padding: 2rem; max-width: 760px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #fraudHistoryModal h3 { font-size: 1.3rem; font-weight: 900; margin: 0 0 0.25rem; }
    #fraudHistoryModal h4 { font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin: 0; }
    #fraudHistoryModal .close-btn { float: right; background: none; border: none; font-size: 1.1rem; color: #999; cursor: pointer; }

    /* "How this works" guide */
    .guide-banner { width: 100%; text-align: left; cursor: pointer; font-family: inherit; background: var(--surface); border: 1px solid var(--border-color); border-radius: 24px; padding: 1rem 1.25rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; gap: 16px; transition: 0.15s; }
    .guide-banner:hover { border-color: #f9a8d4; }
    .guide-banner-title { font-size: 0.85rem; font-weight: 900; color: var(--text-main); margin: 0; }
    .guide-banner-text { font-size: 0.75rem; color: #6b7280; margin: 2px 0 0; }
    .guide-banner-link { font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; color: #ec4899; white-space: nowrap; }

    #methodologyModalOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.6); z-index: 550; align-items: center; justify-content: center; padding: 20px; }
    #methodologyModalOverlay.open { display: flex; }
    #methodologyModal { background: var(--surface); color: var(--text-main); border-radius: 24px; padding: 2rem; max-width: 820px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.25); border: 1px solid var(--border-color); }
    #methodologyModal .close-btn { float: right; background: none; border: none; font-size: 1.2rem; color: #999; cursor: pointer; }
    .guide-intro { font-size: 0.82rem; color: #4b5563; line-height: 1.6; margin: 0.5rem 0 1.25rem; }
    .guide-section { border-top: 1px solid var(--border-color); padding: 1.1rem 0; }
    .guide-section h4 { font-size: 0.95rem; font-weight: 900; color: var(--text-main); margin: 0 0 0.5rem; }
    .guide-section p, .guide-section li { font-size: 0.8rem; color: #4b5563; line-height: 1.65; }
    .guide-section p { margin: 0 0 0.5rem; }
    .guide-section ul { margin: 0.25rem 0 0.5rem; padding-left: 1.2rem; list-style: disc; }
    .guide-section li { margin-bottom: 0.3rem; }
    .guide-table { width: 100%; border-collapse: collapse; margin: 0.5rem 0 0.75rem; font-size: 0.78rem; }
    .guide-table th { text-align: left; font-size: 0.62rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; padding: 8px 10px; border-bottom: 1px solid var(--border-color); }
    .guide-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-color); color: #374151; vertical-align: top; line-height: 1.5; }
    .guide-table td:first-child { font-weight: 800; white-space: nowrap; }
    .guide-note { font-size: 0.75rem; color: #6b7280; background: #f9fafb; border-radius: 12px; padding: 10px 12px; line-height: 1.55; }

    html[data-theme="dark"] :is(.guide-banner-text, .guide-intro, .guide-section p, .guide-section li, .guide-note) { color: var(--text-secondary); }
    html[data-theme="dark"] .guide-table td { color: var(--text-main); }
    html[data-theme="dark"] .guide-table th { color: var(--text-light); }
    html[data-theme="dark"] .guide-note { background: var(--surface-alt); }

    /* Cash on Delivery state on cards and in the log */
    .cod-line { font-size: 0.7rem; font-weight: 700; color: #92400e; margin-top: 4px; }
    html[data-theme="dark"] .cod-line { color: #fcd34d; }
    .fh-section { margin-bottom: 1.5rem; }
    .fh-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
    .fh-toolbar label { font-size: 0.72rem; font-weight: 700; color: #6b7280; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .fh-order { border: 1px solid var(--border-color); border-radius: 16px; padding: 14px 16px; margin-bottom: 12px; }
    .fh-order.has-activity { border-left: 4px solid #f59e0b; }
    .fh-order-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; gap: 10px; flex-wrap: wrap; }
    .fh-order-badges { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .fh-meta { font-size: 0.72rem; color: #6b7280; margin-top: 2px; }
    .fh-status { font-size: 0.62rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 10px; background: #f3f4f6; color: #374151; }
    .fh-flag-row { display: flex; align-items: flex-start; gap: 8px; font-size: 0.78rem; color: #444; line-height: 1.5; margin-top: 6px; }
    .fh-empty { text-align: center; color: #bbb; font-style: italic; padding: 2rem; }
    .fh-summary { display: flex; flex-direction: column; gap: 12px; margin-bottom: 1.5rem; padding: 14px 16px; border: 1px solid var(--border-color); border-radius: 16px; }

    /* Transaction history */
    .fh-history-box { border: 1px solid var(--border-color); border-radius: 16px; padding: 14px 16px; }
    .fh-history-note { font-size: 0.75rem; color: #4b5563; line-height: 1.5; margin: 10px 0 12px; }
    .fh-history-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .fh-stat { background: #f9fafb; border-radius: 12px; padding: 10px 12px; }
    .fh-stat-label { font-size: 0.58rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; display: block; }
    .fh-stat-value { font-size: 0.9rem; font-weight: 800; color: #1f2937; display: block; margin-top: 2px; }
    .fh-stat-sub { font-size: 0.65rem; color: #6b7280; display: block; margin-top: 1px; }
    .fh-retry { margin-top: 8px; font-size: 0.7rem; font-weight: 800; color: #7380ec; background: none; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Admin decision */
    .fh-decision-box { border: 1px solid var(--border-color); border-radius: 16px; padding: 14px 16px; }
    .fh-last-review { font-size: 0.75rem; color: #374151; background: #f9fafb; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px; line-height: 1.5; }
    .fh-blocked-note { font-size: 0.78rem; font-weight: 700; color: #7f1d1d; background: #fef2f2; border-radius: 12px; padding: 10px 12px; }
    #fhReason { width: 100%; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 12px; font-size: 0.82rem; font-family: inherit; resize: vertical; background: var(--surface); color: var(--text-main); outline: none; }
    #fhReason:focus { border-color: #f9a8d4; }
    .fh-reason-meta { display: flex; justify-content: flex-end; font-size: 0.65rem; color: #9ca3af; margin: 4px 0 10px; }
    /* "Same household" tick box: only shown when the account has a shared-address sign */
    .fh-household { display: none; align-items: flex-start; gap: 8px; font-size: 0.75rem; color: var(--text-main); background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 10px 12px; margin-bottom: 10px; cursor: pointer; line-height: 1.5; }
    .fh-household.visible { display: flex; }
    .fh-household input { margin-top: 3px; accent-color: #059669; }
    html[data-theme="dark"] .fh-household { background: rgba(5,150,105,0.12); border-color: rgba(5,150,105,0.4); }
    .fh-decision-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
    .decision-btn { padding: 9px 16px; border-radius: 20px; font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; border: none; cursor: pointer; color: #fff; font-family: inherit; display: inline-flex; align-items: center; gap: 6px; transition: 0.15s; }
    .decision-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .decision-fraud { background: #b91c1c; }
    .decision-clear { background: #059669; }
    .fh-decision-msg { font-size: 0.75rem; font-weight: 700; margin-top: 10px; min-height: 1em; }
    .fh-decision-msg.info { color: #6b7280; }
    .fh-decision-msg.success { color: #047857; }
    .fh-decision-msg.error { color: #b91c1c; }

    /* Restriction appeals panel + appeal window */
    .appeals-panel { background: var(--surface); border: 1px solid #fcd34d; border-radius: 28px; padding: 1.5rem 1.75rem; margin-bottom: 2rem; }
    .appeals-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
    .appeals-title { font-size: 0.95rem; font-weight: 900; color: var(--text-main); margin: 0; }
    .appeals-count { font-size: 0.65rem; font-weight: 900; padding: 4px 12px; border-radius: 20px; background: #f59e0b; color: #fff; }
    .appeals-sub { font-size: 0.75rem; color: #6b7280; margin: 0 0 14px; line-height: 1.5; }
    .appeal-item { border: 1px solid var(--border-color); border-radius: 18px; padding: 14px 16px; margin-top: 10px; display: flex; gap: 14px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
    .appeal-item-main { flex: 1; min-width: 240px; }
    .appeal-item-name { font-size: 0.9rem; font-weight: 900; color: var(--text-main); margin: 0; }
    .appeal-item-meta { font-size: 0.7rem; color: #6b7280; margin: 2px 0 6px; }
    .appeal-item-text { font-size: 0.8rem; color: #374151; line-height: 1.5; margin: 0; white-space: pre-wrap; word-break: break-word; }
    .appeal-open-btn { padding: 9px 16px; border-radius: 20px; font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; border: none; cursor: pointer; color: #fff; background: #ec4899; font-family: inherit; white-space: nowrap; }
    .appeal-chip { font-size: 0.6rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 10px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; display: inline-flex; align-items: center; gap: 5px; }
    /* Appeal shown directly under a customer's name (card + log window) */
    .appeal-inline { margin-top: 10px; border: 1px solid #fcd34d; background: #fffbeb; border-radius: 14px; padding: 10px 12px; max-width: 520px; }
    .appeal-inline-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .appeal-inline-label { font-size: 0.62rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.8px; color: #92400e; }
    .appeal-inline-text { font-size: 0.78rem; line-height: 1.5; color: #1f2937; margin: 6px 0 0; white-space: pre-wrap; word-break: break-word; }
    .appeal-inline-meta { font-size: 0.65rem; color: #6b7280; margin: 4px 0 0; }
    .appeal-inline .appeal-open-btn { padding: 6px 12px; font-size: 0.62rem; }
    html[data-theme="dark"] .appeal-inline { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.35); }
    html[data-theme="dark"] .appeal-inline-label { color: #fcd34d; }
    html[data-theme="dark"] .appeal-inline-text { color: var(--text-main); }
    #appealOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 520; align-items: center; justify-content: center; padding: 20px; }
    #appealOverlay.open { display: flex; }
    #appealModal { background: var(--surface); color: var(--text-main); border-radius: 24px; padding: 2rem; max-width: 640px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #appealModal h3 { font-size: 1.25rem; font-weight: 900; margin: 0 0 0.25rem; }
    #appealModal .close-btn { float: right; background: none; border: none; font-size: 1.1rem; color: #999; cursor: pointer; }
    .appeal-message-box { background: #f9fafb; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; font-size: 0.82rem; line-height: 1.6; color: #1f2937; white-space: pre-wrap; word-break: break-word; margin: 6px 0 14px; max-height: 220px; overflow-y: auto; }
    #appealReason { width: 100%; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 12px; font-size: 0.82rem; font-family: inherit; resize: vertical; background: var(--surface); color: var(--text-main); outline: none; }
    #appealReason:focus { border-color: #f9a8d4; }
    .decision-lift { background: #059669; }
    .decision-keep { background: #b45309; }
    .appeal-open-log { font-size: 0.7rem; font-weight: 800; color: #7380ec; background: none; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; padding: 0; font-family: inherit; }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .audit-trail-btn { background: var(--surface-alt); }
    html[data-theme="dark"] .audit-trail-btn:hover { background: var(--background); border-color: var(--text-light); }
    html[data-theme="dark"] .fh-flag-row,
    html[data-theme="dark"] .fh-meta,
    html[data-theme="dark"] .fh-toolbar label,
    html[data-theme="dark"] .fh-history-note,
    html[data-theme="dark"] .card-review-line { color: var(--text-secondary); }
    html[data-theme="dark"] .fh-status { background: var(--surface-alt); color: var(--text-main); }
    html[data-theme="dark"] .fh-empty { color: var(--text-light); }
    html[data-theme="dark"] .fraud-card { box-shadow: none; }
    html[data-theme="dark"] .filter-chip:not(.active) { color: var(--text-secondary); }
    html[data-theme="dark"] .score-pill { background: var(--surface-alt); color: var(--text-main); border-color: var(--border-color); }
    html[data-theme="dark"] .score-pill.unscored { color: var(--text-light); }
    html[data-theme="dark"] .score-value,
    html[data-theme="dark"] .fh-stat-value { color: var(--text-main); }
    html[data-theme="dark"] :is(.score-value small, .score-value.unscored, .score-label, .score-note, .fh-stat-label, .fh-stat-sub, .fh-reason-meta) { color: var(--text-light); }
    html[data-theme="dark"] .score-track { background: var(--surface-alt); }
    html[data-theme="dark"] .fill-critical { background: #dc2626; }
    html[data-theme="dark"] :is(.fh-stat, .fh-last-review) { background: var(--surface-alt); color: var(--text-main); }
    html[data-theme="dark"] .fh-blocked-note { background: rgba(239, 68, 68, 0.15); color: #fca5a5; }
    html[data-theme="dark"] .fh-decision-msg.success { color: #6ee7b7; }
    html[data-theme="dark"] .fh-decision-msg.error { color: #fca5a5; }

    html[data-theme="dark"] :is(.appeals-sub, .appeal-item-meta) { color: var(--text-secondary); }
    html[data-theme="dark"] .appeal-item-text { color: var(--text-main); }
    html[data-theme="dark"] .appeal-message-box { background: var(--surface-alt); color: var(--text-main); }
    html[data-theme="dark"] .appeal-chip { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }

    html[data-theme="dark"] .risk-low { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
    html[data-theme="dark"] .risk-medium { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
    html[data-theme="dark"] .risk-high { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
    html[data-theme="dark"] .risk-blocked { border-color: #6b7280; }
    html[data-theme="dark"] .lvl-critical { background: rgba(127, 29, 29, 0.85); color: #fecaca; border-color: #b91c1c; }

    html[data-theme="dark"] .cat-account_takeover { background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border-color: rgba(139, 92, 246, 0.3); }
    html[data-theme="dark"] .cat-payment_fraud { background: rgba(59, 130, 246, 0.15); color: #93c5fd; border-color: rgba(59, 130, 246, 0.3); }
    html[data-theme="dark"] .cat-fake_transaction { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
    html[data-theme="dark"] .cat-malicious_return { background: rgba(20, 184, 166, 0.15); color: #5eead4; border-color: rgba(20, 184, 166, 0.3); }
    html[data-theme="dark"] :is(.cat-account_action, .cat-other) { background: var(--surface-alt); color: var(--text-secondary); border-color: var(--border-color); }

    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.bg-gray-50, .bg-gray-100) { background-color: var(--surface-alt); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.border-gray-50, .border-gray-100, .border-gray-200) { border-color: var(--border-color); }
    html[data-theme="dark"] .fraud-content .bg-pink-50 { background-color: rgba(236, 72, 153, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-red-50 { background-color: rgba(239, 68, 68, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-amber-50 { background-color: rgba(245, 158, 11, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-rose-50 { background-color: rgba(244, 63, 94, 0.12); }
</style>

<main class="fraud-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-6">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Fraud Activity Log</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Accounts are ordered by risk level and triage score so the riskiest are reviewed first. Decisions are made inside each account's log, after reviewing its history.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
           
            <div class="relative flex-1 md:flex-none">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" id="fraudAccountSearch" class="bg-white border border-gray-100 rounded-2xl px-12 py-3 text-sm outline-none focus:border-pink-300 transition-all w-full md:w-80 shadow-sm" placeholder="Search Account Name or UID...">
            </div>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <!-- Opens the plain-language guide below. Super admin only. -->
    <button type="button" class="guide-banner" onclick="openMethodologyModal()">
        <div>
            <p class="guide-banner-title">Super Admin Guide: How the Fraud Activity Log works</p>
            <p class="guide-banner-text">What the system checks at checkout, how accounts are ranked, and what you and your admins decide.</p>
        </div>
        <span class="guide-banner-link">Read the guide &rarr;</span>
    </button>
    <?php endif; ?>

    <!-- Overview Counters -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Accounts Monitored</p>
                <h3 class="brand-font text-3xl font-black text-gray-800" id="count-total">0</h3>
            </div>
            <div class="w-12 h-12 bg-pink-50 text-pink-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-users-viewfinder"></i></div>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">High Priority (To Review)</p>
                <h3 class="brand-font text-3xl font-black text-rose-600" id="count-priority">0</h3>
            </div>
            <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Accounts With Flagged Activity</p>
                <h3 class="brand-font text-3xl font-black text-amber-500" id="count-flagged">0</h3>
            </div>
            <div class="w-12 h-12 bg-amber-50 text-amber-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-flag"></i></div>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Restricted / Blacklisted</p>
                <h3 class="brand-font text-3xl font-black text-red-500" id="count-actioned">0</h3>
            </div>
            <div class="w-12 h-12 bg-red-50 text-red-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-user-lock"></i></div>
        </div>
    </div>

    <!-- Restriction appeals: filled live by JavaScript from fraud_appeals.
         Stays hidden while there is nothing to review. -->
    <section id="appealsPanel" class="appeals-panel" style="display:none;">
        <div class="appeals-head">
            <h2 class="appeals-title"><i class="fa-solid fa-envelope-open-text mr-2 text-amber-500"></i>Restriction Appeals</h2>
            <span class="appeals-count" id="appealsCount">0</span>
        </div>
        <p class="appeals-sub">These customers cannot order with any payment method until you decide. Read the appeal, check the account's log if needed, then lift or keep the restriction.</p>
        <div id="appealsList"></div>
    </section>

    <!-- Filter chips: built by JavaScript from FILTERS below -->
    <div id="fraudFilterBar" class="flex flex-wrap gap-2 mb-8"></div>

    <div id="fraudAnalyticsGrid" style="display:grid; grid-template-columns: 1fr; gap:24px;">
        <div class="text-center p-12 text-gray-300 italic">Loading customer accounts...</div>
    </div>
</main>

<!-- Fraud Activity Log modal: opened from a customer's card -->
<div id="fraudHistoryOverlay">
    <div id="fraudHistoryModal">
        <button class="close-btn" onclick="closeFraudActivityLog()"><i class="fa-solid fa-xmark"></i></button>
        <h3 id="fhCustomerName">Fraud Activity Log</h3>
        <p class="text-xs text-gray-400 font-mono mb-3" id="fhCustomerUid"></p>
        <!-- Pending appeal for this customer (filled by renderLogAppeal) -->
        <div id="fhAppeal"></div>
        <div class="fh-summary" id="fhRiskSummary"></div>

        <div class="fh-section">
            <h4 class="mb-2">Account-Level Activity</h4>
            <div id="fhAccountActivity"></div>
        </div>

        <div class="fh-section">
            <h4 class="mb-2">Transaction History</h4>
            <div id="fhHistory"></div>
        </div>

        <div class="fh-section">
            <div class="fh-toolbar">
                <h4>Order Log</h4>
                <label><input type="checkbox" id="fhFlaggedOnly"> Only orders with flagged activity</label>
            </div>
            <div id="fhOrderList"><div class="fh-empty">Loading order log...</div></div>
        </div>

        <!-- Static markup on purpose: re-rendering it would wipe the
             reason the admin is typing. JavaScript only toggles it. -->
        <div class="fh-section">
            <h4 class="mb-2">Admin Decision</h4>
            <div class="fh-decision-box">
                <div id="fhLastReview"></div>
                <div id="fhDecisionBlocked" class="fh-blocked-note" style="display:none;">
                    <i class="fa-solid fa-ban mr-1"></i> This account is blacklisted. No further decisions can be made here.
                </div>
                <div id="fhDecisionForm">
                    <p class="fh-meta" style="margin-bottom:8px;">Decide only after reading the transaction history and the order log above. Your reason is saved in the admin audit log.</p>
                    <textarea id="fhReason" rows="3" maxlength="500" placeholder="Explain your decision (at least 15 characters)..."></textarea>
                    <div class="fh-reason-meta"><span id="fhReasonCount">0 / 500</span></div>
                    <!-- Shown by renderDecisionControls() only when this account has a
                         shared-address sign. Sent as markHousehold with Mark False Alarm. -->
                    <label id="fhHouseholdWrap" class="fh-household" for="fhHousehold">
                        <input type="checkbox" id="fhHousehold">
                        <span><strong>Same household:</strong> the shared delivery address belongs to this customer's family or home. Stop flagging it for this account. Applies to <em>Mark False Alarm</em> only; other accounts using the address are still checked.</span>
                    </label>
                    <div class="fh-decision-buttons">
                        <button type="button" id="fhConfirmFraud" class="decision-btn decision-fraud" disabled>
                            <i class="fa-solid fa-user-slash"></i> Confirm Fraud
                        </button>
                        <button type="button" id="fhFalseAlarm" class="decision-btn decision-clear" disabled>
                            <i class="fa-solid fa-circle-check"></i> Mark False Alarm
                        </button>
                    </div>
                    <p id="fhDecisionMsg" class="fh-decision-msg"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Appeal window: opened from the Restriction Appeals panel.
     Static markup on purpose: re-rendering would wipe the reason being typed. -->
<div id="appealOverlay">
    <div id="appealModal" role="dialog" aria-modal="true" aria-labelledby="appealTitle">
        <button class="close-btn" type="button" onclick="closeAppealModal()"><i class="fa-solid fa-xmark"></i></button>
        <h3 id="appealTitle">Restriction Appeal</h3>
        <p class="text-xs text-gray-400 font-mono mb-3" id="appealUid"></p>
        <div class="fh-summary" id="appealSummary"></div>

        <h4 class="mb-1" style="font-size:0.7rem;font-weight:900;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;">Customer's Appeal</h4>
        <div class="appeal-message-box" id="appealMessage"></div>

        <p class="mb-3"><button type="button" class="appeal-open-log" id="appealOpenLog"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Open this account's Fraud Activity Log &rarr;</button></p>

        <div class="fh-decision-box">
            <p class="fh-meta" style="margin-bottom:8px;">Your reason is saved in the admin audit log. The customer only sees whether the appeal was approved or not.</p>
            <textarea id="appealReason" rows="3" maxlength="500" placeholder="Explain your decision (at least 15 characters)..."></textarea>
            <div class="fh-reason-meta"><span id="appealReasonCount">0 / 500</span></div>
            <div class="fh-decision-buttons">
                <button type="button" id="appealLift" class="decision-btn decision-lift" disabled>
                    <i class="fa-solid fa-lock-open"></i> Lift Restriction
                </button>
                <button type="button" id="appealKeep" class="decision-btn decision-keep" disabled>
                    <i class="fa-solid fa-lock"></i> Keep Restriction
                </button>
            </div>
            <p id="appealDecisionMsg" class="fh-decision-msg"></p>
        </div>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- "How this works" guide, written for the super admin.
     Every number here must match includes/fraud_activity.php. -->
<div id="methodologyModalOverlay" onclick="if(event.target === this) closeMethodologyModal();">
    <div id="methodologyModal" role="dialog" aria-modal="true" aria-labelledby="guideTitle">
        <button class="close-btn" onclick="closeMethodologyModal()" title="Close"><i class="fa-solid fa-xmark"></i></button>
        <p class="guide-note" style="margin:0 0 4px;">Super Admin Guide</p>
        <h3 id="guideTitle" class="text-xl font-black m-0">How the Fraud Activity Log Works</h3>
        <p class="guide-intro">
            This guide is for you as super admin. It explains what the fraud rules do, so you can
            answer for them and check that your admins use them correctly. Every online order is
            checked for warning signs at the moment the customer places it. The system does not
            decide who is a fraudster. It notes what it found, ranks the accounts so the most
            worrying ones are reviewed first, and leaves the final decision to you or your admins.
        </p>

        <div class="guide-section">
            <h4>1. What the system looks for</h4>
            <p>Each warning sign belongs to one of four kinds of online-shop fraud described in e-commerce fraud studies:</p>
            <ul>
                <li><strong>Account Theft:</strong> someone may be using another person's account, for example ordering through a hidden network connection or from a location far from the delivery area.</li>
                <li><strong>Payment Fraud:</strong> someone may be trying to pay less than the real price, or the amount is far outside what is normal.</li>
                <li><strong>Fake Transaction:</strong> the order may not be genuine, for example a throwaway phone number, details already used by a different account, or many orders in a few minutes.</li>
                <li><strong>Malicious Return:</strong> refused deliveries or false complaints after delivery.</li>
            </ul>
        </div>

        <div class="guide-section">
            <h4>2. How much each warning sign counts</h4>
            <p>Signs backed by stronger evidence count for more. The same kind of sign is counted only once, so one problem cannot inflate the total.</p>
            <table class="guide-table">
                <thead><tr><th>Weight</th><th>Warning signs</th></tr></thead>
                <tbody>
                    <tr><td>15 points</td><td>Order placed from a device that was banned before. Prices sent from the customer's browser lower than the real prices.</td></tr>
                    <tr><td>10 points</td><td>Throwaway or internet-only phone number. Two or more other orders by the same account within 5 minutes. A returning customer's order well above their usual spending range. A new account placing an unusually large order.</td></tr>
                    <tr><td>8 points</td><td>Hidden or high-risk internet connection (VPN, proxy, Tor). Delivery point more than 50 km from the branch.</td></tr>
                    <tr><td>6 points</td><td>One other order by the same account within 5 minutes (people sometimes reorder a forgotten item). Phone number already used by a different account. Delivery address shared by more than 3 accounts with no successful delivery there yet. Risky email address at sign-up.</td></tr>
                </tbody>
            </table>
            <p class="guide-note">The point values are starting values chosen by the BLOOM team from the research. They will be adjusted once enough real cases have been reviewed by staff.</p>
        </div>

        <div class="guide-section">
            <h4>3. Risk level and triage score</h4>
            <ul>
                <li><strong>Risk level</strong> depends on how many <em>kinds</em> of fraud were seen: <strong>Critical</strong> when a banned device was used, <strong>High</strong> for two or more kinds, <strong>Medium</strong> for one kind, <strong>Low</strong> for none.</li>
                <li><strong>Triage score</strong> (0 to 100) adds up the points of the warning signs on the account.</li>
            </ul>
            <p>Accounts are listed by level first and score second, so the riskiest are reviewed first. The score is a ranking aid, not a percentage chance that someone is committing fraud.</p>
        </div>

        <div class="guide-section">
            <h4>4. Comparing an order with what is usual</h4>
            <ul>
                <li><strong>Returning customers</strong> (3 or more orders in the last 60 days): the system works out their average order and how much their orders usually vary. An order is flagged when it goes well beyond that usual range: above the average plus two times the usual variation (two "standard deviations"). Example: a customer whose orders were P800, P1,000 and P1,200 averages P1,000 and usually varies by about P200, so an order above P1,400 is flagged. This is the same range used in a 2024 credit card fraud study.</li>
                <li><strong>New accounts</strong> (30 days old or less, with no such history): an order is flagged when it is larger than 95 out of every 100 recent orders in the shop. The line is recalculated from the latest 200 orders, so it follows the season automatically.</li>
            </ul>
            <p>A first-time customer is never flagged just for being new. Large orders around Valentine's Day or Mother's Day are normal, which is why these are warning signs for review and not proof.</p>
        </div>

        <div class="guide-section">
            <h4>5. What happens automatically, and what staff decide</h4>
            <ul>
                <li><strong>Banned device:</strong> the account is restricted for up to 30 days. While restricted, the customer cannot order with any payment method (GCash, Maya or Cash on Delivery). Verifying a phone number does not lift it. Instead, the checkout shows an <em>Appeal</em> link above the restriction message.</li>
                <li><strong>Score of 50 or more:</strong> Cash on Delivery is turned off and the customer pays online with GCash or Maya. Fresh bouquets cannot be resold if a delivery is refused, so this protects the shop without stopping the order.</li>
                <li><strong>Everything else:</strong> the order goes through normally and the warning signs wait for staff review.</li>
            </ul>
            <p>You or an admin open an account's log, read its transaction history and orders, and choose one of two decisions, each with a written reason. Every decision and its reason is saved in the admin audit log, so you can check later who decided what and why:</p>
            <ul>
                <li><strong>Confirm Fraud:</strong> the account is blacklisted and its devices are banned.</li>
                <li><strong>Mark False Alarm:</strong> any restriction is lifted and Cash on Delivery comes back, until a new warning sign appears.</li>
                <li><strong>Restriction appeals:</strong> when a restricted customer sends an appeal, you get a notification and the appeal appears in the <em>Restriction Appeals</em> panel at the top of this page. Read it, open the account's log if you want more detail, then choose <em>Lift Restriction</em> or <em>Keep Restriction</em> with a written reason. If you keep it, the customer can send a new appeal after 24 hours. Restrictions also end by themselves after 30 days.</li>
                <li><strong>Shared addresses:</strong> up to 3 accounts may order to one address without any warning sign. An address that has already received a successful delivery counts as a trusted household and is never flagged for sharing.</li>
                <li><strong>Same household (tick box):</strong> when a shared delivery address belongs to one family, ticking it with Mark False Alarm stops that address from being flagged for this account. Other accounts using the address are still checked.</li>
            </ul>
        </div>

        <div class="guide-section">
            <h4>6. Where the rules come from</h4>
            <p>Each warning sign is based on published sources: e-commerce fraud research (including eBay's work on linked accounts, devices and addresses, and a 2024 study on comparing orders with a customer's previous two months), OWASP security guidance, and PayMongo's fraud-prevention documentation.</p>
            <p>The rules were also tested on two public datasets (about 1.5 million online-shop transactions and 1.85 million card transactions). Each limit was tried at several values to find the best balance between catching fraud and bothering honest customers:</p>
            <ul>
                <li>New accounts placing unusually large orders were about 12.7 times more likely to be fraud than average.</li>
                <li>The "top 5%" line caught about three quarters of fraud while flagging only 5 out of 100 purchases, a better balance than 10% or 1%.</li>
                <li>One repeat order within 5 minutes was a mild sign; two or more was a strong sign, so they carry different weights.</li>
            </ul>
            <p>Both datasets are computer-generated, so they were used to compare settings and confirm which signs matter most, not as proof about BLOOM's own customers.</p>
            <p class="guide-note">The system does not use an artificial-intelligence model. Every flag comes from a specific, written rule, so staff can always see exactly why an order was flagged.</p>
        </div>

        <div class="mt-4 flex justify-end">
            <button type="button" onclick="closeMethodologyModal()" class="filter-chip active">Close guide</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    /* =====================================================================
     * 1. CATEGORY, LEVEL, SCORE AND FILTER DEFINITIONS
     * =================================================================== */

    // One entry per category. The keys (account_takeover, payment_fraud...)
    // are the stable IDs stored in Firestore; labels are display text.
    const FRAUD_CATEGORIES = {
        account_takeover: { label: 'Account Theft',     icon: 'fa-user-secret' },
        payment_fraud:    { label: 'Payment Fraud',     icon: 'fa-credit-card' },
        fake_transaction: { label: 'Fake Transaction',  icon: 'fa-receipt' },
        malicious_return: { label: 'Malicious Return',  icon: 'fa-rotate-left' },
        account_action:   { label: 'Account Action',    icon: 'fa-user-gear' },
        other:            { label: 'Other Signal',      icon: 'fa-circle-question' }
    };

    // Only these four count toward the risk LEVEL (same list as
    // BLOOM_FRAUD_CATEGORIES in includes/fraud_activity.php).
    const STUDY_CATEGORIES = ['account_takeover', 'payment_fraud', 'fake_transaction', 'malicious_return'];

    // Same as BLOOM_HARD_EVIDENCE_CODES in includes/fraud_activity.php.
    const HARD_EVIDENCE_CODES = ['banned_device'];

    // Same as BLOOM_RISK_SCORE_CAP in includes/fraud_activity.php.
    const SCORE_MAX = 100;

    // Same as BLOOM_COD_BLOCK_SCORE in includes/fraud_activity.php.
    const COD_BLOCK_SCORE = 50;

    // Same as BLOOM_REVIEW_REASON_MIN_CHARS / MAX in fraud_review.php.
    const REASON_MIN_CHARS = 15;
    const REASON_MAX_CHARS = 500;

    // Review endpoint (same folder as this page).
    const REVIEW_ENDPOINT = 'fraud_review.php';

    // Appeal endpoint. Reason limits are the same as
    // BLOOM_APPEAL_REASON_MIN_CHARS / MAX in fraud_appeal.php.
    const APPEAL_ENDPOINT = 'fraud_appeal.php';

    // Translates free-text flags into a category. Checked top to bottom;
    // the first rule with a matching keyword wins, so order matters:
    // account actions go first because "Automated 30-Day Restriction ...
    // (previously banned device)" mentions a banned device but is a
    // system action, not a new fraud activity. 'blacklisted' and
    // 'reviewed by admin' cover the notes written by fraud_review.php.
    const CATEGORY_RULES = [
        { category: 'account_action',   keywords: ['restriction', 'restricted', 'trust restored', 'verified via', 'blacklisted', 'reviewed by admin'] },
        { category: 'malicious_return', keywords: ['refund', 'void', 'return', 'not delivered', 'wilted'] },
        { category: 'payment_fraud',    keywords: ['payment', 'declined', 'dispute', 'chargeback', 'usual spending', 'top 5%'] },
        { category: 'account_takeover', keywords: ['tor/abuse', 'vpn', 'proxy', 'device-to-destination', 'new device', 'unrecognized device'] },
        { category: 'fake_transaction', keywords: ['banned device', 'disposable', 'voip', 'phone number already', 'address already', 'repeat checkout', 'rapid checkouts', 'first order', 'email risk'] }
    ];

    // Account states, with a rank used for sorting (lower = shown first).
    const STATE_META = {
        blocked:    { label: 'Blacklisted',             badge: 'risk-blocked', rank: 0 },
        restricted: { label: 'Restricted',              badge: 'risk-high',    rank: 1 },
        flagged:    { label: 'Flagged Activity',        badge: 'risk-medium',  rank: 2 },
        reviewed:   { label: 'Reviewed - False Alarm',  badge: 'risk-low',     rank: 3 },
        clear:      { label: 'No Flagged Activity',     badge: 'risk-low',     rank: 4 }
    };

    // Option B risk levels (same keys as BLOOM_RISK_LEVELS in PHP).
    const RISK_LEVELS = {
        critical: { label: 'Critical Risk', badge: 'lvl-critical', fill: 'fill-critical', icon: 'fa-skull-crossbones',     rank: 0 },
        high:     { label: 'High Risk',     badge: 'risk-high',    fill: 'fill-high',     icon: 'fa-triangle-exclamation', rank: 1 },
        medium:   { label: 'Medium Risk',   badge: 'risk-medium',  fill: 'fill-medium',   icon: 'fa-circle-exclamation',   rank: 2 },
        low:      { label: 'Low Risk',      badge: 'risk-low',     fill: 'fill-low',      icon: 'fa-circle-check',         rank: 3 }
    };

    // Transaction-history profiles returned by fraud_review.php.
    const HISTORY_PROFILES = {
        no_orders:  { label: 'No Orders Yet',                       badge: 'risk-low' },
        cold_start: { label: 'New Account - No Completed Orders',   badge: 'risk-medium' },
        returning:  { label: 'Returning Customer',                  badge: 'risk-low' }
    };

    const DECISION_LABELS = {
        confirmed_fraud: 'Confirmed Fraud',
        false_alarm: 'False Alarm'
    };

    // Filter chips shown above the grid.
    const FILTERS = [
        { key: 'all',              label: 'All Accounts' },
        { key: 'priority',         label: 'High Priority' },
        { key: 'appeal',           label: 'Appeal Pending' },
        { key: 'flagged',          label: 'Flagged Only' },
        { key: 'account_takeover', label: 'Account Theft' },
        { key: 'payment_fraud',    label: 'Payment Fraud' },
        { key: 'fake_transaction', label: 'Fake Transaction' },
        { key: 'malicious_return', label: 'Malicious Return' }
    ];

    /* =====================================================================
     * 2. PAGE STATE
     * =================================================================== */
    let accounts = [];          // every customer, already summarized, sorted
    let accountIndex = {};      // uid -> account, for quick lookup on clicks
    let activeFilter = 'all';   // which filter chip is selected
    let openLogUid = null;      // whose log the modal is currently showing
    let openLogOrders = [];     // that customer's loaded orders
    let openLogHistory = null;  // that customer's server-computed history
    let decisionBusy = false;   // true while a decision is being saved
    let pendingAppeals = {};    // uid -> pending appeal document (live)
    let openAppealUid = null;   // whose appeal the appeal window is showing
    let appealBusy = false;     // true while an appeal decision is being saved

    /* =====================================================================
     * 3. SMALL HELPERS
     * =================================================================== */

    // Makes any text safe to put inside innerHTML, so a customer named
    // "<img onerror=...>" shows up as text instead of running as code.
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch]));
    }

    function maskCustomerName(name) {
        if (!name) return "A********* U***";
        const parts = name.trim().split(' ');
        return parts.map(p => p.length <= 2 ? p[0] + "*" : p[0] + "*".repeat(p.length - 2) + p[p.length - 1]).join(' ');
    }

    function formatPeso(amount) {
        const n = Number(amount || 0);
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // ISO text from fraud_review.php -> local date, or a dash.
    function formatIsoDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        return isNaN(d.getTime()) ? '—' : d.toLocaleDateString();
    }

    // Orders written by submit_order.php carry createdAt; some older or
    // POS-created ones only carry timestamp. Try both.
    function getOrderMillis(order) {
        const ts = order.createdAt || order.timestamp;
        return ts && ts.toMillis ? ts.toMillis() : 0;
    }

    // A score is only trusted when the server actually wrote a number.
    function readScore(doc) {
        if (typeof doc.riskScore === 'number' && Number.isFinite(doc.riskScore)) return doc.riskScore;
        if (typeof doc.fraudScore === 'number' && Number.isFinite(doc.fraudScore)) return doc.fraudScore;
        return null;
    }

    // Same 30-day rule as bloom_restriction_state() in PHP: a restriction
    // whose end date has passed no longer counts.
    function isRestrictionActive(doc) {
        if (doc.isRestricted !== true) return false;
        const until = doc.restrictedUntil;
        if (until && until.toMillis) return until.toMillis() > Date.now();
        return true; // old record without an end date: still active
    }

    // "False alarm" counts only while no NEW activity arrived after it.
    function isDismissed(doc) {
        const review = doc.fraudReview;
        if (!review || review.decision !== 'false_alarm' || !review.reviewedAt || !review.reviewedAt.toMillis) return false;
        const last = doc.lastFraudActivityAt;
        if (!last || !last.toMillis) return true;
        return review.reviewedAt.toMillis() >= last.toMillis();
    }

    // Same rule as bloom_customer_cod_blocked() in PHP: banned, an active
    // restriction, or a score at/above COD_BLOCK_SCORE that has not been
    // cleared as a false alarm.
    function isCodBlocked(doc, score, dismissed) {
        if (doc.isBanned === true || isRestrictionActive(doc)) return true;
        return score !== null && score >= COD_BLOCK_SCORE && !dismissed;
    }

    function buildCodLine(account) {
        if (account.state === 'blocked' || !account.codBlocked) return '';
        return '<p class="cod-line"><i class="fa-solid fa-money-bill-wave mr-1"></i>Cash on Delivery off: online payment only</p>';
    }

    function buildLevelBadge(level) {
        const meta = RISK_LEVELS[level] || RISK_LEVELS.low;
        return `<span class="risk-badge ${meta.badge}"><i class="fa-solid ${meta.icon}"></i>${escapeHtml(meta.label)}</span>`;
    }

    // Compact pill, used on single orders inside the log.
    function buildScorePill(score) {
        if (score === null) {
            return '<span class="score-pill unscored" title="Recorded before triage scoring existed">Not scored</span>';
        }
        return `<span class="score-pill" title="Triage points: sets review order only"><i class="fa-solid fa-gauge-high"></i>${score} pts</span>`;
    }

    /**
     * Triage score with a bar line.
     *   score = null -> "Not scored" with an empty grey bar
     *   score = n    -> "n / 100" with a bar filled n% in the level's color
     */
    function buildScoreBar(score, level) {
        if (score === null) {
            return `
            <div class="score-block">
                <div class="score-head">
                    <span class="score-label">Triage Score</span>
                    <span class="score-value unscored">Not scored</span>
                </div>
                <div class="score-track"><div class="score-fill" style="width:0%"></div></div>
                <p class="score-note">Recorded before triage scoring existed.</p>
            </div>`;
        }

        const meta = RISK_LEVELS[level] || RISK_LEVELS.low;
        const safeScore = Math.max(0, Math.min(SCORE_MAX, Math.round(score)));
        const percent = Math.round((safeScore / SCORE_MAX) * 100);

        return `
            <div class="score-block">
                <div class="score-head">
                    <span class="score-label">Triage Score</span>
                    <span class="score-value">${safeScore}<small> / ${SCORE_MAX}</small></span>
                </div>
                <div class="score-track" role="progressbar" aria-label="Triage score"
                     aria-valuemin="0" aria-valuemax="${SCORE_MAX}" aria-valuenow="${safeScore}">
                    <div class="score-fill ${meta.fill}" style="width:${percent}%"></div>
                </div>
                <p class="score-note">Sets review order. At ${COD_BLOCK_SCORE} or more, Cash on Delivery is turned off.</p>
            </div>`;
    }

    /* =====================================================================
     * 4. CLASSIFYING FLAGS AND SUMMARIZING ACCOUNTS
     * =================================================================== */

    // Accepts either an old text flag ("VPN/Proxy detected") or a
    // structured activity ({ category: 'payment_fraud', reason: '...' })
    // and always returns the same shape: { category, text }.
    function classifyFlag(flag) {
        if (flag && typeof flag === 'object') {
            const category = FRAUD_CATEGORIES[flag.category] ? flag.category : 'other';
            return { category, text: flag.reason || flag.text || 'Unlabeled activity' };
        }
        const text = String(flag ?? '');
        const lower = text.toLowerCase();
        for (const rule of CATEGORY_RULES) {
            if (rule.keywords.some(word => lower.includes(word))) {
                return { category: rule.category, text };
            }
        }
        return { category: 'other', text };
    }

    function buildCategoryChip(category, suffix = '') {
        const meta = FRAUD_CATEGORIES[category] || FRAUD_CATEGORIES.other;
        return `<span class="cat-chip cat-${category}"><i class="fa-solid ${meta.icon}"></i>${escapeHtml(meta.label)}${suffix}</span>`;
    }

    /**
     * Risk level for one account.
     * 1) If the server already wrote a valid riskLevel, use it (source of truth).
     * 2) Otherwise (accounts recorded before triage existed) derive it with
     *    the SAME Option B rule as bloom_fraud_risk_level() in PHP.
     */
    function deriveRiskLevel(doc, activity) {
        if (RISK_LEVELS[doc.riskLevel]) return doc.riskLevel;

        const codes = Array.isArray(doc.fraudCodes) ? doc.fraudCodes : [];
        const hardEvidence = codes.some(code => HARD_EVIDENCE_CODES.includes(code))
            || activity.some(f => f.text.toLowerCase().includes('banned device'));
        if (hardEvidence) return 'critical';

        const distinct = new Set(activity.map(f => f.category).filter(c => STUDY_CATEGORIES.includes(c)));
        if (distinct.size >= 2) return 'high';
        if (distinct.size === 1) return 'medium';
        return 'low';
    }

    // Turns one raw customer document into everything the card needs.
    function summarizeAccount(id, doc) {
        const name = doc.name || doc.username || (doc.email ? doc.email.split('@')[0] : '') || 'Registered User';
        const rawFlags = Array.isArray(doc.fraudFlags) ? doc.fraudFlags : [];
        const classified = rawFlags.map(classifyFlag);

        // "Activity" = real fraud signals. Account actions (restrictions,
        // reviews, trust restored) are history, not fraud.
        const activity = classified.filter(f => f.category !== 'account_action');

        const counts = {};
        activity.forEach(f => { counts[f.category] = (counts[f.category] || 0) + 1; });

        const dismissed = isDismissed(doc);

        let state = 'clear';
        if (doc.status === 'blocked') state = 'blocked';
        else if (isRestrictionActive(doc)) state = 'restricted';
        else if (activity.length > 0) state = dismissed ? 'reviewed' : 'flagged';

        // Score rules:
        //   - server wrote a number          -> use it
        //   - no number AND no activity       -> truly 0 points
        //   - no number BUT activity exists   -> null ("Not scored", legacy)
        const storedScore = readScore(doc);
        const score = storedScore !== null ? storedScore : (activity.length === 0 ? 0 : null);

        return {
            id,
            doc,
            name,
            maskedName: maskCustomerName(name),
            classified,
            activity,
            counts,
            state,
            dismissed,
            review: doc.fraudReview || null,
            level: deriveRiskLevel(doc, activity),
            score,
            codBlocked: isCodBlocked(doc, score, dismissed)
        };
    }

    /* =====================================================================
     * 5. RENDERING THE GRID
     * =================================================================== */

    function renderFilterBar() {
        const bar = document.getElementById('fraudFilterBar');
        bar.innerHTML = FILTERS.map(f =>
            `<button type="button" class="filter-chip ${f.key === activeFilter ? 'active' : ''}" data-filter="${f.key}">${escapeHtml(f.label)}</button>`
        ).join('');
    }

    // High priority = still NEEDS a decision: critical/high level, not
    // already blacklisted, and not cleared as a false alarm.
    function isHighPriority(account) {
        return (account.level === 'critical' || account.level === 'high')
            && account.state !== 'blocked'
            && !account.dismissed;
    }

    function matchesFilter(account) {
        if (activeFilter === 'all') return true;
        if (activeFilter === 'priority') return isHighPriority(account);
        if (activeFilter === 'appeal') return !!pendingAppeals[account.id];
        if (activeFilter === 'flagged') return account.state !== 'clear';
        return (account.counts[activeFilter] || 0) > 0;
    }

    function matchesSearch(account, term) {
        if (!term) return true;
        return account.name.toLowerCase().includes(term) || account.id.toLowerCase().includes(term);
    }

    // The pending appeal, shown right under the customer's name. Used on the
    // account card AND at the top of the Fraud Activity Log window.
    // Empty string when this customer has no pending appeal.
    function buildInlineAppeal(uid) {
        const appeal = pendingAppeals[uid];
        if (!appeal) return '';
        const text = String(appeal.message || '');
        const preview = text.length > 220 ? text.slice(0, 220).trim() + '…' : text;
        return `
        <div class="appeal-inline">
            <div class="appeal-inline-head">
                <span class="appeal-inline-label"><i class="fa-solid fa-envelope-open-text mr-1"></i>Customer appeal</span>
                <button type="button" class="appeal-open-btn" data-action="open-appeal" data-uid="${escapeHtml(uid)}">Review appeal</button>
            </div>
            <p class="appeal-inline-text">${escapeHtml(preview)}</p>
            <p class="appeal-inline-meta">Sent ${escapeHtml(formatTimestamp(appeal.submittedAt))}</p>
        </div>`;
    }

    function buildReviewLine(review) {
        if (!review || !DECISION_LABELS[review.decision]) return '';
        const when = review.reviewedAt && review.reviewedAt.toDate ? review.reviewedAt.toDate().toLocaleDateString() : '';
        return `<p class="card-review-line"><i class="fa-solid fa-clipboard-check mr-1"></i>Last review: ${escapeHtml(DECISION_LABELS[review.decision])}${when ? ' &bull; ' + escapeHtml(when) : ''}</p>`;
    }

    function buildCard(a) {
        const stateMeta = STATE_META[a.state];
        const isActioned = a.state === 'blocked' || a.state === 'restricted';

        // Names stay masked unless the account has already been actioned;
        // at that point staff need to know exactly who it is.
        const displayName = isActioned
            ? `<span class="text-red-600 font-bold"><i class="fa-solid fa-eye mr-1"></i> ${escapeHtml(a.name)}</span>`
            : escapeHtml(a.maskedName);

        // The level badge only appears when there is something to triage.
        const hasTriage = a.level !== 'low' || a.activity.length > 0;
        const levelBadge = hasTriage ? buildLevelBadge(a.level) : '';

        const categoryChips = Object.keys(a.counts).length > 0
            ? Object.entries(a.counts).map(([cat, n]) => buildCategoryChip(cat, ` &times;${n}`)).join(' ')
            : '<span class="text-xs text-gray-400 italic">No fraud activity recorded</span>';

        const restrictedUntil = a.state === 'restricted' && a.doc.restrictedUntil && a.doc.restrictedUntil.toDate
            ? `<p class="text-xs text-red-500 font-semibold mt-1">Restricted until ${escapeHtml(a.doc.restrictedUntil.toDate().toLocaleDateString())}</p>`
            : '';

        const latest = a.activity.length > 0
            ? a.activity.slice(-3).map(f => escapeHtml(f.text)).join(' &bull; ')
            : 'No fraud activity on file for this account.';

        return `
        <div class="fraud-card">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-4">
                <div>
                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                        <h3 class="brand-font text-2xl font-black text-gray-800">${displayName}</h3>
                        <span class="risk-badge ${stateMeta.badge}">${stateMeta.label}</span>
                        ${levelBadge}
                        ${pendingAppeals[a.id] ? '<span class="appeal-chip"><i class="fa-solid fa-envelope-open-text"></i>Appeal pending</span>' : ''}
                    </div>
                    <p class="text-xs font-mono text-gray-400">UID: ${escapeHtml(a.id)}</p>
                    ${restrictedUntil}
                    ${buildCodLine(a)}
                    ${buildReviewLine(a.review)}
                    ${buildInlineAppeal(a.id)}
                </div>
                <div class="flex-1" style="max-width: 420px; width: 100%;">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Recorded Activity Types</p>
                    <div class="flex flex-wrap gap-2">${categoryChips}</div>
                </div>
                <div style="width: 100%; max-width: 260px;">
                    ${buildScoreBar(a.score, a.level)}
                </div>
            </div>
            <button type="button" class="audit-trail-btn" data-action="open-log" data-uid="${escapeHtml(a.id)}">
                <span class="block text-[9px] text-gray-400 uppercase font-black mb-1">Latest Recorded Activity</span>
                <span class="text-xs text-gray-500 font-semibold">
                    <i class="fa-solid fa-circle-nodes text-pink-500 mr-1"></i> ${latest}
                </span>
                <span class="view-hint block mt-1"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Open Fraud Activity Log &rarr;</span>
            </button>
        </div>`;
    }

    function renderGrid() {
        const grid = document.getElementById('fraudAnalyticsGrid');
        const term = document.getElementById('fraudAccountSearch').value.trim().toLowerCase();
        const visible = accounts.filter(a => matchesFilter(a) && matchesSearch(a, term));

        if (visible.length === 0) {
            grid.innerHTML = `<div class="text-center p-12 text-gray-400 italic">No accounts match this view.</div>`;
            return;
        }
        grid.innerHTML = visible.map(buildCard).join('');
    }

    function renderCounters() {
        document.getElementById('count-total').innerText = accounts.length;
        document.getElementById('count-priority').innerText = accounts.filter(isHighPriority).length;
        document.getElementById('count-flagged').innerText = accounts.filter(a => a.state !== 'clear').length;
        document.getElementById('count-actioned').innerText = accounts.filter(a => a.state === 'blocked' || a.state === 'restricted').length;
    }

    /* =====================================================================
     * 6. SERVER CALLS (fraud_review.php)
     * =================================================================== */

    function reviewError(message, code) {
        const err = new Error(message);
        err.code = code || null;
        return err;
    }

    // POSTs to fraud_review.php (or another endpoint, such as the appeal
    // one) with the admin's Firebase ID token.
    // Resolves with the JSON body on success; throws an Error with .code
    // (server code, or NETWORK / BAD_RESPONSE / NO_SESSION) otherwise.
    async function callFraudReview(payload, endpoint = REVIEW_ENDPOINT) {
        const user = firebase.auth().currentUser;
        if (!user) {
            throw reviewError('Your session is not ready yet. Please refresh the page and try again.', 'NO_SESSION');
        }
        const token = await user.getIdToken();

        let response;
        try {
            response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
                body: JSON.stringify(payload)
            });
        } catch (e) {
            throw reviewError('Could not reach the server. Please check your connection and try again.', 'NETWORK');
        }

        // Read the reply as TEXT first, then parse it. If the server sent
        // something that is not JSON (a PHP error, a hosting error page, an
        // empty reply), show the HTTP status and a short preview so the real
        // cause is visible. The full reply also goes to the browser console.
        let data;
        const rawText = await response.text();
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error('Unexpected reply from ' + endpoint + ' (HTTP ' + response.status + '):\n' + rawText);
            const preview = rawText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
            throw reviewError(
                'The server returned an unexpected response (HTTP ' + response.status + '). '
                + (preview ? 'Server said: "' + preview + '"' : 'The reply was empty.'),
                'BAD_RESPONSE'
            );
        }

        if (!data || data.success !== true) {
            throw reviewError((data && data.message) || 'The request could not be completed.', data && data.code);
        }
        return data;
    }

    /* =====================================================================
     * 7. FRAUD ACTIVITY LOG MODAL: SUMMARY, ACTIVITY, ORDERS
     * =================================================================== */

    // Pending appeal at the top of the log window, under the name.
    function renderLogAppeal() {
        const box = document.getElementById('fhAppeal');
        box.innerHTML = openLogUid ? buildInlineAppeal(openLogUid) : '';
    }

    function renderRiskSummary(account) {
        const el = document.getElementById('fhRiskSummary');
        el.innerHTML = `
            <div>${buildLevelBadge(account.level)} <span class="risk-badge ${STATE_META[account.state].badge}">${STATE_META[account.state].label}</span></div>
            ${buildScoreBar(account.score, account.level)}
            ${buildCodLine(account)}`;
    }

    function renderAccountActivity(account) {
        const el = document.getElementById('fhAccountActivity');
        if (account.classified.length === 0) {
            el.innerHTML = '<p class="text-xs text-gray-400 italic">Nothing recorded at the account level.</p>';
            return;
        }
        el.innerHTML = account.classified.map(f => `
            <div class="fh-flag-row">${buildCategoryChip(f.category)}<span>${escapeHtml(f.text)}</span></div>
        `).join('');
    }

    function renderOrderLog() {
        const listEl = document.getElementById('fhOrderList');
        const flaggedOnly = document.getElementById('fhFlaggedOnly').checked;

        const rows = openLogOrders
            .map(o => {
                const source = Array.isArray(o.fraudActivities) ? o.fraudActivities : (Array.isArray(o.fraudFlags) ? o.fraudFlags : []);
                const flags = source.map(classifyFlag);
                return { order: o, flags, hasActivity: flags.some(f => f.category !== 'account_action') };
            })
            .filter(row => !flaggedOnly || row.hasActivity);

        if (openLogOrders.length === 0) {
            listEl.innerHTML = '<div class="fh-empty">No web orders on file for this account yet.</div>';
            return;
        }
        if (rows.length === 0) {
            listEl.innerHTML = '<div class="fh-empty">None of this account\'s orders have flagged activity.</div>';
            return;
        }

        listEl.innerHTML = rows.map(({ order: o, flags, hasActivity }) => {
            const ms = getOrderMillis(o);
            const when = ms ? new Date(ms).toLocaleString() : 'Date unavailable';
            const flagHtml = flags.length > 0
                ? flags.map(f => `<div class="fh-flag-row">${buildCategoryChip(f.category)}<span>${escapeHtml(f.text)}</span></div>`).join('')
                : '<p class="fh-meta italic">No activity flagged on this order.</p>';

            // Per-order triage only exists on orders written after
            // submit_order.php starts saving riskLevel/riskScore.
            const orderTriage = RISK_LEVELS[o.riskLevel] && o.riskLevel !== 'low'
                ? `${buildLevelBadge(o.riskLevel)} ${buildScorePill(readScore(o))}`
                : '';

            return `
                <div class="fh-order ${hasActivity ? 'has-activity' : ''}">
                    <div class="fh-order-top">
                        <div>
                            <span class="font-bold text-sm text-gray-800">${escapeHtml(o.invoiceId || o.id)}</span>
                            <span class="text-xs text-gray-400 ml-2">${escapeHtml(when)}</span>
                            <p class="fh-meta">${escapeHtml(o.payment_method || 'Unknown payment')} &bull; ${formatPeso(o.total_price)}</p>
                        </div>
                        <div class="fh-order-badges">
                            ${orderTriage}
                            <span class="fh-status">${escapeHtml(o.status || 'unknown')}</span>
                        </div>
                    </div>
                    ${flagHtml}
                </div>`;
        }).join('');
    }

    /* =====================================================================
     * 8. TRANSACTION HISTORY (from fraud_review.php)
     * =================================================================== */

    function buildHistoryNote(h) {
        if (h.historyProfile === 'no_orders') {
            return 'This account has never placed an order.';
        }
        if (h.historyProfile === 'cold_start') {
            return 'Limited history to compare against: none of this account\'s orders has been completed yet. Weigh the recorded evidence carefully.';
        }
        return `${h.completedOrders} completed order(s) on record. A single flagged order may be a false positive, but long-standing accounts can still be misused, so check the order log too.`;
    }

    // One-line text version, reused in the confirmation dialog.
    function buildHistorySummaryText(h) {
        const profile = (HISTORY_PROFILES[h.historyProfile] || HISTORY_PROFILES.no_orders).label;
        const age = h.accountAgeDays !== null && h.accountAgeDays !== undefined ? `${h.accountAgeDays} day(s) old` : 'account age unknown';
        const pct = h.flaggedOrderPercent !== null && h.flaggedOrderPercent !== undefined ? ` (${h.flaggedOrderPercent}%)` : '';
        return `${profile}, ${age}. Orders: ${h.totalOrders} total, ${h.completedOrders} completed, ${h.cancelledOrders} cancelled, ${h.flaggedOrders} flagged${pct}.`;
    }

    function stat(label, value, sub = '') {
        return `
            <div class="fh-stat">
                <span class="fh-stat-label">${escapeHtml(label)}</span>
                <span class="fh-stat-value">${escapeHtml(value)}</span>
                ${sub ? `<span class="fh-stat-sub">${escapeHtml(sub)}</span>` : ''}
            </div>`;
    }

    function renderHistory(h) {
        const el = document.getElementById('fhHistory');
        const profile = HISTORY_PROFILES[h.historyProfile] || HISTORY_PROFILES.no_orders;

        const age = h.accountAgeDays !== null && h.accountAgeDays !== undefined
            ? `${h.accountAgeDays} day(s)`
            : 'Unknown';
        const flagged = h.flaggedOrderPercent !== null && h.flaggedOrderPercent !== undefined
            ? `${h.flaggedOrders} (${h.flaggedOrderPercent}%)`
            : String(h.flaggedOrders);

        // Latest order compared with this customer's own usual spend.
        let latestSub = '';
        if (h.latestOrderTotal !== null && h.averageCompletedOrder) {
            const ratio = h.latestOrderTotal / h.averageCompletedOrder;
            latestSub = `${ratio.toFixed(1)}x their average completed order`;
        }

        el.innerHTML = `
            <div class="fh-history-box">
                <span class="risk-badge ${profile.badge}"><i class="fa-solid fa-user-clock"></i>${escapeHtml(profile.label)}</span>
                <p class="fh-history-note">${escapeHtml(buildHistoryNote(h))}</p>
                <div class="fh-history-grid">
                    ${stat('Account Age', age, h.accountCreatedAt ? 'Created ' + formatIsoDate(h.accountCreatedAt) : '')}
                    ${stat('Total Orders', String(h.totalOrders), `${h.openOrders} still open`)}
                    ${stat('Completed', String(h.completedOrders))}
                    ${stat('Cancelled', String(h.cancelledOrders))}
                    ${stat('Flagged Orders', flagged)}
                    ${stat('First Order', formatIsoDate(h.firstOrderAt))}
                    ${stat('Latest Order', formatIsoDate(h.lastOrderAt))}
                    ${stat('Avg Completed Order', h.averageCompletedOrder !== null ? formatPeso(h.averageCompletedOrder) : '—', `Total spent ${formatPeso(h.completedSpend)}`)}
                    ${stat('Latest Order Total', h.latestOrderTotal !== null ? formatPeso(h.latestOrderTotal) : '—', latestSub)}
                </div>
            </div>`;
    }

    function renderLastReview(review) {
        const el = document.getElementById('fhLastReview');
        if (!review || !DECISION_LABELS[review.decision]) {
            el.innerHTML = '';
            return;
        }
        el.innerHTML = `
            <div class="fh-last-review">
                <strong>Last review: ${escapeHtml(DECISION_LABELS[review.decision])}</strong>
                by ${escapeHtml(review.reviewedByRole || 'admin')} on ${escapeHtml(formatIsoDate(review.reviewedAt))}<br>
                <span class="italic">"${escapeHtml(review.reason || '')}"</span>
            </div>`;
    }

    // Loads (or reloads) the history. Decision buttons stay disabled
    // until this finishes, so nobody decides without seeing it.
    async function loadHistory(uid) {
        openLogHistory = null;
        renderDecisionControls();
        document.getElementById('fhHistory').innerHTML = '<div class="fh-empty">Loading transaction history...</div>';

        try {
            const data = await callFraudReview({ action: 'history', uid });
            if (openLogUid !== uid) return; // admin moved on
            openLogHistory = data.history;
            renderHistory(data.history);
            renderLastReview(data.review);
        } catch (err) {
            if (openLogUid !== uid) return;
            document.getElementById('fhHistory').innerHTML = `
                <div class="fh-empty">
                    Could not load transaction history: ${escapeHtml(err.message)}<br>
                    <button type="button" class="fh-retry" data-action="retry-history">Try again</button>
                </div>`;
        } finally {
            if (openLogUid === uid) renderDecisionControls();
        }
    }

    /* =====================================================================
     * 9. ADMIN DECISION
     * =================================================================== */

    function setDecisionMessage(text, kind) {
        const el = document.getElementById('fhDecisionMsg');
        el.textContent = text || '';
        el.className = 'fh-decision-msg' + (kind ? ' ' + kind : '');
    }

    function updateReasonCount() {
        const length = document.getElementById('fhReason').value.trim().length;
        document.getElementById('fhReasonCount').textContent = `${length} / ${REASON_MAX_CHARS}${length < REASON_MIN_CHARS ? ` (min ${REASON_MIN_CHARS})` : ''}`;
    }

    // Does this account carry a shared-address sign? Checks the code first
    // (new records), then the reason text (older records without codes).
    function hasAddressReuse(account) {
        if (!account) return false;
        const codes = Array.isArray(account.doc.fraudCodes) ? account.doc.fraudCodes : [];
        if (codes.includes('address_reuse')) return true;
        return account.activity.some(f => f.text.toLowerCase().includes('delivery address already associated'));
    }

    // Shows/hides and enables/disables the decision controls. Never
    // re-renders the textarea, so the reason being typed is kept.
    function renderDecisionControls() {
        const account = openLogUid ? accountIndex[openLogUid] : null;
        const isBlocked = !!account && account.state === 'blocked';

        document.getElementById('fhDecisionBlocked').style.display = isBlocked ? 'block' : 'none';
        document.getElementById('fhDecisionForm').style.display = isBlocked ? 'none' : 'block';

        // "Same household" only makes sense when an address was flagged.
        const householdWrap = document.getElementById('fhHouseholdWrap');
        const showHousehold = hasAddressReuse(account);
        householdWrap.classList.toggle('visible', showHousehold);
        if (!showHousehold) document.getElementById('fhHousehold').checked = false;
        document.getElementById('fhHousehold').disabled = decisionBusy;

        const reasonOk = document.getElementById('fhReason').value.trim().length >= REASON_MIN_CHARS;
        const ready = !!account && !!openLogHistory && !decisionBusy;
        const hasEvidence = !!account && (account.activity.length > 0 || (openLogHistory && openLogHistory.flaggedOrders > 0));

        const confirmBtn = document.getElementById('fhConfirmFraud');
        const clearBtn = document.getElementById('fhFalseAlarm');
        confirmBtn.disabled = !(ready && reasonOk && hasEvidence);
        clearBtn.disabled = !(ready && reasonOk);
        confirmBtn.title = hasEvidence ? '' : 'No recorded fraud activity to confirm.';
    }

    async function submitDecision(decision) {
        const uid = openLogUid;
        const account = uid ? accountIndex[uid] : null;
        const history = openLogHistory;
        if (!account || !history || decisionBusy) return;

        const reasonEl = document.getElementById('fhReason');
        const reason = reasonEl.value.trim();
        if (reason.length < REASON_MIN_CHARS) {
            setDecisionMessage(`Please write at least ${REASON_MIN_CHARS} characters explaining your decision.`, 'error');
            return;
        }

        // Sent only with a false alarm, and only when the box is visible.
        const markHousehold = decision === 'false_alarm'
            && hasAddressReuse(account)
            && document.getElementById('fhHousehold').checked;

        const summary = buildHistorySummaryText(history);
        const householdLine = markHousehold
            ? '\n\nSame household: the shared delivery address will no longer be flagged for this account. Other accounts using it are still checked.'
            : '';
        const question = decision === 'confirmed_fraud'
            ? `Confirm fraud for this account?\n\nThis will BLACKLIST the account and BAN every device linked to it. It cannot be undone from this screen.\n\nHistory you reviewed:\n${summary}`
            : `Mark this account's activity as a false alarm?\n\nNothing is punished. If the account is restricted, the restriction is lifted and Cash on Delivery is turned back on. The account leaves High Priority until new activity appears.${householdLine}\n\nHistory you reviewed:\n${summary}`;
        if (!confirm(question)) return;

        decisionBusy = true;
        setDecisionMessage('Saving decision...', 'info');
        renderDecisionControls();

        try {
            const data = await callFraudReview({
                action: 'decide',
                uid,
                decision,
                reason,
                seenTotalOrders: history.totalOrders,
                seenFlaggedOrders: history.flaggedOrders,
                markHousehold
            });
            if (openLogUid !== uid) return;

            reasonEl.value = '';
            document.getElementById('fhHousehold').checked = false;
            updateReasonCount();
            if (decision === 'confirmed_fraud') {
                setDecisionMessage(`Decision saved. Account blacklisted; ${data.bannedDeviceCount} device(s) banned.`, 'success');
            } else {
                const extras = [];
                if (data.restrictionLifted) extras.push('restriction lifted');
                if (data.allowListedDevices > 0) extras.push(`allowed on ${data.allowListedDevices} banned device(s)`);
                if (data.householdAddressesCleared > 0) extras.push(`known household on ${data.householdAddressesCleared} shared address(es)`);
                setDecisionMessage(`Decision saved as false alarm${extras.length ? ' (' + extras.join(', ') + ')' : ''}.`, 'success');
            }
            await loadHistory(uid); // refreshes "Last review"
        } catch (err) {
            if (openLogUid !== uid) return;
            setDecisionMessage(err.message, 'error');
            if (err.code === 'HISTORY_CHANGED') {
                await loadHistory(uid);
            }
        } finally {
            decisionBusy = false;
            if (openLogUid === uid) renderDecisionControls();
        }
    }

    /* =====================================================================
     * 9b. RESTRICTION APPEALS
     *
     * Data flow:
     *   customer -> fraud_appeal.php (submit) -> fraud_appeals/{uid}
     *   fraud_appeals (pending)  -> live listener below -> this panel
     *   admin decision           -> fraud_appeal.php (decide) -> the customer
     *   document, the appeal and the audit log change in ONE transaction
     * =================================================================== */

    function formatTimestamp(ts) {
        return ts && ts.toDate ? ts.toDate().toLocaleString() : '—';
    }

    // Newest appeals last in time = oldest first, so nobody waits forever.
    function sortedPendingAppeals() {
        return Object.values(pendingAppeals).sort((a, b) =>
            (a.submittedAt && a.submittedAt.toMillis ? a.submittedAt.toMillis() : 0)
            - (b.submittedAt && b.submittedAt.toMillis ? b.submittedAt.toMillis() : 0));
    }

    // Appeals are a fraud view, so the customer's name stays MASKED with
    // asterisks (same maskCustomerName() as the monitoring cards). The UID
    // shown in the appeal window identifies the account without exposing
    // the name.
    function appealDisplayName(appeal) {
        const account = accountIndex[appeal.uid];
        const realName = (account && account.name) || appeal.customerName || '';
        return maskCustomerName(realName);
    }

    function renderAppealsPanel() {
        const panel = document.getElementById('appealsPanel');
        const list = sortedPendingAppeals();
        panel.style.display = list.length > 0 ? 'block' : 'none';
        document.getElementById('appealsCount').textContent = list.length + ' waiting';

        document.getElementById('appealsList').innerHTML = list.map(appeal => {
            const text = String(appeal.message || '');
            const preview = text.length > 220 ? text.slice(0, 220).trim() + '…' : text;
            return `
            <div class="appeal-item">
                <div class="appeal-item-main">
                    <p class="appeal-item-name">${escapeHtml(appealDisplayName(appeal))}</p>
                    <p class="appeal-item-meta">Sent ${escapeHtml(formatTimestamp(appeal.submittedAt))} &bull; Restricted until ${escapeHtml(appeal.restrictedUntil && appeal.restrictedUntil.toDate ? appeal.restrictedUntil.toDate().toLocaleDateString() : '—')}</p>
                    <p class="appeal-item-text">${escapeHtml(preview)}</p>
                </div>
                <button type="button" class="appeal-open-btn" data-action="open-appeal" data-uid="${escapeHtml(appeal.uid)}">Review appeal</button>
            </div>`;
        }).join('');
    }

    function renderAppealSummary() {
        const appeal = openAppealUid ? pendingAppeals[openAppealUid] : null;
        if (!appeal) return;
        const account = accountIndex[openAppealUid];

        const level = appeal.riskLevelAtAppeal && RISK_LEVELS[appeal.riskLevelAtAppeal] ? RISK_LEVELS[appeal.riskLevelAtAppeal] : null;
        const codes = Array.isArray(appeal.fraudCodesAtAppeal) ? appeal.fraudCodesAtAppeal : [];

        document.getElementById('appealTitle').textContent = 'Restriction Appeal — ' + appealDisplayName(appeal);
        document.getElementById('appealUid').textContent = 'UID: ' + appeal.uid;
        document.getElementById('appealMessage').textContent = appeal.message || '';
        document.getElementById('appealSummary').innerHTML = `
            <div class="flex flex-wrap items-center gap-2">
                ${level ? `<span class="risk-badge ${level.badge}">${escapeHtml(level.label)}</span>` : ''}
                ${typeof appeal.riskScoreAtAppeal === 'number' ? `<span class="score-pill">Score ${appeal.riskScoreAtAppeal} / ${SCORE_MAX}</span>` : ''}
                ${account && account.state === 'restricted' ? '<span class="risk-badge risk-high">Still restricted</span>' : '<span class="risk-badge risk-low">No longer restricted</span>'}
            </div>
            <p class="fh-meta">Sent ${escapeHtml(formatTimestamp(appeal.submittedAt))} &bull; Restricted until ${escapeHtml(appeal.restrictedUntil && appeal.restrictedUntil.toDate ? appeal.restrictedUntil.toDate().toLocaleDateString() : '—')} &bull; Appeal #${escapeHtml(appeal.appealCount || 1)}</p>
            <p class="fh-meta">Warning signs at the time: ${codes.length ? escapeHtml(codes.join(', ')) : 'none recorded'}</p>`;
    }

    function setAppealMessage(text, kind) {
        const el = document.getElementById('appealDecisionMsg');
        el.textContent = text || '';
        el.className = 'fh-decision-msg' + (kind ? ' ' + kind : '');
    }

    function updateAppealReasonCount() {
        const length = document.getElementById('appealReason').value.trim().length;
        document.getElementById('appealReasonCount').textContent = `${length} / ${REASON_MAX_CHARS}${length < REASON_MIN_CHARS ? ` (min ${REASON_MIN_CHARS})` : ''}`;
    }

    // Buttons stay disabled until the reason is long enough and nothing is
    // being saved. Never re-renders the textarea.
    function renderAppealControls() {
        const ready = !!openAppealUid && !!pendingAppeals[openAppealUid] && !appealBusy;
        const reasonOk = document.getElementById('appealReason').value.trim().length >= REASON_MIN_CHARS;
        document.getElementById('appealLift').disabled = !(ready && reasonOk);
        document.getElementById('appealKeep').disabled = !(ready && reasonOk);
    }

    function openAppealModal(uid) {
        if (!pendingAppeals[uid]) return;
        openAppealUid = uid;
        appealBusy = false;
        document.getElementById('appealReason').value = '';
        updateAppealReasonCount();
        setAppealMessage('', '');
        renderAppealSummary();
        renderAppealControls();
        document.getElementById('appealOverlay').classList.add('open');
    }

    function closeAppealModal() {
        if (appealBusy) return;
        openAppealUid = null;
        document.getElementById('appealOverlay').classList.remove('open');
    }

    async function submitAppealDecision(decision) {
        const uid = openAppealUid;
        const appeal = uid ? pendingAppeals[uid] : null;
        if (!appeal || appealBusy) return;

        const reasonEl = document.getElementById('appealReason');
        const reason = reasonEl.value.trim();
        if (reason.length < REASON_MIN_CHARS) {
            setAppealMessage(`Please write at least ${REASON_MIN_CHARS} characters explaining your decision.`, 'error');
            return;
        }

        const question = decision === 'approve'
            ? 'Lift this restriction?\n\nThe customer will be able to order again right away, with any payment method. Their warning signs stay on record, and new suspicious activity is still recorded.'
            : 'Keep this restriction?\n\nThe customer still cannot order. They can send a new appeal after 24 hours, and the restriction ends by itself after 30 days.';
        if (!confirm(question)) return;

        appealBusy = true;
        setAppealMessage('Saving decision...', 'info');
        renderAppealControls();

        try {
            const data = await callFraudReview({
                action: 'decide',
                uid,
                decision,
                reason,
                seenAppealCount: Number(appeal.appealCount || 1)
            }, APPEAL_ENDPOINT);

            reasonEl.value = '';
            updateAppealReasonCount();
            if (decision === 'approve') {
                const extras = data.allowListedDevices > 0 ? ` (allowed on ${data.allowListedDevices} banned device(s))` : '';
                setAppealMessage(`Appeal approved. Restriction lifted${extras}.`, 'success');
            } else {
                setAppealMessage('Appeal denied. The restriction stays.', 'success');
            }
            // The live listener removes the appeal from the panel; close the
            // window a moment later so the message can be read.
            appealBusy = false;
            setTimeout(() => { if (openAppealUid === uid) closeAppealModal(); }, 1400);
        } catch (err) {
            setAppealMessage(err.message, 'error');
            appealBusy = false;
            // Someone else already decided it, or the customer re-appealed:
            // the live list refreshes by itself, so just close this window.
            if (err.code === 'ALREADY_DECIDED' || err.code === 'APPEAL_CHANGED') {
                setTimeout(() => { if (openAppealUid === uid) closeAppealModal(); }, 2200);
            }
        } finally {
            renderAppealControls();
        }
    }

    /* =====================================================================
     * 10. OPENING / CLOSING THE LOG
     * =================================================================== */

    /**
     * No .orderBy() in the orders query on purpose: an equality filter on
     * one field plus a sort on a DIFFERENT field requires a manually
     * created composite index in Firestore. The per-customer result set is
     * small, so it is sorted in JavaScript instead.
     *
     * The modal always shows the MASKED name (privacy-sensitive view).
     */
    function openFraudActivityLog(uid) {
        const account = accountIndex[uid];
        if (!account) return;

        openLogUid = uid;
        openLogOrders = [];
        openLogHistory = null;
        decisionBusy = false;

        document.getElementById('fhFlaggedOnly').checked = false;
        document.getElementById('fhCustomerName').innerText = 'Fraud Activity Log — ' + account.maskedName;
        document.getElementById('fhCustomerUid').innerText = 'UID: ' + uid;
        document.getElementById('fhOrderList').innerHTML = '<div class="fh-empty">Loading order log...</div>';
        document.getElementById('fhReason').value = '';
        document.getElementById('fhHousehold').checked = false;
        document.getElementById('fhLastReview').innerHTML = '';
        updateReasonCount();
        setDecisionMessage('', '');

        renderRiskSummary(account);
        renderLogAppeal();
        renderAccountActivity(account);
        renderDecisionControls();
        document.getElementById('fraudHistoryOverlay').classList.add('open');

        loadHistory(uid);

        db.collection('orders')
            .where('user_id', '==', uid)
            .limit(50)
            .get()
            .then(snap => {
                if (openLogUid !== uid) return;
                const orders = [];
                snap.forEach(doc => orders.push({ id: doc.id, ...doc.data() }));
                orders.sort((a, b) => getOrderMillis(b) - getOrderMillis(a));
                openLogOrders = orders;
                renderOrderLog();
            })
            .catch(err => {
                if (openLogUid !== uid) return;
                document.getElementById('fhOrderList').innerHTML = `<div class="fh-empty">Could not load order log: ${escapeHtml(err.message)}</div>`;
            });
    }

    function closeFraudActivityLog() {
        openLogUid = null;
        openLogHistory = null;
        document.getElementById('fraudHistoryOverlay').classList.remove('open');
    }

    function openMethodologyModal() {
        const modal = document.getElementById('methodologyModalOverlay');
        if (modal) modal.classList.add('open');
    }

    function closeMethodologyModal() {
        const modal = document.getElementById('methodologyModalOverlay');
        if (modal) modal.classList.remove('open');
    }

    /* =====================================================================
     * 11. WIRING EVERYTHING UP
     * =================================================================== */
    document.addEventListener('DOMContentLoaded', () => {
        renderFilterBar();

        // One click listener for every card (event delegation), so the
        // "open log" buttons still work after the grid is re-drawn.
        document.getElementById('fraudAnalyticsGrid').addEventListener('click', e => {
            const appealBtn = e.target.closest('[data-action="open-appeal"]');
            if (appealBtn) {
                openAppealModal(appealBtn.dataset.uid);
                return;
            }
            const btn = e.target.closest('[data-action="open-log"]');
            if (!btn) return;
            openFraudActivityLog(btn.dataset.uid);
        });

        document.getElementById('fraudFilterBar').addEventListener('click', e => {
            const chip = e.target.closest('[data-filter]');
            if (!chip) return;
            activeFilter = chip.dataset.filter;
            renderFilterBar();
            renderGrid();
        });

        document.getElementById('fraudAccountSearch').addEventListener('input', renderGrid);
        document.getElementById('fhFlaggedOnly').addEventListener('change', renderOrderLog);

        // Appeals: "Review appeal" buttons (rendered dynamically), the
        // appeal window's reason box and its two decision buttons.
        document.getElementById('appealsList').addEventListener('click', e => {
            const btn = e.target.closest('[data-action="open-appeal"]');
            if (btn) openAppealModal(btn.dataset.uid);
        });
        // The appeal shown under the name inside the log window (rendered dynamically).
        document.getElementById('fhAppeal').addEventListener('click', e => {
            const btn = e.target.closest('[data-action="open-appeal"]');
            if (btn) openAppealModal(btn.dataset.uid);
        });
        document.getElementById('appealReason').addEventListener('input', () => {
            updateAppealReasonCount();
            renderAppealControls();
        });
        document.getElementById('appealLift').addEventListener('click', () => submitAppealDecision('approve'));
        document.getElementById('appealKeep').addEventListener('click', () => submitAppealDecision('deny'));
        document.getElementById('appealOpenLog').addEventListener('click', () => {
            const uid = openAppealUid;
            if (!uid || appealBusy) return;
            closeAppealModal();
            openFraudActivityLog(uid);
        });
        document.getElementById('appealOverlay').addEventListener('click', e => {
            if (e.target.id === 'appealOverlay') closeAppealModal();
        });

        // History "Try again" button (rendered dynamically).
        document.getElementById('fhHistory').addEventListener('click', e => {
            if (e.target.closest('[data-action="retry-history"]') && openLogUid) {
                loadHistory(openLogUid);
            }
        });

        // Decision controls (static markup, wired once).
        document.getElementById('fhReason').addEventListener('input', () => {
            updateReasonCount();
            renderDecisionControls();
        });
        document.getElementById('fhConfirmFraud').addEventListener('click', () => submitDecision('confirmed_fraud'));
        document.getElementById('fhFalseAlarm').addEventListener('click', () => submitDecision('false_alarm'));

        // Close the modal by clicking the dark backdrop or pressing Escape
        // (but never while a decision is being saved).
        document.getElementById('fraudHistoryOverlay').addEventListener('click', e => {
            if (e.target.id === 'fraudHistoryOverlay' && !decisionBusy) closeFraudActivityLog();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if (!decisionBusy) closeFraudActivityLog();
                closeAppealModal();
                closeMethodologyModal();
            }
        });

        // Live listener for PENDING appeals only. Runs once now, then again
        // whenever a customer sends an appeal or an admin decides one.
        // Needs the fraud_appeals rule in firestore.rules (admins can read).
        db.collection('fraud_appeals').where('status', '==', 'pending').onSnapshot(snap => {
            pendingAppeals = {};
            snap.forEach(doc => { pendingAppeals[doc.id] = { uid: doc.id, ...doc.data() }; });
            renderAppealsPanel();
            renderGrid(); // refreshes the "Appeal pending" chips + under-name appeals
            renderLogAppeal();
            if (openAppealUid) {
                if (pendingAppeals[openAppealUid]) {
                    renderAppealSummary();
                } else if (!appealBusy) {
                    // Decided elsewhere (or just now by this admin).
                    closeAppealModal();
                }
                renderAppealControls();
            }
        }, err => {
            console.error('Could not load restriction appeals: ', err);
            const panel = document.getElementById('appealsPanel');
            panel.style.display = 'block';
            document.getElementById('appealsCount').textContent = 'error';
            document.getElementById('appealsList').innerHTML =
                '<p class="appeals-sub" style="color:#b91c1c;">Could not load appeals (' + escapeHtml(err.message) + '). Deploy the updated firestore.rules, then refresh.</p>';
        });

        // Live listener: runs once now, then again every time ANY customer
        // document changes (a new flag, a new score, a decision...).
        db.collection('customers').onSnapshot(snap => {
            accounts = [];
            accountIndex = {};
            snap.forEach(doc => {
                const account = summarizeAccount(doc.id, doc.data());
                accounts.push(account);
                accountIndex[doc.id] = account;
            });

            // Review order (triage):
            //   1. account state  (blacklisted, restricted, flagged, reviewed, clear)
            //   2. risk level     (critical, high, medium, low)
            //   3. triage score   (higher first; "Not scored" last)
            //   4. activity count (more first)
            //   5. name           (A-Z, just to keep the order stable)
            accounts.sort((a, b) =>
                STATE_META[a.state].rank - STATE_META[b.state].rank
                || RISK_LEVELS[a.level].rank - RISK_LEVELS[b.level].rank
                || (b.score ?? -1) - (a.score ?? -1)
                || b.activity.length - a.activity.length
                || a.name.localeCompare(b.name)
            );

            renderCounters();
            renderGrid();
            renderAppealsPanel();
            if (openAppealUid) renderAppealSummary();

            // If the log is open for someone whose data just changed,
            // refresh what depends on the customer document.
            if (openLogUid && accountIndex[openLogUid]) {
                renderRiskSummary(accountIndex[openLogUid]);
                renderAccountActivity(accountIndex[openLogUid]);
                renderDecisionControls();
            }
        }, err => {
            document.getElementById('fraudAnalyticsGrid').innerHTML =
                `<div class="text-center p-12 text-red-400 italic">Could not load accounts: ${escapeHtml(err.message)}</div>`;
        });
    });
</script>
<?php include 'templates/footer.php'; ?>