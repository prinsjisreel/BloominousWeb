<?php   
/* BLOOMINOUS - Fraud Risk Analytics & Account Trust Telemetry */
if (session_status() === PHP_SESSION_NONE) { session_start(); } 
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {     
    header("Location: index.php");     
    exit(); 
}
include 'templates/header.php';  
?>
<style>     
    .risk-badge { font-size: 0.65rem; font-weight: 900; padding: 6px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 1px; display: inline-flex; align-items: center; gap: 6px; }     
    .risk-low { background: #e8f8f0; color: #14532d; border: 1px solid #bbf7d0; }     
    .risk-medium { background: #fffbeb; color: #78350f; border: 1px solid #fef3c7; }     
    .risk-high { background: #fef2f2; color: #7f1d1d; border: 1px solid #fee2e2; }     
    .risk-critical { background: #fef2f2; color: #7f1d1d; border: 1px solid #fee2e2; }
    .risk-blocked { background: #111827; color: #ffffff; border: 1px solid #374151; }     
    .fraud-card { background: var(--surface); border: 1px solid var(--border-color); padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.01); transition: all 0.3s ease; }     
    .telemetry-track { background: #f3f4f6; height: 12px; width: 100%; border-radius: 20px; overflow: hidden; }     
    .telemetry-fill { height: 100%; border-radius: 20px; width: 0%; transition: width 1s ease; }          
    .fill-low { background: linear-gradient(90deg, #10b981, #34d399); }     
    .fill-medium { background: linear-gradient(90deg, #f59e0b, #fbbf24); }     
    .fill-high { background: linear-gradient(90deg, #ef4444, #f87171); }     
    .fill-critical { background: linear-gradient(90deg, #ef4444, #f87171); }
    .fill-blocked { background: linear-gradient(90deg, #111827, #4b5563); }     
    .btn-restrict { padding: 6px 14px; border-radius: 20px; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; border: none; cursor: pointer; transition: 0.2s; }

    /* Audit trail block is a button, not just static text */
    .audit-trail-btn {
        width: 100%; text-align: left; cursor: pointer; border: none;
        background: #f9fafb; padding: 0.75rem; border-radius: 12px; border: 1px solid var(--border-color);
        font-family: inherit; transition: 0.15s;
    }
    .audit-trail-btn:hover { background: #f3f4f6; border-color: #e5e7eb; }
    .audit-trail-btn .view-hint { font-size: 0.65rem; font-weight: 800; color: #7380ec; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Fraud History modal */
    #fraudHistoryOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #fraudHistoryOverlay.open { display: flex; }
    #fraudHistoryModal { background: var(--surface); color: var(--text-main); border-radius: 24px; padding: 2rem; max-width: 640px; width: 100%; max-height: 85vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #fraudHistoryModal h3 { font-size: 1.3rem; font-weight: 900; margin: 0 0 0.25rem; }
    #fraudHistoryModal .close-btn { float: right; background: none; border: none; font-size: 1.1rem; color: #999; cursor: pointer; }
    .fh-order { border: 1px solid var(--border-color); border-radius: 16px; padding: 14px 16px; margin-bottom: 12px; }
    .fh-order-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 10px; }
    .fh-flags { font-size: 0.78rem; color: #444; line-height: 1.6; }
    .fh-flags li { margin-left: 1.1rem; }
    .fh-empty { text-align: center; color: #bbb; font-style: italic; padding: 2rem; }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */

    /* 1) Page-specific colors with no matching theme variable */
    html[data-theme="dark"] .telemetry-track { background: var(--surface-alt); }
    html[data-theme="dark"] .audit-trail-btn { background: var(--surface-alt); }
    html[data-theme="dark"] .audit-trail-btn:hover { background: var(--background); border-color: var(--text-light); }
    html[data-theme="dark"] .fh-flags { color: var(--text-secondary); }
    html[data-theme="dark"] .fh-empty { color: var(--text-light); }
    html[data-theme="dark"] .fraud-card { box-shadow: none; }

    /* 2) Risk badges: pale "sticker" pills become translucent tints with
          light text, so they read as status colors instead of glaring. */
    html[data-theme="dark"] .risk-low { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
    html[data-theme="dark"] .risk-medium { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
    html[data-theme="dark"] :is(.risk-high, .risk-critical) { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
    html[data-theme="dark"] .risk-blocked { border-color: #6b7280; }

    /* 3) Tailwind class remap, scoped to this page's content + the history modal */
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.bg-gray-50, .bg-gray-100) { background-color: var(--surface-alt); }
    html[data-theme="dark"] :is(.fraud-content, #fraudHistoryModal) :is(.border-gray-50, .border-gray-100, .border-gray-200) { border-color: var(--border-color); }
    html[data-theme="dark"] .fraud-content .bg-pink-50 { background-color: rgba(236, 72, 153, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-red-50 { background-color: rgba(239, 68, 68, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-emerald-50 { background-color: rgba(16, 185, 129, 0.12); }
    html[data-theme="dark"] .fraud-content .bg-gray-800 { background-color: #4b5563; }
</style> 
<main class="fraud-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">     
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-12 gap-6">         
        <div>             
            <h1 class="brand-font text-5xl font-black text-gray-800">Fraud Risk Analytics</h1>             
            <p class="text-gray-400 text-sm font-medium mt-1">Real-time user account security matrix, customer action logging checks, and profile telemetry.</p>         
        </div>         
        <div class="flex flex-wrap items-center gap-4 w-full md:w-auto">             
            <div class="relative flex-1 md:flex-none">                 
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>                 
                <input type="text" id="fraudAccountSearch" class="bg-white border border-gray-100 rounded-2xl px-12 py-3 text-sm outline-none focus:border-pink-300 transition-all w-full md:w-80 shadow-sm" placeholder="Search Account Name or UID...">             
            </div>         
        </div>     </div>     
    <!-- Overview Counters -->     
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">         
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">             
            <div>                 
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Accounts Audited</p>                 
                <h3 class="brand-font text-3xl font-black text-gray-800" id="count-total">0</h3>             
            </div>             
            <div class="w-12 h-12 bg-pink-50 text-pink-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-users-shield"></i></div>         
        </div>         
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">             
            <div>                 
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">High Risk Flagged</p>                 
                <h3 class="brand-font text-3xl font-black text-red-500" id="count-high">0</h3>             
            </div>             
            <div class="w-12 h-12 bg-red-50 text-red-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-triangle-exclamation"></i></div>         
        </div>         
        <div class="bg-white p-6 border border-gray-100 rounded-3xl flex items-center justify-between">             
            <div>                 
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Global Trust Ratio</p>                 
                <h3 class="brand-font text-3xl font-black text-emerald-600" id="count-trust">100%</h3>             
            </div>             
            <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-shield-heart"></i></div>         
        </div>     
    </div>     
    <div id="fraudAnalyticsGrid" style="display:grid; grid-template-columns: 1fr; gap:24px;">         
        <div class="text-center p-12 text-gray-300 italic">Initializing risk directories...</div>     
    </div> 
</main>

<!-- Fraud History Modal: opened by clicking a customer's Audit Trail block -->
<div id="fraudHistoryOverlay">
    <div id="fraudHistoryModal">
        <button class="close-btn" onclick="closeFraudHistory()"><i class="fa-solid fa-xmark"></i></button>
        <h3 id="fhCustomerName">Fraud History</h3>
        <p class="text-xs text-gray-400 font-mono mb-4" id="fhCustomerUid"></p>
        <div id="fhOrderList"><div class="fh-empty">Loading order history...</div></div>
    </div>
</div>

<script>     
    function maskCustomerName(name) {         
        if (!name) return "A********* U***";         
        const parts = name.trim().split(' ');         
        return parts.map(p => p.length <= 2 ? p[0] + "*" : p[0] + "*".repeat(p.length - 2) + p[p.length - 1]).join(' ');     
    }     
    
    // Bans every device hash on record for this account, so the same
    // person can't just spin up a new account from the same device.
    // Separate from the isRestricted toggle above — this targets the
    // device, not the account.
    async function manualBanDevices(uid, deviceHashes) {
        if (!deviceHashes || deviceHashes.length === 0) {
            alert('No device history on file for this account yet.');
            return;
        }
        if (!confirm(`Ban ${deviceHashes.length} device(s) linked to this account?`)) return;
        try {
            const batch = db.batch();
            deviceHashes.forEach(hash => {
                const ref = db.collection('banned_devices').doc(hash);
                batch.set(ref, {
                    bannedUid: uid,
                    reason: 'Manually banned by admin from Fraud Risk Analytics',
                    bannedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            });
            await batch.commit();
            alert('Device(s) banned.');
        } catch (e) { alert('Admin mutation access error: ' + e.message); }
    }

    // Manual Admin Penalty Control states (Completely separate path from customer restrictions)     
    async function manualAdminOverrideToggle(uid, currentState) {         
        const nextState = !currentState;         
        const msg = nextState ? 'APPLY MANUAL OVERRIDE RESTRICTION?' : 'LIFT MANUAL OVERRIDE PENALTY?';         
        if (confirm(msg)) {             
            try {                 
                const expiryDate = new Date();                 
                expiryDate.setDate(expiryDate.getDate() + 30);                 
                await db.collection('customers').doc(uid).update({                     
                    isRestricted: nextState,                     
                    restrictedUntil: nextState ? firebase.firestore.Timestamp.fromDate(expiryDate) : null,                     
                    fraudFlags: nextState ? firebase.firestore.FieldValue.arrayUnion("Restricted by admin manual override parameters") : firebase.firestore.FieldValue.arrayRemove("Restricted by admin manual override parameters")                 
                });             
            } catch(e) { alert('Admin mutation access error: ' + e.message); }         
        }     
    }

    /**
     * Click-through Fraud History.
     *
     * FIX (index error): the original version chained
     * .where('user_id','==',uid).orderBy('createdAt','desc') — an equality
     * filter on one field PLUS a sort on a DIFFERENT field. Firestore only
     * auto-creates indexes for a filter and a sort on the SAME field; the
     * moment they're different fields, it demands a manually-created
     * composite index. sales_anomalies.js already hit this exact wall for
     * its own queries and solved it the same way this now does: drop the
     * orderBy from the query itself, pull the (small, per-customer) result
     * set, and sort it in JavaScript instead. No index needed, ever.
     *
     * FIX (privacy): displayName is ALWAYS the masked name, regardless of
     * this account's risk tier.
     */
    function openFraudHistory(uid, maskedDisplayName) {
        document.getElementById('fhCustomerName').innerText = 'Fraud History — ' + maskedDisplayName;
        document.getElementById('fhCustomerUid').innerText = 'UID: ' + uid;
        document.getElementById('fhOrderList').innerHTML = '<div class="fh-empty">Loading order history...</div>';
        document.getElementById('fraudHistoryOverlay').classList.add('open');

        db.collection('orders')
            .where('user_id', '==', uid)
            // NOTE: no .orderBy() here on purpose — see the comment above.
            .limit(50)
            .get()
            .then(snap => {
                const listEl = document.getElementById('fhOrderList');
                if (snap.empty) {
                    listEl.innerHTML = '<div class="fh-empty">No web orders on file for this account yet.</div>';
                    return;
                }

                // Sort newest-first ourselves, client-side, using the
                // Timestamp's own comparable millis value.
                const orders = [];
                snap.forEach(doc => orders.push({ id: doc.id, ...doc.data() }));
                orders.sort((a, b) => {
                    const aMs = a.createdAt && a.createdAt.toMillis ? a.createdAt.toMillis() : 0;
                    const bMs = b.createdAt && b.createdAt.toMillis ? b.createdAt.toMillis() : 0;
                    return bMs - aMs;
                });

                let html = '';
                orders.forEach(o => {
                    const when = o.createdAt && o.createdAt.toDate ? o.createdAt.toDate().toLocaleString() : '...';
                    const tier = o.riskTier || null;
                    const badgeClass = tier ? 'risk-' + tier : 'risk-low';
                    const flags = Array.isArray(o.fraudFlags) ? o.fraudFlags : [];
                    html += `
                        <div class="fh-order">
                            <div class="fh-order-top">
                                <div>
                                    <span class="font-bold text-sm text-gray-800">${o.invoiceId || o.id}</span>
                                    <span class="text-xs text-gray-400 ml-2">${when}</span>
                                </div>
                                <span class="risk-badge ${badgeClass}">${tier || 'n/a'} &bull; score ${o.fraudScore ?? 'n/a'}</span>
                            </div>
                            ${flags.length > 0
                                ? `<ul class="fh-flags">${flags.map(f => `<li>${f}</li>`).join('')}</ul>`
                                : `<p class="fh-flags text-gray-300 italic">No flags raised on this order.</p>`}
                        </div>
                    `;
                });
                listEl.innerHTML = html;
            })
            .catch(err => {
                document.getElementById('fhOrderList').innerHTML = `<div class="fh-empty">Could not load history: ${err.message}</div>`;
            });
    }

    function closeFraudHistory() {
        document.getElementById('fraudHistoryOverlay').classList.remove('open');
    }
    
    document.addEventListener('DOMContentLoaded', () => {         
        const fraudGrid = document.getElementById('fraudAnalyticsGrid');         
        const searchInput = document.getElementById('fraudAccountSearch');         
        
        db.collection('customers').onSnapshot(snap => {             
            if (snap.empty) {                 
                fraudGrid.innerHTML = `<div class="text-center p-12 text-gray-400 italic">No customer profiles mapped.</div>`;                 
                return;             
            }             
            let totalProfiles = snap.size, highRiskCount = 0, combinedScores = 0;             
            const accountDocs = [];             
            snap.forEach(doc => accountDocs.push({ id: doc.id, ...doc.data() }));             
            
            // Highest fraud score sorts to the top of the list
            accountDocs.sort((a, b) => {
                let scoreA = parseInt(a.fraudScore || 0);
                let scoreB = parseInt(b.fraudScore || 0);
                return scoreB - scoreA;
            });

            function renderFraudGrid(filterTerm = '') {                 
                let html = '';                 
                accountDocs.forEach(c => {                     
                    const accountName = c.name || c.username || c.email?.split('@')[0] || "Registered User";                     
                    if (filterTerm && !accountName.toLowerCase().includes(filterTerm.toLowerCase()) && !c.id.toLowerCase().includes(filterTerm.toLowerCase())) return;                     
                    
                    let rawScore = parseInt(c.fraudScore || 10);                     
                    combinedScores += rawScore;                     
                    
                    // Prefer the actual riskTier submit_order.php writes
                    // (Low/Medium/High/Critical). Accounts that predate
                    // this won't have riskTier yet, so fall back to the
                    // original score-band guess for those only.
                    let riskClass, fillClass, statusLabel;
                    if (c.status === 'blocked' || rawScore >= 100) {
                        riskClass = 'risk-blocked'; fillClass = 'fill-blocked'; statusLabel = 'Permanently Terminated';
                        highRiskCount++;
                    } else if (c.riskTier) {
                        const tierLabels = { low: 'Account Safe', medium: 'Suspicious Profile', high: 'Critical Scrutiny', critical: 'Critical Scrutiny' };
                        riskClass = 'risk-' + c.riskTier;
                        fillClass = 'fill-' + c.riskTier;
                        statusLabel = tierLabels[c.riskTier] || 'Account Safe';
                        if (c.riskTier === 'high' || c.riskTier === 'critical') highRiskCount++;
                    } else if (rawScore >= 50 && rawScore < 75) {
                        riskClass = 'risk-medium'; fillClass = 'fill-medium'; statusLabel = 'Suspicious Profile';
                    } else if (rawScore >= 75 && rawScore < 100) {
                        riskClass = 'risk-high'; fillClass = 'fill-high'; statusLabel = 'Critical Scrutiny';
                        highRiskCount++;
                    } else {
                        riskClass = 'risk-low'; fillClass = 'fill-low'; statusLabel = 'Account Safe';
                    }
                    
                    const maskedName = maskCustomerName(accountName);
                    const anonymizedName = (rawScore >= 75)                          
                        ? `<span class="text-red-600 font-bold"><i class="fa-solid fa-eye mr-1 animate-pulse"></i> ${accountName}</span>`                          
                        : maskedName;                     
                    
                    const isRestricted = c.isRestricted === true;
                    // Always the MASKED name goes into the click handler —
                    // the History modal is a privacy-sensitive detail view.
                    const safeMaskedName = maskedName.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    html += `                     
                    <div class="fraud-card">                         
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-4">                             
                            <div>                                 
                                <div class="flex items-center gap-4 mb-2">                                     
                                    <h3 class="brand-font text-2xl font-black text-gray-800">${anonymizedName}</h3>                                     
                                    <span class="risk-badge ${riskClass}">${statusLabel}</span>                                 
                                </div>                                 
                                <p class="text-xs font-mono text-gray-400">UID: ${c.id}</p>                             
                            </div>                             
                            <div class="flex-1" style="max-width: 400px; width: 100%;">                                 
                                <div class="flex justify-between items-center mb-1 text-xs font-bold text-gray-400">                                     
                                    <span>Vector Rating</span> <span>${rawScore}%</span>                                 
                                </div>                                 
                                <div class="telemetry-track"><div class="telemetry-fill ${fillClass}" style="width: ${rawScore}%;"></div></div>                             
                            </div>                             
                            <div>                                 
                                ${c.status === 'blocked' ? '<span class="text-xs font-black text-gray-400 uppercase bg-gray-100 px-4 py-2 rounded-xl">Blacklisted</span>' : `                                 
                                <button onclick="manualAdminOverrideToggle('${c.id}', ${isRestricted})" class="btn-restrict ${isRestricted ? 'bg-emerald-600' : 'bg-red-600'} text-white">                                     
                                    ${isRestricted ? 'Lift Penalty' : 'Manual Restrict'}                                 
                                </button>
                                <button onclick='manualBanDevices("${c.id}", ${JSON.stringify(c.deviceHashes || [])})' class="btn-restrict bg-gray-800 text-white" style="margin-left:6px;">
                                    Ban Device(s)
                                </button>`}                             
                            </div>                         
                        </div>                         
                        <button type="button" class="audit-trail-btn" onclick="openFraudHistory('${c.id}', '${safeMaskedName}')">
                            <span class="block text-[9px] text-gray-400 uppercase font-black mb-1">Audit Trail Logging Flags</span>
                            <span class="text-xs text-gray-500 font-semibold">
                                <i class="fa-solid fa-circle-nodes text-pink-500 mr-1"></i> ${c.fraudFlags && c.fraudFlags.length > 0 ? c.fraudFlags.slice(-3).join(', ') : 'Profile registers secure telemetry baselines.'}
                            </span>
                            <span class="view-hint block mt-1"><i class="fa-solid fa-clock-rotate-left mr-1"></i>View full fraud history &rarr;</span>
                        </button>
                    </div>`;                 
                });                 
                fraudGrid.innerHTML = html;             
            }             
            
            renderFraudGrid();             
            searchInput.onkeyup = (e) => renderFraudGrid(e.target.value);             
            document.getElementById('count-total').innerText = totalProfiles;             
            document.getElementById('count-high').innerText = highRiskCount;             
            let avgTrust = Math.round(100 - (combinedScores / totalProfiles));             
            document.getElementById('count-trust').innerText = Math.max(0, avgTrust) + '%';         
        });     
    }); 
</script> 
<?php include 'templates/footer.php'; ?>