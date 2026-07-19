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
    .risk-blocked { background: #111827; color: #ffffff; border: 1px solid #374151; }     
    .fraud-card { background: white; border: 1px solid #f0f0f0; padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.01); transition: all 0.3s ease; }     
    .telemetry-track { background: #f3f4f6; height: 12px; width: 100%; border-radius: 20px; overflow: hidden; }     
    .telemetry-fill { height: 100%; border-radius: 20px; width: 0%; transition: width 1s ease; }          
    .fill-low { background: linear-gradient(90deg, #10b981, #34d399); }     
    .fill-medium { background: linear-gradient(90deg, #f59e0b, #fbbf24); }     
    .fill-high { background: linear-gradient(90deg, #ef4444, #f87171); }     
    .fill-blocked { background: linear-gradient(90deg, #111827, #4b5563); }     
    .btn-restrict { padding: 6px 14px; border-radius: 20px; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; border: none; cursor: pointer; transition: 0.2s; } 
</style> 
<main style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">     
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
<script>     
    function maskCustomerName(name) {         
        if (!name) return "A********* U***";         
        const parts = name.trim().split(' ');         
        return parts.map(p => p.length <= 2 ? p[0] + "*" : p[0] + "*".repeat(p.length - 2) + p[p.length - 1]).join(' ');     
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
            
            // --- THE CRITICAL LOGIC FIX: DESCENT SORT FOR HIGH RISK LEVEL TO RADAR TOP Baseline ---
            accountDocs.sort((a, b) => {
                let scoreA = parseInt(a.fraudScore || 0);
                let scoreB = parseInt(b.fraudScore || 0);
                return scoreB - scoreA; // Highest threat vector bubbles up to index zero layout parameters[cite: 1]
            });

            function renderFraudGrid(filterTerm = '') {                 
                let html = '';                 
                accountDocs.forEach(c => {                     
                    const accountName = c.name || c.username || c.email?.split('@')[0] || "Registered User";                     
                    if (filterTerm && !accountName.toLowerCase().includes(filterTerm.toLowerCase()) && !c.id.toLowerCase().includes(filterTerm.toLowerCase())) return;                     
                    
                    let rawScore = parseInt(c.fraudScore || 10);                     
                    combinedScores += rawScore;                     
                    
                    let riskClass = 'risk-low', fillClass = 'fill-low', statusLabel = 'Account Safe';                     
                    if (rawScore >= 50 && rawScore < 75) {                         
                        riskClass = 'risk-medium'; fillClass = 'fill-medium'; statusLabel = 'Suspicious Profile';                     
                    } else if (rawScore >= 75 && rawScore < 100) {                         
                        riskClass = 'risk-high'; fillClass = 'fill-high'; statusLabel = 'Critical Scrutiny';                         
                        highRiskCount++;                     
                    } else if (c.status === 'blocked' || rawScore >= 100) {                         
                        riskClass = 'risk-blocked'; fillClass = 'fill-blocked'; statusLabel = 'Permanently Terminated';                         
                        highRiskCount++;                     
                    }                     
                    
                    const anonymizedName = (rawScore >= 75)                          
                        ? `<span class="text-red-600 font-bold"><i class="fa-solid fa-eye mr-1 animate-pulse"></i> ${accountName}</span>`                          
                        : maskCustomerName(accountName);                     
                    
                    const isRestricted = c.isRestricted === true;                     
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
                                </button>`}                             
                            </div>                         
                        </div>                         
                        <div class="text-xs text-gray-500 bg-gray-50 p-3 rounded-xl border border-gray-100 font-semibold">                             
                            <span class="block text-[9px] text-gray-400 uppercase font-black mb-1">Audit Trail Logging Flags</span>                             
                            <i class="fa-solid fa-circle-nodes text-pink-500 mr-1"></i> ${c.fraudFlags && c.fraudFlags.length > 0 ? c.fraudFlags.join(', ') : 'Profile registers secure telemetry baselines.'}                         
                        </div>                     
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