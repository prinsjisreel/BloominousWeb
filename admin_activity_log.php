<?php
/**
 * BLOOMINOUS - Admin Activity Log
 *
 * FIX: the login + role checks now run BEFORE templates/header.php is
 * included. A header("Location: ...") redirect only works before any
 * HTML has been sent; previously the header's HTML went out first, so
 * the redirect was silently ignored and non-admins could open this page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'super-admin') {
    header("Location: admin.php");
    exit();
}
$is_super_admin = $user_role === 'super-admin';

require_once __DIR__ . '/templates/header.php';
?>

<style>
    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>.
       The .card containers already follow the theme via header.php;
       these rules recolor the Tailwind classes used inside this page
       (including the entries JavaScript builds later). */

    /* Neutral text, surfaces, borders */
    html[data-theme="dark"] .activity-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .activity-content .text-gray-600 { color: var(--text-secondary); }
    html[data-theme="dark"] .activity-content :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] .activity-content .bg-gray-50 { background-color: var(--surface-alt); }
    html[data-theme="dark"] .activity-content :is(.border-gray-100, .border-gray-200) { border-color: var(--border-color); }

    /* Action chips: pale "sticker" colors -> translucent tints with
       light text. Each color family keeps its meaning (red = restrict/
       archive, green = lifted/stock added, etc.) in both themes. */
    html[data-theme="dark"] .activity-content .bg-red-50 { background-color: rgba(239, 68, 68, 0.14); }
    html[data-theme="dark"] .activity-content .border-red-200 { border-color: rgba(239, 68, 68, 0.35); }
    html[data-theme="dark"] .activity-content :is(.text-red-600, .text-red-800) { color: #fca5a5; }

    html[data-theme="dark"] .activity-content .bg-green-50 { background-color: rgba(34, 197, 94, 0.14); }
    html[data-theme="dark"] .activity-content .border-green-200 { border-color: rgba(34, 197, 94, 0.35); }
    html[data-theme="dark"] .activity-content .text-green-600 { color: #86efac; }

    html[data-theme="dark"] .activity-content .bg-slate-100 { background-color: rgba(148, 163, 184, 0.15); }
    html[data-theme="dark"] .activity-content .border-slate-200 { border-color: rgba(148, 163, 184, 0.35); }
    html[data-theme="dark"] .activity-content .text-slate-700 { color: #cbd5e1; }

    html[data-theme="dark"] .activity-content .bg-purple-50 { background-color: rgba(168, 85, 247, 0.14); }
    html[data-theme="dark"] .activity-content .border-purple-200 { border-color: rgba(168, 85, 247, 0.35); }
    html[data-theme="dark"] .activity-content .text-purple-600 { color: #d8b4fe; }

    html[data-theme="dark"] .activity-content .bg-blue-50 { background-color: rgba(59, 130, 246, 0.12); }
    html[data-theme="dark"] .activity-content :is(.border-blue-100, .border-blue-200) { border-color: rgba(59, 130, 246, 0.35); }
    html[data-theme="dark"] .activity-content :is(.text-blue-500, .text-blue-600) { color: #93c5fd; }
    html[data-theme="dark"] .activity-content .text-blue-900 { color: #bfdbfe; }

    html[data-theme="dark"] .activity-content .bg-amber-50 { background-color: rgba(245, 158, 11, 0.14); }
    html[data-theme="dark"] .activity-content .border-amber-200 { border-color: rgba(245, 158, 11, 0.35); }
    html[data-theme="dark"] .activity-content .text-amber-700 { color: #fcd34d; }

    html[data-theme="dark"] .activity-content .bg-emerald-50 { background-color: rgba(16, 185, 129, 0.14); }
    html[data-theme="dark"] .activity-content .border-emerald-200 { border-color: rgba(16, 185, 129, 0.35); }
    html[data-theme="dark"] .activity-content .text-emerald-600 { color: #6ee7b7; }

    html[data-theme="dark"] .activity-content .bg-yellow-50 { background-color: rgba(234, 179, 8, 0.14); }
    html[data-theme="dark"] .activity-content .border-yellow-200 { border-color: rgba(234, 179, 8, 0.35); }
    html[data-theme="dark"] .activity-content .text-yellow-700 { color: #fde047; }

    html[data-theme="dark"] .activity-content .bg-cyan-50 { background-color: rgba(6, 182, 212, 0.14); }
    html[data-theme="dark"] .activity-content .border-cyan-200 { border-color: rgba(6, 182, 212, 0.35); }
    html[data-theme="dark"] .activity-content .text-cyan-600 { color: #67e8f9; }

    /* Search box text follows the theme */
    html[data-theme="dark"] .activity-content #audit-search { color: var(--text-main); }
</style>

<main class="activity-content">
    <div class="mb-8">
        <h1 class="text-3xl font-black text-gray-800 brand-font"><?php echo $is_super_admin ? 'Admin Activity Log' : 'My Activity Log'; ?></h1>
        <p class="text-sm text-gray-500 mt-1">
            <?php echo $is_super_admin
                ? 'Every logged privileged action across all admins and employees.'
                : 'Your own logged privileged actions. The full cross-admin log is visible to Super Admins only.'; ?>
        </p>
    </div>

    <?php if (!$is_super_admin): ?>
    <div class="card mb-6 bg-blue-50 border-blue-100">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-circle-info text-blue-500 mt-0.5"></i>
            <p class="text-sm text-blue-900">You are viewing only your own logged actions. This is intentional — the full activity log across all admins and employees is restricted to Super Admins.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-6">
        <input type="text" id="audit-search" placeholder="Search by <?php echo $is_super_admin ? 'admin/employee email, target UID, or action...' : 'target UID or action...'; ?>"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none">

        <?php if ($is_super_admin): ?>
        <!-- Role filter — only meaningful for super-admin's full-log view. -->
        <div class="flex gap-2 mt-4" id="role-filter-chips">
            <button data-role="All" class="role-chip px-4 py-1.5 rounded-full text-xs font-bold border bg-amber-500 text-white border-amber-500">All</button>
            <button data-role="Admin" class="role-chip px-4 py-1.5 rounded-full text-xs font-bold border bg-gray-50 text-gray-700 border-gray-200">Admin</button>
            <button data-role="Staff" class="role-chip px-4 py-1.5 rounded-full text-xs font-bold border bg-gray-50 text-gray-700 border-gray-200">Staff</button>
        </div>
        <?php endif; ?>
    </div>

    <div id="audit-list" class="space-y-3">
        <p class="text-gray-300 italic text-sm text-center py-8">Loading activity log...</p>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.db) return;

    const listEl = document.getElementById('audit-list');
    const searchInput = document.getElementById('audit-search');
    const isSuperAdmin = window.currentUserRole === 'super-admin';
    const roleChips = document.querySelectorAll('.role-chip');
    let activeRoleFilter = 'All';

    const adminRoles = new Set(['admin', 'super-admin']);
    const staffRoles = new Set(['staff', 'employee']);

    function matchesRoleFilter(actorRole) {
        if (activeRoleFilter === 'All') return true;
        if (activeRoleFilter === 'Admin') return adminRoles.has(actorRole);
        if (activeRoleFilter === 'Staff') return staffRoles.has(actorRole);
        return true;
    }

    const actionLabels = {
        manual_restrict: 'MANUAL RESTRICT',
        lift_restriction: 'RESTRICTION LIFTED',
        ban_devices: 'DEVICE(S) BANNED',
        walkin_cancel_override: 'WALK-IN OVERRIDE USED',
        create_employee_account: 'ACCOUNT CREATED',
        update_employee_role: 'ROLE/BRANCH CHANGED',
        order_status_change: 'ORDER STATUS CHANGE',
        pos_sale_completed: 'POS SALE',
        inventory_item_updated: 'ITEM UPDATED',
        inventory_item_created: 'ITEM CREATED',
        inventory_stock_added: 'STOCK ADDED',
        inventory_item_archived: 'ITEM ARCHIVED',
    };
    const actionColors = {
        manual_restrict: 'text-red-600 bg-red-50 border-red-200',
        lift_restriction: 'text-green-600 bg-green-50 border-green-200',
        ban_devices: 'text-slate-700 bg-slate-100 border-slate-200',
        walkin_cancel_override: 'text-purple-600 bg-purple-50 border-purple-200',
        create_employee_account: 'text-blue-600 bg-blue-50 border-blue-200',
        update_employee_role: 'text-amber-700 bg-amber-50 border-amber-200',
        order_status_change: 'text-gray-600 bg-gray-50 border-gray-200',
        pos_sale_completed: 'text-emerald-600 bg-emerald-50 border-emerald-200',
        inventory_item_updated: 'text-yellow-700 bg-yellow-50 border-yellow-200',
        inventory_item_created: 'text-cyan-600 bg-cyan-50 border-cyan-200',
        inventory_stock_added: 'text-green-600 bg-green-50 border-green-200',
        inventory_item_archived: 'text-red-800 bg-red-50 border-red-200',
    };

    let allDocs = [];

    function renderList(searchTerm = '') {
        const q = searchTerm.toLowerCase();
        const filtered = allDocs.filter(d => {
            const data = d.data();
            if (!matchesRoleFilter(data.actorRole || '')) return false;
            if (!q) return true;
            return (data.actorEmail || '').toLowerCase().includes(q) ||
                   (data.targetUid || '').toLowerCase().includes(q) ||
                   (data.targetEmail || '').toLowerCase().includes(q) ||
                   (data.action || '').toLowerCase().includes(q);
        });

        if (filtered.length === 0) {
            listEl.innerHTML = `<p class="text-gray-300 italic text-sm text-center py-8">${isSuperAdmin ? 'No matching audit entries.' : 'No logged actions from you yet.'}</p>`;
            return;
        }

        let html = '';
        filtered.forEach(doc => {
            const data = doc.data();
            const action = data.action || '';
            const label = actionLabels[action] || action.toUpperCase();
            const colorClass = actionColors[action] || 'text-gray-600 bg-gray-50 border-gray-200';
            const ts = data.timestamp ? data.timestamp.toDate() : new Date();
            const dateText = ts.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            html += `
                <div class="card">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] font-black px-2.5 py-1 rounded-lg border ${colorClass}">${label}</span>
                        <span class="text-xs text-gray-400">${dateText}</span>
                    </div>
                    ${isSuperAdmin ? `<p class="text-sm font-bold text-gray-800 mb-1">By: ${data.actorEmail || 'unknown'} (${data.actorRole || 'unknown'})</p>` : ''}
                    <p class="text-xs text-gray-400 mb-1">Target: ${data.targetEmail || data.targetUid || 'unknown'}</p>
                    ${data.details ? `<p class="text-xs text-gray-600 mt-2">${data.details}</p>` : ''}
                </div>
            `;
        });
        listEl.innerHTML = html;
    }

    roleChips.forEach(chip => {
        chip.addEventListener('click', () => {
            activeRoleFilter = chip.dataset.role;
            roleChips.forEach(c => {
                c.classList.remove('bg-amber-500', 'text-white', 'border-amber-500');
                c.classList.add('bg-gray-50', 'text-gray-700', 'border-gray-200');
            });
            chip.classList.remove('bg-gray-50', 'text-gray-700', 'border-gray-200');
            chip.classList.add('bg-amber-500', 'text-white', 'border-amber-500');
            renderList(searchInput.value);
        });
    });

    // FIX: wait until Firebase has restored the login before querying.
    // Right after a page load, firebase.auth().currentUser can still be
    // null for a moment -- a plain admin's query would then filter on
    // actorUid == undefined and show nothing. onAuthStateChanged fires
    // as soon as the login is ready (or immediately, if it already is).
    let unsubscribeLog = null;
    firebase.auth().onAuthStateChanged(user => {
        if (!user) return;           // not ready / signed out
        if (unsubscribeLog) return;  // listener already running

        let query = db.collection('admin_actions');
        if (!isSuperAdmin) {
            query = query.where('actorUid', '==', user.uid);
        }

        unsubscribeLog = query.limit(200).onSnapshot(snap => {
            allDocs = snap.docs.slice().sort((a, b) => {
                const aTime = a.data().timestamp ? a.data().timestamp.toMillis() : 0;
                const bTime = b.data().timestamp ? b.data().timestamp.toMillis() : 0;
                return bTime - aTime;
            });
            renderList(searchInput.value);
        }, err => {
            console.error('Error loading audit log:', err);
            listEl.innerHTML = `<p class="text-red-400 text-sm text-center py-8">Error loading audit log: ${err.message}</p>`;
        });
    });

    searchInput.addEventListener('input', () => renderList(searchInput.value));
});
</script>

    </div> <!-- closes .main-content, opened inside templates/header.php -->
</body>
</html>