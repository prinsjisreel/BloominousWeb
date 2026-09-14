<?php
require_once __DIR__ . '/templates/header.php';

// header.php already blocks customers, delivery personnel, and anyone
// not logged in at all. It does NOT restrict staff/employee from
// individual admin pages — each page gates that itself. This one is
// admin-only, same as fraud_analytics.php and manage_accounts.php: an
// employee's actual write here would be denied by firestore.rules'
// isAdmin() check anyway, but redirecting them here avoids a confusing
// "permission-denied" screen on a page they were never meant to reach.
$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'super-admin') {
    header("Location: admin.php");
    exit();
}
?>

<div class="mb-8">
    <h1 class="text-3xl font-black text-gray-800 brand-font">Override Codes</h1>
    <p class="text-sm text-gray-500 mt-1">Single-use codes required for an employee's 4th+ walk-in cancellation of the day at a branch.</p>
</div>

<div class="card mb-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-lg font-black text-gray-800">Current Batch</h2>
            <p class="text-xs text-gray-400" id="branch-context-label">Loading branch...</p>
        </div>
        <button id="generate-batch-btn" class="btn-primary">
            <i class="fa-solid fa-key"></i>
            Generate New Batch of 8
        </button>
    </div>

    <div id="unused-codes-container" class="flex flex-wrap gap-3">
        <p class="text-gray-300 italic text-sm">Nothing generated yet</p>
    </div>
</div>

