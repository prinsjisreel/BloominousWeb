<?php 
/**
 * BLOOMINOUS - Customer Management (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY CHECK
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .customer-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
    .stat-card { background: #fff; padding: 2rem; border-radius: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 20px; border: 1px solid #f0f0f0; }
    .stat-card i { font-size: 1.5rem; width: 60px; height: 60px; border-radius: 18px; display: flex; align-items: center; justify-content: center; }
    .pink-bg { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .indigo-bg { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .teal-bg { background: rgba(0, 206, 209, 0.1); color: #00ced1; }
    
    .form-card { background: #fff; padding: 2rem; border-radius: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: none; margin-bottom: 2rem; border: 1px solid var(--primary); border-style: dashed; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    
    label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px; }
    input, select { width: 100%; padding: 12px 15px; border: 1px solid #eee; border-radius: 12px; outline: none; font-size: 0.9rem; background: #fafafa; transition: 0.3s; font-weight: 500; }
    input:focus { border-color: var(--primary); background: #fff; }

    .list-card { background: #fff; border-radius: 30px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    #customerTable { width: 100%; border-collapse: collapse; }
    #customerTable th { text-align: left; padding: 20px; color: var(--text-light); border-bottom: 1px solid #f0f0f0; text-transform: uppercase; font-size: 0.75rem; font-weight: 800; letter-spacing: 1px; }
    #customerTable td { padding: 20px; border-bottom: 1px solid #f8f9fa; color: var(--text-main); font-size: 0.9rem; font-weight: 500; }
    
    .active-user { background: rgba(46, 204, 113, 0.1); color: #27ae60; padding: 6px 14px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
    .btn-view { display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; background: rgba(123, 121, 242, 0.1); color: var(--secondary); border-radius: 10px; transition: 0.2s; }
    .btn-view:hover { background: var(--secondary); color: white; transform: scale(1.1); }

    .avatar { width: 40px; height: 40px; border-radius: 50%; background: #fafafa; border: 1px solid #eee; display: flex; align-items: center; justify-content: center; font-weight: 800; color: var(--primary); font-size: 0.9rem; }
</style>

<main class="customer-content">
    <div class="flex justify-between items-center mb-10">
        <div>
            <h1 class="brand-font text-4xl font-black text-gray-800">Customers</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Manage your customer profiles and loyalty points.</p>
        </div>
        <button onclick="toggleCustForm()" class="btn-primary shadow-lg shadow-pink-100 flex items-center px-8">
            <i class="fa-solid fa-user-plus mr-3"></i> Enlist New
        </button>
    </div>

    <!-- MANUAL ADD FORM (Hidden by default) -->
    <div id="manualCustForm" class="form-card">
        <h4 class="brand-font text-xl font-black mb-6 text-gray-800"><i class="fa-solid fa-file-signature mr-2 text-pink-500"></i> Manual Enrollment</h4>
        <form id="addCustomerForm">
            <div class="form-grid">
                <div>
                    <label>First Name</label>
                    <input type="text" id="firstName" placeholder="First" required>
                </div>
                <div>
                    <label>Last Name</label>
                    <input type="text" id="lastName" placeholder="Last" required>
                </div>
                <div>
                    <label>Middle Name</label>
                    <input type="text" id="middleName" placeholder="Optional">
                </div>
                <div>
                    <label>Email Access</label>
                    <input type="email" id="custEmail" placeholder="email@address.com">
                </div>
                <div>
                    <label>Assigned Birthday</label>
                    <input type="date" id="birthday">
                </div>
                <div>
                    <label>Initial Loyalty</label>
                    <input type="number" id="custPoints" value="0">
                </div>
            </div>
            <p class="text-xs text-gray-400 font-medium mt-4 italic">* Employee-enrolled users utilize system default validation benchmarks.</p>
            <button type="submit" class="btn-primary mt-6 px-10 py-4 text-xs font-black uppercase tracking-widest" id="saveBtn">Authorize Profile</button>
        </form>
    </div>

    <!-- Analytics Cards -->
    <div class="analytics-grid">
        <div class="stat-card">
            <i class="fa-solid fa-users pink-bg"></i>
            <div>
                <h3 class="brand-font text-3xl font-black mb-0" id="totalMembers">...</h3>
                <small class="text-gray-400 font-bold uppercase tracking-widest" style="font-size: 10px;">Community Reach</small>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-user-plus indigo-bg"></i>
            <div>
                <h3 class="brand-font text-3xl font-black mb-0" id="newToday">...</h3>
                <small class="text-gray-400 font-bold uppercase tracking-widest" style="font-size: 10px;">Organic Growth</small>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-heart teal-bg"></i>
            <div>
                <h3 class="brand-font text-3xl font-black mb-0" id="avgPoints">...</h3>
                <small class="text-gray-400 font-bold uppercase tracking-widest" style="font-size: 10px;">Loyalty Retention</small>
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="p-8 border-b border-gray-50 flex justify-between items-center bg-gray-50/30">
            <h4 class="brand-font text-2xl font-black text-gray-800">Member Directory</h4>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" id="tableSearch" placeholder="Filter members..." class="pl-10 pr-4 py-2 border border-gray-100 rounded-xl text-sm outline-none w-64 focus:border-pink-200 transition-all">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table id="customerTable">
                <thead>
                    <tr>
                        <th>Identity</th>
                        <th>Engagement</th>
                        <th>Contribution</th>
                        <th>Status</th>
                        <th style="text-align: right;">Profile</th>
                    </tr>
                </thead>
                <tbody id="customerData">
                    <tr><td colspan='5' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium italic'>Retrieving community records...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const customerData = document.getElementById('customerData');

        // Real-time listener for customers
        db.collection('customers').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                customerData.innerHTML = "<tr><td colspan='5' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium'>No active member profiles detected.</td></tr>";
                return;
            }

            let html = '';
            let totalCount = snap.size;
            let newTodayCount = 0;
            let totalPoints = 0;
            const today = new Date().toDateString();

            snap.forEach(doc => {
                const data = doc.data();
                const id = doc.id;
                const createdAt = data.lastLogin ? data.lastLogin.toDate() : (data.created_at ? data.created_at.toDate() : new Date());
                
                if (createdAt.toDateString() === today) newTodayCount++;
                const pts = parseInt(data.points || 0);
                totalPoints += pts;

                const name = data.fullName || data.name || data.username || 'System User';
                const initial = name.charAt(0).toUpperCase();

                html += `
                <tr>
                    <td>
                        <div class="flex items-center gap-4">
                            <div class="avatar">${initial}</div>
                            <div>
                                <div style="font-weight: 700; color: var(--text-main);">${name}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.85rem;">Since ${createdAt.toLocaleDateString()}</div>
                        <div style="font-size: 10px; color: var(--text-light); font-weight: 700;">${pts.toLocaleString()} Points Accrued</div>
                    </td>
                    <td style="font-weight: 800; color: var(--primary);">₱${parseFloat(data.total_spend || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td><span class='active-user'>Verified Member</span></td>
                    <td style="text-align: right;"><a href="customer_profile.php?id=${id}" class="btn-view" title="Explore Portfolio"><i class="fa-solid fa-chevron-right"></i></a></td>
                </tr>
                `;
            });

            customerData.innerHTML = html;
            document.getElementById('totalMembers').innerText = totalCount;
            document.getElementById('newToday').innerText = newTodayCount;
            document.getElementById('avgPoints').innerText = totalCount > 0 ? Math.round(totalPoints / totalCount) : 0;
        });

        // Add Customer Manually
        document.getElementById('addCustomerForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Deploying...';

            const fName = document.getElementById('firstName').value.trim();
            const lName = document.getElementById('lastName').value.trim();
            const mName = document.getElementById('middleName').value.trim();
            const fullName = (fName + " " + (mName ? mName + " " : "") + lName).trim();

            try {
                await db.collection('customers').add({
                    firstName: fName,
                    lastName: lName,
                    middleName: mName,
                    fullName: fullName,
                    name: fullName, // legacy
                    email: document.getElementById('custEmail').value,
                    birthday: document.getElementById('birthday').value,
                    points: parseInt(document.getElementById('custPoints').value),
                    total_spend: 0,
                    lastLogin: firebase.firestore.FieldValue.serverTimestamp(),
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });
                document.getElementById('addCustomerForm').reset();
                toggleCustForm();
            } catch (err) {
                console.error(err);
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Authorize Profile';
            }
        };

        // Search functionality
        document.getElementById('tableSearch').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            let rows = document.querySelectorAll('#customerData tr');
            rows.forEach(r => {
                if (r.cells.length > 1) {
                    r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
                }
            });
        });
    });

    function toggleCustForm() {
        var f = document.getElementById("manualCustForm");
        f.style.display = (f.style.display === "none" || f.style.display === "") ? "block" : "none";
    }
</script>

<?php include 'templates/footer.php'; ?>
