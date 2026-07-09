<?php 
/**
 * BLOOMINOUS - POS Sales Analytics (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

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
        background: #fff; 
        padding: 2.5rem; 
        border-radius: 30px; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
        display: flex; 
        align-items: center; 
        gap: 25px;
        border: 1px solid #f0f0f0;
    }
    
    .stat-card .icon-box {
        width: 70px;
        height: 70px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }

    .pink-bg { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .indigo-bg { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .teal-bg { background: rgba(0, 206, 209, 0.1); color: #00ced1; }
    .orange-bg { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    
    .amount-text { font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 800; color: var(--text-main); margin: 0; line-height: 1; }
    .label-text { color: var(--text-light); font-size: 0.7rem; text-transform: uppercase; font-weight: 800; margin-bottom: 8px; letter-spacing: 1px; }

    .chart-card { 
        background: #fff; 
        border-radius: 35px; 
        padding: 3rem; 
        box-shadow: 0 10px 30px rgba(0,0,0,0.02); 
        border: 1px solid #f0f0f0;
    }

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
        box-shadow: 0 10px 25px rgba(233, 30, 99, 0.15);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.8rem;
    }
    .btn-terminal:hover {
        background: #d81b60;
        transform: translateY(-2px);
        box-shadow: 0 15px 30px rgba(233, 30, 99, 0.25);
    }
</style>

<main class="pos-content">
    <div class="header-area">
        <div class="flex justify-between items-start flex-wrap gap-8">
            <div>
                <h1 class="brand-font text-5xl font-black text-gray-800">POS Analytics</h1>
                <p class="text-gray-400 text-sm font-medium mt-1">Real-time sales, revenue, and product analytics.</p>
            </div>
            <div class="flex gap-4 items-center flex-wrap">
                <a href="pos_terminal.php" class="btn-terminal">
                    <i class="fa-solid fa-cash-register"></i> <span>Launch Terminal</span>
                </a>
                <div class="bg-white px-8 py-4 border border-gray-100 rounded-3xl font-black text-xs text-gray-800 uppercase tracking-widest shadow-sm flex items-center">
                    <i class="fa-regular fa-calendar-days text-pink-500 mr-3 text-sm"></i> <?php echo date('F d, Y'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <div class="icon-box pink-bg"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <p class="label-text">Daily Flux</p>
                <h3 class="amount-text" id="dailySales">₱0.00</h3>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box orange-bg"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <p class="label-text">Weekly Momentum</p>
                <h3 class="amount-text" id="weeklySales">₱0.00</h3>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box indigo-bg"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <p class="label-text">Monthly Volume</p>
                <h3 class="amount-text" id="monthlySales">₱0.00</h3>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-box teal-bg"><i class="fa-solid fa-chart-line"></i></div>
            <div>
                <p class="label-text">Annual Trajectory</p>
                <h3 class="amount-text" id="yearlySales">₱0.00</h3>
            </div>
        </div>
    </div>

    <div class="chart-card">
        <div class="flex justify-between items-center mb-10">
            <h4 class="brand-font text-3xl font-black text-gray-800"><i class="fa-solid fa-chart-area mr-4 text-[#7B79F2]"></i> Revenue Dynamics</h4>
            <div class="text-xs text-gray-400 font-bold uppercase tracking-widest bg-gray-50 px-4 py-2 rounded-full">Synchronized Live</div>
        </div>
        <div style="height: 400px; position: relative;">
            <canvas id="salesTrendChart"></canvas>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('salesTrendChart').getContext('2d');
        let chart;

        // Real-time listener for sales analytics
        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            
            // Weekly start (Monday)
            const d = new Date(now);
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1);
            const weekStart = new Date(d.setDate(diff));
            weekStart.setHours(0,0,0,0);
            
            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const yearStart = new Date(now.getFullYear(), 0, 1);

            let daily = 0, weekly = 0, monthly = 0, yearly = 0;
            
            // For Graph (Last 7 Days)
            const last7Days = {};
            for(let i=6; i>=0; i--) {
                const dateObj = new Date();
                dateObj.setDate(dateObj.getDate() - i);
                const key = dateObj.toISOString().split('T')[0];
                last7Days[key] = { label: dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }), total: 0 };
            }

            snap.forEach(doc => {
                const o = doc.data();
                
                // Check root status or status in the first item (Flutter app structure)
                const status = (o.status || (o.items && o.items[0] ? o.items[0].status : "") || "").toLowerCase();
                
                // Include both 'completed' and 'delivered' in sales calculation
                if (status === 'completed' || status === 'delivered') {
                    const amount = parseFloat(o.totalAmount || o.total_amount || o.total_price || (o.items && o.items[0] ? o.items[0].totalAmount || o.items[0].total_amount : 0) || 0);
                    const dateObj = o.createdAt || o.timestamp;
                    const date = dateObj ? dateObj.toDate() : new Date();
                    
                    if (date >= today) daily += amount;
                    if (date >= weekStart) weekly += amount;
                    if (date >= monthStart) monthly += amount;
                    if (date >= yearStart) yearly += amount;

                    const key = date.toISOString().split('T')[0];
                    if (last7Days[key]) {
                        last7Days[key].total += amount;
                    }
                }
            });

            document.getElementById('dailySales').innerText = '₱' + daily.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('weeklySales').innerText = '₱' + weekly.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('monthlySales').innerText = '₱' + monthly.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('yearlySales').innerText = '₱' + yearly.toLocaleString(undefined, {minimumFractionDigits: 2});

            const labels = Object.values(last7Days).map(v => v.label);
            const data = Object.values(last7Days).map(v => v.total);

            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue Flow',
                        data: data,
                        borderColor: '#E91E63',
                        backgroundColor: 'rgba(233, 30, 99, 0.05)',
                        borderWidth: 4,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#E91E63',
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
                                label: function(context) {
                                    return 'Intensity: ₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            grid: { color: '#f8f8f8', drawBorder: false },
                            ticks: {
                                font: { family: 'Inter', size: 10, weight: '700' },
                                color: '#aaa',
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: {
                                font: { family: 'Inter', size: 10, weight: '700' },
                                color: '#aaa'
                            }
                        }
                    }
                }
            });
        });
    });
</script>

<?php include 'templates/footer.php'; ?>
