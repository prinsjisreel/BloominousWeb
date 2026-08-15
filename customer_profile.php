<?php
/**
 * BLOOMINOUS - Customer Profile (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// GET CUSTOMER ID FROM URL
$c_id = $_GET['id'] ?? null;
if (!$c_id) {
    header("Location: customer.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    .profile-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .back-btn { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--primary); font-weight: 800; margin-bottom: 2.5rem; transition: 0.3s; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 2px; }
    .back-btn:hover { transform: translateX(-5px); filter: brightness(1.2); }
    .back-btn i { font-size: 1rem; }

    .profile-grid { display: grid; grid-template-columns: 400px 1fr; gap: 2.5rem; }

    .info-card { background: #fff; border-radius: 35px; padding: 4rem 2.5rem; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.02); height: fit-content; border: 1px solid #f0f0f0; position: relative; overflow: hidden; }
    .avatar-circle { width: 140px; height: 140px; background: #fff5f8; color: var(--primary); border-radius: 45px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2.5rem; font-size: 4rem; font-weight: 900; border: 1px solid rgba(233, 30, 99, 0.1); font-family: 'Cormorant Garamond', serif; transition: 0.4s; }
    .info-card:hover .avatar-circle { transform: scale(1.05) rotate(-3deg); box-shadow: 0 15px 35px rgba(233, 30, 99, 0.1); }

    .stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2.5rem; }
    .stat-box { background: #fff; padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; transition: 0.4s; }
    .stat-box:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.05); }

    .history-card { background: #fff; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    .table-container { padding: 0; }
    .order-table { width: 100%; border-collapse: collapse; }
    .order-table th { text-align: left; padding: 25px 20px; color: var(--text-light); border-bottom: 1px solid #f0f0f0; text-transform: uppercase; font-size: 0.7rem; font-weight: 800; letter-spacing: 1.5px; background: #fafafa; }
    .order-table td { padding: 20px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: var(--text-main); font-weight: 600; }
    tr:hover td { background: #fafafa; }

    .badge-status { padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
    .status-completed { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }
    .status-pending { background: #fff9e6; color: #f39c12; }
    .status-cancelled { background: rgba(233, 30, 99, 0.1); color: var(--primary); }

    .meta-box { text-align: left; background: #fafafa; padding: 2rem; border-radius: 25px; border: 1px solid #f0f0f0; margin-top: 2rem; }
    .meta-item { display: flex; flex-direction: column; gap: 5px; margin-bottom: 1.5rem; }
    .meta-item:last-child { margin-bottom: 0; }
    .meta-label { font-size: 0.65rem; font-weight: 800; color: #ccc; text-transform: uppercase; letter-spacing: 1.5px; }
    .meta-value { font-size: 0.85rem; font-weight: 700; color: var(--text-main); }
</style>

<main class="profile-content">
    <a href="customer.php" class="back-btn">
        <i class="fa-solid fa-chevron-left"></i> <span>Return to Grid</span>
    </a>

    <div class="profile-grid">
        <!-- LEFT COLUMN: USER INFO -->
        <div class="info-card" id="customerInfo">
            <div class="avatar-circle" id="avatar">?</div>
            <h2 class="brand-font text-4xl font-black text-gray-800 mb-2" id="username">Loading...</h2>
            <p class="text-gray-400 font-bold text-xs uppercase tracking-widest mb-10" id="email">...</p>

            <div class="meta-box">
                <div class="meta-item">
                    <span class="meta-label">Relationship Status</span>
                    <span class="flex items-center gap-2 text-green-500 font-black text-xs uppercase tracking-wider">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span> Active Member
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Onboarded Since</span>
                    <span id="memberSince" class="meta-value">...</span>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: STATS & TRANSACTIONS -->
        <div class="main-details">
            <div class="stats-row">
                <div class="stat-box">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Loyalty Currency</p>
                    <h2 class="brand-font text-5xl font-black text-pink-500 mb-0" id="totalPoints">0</h2>
                </div>
                <div class="stat-box">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Lifetime Investment</p>
                    <h2 class="brand-font text-5xl font-black text-gray-800 mb-0" id="totalSpend">₱0.00</h2>
                </div>
            </div>

            <div class="history-card">
                <div class="flex justify-between items-center p-8 border-bottom border-gray-100 bg-gray-50/30">
                    <div>
                        <h4 class="brand-font text-2xl font-black text-gray-800">Order Ledger</h4>
                        <p class="text-gray-400 text-xs font-bold uppercase tracking-widest mt-1">Historical Transaction Telemetry</p>
                    </div>
                </div>

                <div class="table-container">
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th>Telemetry Date</th>
                                <th>Valuation</th>
                                <th style="text-align: right;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="orderHistoryData">
                            <tr><td colspan='3' style='text-align: center; padding: 80px;' class="text-gray-300 italic font-medium">Synchronizing transaction logs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    const customerId = "<?php echo $c_id; ?>";

    document.addEventListener('DOMContentLoaded', () => {
        // Load Customer Details
        db.collection('customers').doc(customerId).onSnapshot(doc => {
            if (!doc.exists) {
                alert('Customer not found!');
                window.location.href = 'customer.php';
                return;
            }
            const c = doc.data();
            const displayName = c.username || c.name || c.email || 'N/A';
            document.getElementById('username').innerText = displayName;
            document.getElementById('email').innerText = c.email || 'N/A';
            document.getElementById('avatar').innerText = displayName.charAt(0).toUpperCase();
            document.getElementById('customerId').innerText = doc.id;
            
            const since = c.lastLogin ? c.lastLogin.toDate().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : (c.created_at ? c.created_at.toDate().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : 'N/A');
            document.getElementById('memberSince').innerText = since;
            
            document.getElementById('totalPoints').innerText = (c.points || 0).toLocaleString();
            document.getElementById('totalSpend').innerText = '₱' + (c.total_spend || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
        });

        // Load Order History in Real-time
        db.collection('orders').where('user_id', '==', customerId).orderBy('timestamp', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                document.getElementById('orderHistoryData').innerHTML = "<tr><td colspan='3' style='text-align: center; padding: 50px; color: #b2bec3;'><i class='fa-solid fa-box-open' style='display:block; font-size: 2rem; margin-bottom: 10px; opacity: 0.3;'></i>No purchase history found.</td></tr>";
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const o = doc.data();
                const date = o.timestamp ? o.timestamp.toDate().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
                const status = (o.status || 'Completed').toLowerCase();
                const statusClass = status === 'completed' ? 'status-completed' : (status === 'cancelled' ? 'status-cancelled' : 'status-pending');

                html += `
                <tr style="transition: 0.2s;">
                    <td style="color: #7d8da1; font-weight: 500;">${date}</td>
                    <td style="font-weight: 800; color: #363949;">₱${parseFloat(o.total_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td style="text-align: right;"><span class="badge-status ${statusClass}">${status}</span></td>
                </tr>
                `;
            });
            document.getElementById('orderHistoryData').innerHTML = html;
        });
    });
</script>

<?php include 'templates/footer.php'; ?>