<div class="card">
    <h2 class="text-lg font-black text-gray-800 mb-4">How This Works</h2>
    <ul class="text-sm text-gray-600 space-y-2 list-disc pl-5">
        <li>Each code is <strong>single-use</strong> — the instant an employee's app or a cashier uses one to approve a cancellation, it's permanently marked used and can never be reused, on either platform.</li>
        <li>Codes are scoped to <strong>this specific branch</strong> only.</li>
        <li>A new batch of 8 <strong>can only be generated once every code in the current batch has been used</strong> — the same rule Google's backup codes follow. Used codes stay visible with a strikethrough so you can see the batch's full history until it's replaced.</li>
        <li>Every override used is recorded in the Admin Activity Log, including which employee used it and which order it applied to.</li>
    </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.db) return;

    const container = document.getElementById('unused-codes-container');
    const branchLabel = document.getElementById('branch-context-label');
    const generateBtn = document.getElementById('generate-batch-btn');

    function renderBranchLabel() {
        branchLabel.innerText = `Branch: ${window.currentBranch}`;
    }
    renderBranchLabel();

    // Same character set as the Flutter app's generator — excludes
    // 0/O/1/I so a code read aloud over a phone call is never
    // misheard. window.crypto.getRandomValues is the browser's
    // cryptographically-secure RNG, matching Dart's Random.secure() —
    // not Math.random(), which is NOT safe for anything security-related.
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    function generateSecureCode(length = 8) {
        const randomValues = new Uint32Array(length);
        window.crypto.getRandomValues(randomValues);
        let code = '';
        for (let i = 0; i < length; i++) {
            // chars.length (32) divides evenly into 2^32, so this
            // modulo introduces zero measurable bias.
            code += chars[randomValues[i] % chars.length];
        }
        return code;
    }

    function setButtonState(mode, remaining) {
        // mode: 'empty' (no batch exists yet), 'active' (unused codes
        // remain), or 'exhausted' (every code in the batch is used).
        if (mode === 'active') {
            generateBtn.disabled = true;
            generateBtn.style.opacity = '0.5';
            generateBtn.style.cursor = 'not-allowed';
            generateBtn.title = `${remaining} unused code(s) remain — every code in this batch must be used before generating a new one.`;
            generateBtn.innerHTML = `<i class="fa-solid fa-lock"></i> ${remaining} Code${remaining === 1 ? '' : 's'} Still Active`;
        } else {
            generateBtn.disabled = false;
            generateBtn.style.opacity = '1';
            generateBtn.style.cursor = 'pointer';
            generateBtn.title = mode === 'exhausted'
                ? 'All codes in this batch have been used. Generate a fresh batch.'
                : '';
            generateBtn.innerHTML = '<i class="fa-solid fa-key"></i> Generate New Batch of 8';
        }
    }

    function renderCodes(docs) {
        if (docs.length === 0) {
            container.innerHTML = '<p class="text-gray-300 italic text-sm">No codes generated yet for this branch. Click "Generate New Batch of 8" to create the first set.</p>';
            setButtonState('empty', 0);
            return;
        }

        let html = '';
        let usedCount = 0;
        docs.forEach(doc => {
            const data = doc.data();
            const isUsed = data.used === true;
            if (isUsed) usedCount++;

            // Used codes get the strikethrough/grey treatment — same
            // visual language as Google's "used backup code" state.
            html += isUsed
                ? `<div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-2 font-mono font-bold text-gray-400 text-sm tracking-widest line-through" title="Already used">
                        ${data.code || '????????'}
                   </div>`
                : `<div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 font-mono font-bold text-amber-700 text-sm tracking-widest">
                        ${data.code || '????????'}
                   </div>`;
        });
        container.innerHTML = html;

        const remaining = docs.length - usedCount;
        setButtonState(remaining > 0 ? 'active' : 'exhausted', remaining);
    }

    let codesUnsubscribe = null;

    // Watches the "pointer" doc that says which batch is currently
    // active for this branch. Whenever the pointer changes (i.e., a new
    // batch is generated), re-subscribe to that specific batch's 8
    // codes instead of every code this branch has ever had.
    db.collection('override_code_batches').doc(window.currentBranch)
      .onSnapshot(pointerDoc => {
          if (codesUnsubscribe) {
              codesUnsubscribe();
              codesUnsubscribe = null;
          }

          if (!pointerDoc.exists || !pointerDoc.data().currentBatchId) {
              renderCodes([]);
              return;
          }

          const currentBatchId = pointerDoc.data().currentBatchId;

          // Two plain equality filters — no orderBy, so no composite
          // index is required here.
          codesUnsubscribe = db.collection('override_codes')
            .where('branchId', '==', window.currentBranch)
            .where('batchId', '==', currentBatchId)
            .onSnapshot(snap => {
                renderCodes(snap.docs);
            }, err => {
                console.error('Error loading override codes:', err);
                container.innerHTML = '<p class="text-red-400 text-sm">Error loading codes: ' + err.message + '</p>';
            });
      }, err => {
          console.error('Error loading batch pointer:', err);
      });

    generateBtn.addEventListener('click', async () => {
        // Guard against a stray click slipping through while disabled —
        // the real gate is server-verified by firestore.rules' isAdmin()
        // check regardless, but this avoids a wasted write attempt.
        if (generateBtn.disabled) return;

        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

        try {
            const newBatchId = crypto.randomUUID();
            const batch = db.batch();

            for (let i = 0; i < 8; i++) {
                const ref = db.collection('override_codes').doc();
                batch.set(ref, {
                    branchId: window.currentBranch,
                    batchId: newBatchId,
                    code: generateSecureCode(),
                    used: false,
                    createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                    createdBy: window.currentUserEmail || 'unknown',
                });
            }

            // Atomically points the branch at this new batch in the SAME
            // commit as the 8 codes — there's never a moment where the
            // pointer references a batch whose codes don't exist yet, or
            // vice versa.
            const pointerRef = db.collection('override_code_batches').doc(window.currentBranch);
            batch.set(pointerRef, {
                currentBatchId: newBatchId,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
                updatedBy: window.currentUserEmail || 'unknown',
            });

            await batch.commit();
            // No manual re-enable here — the pointer's onSnapshot fires
            // immediately after commit and calls renderCodes(), which
            // correctly shows all 8 new codes as unused and disables the
            // button again right away, exactly as intended.
        } catch (e) {
            alert('Failed to generate codes: ' + e.message);
            setButtonState('exhausted', 0);
        }
    });
});
</script>

    </div> <!-- closes .main-content, opened inside templates/header.php -->
</body>
</html>