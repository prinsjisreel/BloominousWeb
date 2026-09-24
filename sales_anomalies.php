<?php
/* BLOOMINOUS - Sales Anomalies Detection Dashboard */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}
$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
$isAdminUser = in_array($user_role, ['admin', 'super-admin'], true);
include 'templates/header.php';
?>
<style>
    .risk-badge { font-size: 0.65rem; font-weight: 900; padding: 6px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 1px; display: inline-flex; align-items: center; gap: 6px; }
    .risk-low { background: #e8f8f0; color: #14532d; border: 1px solid #bbf7d0; }
    .risk-medium { background: #fffbeb; color: #78350f; border: 1px solid #fef3c7; }
    .risk-critical { background: #fef2f2; color: #7f1d1d; border: 1px solid #fee2e2; }
    .anomaly-card { background: white; border: 1px solid #f0f0f0; padding: 1.75rem 2rem; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.01); margin-bottom: 16px; }
    .type-pill { font-size: 0.65rem; font-weight: 800; color: #6b7280; background: #f3f4f6; padding: 4px 10px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
    .filter-btn { padding: 8px 18px; border-radius: 20px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; border: 1px solid #f0f0f0; background: #fff; color: #999; cursor: pointer; transition: 0.2s; }
    .filter-btn.active { background: #111827; color: #fff; border-color: #111827; }
    .config-input { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #f0f0f0; background: #fafafa; font-weight: 700; font-size: 0.85rem; outline: none; }
    .config-input:focus { border-color: #7380ec; background: #fff; }
    .config-label { display: flex; align-items: center; gap: 6px; font-size: 0.62rem; font-weight: 800; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }

    /* --- Plain-language tooltip ("?" circle) --- */
    .info-tip {
        display: inline-flex; align-items: center; justify-content: center;
        width: 15px; height: 15px; border-radius: 50%;
        background: #e5e7eb; color: #6b7280;
        font-size: 9px; font-weight: 900; line-height: 1;
        cursor: help; position: relative; flex-shrink: 0;
        text-transform: none; letter-spacing: normal;
    }
    .info-tip:hover, .info-tip:focus { background: #7380ec; color: #fff; outline: none; }
    .info-tip .info-tip-bubble {
        display: none; position: absolute; bottom: 140%; left: 50%; transform: translateX(-50%);
        width: 230px; background: #111827; color: #fff;
        font-size: 0.68rem; font-weight: 600; line-height: 1.45;
        text-transform: none; letter-spacing: normal;
        padding: 10px 12px; border-radius: 10px; z-index: 30;
        box-shadow: 0 10px 25px rgba(0,0,0,0.25);
    }
    .info-tip:hover .info-tip-bubble, .info-tip:focus .info-tip-bubble { display: block; }
    .config-section-title { grid-column: 1 / -1; font-size: 0.7rem; font-weight: 900; color: #7380ec; text-transform: uppercase; letter-spacing: 1px; margin: 18px 0 4px; }
    .config-section-title:first-child { margin-top: 0; }
</style>
<main style="padding: 1.5rem; max-width: 1200px; margin: 0 auto;">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-6">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Sales Anomalies Detection</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Internal transactional irregularity monitoring — value spikes, void frequency, excessive discounts, off-hours activity.</p>
        </div>
    </div>

    <!-- Overview Counters -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="bg-white p-6 border border-gray-100 rounded-3xl">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Flagged</p>
            <h3 class="brand-font text-3xl font-black text-gray-800" id="count-total">0</h3>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Critical</p>
            <h3 class="brand-font text-3xl font-black text-red-600" id="count-critical">0</h3>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Medium</p>
            <h3 class="brand-font text-3xl font-black text-amber-600" id="count-medium">0</h3>
        </div>
        <div class="bg-white p-6 border border-gray-100 rounded-3xl">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Low</p>
            <h3 class="brand-font text-3xl font-black text-emerald-600" id="count-low">0</h3>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-8">
        <button class="filter-btn active" data-filter="all" onclick="setSeverityFilter('all')">All</button>
        <button class="filter-btn" data-filter="critical" onclick="setSeverityFilter('critical')">Critical</button>
        <button class="filter-btn" data-filter="medium" onclick="setSeverityFilter('medium')">Medium</button>
        <button class="filter-btn" data-filter="low" onclick="setSeverityFilter('low')">Low</button>
    </div>

    <div id="anomaliesGrid">
        <div class="text-center p-12 text-gray-300 italic">Loading anomaly log...</div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Threshold Configuration (admin/super-admin only) -->
    <div style="background:white; padding:2.5rem; border-radius:30px; margin-top:3rem; border:1px dashed #ddd;">
        <h4 class="brand-font text-2xl font-black mb-2 text-gray-800">Detection Settings</h4>
        <p class="text-xs text-gray-400 font-medium mb-8">Changes apply immediately to every branch — no redeploy needed. Hover the <span class="info-tip">?</span> next to any setting for its definition.</p>
        <form id="anomalyConfigForm" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:20px;">

            <div class="config-section-title">Sale Value</div>
            <div>
                <label class="config-label">Elevated Sale Threshold
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The size of a transaction, expressed as a multiple of this branch's typical sale, at which a sale is considered elevated.</span></span>
                </label>
                <input type="number" step="0.1" id="cfg-avgMultiplier" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Severe Sale Threshold
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The size of a transaction, expressed as a multiple of this branch's typical sale, at which a sale is considered severely elevated.</span></span>
                </label>
                <input type="number" step="0.1" id="cfg-criticalMultiplier" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Minimum Sales for Baseline
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The number of prior walk-in sales at a branch required to establish a reliable typical-sale amount.</span></span>
                </label>
                <input type="number" id="cfg-minBaselineTransactions" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Fallback Value Threshold (₱)
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">A fixed peso amount used to judge whether a sale is elevated when a branch does not yet have enough sales history to establish a baseline.</span></span>
                </label>
                <input type="number" id="cfg-fallbackHighValueThreshold" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Peak Season Elevated Threshold
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The Elevated Sale Threshold used specifically during the Peak Season Dates configured below.</span></span>
                </label>
                <input type="number" step="0.1" id="cfg-peakAvgMultiplier" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Peak Season Severe Threshold
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The Severe Sale Threshold used specifically during the Peak Season Dates configured below.</span></span>
                </label>
                <input type="number" step="0.1" id="cfg-peakCriticalMultiplier" class="config-input" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <label class="config-label">Peak Season Dates (one range per line, e.g. 2026-02-10 to 2026-02-15)
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">Date ranges during which higher sales volume is expected and normal, such as known holidays or seasonal events.</span></span>
                </label>
                <textarea id="cfg-peakDates" class="config-input" rows="3" placeholder="2026-02-10 to 2026-02-15&#10;2026-05-04 to 2026-05-11"></textarea>
            </div>

            <div class="config-section-title">Voids &amp; Refunds</div>
            <div>
                <label class="config-label">Void Review Window (hours)
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The rolling time period over which a staff member's voids and refunds are counted.</span></span>
                </label>
                <input type="number" id="cfg-voidWindowHours" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Elevated Void Count
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The number of voids or refunds by one staff member within the Void Review Window considered elevated.</span></span>
                </label>
                <input type="number" id="cfg-voidCountMedium" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Severe Void Count
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The number of voids or refunds by one staff member within the Void Review Window considered severe.</span></span>
                </label>
                <input type="number" id="cfg-voidCountCritical" class="config-input" required>
            </div>

            <div class="config-section-title">Discounts</div>
            <div>
                <label class="config-label">Elevated Discount Percentage
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The manual discount percentage on a single transaction considered elevated.</span></span>
                </label>
                <input type="number" id="cfg-discountMediumPercent" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Severe Discount Percentage
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The manual discount percentage on a single transaction considered severe.</span></span>
                </label>
                <input type="number" id="cfg-discountCriticalPercent" class="config-input" required>
            </div>

            <div class="config-section-title">Store Hours</div>
            <div>
                <label class="config-label">Store Opening Time
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The time of day this branch normally opens for business.</span></span>
                </label>
                <input type="time" id="cfg-storeOpenTime" class="config-input" required>
            </div>
            <div>
                <label class="config-label">Store Closing Time
                    <span class="info-tip" tabindex="0">?<span class="info-tip-bubble">The time of day this branch normally closes for business.</span></span>
                </label>
                <input type="time" id="cfg-storeCloseTime" class="config-input" required>
            </div>

            <div style="display:flex; align-items:flex-end; gap:12px;">
                <button type="submit" id="saveConfigBtn" class="btn-primary" style="width:100%; padding:14px; text-transform:uppercase; letter-spacing:1px; font-size:0.75rem;">Save Settings</button>
                <span id="saveStatus" style="font-size:0.7rem; font-weight:800; color:#16a34a; white-space:nowrap; opacity:0; transition:opacity 0.3s;">
                    <i class="fa-solid fa-circle-check"></i> Saved
                </span>
            </div>
        </form>
    </div>
    <?php endif; ?>
</main>
<script>
    let severityFilter = 'all';
    let allAnomalies = [];

    function setSeverityFilter(f) {
        severityFilter = f;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === f));
        renderAnomalies();
    }

    function timeAgo(ts) {
        if (!ts || !ts.toDate) return '...';
        const d = ts.toDate();
        return d.toLocaleString();
    }

    function renderAnomalies() {
        const grid = document.getElementById('anomaliesGrid');
        const filtered = severityFilter === 'all' ?
            allAnomalies : allAnomalies.filter(a => a.severity === severityFilter);

        if (filtered.length === 0) {
            grid.innerHTML = '<div class="text-center p-12 text-gray-300 italic">No anomalies in this view.</div>';
            return;
        }

        const typeLabels = {
            value_spike: 'Statistical Threshold Spike',
            void_spike: 'Void/Cancellation Spike',
            excessive_discount: 'Excessive Discount',
            off_hours: 'Off-Hours Activity'
        };

        grid.innerHTML = filtered.map(a => `
            <div class="anomaly-card">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="risk-badge risk-${a.severity}">${a.severity}</span>
                            <span class="type-pill">${typeLabels[a.type] || a.type}</span>
                        </div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">${a.detail || ''}</p>
                        <p class="text-xs text-gray-400">
                            ${a.cashierEmail ? 'Staff: ' + a.cashierEmail + ' &bull; ' : ''}
                            Branch: ${a.branchId || 'n/a'}
                            ${a.invoiceId ? ' &bull; Invoice: ' + a.invoiceId : ''}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400 font-mono">${timeAgo(a.timestamp)}</p>
                        ${a.justificationNote ? `<p class="text-[11px] text-amber-700 mt-1 max-w-xs">Note: ${a.justificationNote}</p>` : ''}
                        ${a.overriddenBy ? `<p class="text-[11px] text-red-700 mt-1">Overridden by: ${a.overriddenBy}</p>` : ''}
                    </div>
                </div>
            </div>
        `).join('');
    }

    // Turns the textarea's "2026-02-10 to 2026-02-15" lines into the
    // {start, end} array shape checkValueSpike() expects.
    function parsePeakDatesText(text) {
        return (text || '')
            .split('\n')
            .map(line => line.trim())
            .filter(Boolean)
            .map(line => {
                const parts = line.split(/\s+to\s+/i).map(p => p.trim());
                return { start: parts[0] || '', end: parts[1] || parts[0] || '' };
            })
            .filter(r => r.start && r.end);
    }

    // The reverse of the above — turns the stored array back into
    // editable textarea lines.
    function peakDatesToText(ranges) {
        return (ranges || []).map(r => `${r.start} to ${r.end}`).join('\n');
    }

    document.addEventListener('DOMContentLoaded', () => {
        db.collection('salesAnomalies').orderBy('timestamp', 'desc').limit(300).onSnapshot(snap => {
            allAnomalies = [];
            let critical = 0, medium = 0, low = 0;
            snap.forEach(doc => {
                const data = { id: doc.id, ...doc.data() };
                allAnomalies.push(data);
                if (data.severity === 'critical') critical++;
                else if (data.severity === 'medium') medium++;
                else if (data.severity === 'low') low++;
            });
            document.getElementById('count-total').innerText = allAnomalies.length;
            document.getElementById('count-critical').innerText = critical;
            document.getElementById('count-medium').innerText = medium;
            document.getElementById('count-low').innerText = low;
            renderAnomalies();
        });

        <?php if ($isAdminUser): ?>
        const AnomalyEngine = window.SalesAnomalies;

        // Pulls the latest saved values from Firestore and fills the form.
        // Used both on page load AND right after a save, so "what you see
        // in the boxes" always reflects what's actually stored — never
        // just what you last typed.
        async function loadConfigIntoForm() {
            if (!AnomalyEngine) {
                console.error('SalesAnomalies engine did not load — check the <script src> path in footer.php.');
                return;
            }
            const cfg = await AnomalyEngine.getConfig();
            Object.keys(cfg).forEach(key => {
                if (key === 'peakDates') return; // handled separately below (array, not a plain input)
                const el = document.getElementById('cfg-' + key);
                if (el) el.value = cfg[key];
            });
            const peakDatesEl = document.getElementById('cfg-peakDates');
            if (peakDatesEl) peakDatesEl.value = peakDatesToText(cfg.peakDates);
        }

        loadConfigIntoForm();

        document.getElementById('anomalyConfigForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveConfigBtn');
            const statusBadge = document.getElementById('saveStatus');
            btn.disabled = true;
            btn.innerText = 'Saving...';
            statusBadge.style.opacity = 0;
            try {
                const newConfig = {
                    avgMultiplier: parseFloat(document.getElementById('cfg-avgMultiplier').value),
                    criticalMultiplier: parseFloat(document.getElementById('cfg-criticalMultiplier').value),
                    minBaselineTransactions: parseInt(document.getElementById('cfg-minBaselineTransactions').value),
                    fallbackHighValueThreshold: parseFloat(document.getElementById('cfg-fallbackHighValueThreshold').value),
                    peakAvgMultiplier: parseFloat(document.getElementById('cfg-peakAvgMultiplier').value),
                    peakCriticalMultiplier: parseFloat(document.getElementById('cfg-peakCriticalMultiplier').value),
                    peakDates: parsePeakDatesText(document.getElementById('cfg-peakDates').value),
                    voidWindowHours: parseFloat(document.getElementById('cfg-voidWindowHours').value),
                    voidCountMedium: parseInt(document.getElementById('cfg-voidCountMedium').value),
                    voidCountCritical: parseInt(document.getElementById('cfg-voidCountCritical').value),
                    discountMediumPercent: parseFloat(document.getElementById('cfg-discountMediumPercent').value),
                    discountCriticalPercent: parseFloat(document.getElementById('cfg-discountCriticalPercent').value),
                    storeOpenTime: document.getElementById('cfg-storeOpenTime').value,
                    storeCloseTime: document.getElementById('cfg-storeCloseTime').value
                };

                await db.collection('settings').doc('anomaly_config').set(newConfig, { merge: true });

                if (AnomalyEngine) AnomalyEngine.invalidateConfigCache();

                // The real proof: re-pull from Firestore and refill the
                // boxes with what's ACTUALLY stored, not what we just typed.
                await loadConfigIntoForm();

                // Show the badge, then fade it out after 3 seconds.
                statusBadge.style.opacity = 1;
                setTimeout(() => { statusBadge.style.opacity = 0; }, 3000);
            } catch (err) {
                alert('Error saving thresholds: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Save Settings';
            }
        };
        <?php endif; ?>
    });
</script>
<?php include 'templates/footer.php'; ?>