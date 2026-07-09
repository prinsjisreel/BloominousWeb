<?php 
/**
 * BLOOMINOUS - Freshness Analysis (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<!-- Bootstrap CDN for this page specifically since it uses Bootstrap classes -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    .freshness-container { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .stat-card { background: #fff; border-radius: 30px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: flex; flex-direction: column; gap: 10px; border: 1px solid #f0f0f0; }
    .ai-card-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; border-radius: 35px; padding: 3rem; position: relative; overflow: hidden; }
    .ai-card-premium::before { content: ''; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; }
    
    .table-container { background: white; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    .progress-bar-sm { height: 8px; border-radius: 50px; background: #f0f0f0; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 50px; transition: 1s cubic-bezier(0.4, 0, 0.2, 1); }
    
    .text-label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px; }
    
    .badge-capsule { font-size: 0.7rem; font-weight: 800; padding: 6px 16px; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; }
    .healthy { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
    .warning { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
    .critical { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
</style>

<div class="freshness-container">
    <div class="mb-10 flex justify-between items-end">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Freshness Matrix</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Computer vision metrics for botanical integrity and shelf-life prediction.</p>
        </div>
        <button onclick="location.reload()" class="btn-primary px-8 py-3 text-xs uppercase tracking-widest font-black shadow-lg shadow-pink-100">
            <i class="fas fa-sync mr-2"></i> Recalibrate
        </button>
    </div>

    <!-- Stats Cards Row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="stat-card">
            <div class="text-label">Processed Batches</div>
            <div class="brand-font text-4xl font-black text-gray-800" id="totalScans">0</div>
        </div>
        <div class="stat-card" style="border-top: 5px solid #2ecc71;">
            <div class="text-label">Peak Freshness</div>
            <div class="brand-font text-4xl font-black text-gray-800" id="healthyStocks">0</div>
        </div>
        <div class="stat-card" style="border-top: 5px solid #f39c12;">
            <div class="text-label">Discount Threshold</div>
            <div class="brand-font text-4xl font-black text-gray-800" id="forDiscount">0</div>
        </div>
        <div class="stat-card" style="border-top: 5px solid var(--primary);">
            <div class="text-label">Critical Decay</div>
            <div class="brand-font text-4xl font-black text-gray-800" id="criticalStocks">0</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Table Section -->
        <div class="lg:col-span-8">
            <div class="table-container">
                <div class="p-8 border-b border-gray-50 flex justify-between items-center bg-gray-50/30">
                    <h5 class="brand-font text-2xl font-black text-gray-800">Live Pulse Analysis</h5>
                    <div class="text-xs text-gray-400 font-bold uppercase tracking-widest">Vision Node Alpha-1</div>
                </div>
                
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-label text-gray-400">
                            <th class="p-8 pb-4">Artifact Name</th>
                            <th class="p-8 pb-4">Spectral Score</th>
                            <th class="p-8 pb-4">State</th>
                            <th class="p-8 pb-4">Inference</th>
                            <th class="p-8 pb-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="freshnessData">
                        <tr><td colspan="5" class="text-center p-20 text-gray-300 italic font-medium">Awaiting neural telemetry from mobile nodes...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI Insight Section -->
        <div class="lg:col-span-4 flex flex-col gap-8">
            <div class="ai-card-premium shadow-xl shadow-pink-100/20">
                <i class="fa-solid fa-brain text-5xl mb-6 opacity-30"></i>
                <h5 class="brand-font text-3xl font-black text-white leading-tight mb-4">AI Cognition Operating</h5>
                <p class="text-white/80 text-sm font-medium">Monitoring station under command of <strong><?php echo $_SESSION['username'] ?? 'Nexus Admin'; ?></strong>.</p>
                <div class="mt-8 p-6 bg-white/10 rounded-2xl border border-white/10 backdrop-blur-sm">
                    <p class="text-xs font-bold uppercase tracking-widest text-white/50 mb-2">Protocol 404</p>
                    <p class="text-sm font-medium text-white/90">Stocks falling below <span class="text-pink-200">50% vitality</span> are prioritized for automated clearinghouse discounting.</p>
                </div>
            </div>

            <div class="bg-white rounded-[35px] text-center p-10 border border-gray-100 shadow-sm">
                <div class="brand-font text-6xl font-black text-pink-500 mb-2">99.4%</div>
                <div class="text-label">Inference Fidelity</div>
                <div class="w-20 h-1 bg-pink-100 mx-auto my-8 rounded-full"></div>
                <p class="text-xs text-gray-400 font-medium leading-relaxed px-4">Our neural architecture is refined continuously by thousands of botanical data points across the archipelago.</p>
            </div>
        </div>
    </div>
</div>

<script>
    async function recycleItem(productName, productId) {
        if (!confirm(`Are you sure you want to recycle ${productName} into a Recycled Bouquet? This will deduct the stock from the original item.`)) return;
        
        try {
            const bid = window.currentBranch;
            const description = "This bouquet is composed of salvaged flowers (Mixed varieties). Limited offer: Only good for two days.";
            
            const batch = db.batch();
            
            // 1. Find product in inventory to get current stock or dedupe
            const invRef = db.collection('branches').doc(bid).collection('inventory');
            const productSnap = await invRef.where('name', '==', productName).limit(1).get();
            
            if (productSnap.empty) {
                alert('Product not found in inventory.');
                return;
            }
            
            const productDoc = productSnap.docs[0];
            const currentStock = productDoc.data().stock || 0;
            
            if (currentStock <= 0) {
                alert('No stock left to recycle.');
                return;
            }

            // 2. Deduct Original Stock
            batch.update(productDoc.ref, { stock: 0 }); // Recycle ALL remaining stock of this batch

            let currentLeftovers = 0;
            let existingBouquetRef = null;

            // 3. Create/Update Recycled Bouquet
            const bouquetSnap = await invRef.where('name', '==', 'Recycled Bouquet').limit(1).get();
            if (!bouquetSnap.empty) {
                const bDoc = bouquetSnap.docs[0];
                existingBouquetRef = bDoc.ref;
                currentLeftovers = bDoc.data().leftoverFlowers || 0;
            }

            const totalFlowers = currentStock + currentLeftovers;
            const newBouquets = Math.floor(totalFlowers / 4);
            const newLeftovers = totalFlowers % 4;

            if (existingBouquetRef) {
                batch.update(existingBouquetRef, {
                    stock: firebase.firestore.FieldValue.increment(newBouquets),
                    leftoverFlowers: newLeftovers,
                    description: description,
                    price: 150.0,
                    category: 'Bouquets',
                    updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            } else {
                const newRef = invRef.doc();
                batch.set(newRef, {
                    name: 'Recycled Bouquet',
                    price: 150.0,
                    stock: newBouquets,
                    leftoverFlowers: newLeftovers,
                    category: 'Bouquets',
                    description: description,
                    image: 'https://images.unsplash.com/photo-1582794543139-8ac9cb0f7b11?q=80&w=200&auto=format&fit=crop',
                    branchId: bid,
                    createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                    updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            }

            // 4. Log Spoilage as "Salvaged"
            const spoilRef = db.collection('branches').doc(bid).collection('spoilage').doc();
            batch.set(spoilRef, {
                productId: productDoc.id,
                quantity: currentStock,
                reason: 'Salvaged / Reusable Scraps',
                flower_name: productName,
                loss_amount: 0,
                reported_by: 'AI System (Recycle)',
                is_salvaged: true,
                createdAt: firebase.firestore.FieldValue.serverTimestamp()
            });

            await batch.commit();
            
            let recycleAlert = '';
            if (newBouquets > 0) {
                recycleAlert = `${productName} successfully recycled. Produced ${newBouquets} Recycled Bouquet(s) with ${newLeftovers} flower(s) leftover in the pool!`;
            } else {
                recycleAlert = `${productName} salvaged to pool! Accumulated ${newLeftovers} leftover flower(s). Need 4 flowers to construct a Recycled Bouquet.`;
            }
            alert(recycleAlert);
        } catch (e) {
            alert('Error recycling item: ' + e.message);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const freshnessData = document.getElementById('freshnessData');

        // Real-time listener for freshness analysis sorted on the client side representation to avoid Firestore index requirement
        db.collection('freshness_analysis').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            if (snap.empty) {
                freshnessData.innerHTML = '<tr><td colspan="5" class="text-center p-20 text-gray-300 italic font-medium">Awaiting neural telemetry from mobile nodes...</td></tr>';
                document.getElementById('totalScans').innerText = '0';
                document.getElementById('healthyStocks').innerText = '0';
                document.getElementById('forDiscount').innerText = '0';
                document.getElementById('criticalStocks').innerText = '0';
                return;
            }

            let docsList = [];
            snap.forEach(doc => {
                docsList.push({ id: doc.id, ...doc.data() });
            });

            // Sort on client side to avoid composite index requirement
            docsList.sort((a, b) => {
                const tA = a.scanned_at || a.createdAt;
                const tB = b.scanned_at || b.createdAt;
                const timeA = tA && typeof tA.toDate === 'function' ? tA.toDate().getTime() : 0;
                const timeB = tB && typeof tB.toDate === 'function' ? tB.toDate().getTime() : 0;
                return timeB - timeA;
            });

            // Limit to 10
            const displayDocs = docsList.slice(0, 10);

            let html = '';
            let total = 0;
            let healthy = 0;
            let discount = 0;
            let critical = 0;

            displayDocs.forEach(f => {
                total++;
                const productName = f.product_name || f.productName || 'Unknown Flower';
                const score = parseInt(f.freshness_score || f.freshnessScore || 0);
                const status = f.status || 'Unknown';
                const recommendation = f.ai_recommendation || f.aiRecommendation || 'N/A';
                
                if (status === 'Healthy') healthy++;
                if (score >= 30 && score <= 60) discount++;
                if (score < 30) critical++;

                const colorClass = (score > 70) ? 'healthy' : ((score > 40) ? 'warning' : 'critical');
                const barColor = (score > 70) ? '#2ecc71' : ((score > 40) ? '#f39c12' : 'var(--primary)');
                
                const t = f.scanned_at || f.createdAt;
                const date = t && typeof t.toDate === 'function' ? t.toDate().toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A';

                let recycleBtn = '';
                if (score < 40) {
                    recycleBtn = `<button onclick="recycleItem('${productName}', '${f.id}')" class="btn-primary py-1 px-3 text-[9px] bg-green-500 hover:bg-green-600 border-none shadow-sm font-black uppercase">Recycle</button>`;
                }

                html += `
                <tr class="group hover:bg-gray-50/50 transition-all">
                    <td class="p-8">
                        <div class="font-bold text-gray-800 text-lg">${productName}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-400 font-medium">${date}</span>
                        </div>
                    </td>
                    <td class="p-8">
                        <div class="flex items-center gap-4">
                            <div class="progress-bar-sm flex-1">
                                <div class="progress-fill" style="width: ${score}%; background: ${barColor}"></div>
                            </div>
                            <span class="brand-font font-black text-gray-800">${score}%</span>
                        </div>
                    </td>
                    <td class="p-8"><span class="badge-capsule ${colorClass}">${status}</span></td>
                    <td class="p-8 text-gray-400 text-xs italic font-medium leading-relaxed">"${recommendation}"</td>
                    <td class="p-8 text-right">${recycleBtn}</td>
                </tr>
                `;
            });

            freshnessData.innerHTML = html;
            document.getElementById('totalScans').innerText = total;
            document.getElementById('healthyStocks').innerText = healthy;
            document.getElementById('forDiscount').innerText = discount;
            document.getElementById('criticalStocks').innerText = critical;
        });
    });
</script>

<?php include 'templates/footer.php'; ?>
