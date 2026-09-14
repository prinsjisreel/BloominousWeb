<?php
require_once __DIR__ . '/templates/header.php';

$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'super-admin') {
    header("Location: admin.php");
    exit();
}
$is_super_admin = $user_role === 'super-admin';
?>

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
    <!-- Role filter — only meaningful for super-admin's full-log view.
         A plain admin's own entries under Option B are already all a
         single actor role, so this control has nothing to do there. -->
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
    };
    const actionColors = {
        manual_restrict: 'text-red-600 bg-red-50 border-red-200',
        lift_restriction: 'text-green-600 bg-green-50 border-green-200',
        ban_devices: 'text-slate-700 bg-slate-100 border-slate-200',
        walkin_cancel_override: 'text-purple-600 bg-purple-50 border-purple-200',
        create_employee_account: 'text-blue-600 bg-blue-50 border-blue-200',
        update_employee_role: 'text-amber-700 bg-amber-50 border-amber-200',
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

    let query = db.collection('admin_actions');
    if (!isSuperAdmin) {
        query = query.where('actorUid', '==', firebase.auth().currentUser?.uid);
    }

    query.limit(200).onSnapshot(snap => {
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

    searchInput.addEventListener('input', () => renderList(searchInput.value));
});
</script>

    </div> <!-- closes .main-content, opened inside templates/header.php -->
</body>
</html>