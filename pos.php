<?php
/**
 * BLOOMINOUS - POS Sales Report (Firebase Spoke)
 *
 * - Four cards (Daily / Weekly / Monthly / Annual Sales) built from the
 *   branch's actual sales records in `orders`.
 * - A "sale" = an order that is completed / confirmed / delivered and was
 *   NOT voided, refunded or cancelled. Works for orders written by the web
 *   POS (total_amount), web checkout (total_price) and BloominousApp (totalAmount).
 * - Brand amber everywhere (no pink), including the line graph.
 * - Print button: prints a clean, light, paper-ready report.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$preparedBy = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Staff';

include 'templates/header.php'; 
?>

<style>
    .pos-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    
    .header-area { margin-bottom: 3rem; }
    
    .analytics-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem; 
        margin-bottom: 3rem;
    }
    
    .stat-card { 
        background: var(--surface); 
        padding: 2.5rem; 
        border-radius: 30px; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
        display: flex; 
        align-items: center; 
        gap: 25px;
        border: 1px solid var(--border-color);
    }
    
    .stat-card .icon-box {
        width: 70px;
        height: 70px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    /* Icon colors. Daily used to be pink -> now brand amber. */
    .amber-bg { background: rgba(245, 158, 11, 0.12); color: #F59E0B; }
    .orange-bg { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    .indigo-bg { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .teal-bg { background: rgba(0, 206, 209, 0.1); color: #00ced1; }
    
    .amount-text { font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 800; color: var(--text-main); margin: 0; line-height: 1; }
    .label-text { color: var(--text-light); font-size: 0.7rem; text-transform: uppercase; font-weight: 800; margin-bottom: 8px; letter-spacing: 1px; }
    .sub-text { color: var(--text-light); font-size: 0.72rem; font-weight: 600; margin-top: 8px; }

    .chart-card { 
        background: var(--surface); 
        border-radius: 35px; 
        padding: 3rem; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
        border: 1px solid var(--border-color);
    }
    .chart-wrap { height: 400px; position: relative; }

    .btn-terminal {
        background: var(--primary);
        color: white;
        padding: 16px 35px;
        border-radius: 18px;
        text-decoration: none;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        transition: 0.3s;
        box-shadow: 0 10px 25px rgba(245, 158, 11, 0.2);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.8rem;
        border: none;
        cursor: pointer;
    }
    .btn-terminal:hover {
        background: #d97706;
        transform: translateY(-2px);
        box-shadow: 0 15px 30px rgba(245, 158, 11, 0.3);
    }

    .btn-print {
        background: var(--surface);
        color: var(--primary);
        border: 1px solid rgba(245, 158, 11, 0.4);
        padding: 16px 28px;
        border-radius: 18px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: 0.3s;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.8rem;
        cursor: pointer;
    }
    .btn-print:hover { background: rgba(245, 158, 11, 0.08); transform: translateY(-2px); }

    /* Only visible on paper */
    .print-only { display: none; }
    .print-meta { font-size: 0.8rem; color: var(--text-light); margin-top: 6px; line-height: 1.6; }
    .print-table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; font-size: 0.8rem; }
    .print-table th, .print-table td { padding: 8px 10px; border-bottom: 1px solid #ddd; text-align: left; }
    .print-table th { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; color: #555; }
    .print-table td.num, .print-table th.num { text-align: right; }
    .print-table tr.total td { font-weight: 800; border-top: 2px solid #333; border-bottom: none; }

    /* ============ DARK MODE ============ */
    html[data-theme="dark"] :is(.stat-card, .chart-card) { box-shadow: none; }
    html[data-theme="dark"] .pos-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .pos-content :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] .pos-content .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] .pos-content :is(.bg-gray-50, .bg-gray-100) { background-color: var(--surface-alt); }
    html[data-theme="dark"] .pos-content :is(.border-gray-50, .border-gray-100, .border-gray-200) { border-color: var(--border-color); }

    /* ============ PRINT ============
       Hides the admin chrome and forces a light "paper" look, even if the
       screen is in dark mode. These selectors come AFTER header.php's, so
       at equal specificity they win. */
    @media print {
        @page { size: A4 portrait; margin: 12mm; }

        .sidebar, .kiri-topbar, .no-print { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }

        :root, html[data-theme="light"], html[data-theme="dark"] {
            --background: #ffffff;
            --surface: #ffffff;
            --surface-alt: #f7f7f7;
            --border-color: #dddddd;
            --text-main: #222222;
            --text-light: #555555;
        }
        html, body { background: #fff !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none !important; }

        .print-only { display: block !important; }
        .pos-content { padding: 0; max-width: none; }
        .header-area { margin-bottom: 1rem; }
        .header-area h1 { font-size: 2rem !important; }

        .analytics-grid { grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 1rem; }
        .stat-card { padding: 14px; gap: 10px; border-radius: 14px; break-inside: avoid; }
        .stat-card .icon-box { width: 38px; height: 38px; font-size: 1rem; border-radius: 10px; }
        .amount-text { font-size: 1.3rem; }
        .label-text { font-size: 0.6rem; }
        .sub-text { font-size: 0.6rem; }

        .chart-card { padding: 1rem; border-radius: 14px; break-inside: avoid; }
        .chart-card h4 { font-size: 1.2rem !important; }
        .chart-wrap { height: 260px; }
    }
</style>

<main class="pos-content">
    <div class="header-area">
        <div class="flex justify-between items-start flex-wrap gap-8">
            <div>
                <h1 class="brand-font text-5xl font-black text-gray-800">Sales Report</h1>
                <p class="text-gray-400 text-sm font-medium mt-1 no-print">Real-time sales, revenue, and product analytics.</p>
                <!-- Paper-only details -->
                <div class="print-only print-meta">
                    Branch: <strong id="printBranch">—</strong><br>
                    Generated: <span id="printGenerated">—</span><br>
                    Prepared by: <?php echo htmlspecialchars($preparedBy, ENT_QUOTES); ?>
                </div>
            </div>
            <div class="flex gap-4 items-center flex-wrap no-print">
                <button type="button" class="btn-print" onclick="printReport()">
                    <i class="fa-solid fa-print"></i> <span>Print Report</span>
                </button>
                <a href="pos_terminal.php" class="btn-terminal">
                    <i class="fa-solid fa-cash-register"></i> <span>Launch Terminal</span>
                </a>
                <div class="bg-white px-8 py-4 border border-gray-100 rounded-3xl font-black text-xs text-gray-800 uppercase tracking-widest shadow-sm flex items-center">
                    <i class="fa-regular fa-calendar-days mr-3 text-sm" style="color: var(--primary);"></i> <?php echo date('F d, Y'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <div class="icon-box amber-bg"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <p class="label-text">Daily Sales</p>
                <h3 class="amount-text" id="dailySales">₱0.00</h3>
                <p class="sub-text" id="dailyMeta">Loading...</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box orange-bg"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <p class="label-text">Weekly Sales</p>
                <h3 class="amount-text" id="weeklySales">₱0.00</h3>
                <p class="sub-text" id="weeklyMeta">Loading...</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box indigo-bg"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <p class="label-text">Monthly Sales</p>
                <h3 class="amount-text" id="monthlySales">₱0.00</h3>
                <p class="sub-text" id="monthlyMeta">Loading...</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box teal-bg"><i class="fa-solid fa-chart-line"></i></div>
            <div>
                <p class="label-text">Annual Sales</p>
                <h3 class="amount-text" id="annualSales">₱0.00</h3>
                <p class="sub-text" id="annualMeta">Loading...</p>
            </div>
        </div>
    </div>

    <div class="chart-card">
        <div class="flex justify-between items-center mb-10">
            <h4 class="brand-font text-3xl font-black text-gray-800"><i class="fa-solid fa-chart-area mr-4" style="color: var(--primary);"></i> Sales Trend (Last 7 Days)</h4>
            <div class="text-xs text-gray-400 font-bold uppercase tracking-widest bg-gray-50 px-4 py-2 rounded-full no-print">Synchronized Live</div>
        </div>
        <div class="chart-wrap">
            <canvas id="salesTrendChart"></canvas>
        </div>

        <!-- Paper-only day-by-day breakdown -->
        <table class="print-table print-only">
            <thead>
                <tr><th>Day</th><th class="num">Transactions</th><th class="num">Sales</th></tr>
            </thead>
            <tbody id="printTableBody">
                <tr><td colspan="3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // ---------- Settings ----------
    const BRAND_AMBER = '#F59E0B';
    // Order statuses that mean "the money is in" (web POS, web orders, BloominousApp).
    const SALE_STATUSES = ['completed', 'confirmed', 'delivered'];
    // Payment statuses that cancel a sale out.
    const NOT_A_SALE_PAYMENTS = ['voided', 'refunded'];

    let chart = null;
    let branchName = window.currentBranch;

    // ---------- Small helpers ----------
    function themeColor(varName, fallback) {
        const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
        return value || fallback;
    }

    function peso(n) {
        return '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // "2026-10-28" in LOCAL time (toISOString() would use UTC and shift Manila sales by a day).
    function dayKey(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function shortDate(d) {
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function plural(count, word) {
        return `${count} ${word}${count === 1 ? '' : 's'}`;
    }

    // Is this order a real, finished sale?
    function isCountedSale(o) {
        const firstItem = (o.items && o.items[0]) ? o.items[0] : {};
        const status = String(o.status || firstItem.status || '').toLowerCase();
        const deliveryStatus = String(o.delivery_status || '').toLowerCase();
        const payment = String(o.paymentStatus || '').toLowerCase();

        if (status === 'cancelled' || NOT_A_SALE_PAYMENTS.includes(payment)) return false;
        return SALE_STATUSES.includes(status) || deliveryStatus === 'delivered';
    }

    // Each platform names the total differently, so read whichever exists.
    function saleAmount(o) {
        const firstItem = (o.items && o.items[0]) ? o.items[0] : {};
        return parseFloat(
            o.totalAmount || o.total_amount || o.total_price ||
            firstItem.totalAmount || firstItem.total_amount || 0
        ) || 0;
    }

    function saleDate(o) {
        const ts = o.createdAt || o.timestamp;
        // A just-written serverTimestamp is null for a moment locally -> treat as "now".
        return (ts && typeof ts.toDate === 'function') ? ts.toDate() : new Date();
    }

    function setCard(prefix, bucket, rangeText) {
        document.getElementById(prefix + 'Sales').innerText = peso(bucket.total);
        document.getElementById(prefix + 'Meta').innerText = `${plural(bucket.count, 'transaction')} · ${rangeText}`;
    }

    // ---------- Chart colors (screen vs paper) ----------
    function chartTheme(forPrint) {
        const dark = !forPrint && window.isBloomThemeDark && window.isBloomThemeDark();
        return {
            grid: dark ? themeColor('--border-color', '#3F382F') : '#eeeeee',
            tick: dark ? themeColor('--text-light', '#A0998F') : '#666666',
            point: forPrint ? '#ffffff' : themeColor('--surface', '#ffffff')
        };
    }

    function applyChartTheme(forPrint) {
        if (!chart) return;
        const t = chartTheme(forPrint);
        chart.options.scales.y.grid.color = t.grid;
        chart.options.scales.y.ticks.color = t.tick;
        chart.options.scales.x.ticks.color = t.tick;
        chart.data.datasets[0].pointBackgroundColor = t.point;
        chart.update('none');
    }

    function drawChart(labels, data) {
        // Update in place after the first draw (smoother than destroying it).
        if (chart) {
            chart.data.labels = labels;
            chart.data.datasets[0].data = data;
            chart.update();
            return;
        }

        const t = chartTheme(false);
        const ctx = document.getElementById('salesTrendChart').getContext('2d');
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Sales',
                    data: data,
                    borderColor: BRAND_AMBER,
                    backgroundColor: 'rgba(245, 158, 11, 0.12)',
                    borderWidth: 4,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: t.point,
                    pointBorderColor: BRAND_AMBER,
                    pointBorderWidth: 3,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#121212',
                        titleFont: { family: 'Inter', size: 12, weight: '800' },
                        bodyFont: { family: 'Inter', size: 12 },
                        padding: 15,
                        cornerRadius: 15,
                        displayColors: false,
                        callbacks: {
                            label: (context) => 'Sales: ' + peso(context.parsed.y)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: t.grid, drawBorder: false },
                        ticks: {
                            font: { family: 'Inter', size: 10, weight: '700' },
                            color: t.tick,
                            callback: (value) => '₱' + value.toLocaleString()
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Inter', size: 10, weight: '700' }, color: t.tick }
                    }
                }
            }
        });
    }

    function renderPrintTable(days) {
        let totalCount = 0, totalSales = 0;
        const rows = days.map(d => {
            totalCount += d.count;
            totalSales += d.total;
            return `<tr><td>${d.fullLabel}</td><td class="num">${d.count}</td><td class="num">${peso(d.total)}</td></tr>`;
        }).join('');
        document.getElementById('printTableBody').innerHTML = rows +
            `<tr class="total"><td>Total (7 days)</td><td class="num">${totalCount}</td><td class="num">${peso(totalSales)}</td></tr>`;
    }

    // ---------- Printing ----------
    function stampPrintTime() {
        document.getElementById('printGenerated').innerText = new Date().toLocaleString('en-US', {
            month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
        document.getElementById('printBranch').innerText = branchName;
    }

    function printReport() {
        window.print(); // beforeprint below prepares the page
    }

    // Also fires for Ctrl+P, not just the button.
    window.addEventListener('beforeprint', () => {
        stampPrintTime();
        applyChartTheme(true);
        if (chart) chart.resize();
    });
    window.addEventListener('afterprint', () => {
        applyChartTheme(false);
        if (chart) chart.resize();
    });

    // ---------- Live data ----------
    document.addEventListener('DOMContentLoaded', () => {
        // Branch display name for the printout (falls back to the id).
        db.collection('branches').doc(window.currentBranch).get()
            .then(doc => { if (doc.exists) branchName = doc.data().name || window.currentBranch; })
            .catch(() => { /* keep the id */ });

        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            const now = new Date();
            const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());

            // Week starts on Monday.
            const weekStart = new Date(todayStart);
            const day = weekStart.getDay();
            weekStart.setDate(weekStart.getDate() - (day === 0 ? 6 : day - 1));

            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const yearStart = new Date(now.getFullYear(), 0, 1);

            const daily = { total: 0, count: 0 };
            const weekly = { total: 0, count: 0 };
            const monthly = { total: 0, count: 0 };
            const annual = { total: 0, count: 0 };

            // One bucket per day for the last 7 days (oldest first).
            const last7 = {};
            for (let i = 6; i >= 0; i--) {
                const d = new Date(todayStart);
                d.setDate(d.getDate() - i);
                last7[dayKey(d)] = {
                    label: shortDate(d),
                    fullLabel: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }),
                    total: 0,
                    count: 0
                };
            }

            snap.forEach(doc => {
                const o = doc.data();
                if (!isCountedSale(o)) return;

                const amount = saleAmount(o);
                const date = saleDate(o);

                if (date >= todayStart) { daily.total += amount; daily.count++; }
                if (date >= weekStart) { weekly.total += amount; weekly.count++; }
                if (date >= monthStart) { monthly.total += amount; monthly.count++; }
                if (date >= yearStart) { annual.total += amount; annual.count++; }

                const bucket = last7[dayKey(date)];
                if (bucket) { bucket.total += amount; bucket.count++; }
            });

            setCard('daily', daily, todayStart.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }));
            setCard('weekly', weekly, `${shortDate(weekStart)} – ${shortDate(now)}`);
            setCard('monthly', monthly, now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));
            setCard('annual', annual, `Year ${now.getFullYear()}`);

            const days = Object.values(last7);
            drawChart(days.map(d => d.label), days.map(d => d.total));
            renderPrintTable(days);
        }, err => {
            console.error('Sales report listener error:', err);
            ['daily', 'weekly', 'monthly', 'annual'].forEach(p => {
                document.getElementById(p + 'Meta').innerText = 'Could not load sales: ' + err.message;
            });
        });
    });
</script>

<?php include 'templates/footer.php'; ?>