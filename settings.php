<?php 
/**
 * BLOOMINOUS - Settings & Configurations (Firebase Spoke)
 *
 * Every row is a "settings tile" (icon + title + subtitle), mirroring
 * settings_page.dart. A tile either:
 *   - shows info        (Email, Version)
 *   - toggles something (Dark Mode)
 *   - opens a modal     (Change Password, Terms of Service,
 *                        Privacy Policy, FAQ)
 *   - goes to a page    (Shop Profile -> Manage Branches)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$userRole = $_SESSION['role'] ?? '';
$canManageShop = in_array($userRole, ['admin', 'super-admin'], true);

include 'templates/header.php'; 
?>

<style>
    .settings-content { padding: 30px; max-width: 1000px; margin: 0 auto; font-family: 'Inter', sans-serif; }
    .settings-section-label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px; margin: 32px 0 14px; }
    .settings-section-label:first-of-type { margin-top: 0; }
    .form-group { margin-bottom: 15px; }
    label { font-size: 0.75rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; margin-bottom: 5px; display: block; }
    input, textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 10px; outline: none; font-size: 0.9rem; background: var(--surface-alt); color: var(--text-main); transition: 0.3s; }
    input:focus, textarea:focus { border-color: #7B79F2; }
    .btn-save { background: #7B79F2; color: white; border: none; padding: 12px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 10px; transition: 0.3s; }
    .btn-save:hover { background: #5a58d1; transform: translateY(-2px); }
    .btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 0.9rem; display: none; }

    /* --- Settings-tile styling: mirrors settings_page.dart's tile
       list. All colors route through header.php's CSS variables so
       the page redraws correctly in dark mode. text-decoration:none
       lets an <a> tile (Shop Profile) look identical to a <div> tile. --- */
    .settings-tile { display: flex; align-items: center; gap: 14px; padding: 16px 20px; border-radius: 18px; background: var(--surface); border: 1px solid var(--border-color); margin-bottom: 10px; transition: 0.2s; text-decoration: none; }
    .settings-tile.clickable { cursor: pointer; }
    .settings-tile.clickable:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.04); }
    .settings-tile-icon { width: 38px; height: 38px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0; }
    .settings-tile-text { flex: 1; min-width: 0; }
    .settings-tile-title { font-weight: 700; font-size: 0.85rem; color: var(--text-main); margin: 0; }
    .settings-tile-subtitle { font-size: 0.75rem; color: var(--text-light); margin: 2px 0 0; }
    .settings-tile-chevron { color: var(--border-color); font-size: 0.8rem; }

    /* --- Simple toggle switch, used for Dark Mode --- */
    .toggle-switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider { position: absolute; cursor: pointer; inset: 0; background: var(--border-color); border-radius: 26px; transition: 0.2s; }
    .toggle-slider::before { content: ""; position: absolute; height: 20px; width: 20px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.2s; }
    .toggle-switch input:checked + .toggle-slider { background: var(--primary); }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

    /* --- Modals (Change Password, Terms of Service, Privacy Policy, FAQ) --- */
    .bloom-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 300; align-items: center; justify-content: center; padding: 20px; }
    .bloom-modal-overlay.open { display: flex; }
    .bloom-modal { background: var(--surface); border-radius: 24px; max-width: 680px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
    .bloom-modal.bloom-modal-sm { max-width: 440px; }
    .bloom-modal-header { padding: 24px 28px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .bloom-modal-header h3 { margin: 0; font-weight: 800; color: var(--text-main); }
    .bloom-modal-close { background: none; border: none; font-size: 1.2rem; color: var(--text-light); cursor: pointer; }
    .bloom-modal-body { padding: 24px 28px; overflow-y: auto; color: var(--text-main); font-size: 0.85rem; line-height: 1.7; }
    .bloom-modal-body h4 { font-weight: 800; margin: 20px 0 8px; color: var(--text-main); }
    .bloom-modal-body h4:first-child { margin-top: 0; }
    .bloom-modal-body p, .bloom-modal-body ol, .bloom-modal-body ul { margin: 0 0 10px; color: var(--text-secondary); }
    .bloom-modal-body ul { padding-left: 20px; list-style: disc; }
    .bloom-modal-body li { margin-bottom: 6px; }
    .modal-inline-error { display: none; font-size: 0.8rem; font-weight: 600; color: #ff7782; background: rgba(255, 119, 130, 0.1); padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }

    /* --- FAQ list --- */
    .faq-item { padding: 14px 18px; border-radius: 14px; background: var(--surface-alt); border: 1px solid var(--border-color); margin-bottom: 8px; }
    .faq-question { font-weight: 700; font-size: 0.85rem; color: var(--text-main); margin: 0 0 4px; }
    .faq-answer { font-size: 0.8rem; color: var(--text-secondary); margin: 0; line-height: 1.5; }
</style>

<main class="settings-content">
    <div style="margin-bottom: 10px;">
        <h1 style="font-weight: 800; font-size: 28px; color: var(--text-main);">Settings</h1>
        <p style="color: var(--text-light); font-size: 0.9rem;">Manage your account, shop, and app preferences.</p>
    </div>

    <div id="success-alert" class="alert" style="background: #e6fff6; color: #41f1b6;"></div>
    <div id="error-alert" class="alert" style="background: #ffe6e6; color: #ff7782;"></div>

    <!-- ============ ACCOUNT ============ -->
    <p class="settings-section-label">Account</p>

    <div class="settings-tile">
        <div class="settings-tile-icon"><i class="fa-solid fa-envelope"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Email</p>
            <p class="settings-tile-subtitle"><?php echo htmlspecialchars($_SESSION['email'] ?? 'Not available'); ?></p>
        </div>
    </div>

    <?php if ($canManageShop): ?>
    <!-- Change Password: a tile that opens the password modal below -->
    <div class="settings-tile clickable" onclick="openBloomModal('passwordModal')">
        <div class="settings-tile-icon"><i class="fa-solid fa-key"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Change Password</p>
            <p class="settings-tile-subtitle">Update the password for your account</p>
        </div>
        <i class="fa-solid fa-chevron-right settings-tile-chevron"></i>
    </div>
    <?php endif; ?>

    <!-- ============ SHOP PROFILE ============ -->
    <?php if ($canManageShop): ?>
    <p class="settings-section-label">Shop Profile</p>
    <!-- Shop Profile: a tile that goes to the Manage Branches page -->
    <a class="settings-tile clickable" href="manage_branches.php">
        <div class="settings-tile-icon"><i class="fa-solid fa-store"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Shop Profile</p>
            <p class="settings-tile-subtitle">Manage your shop branches and locations</p>
        </div>
        <i class="fa-solid fa-chevron-right settings-tile-chevron"></i>
    </a>
    <?php endif; ?>

    <!-- ============ PREFERENCES ============ -->
    <p class="settings-section-label">Preferences</p>

    <div class="settings-tile">
        <div class="settings-tile-icon"><i class="fa-solid fa-moon"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Dark Mode</p>
            <p class="settings-tile-subtitle">Follows your system by default, or your saved choice</p>
        </div>
        <label class="toggle-switch">
            <input type="checkbox" id="darkModeToggle">
            <span class="toggle-slider"></span>
        </label>
    </div>

    <!-- ============ APP INFO ============ -->
    <p class="settings-section-label">App Info</p>

    <div class="settings-tile">
        <div class="settings-tile-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Version</p>
            <p class="settings-tile-subtitle">Bloominous Web Admin — v1.0</p>
        </div>
    </div>

    <div class="settings-tile clickable" onclick="openBloomModal('tosModal')">
        <div class="settings-tile-icon"><i class="fa-solid fa-file-contract"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Terms of Service</p>
            <p class="settings-tile-subtitle">Read our policies</p>
        </div>
        <i class="fa-solid fa-chevron-right settings-tile-chevron"></i>
    </div>

    <!-- NEW: Privacy Policy tile (visible to every role, like Terms) -->
    <div class="settings-tile clickable" onclick="openBloomModal('privacyModal')">
        <div class="settings-tile-icon"><i class="fa-solid fa-user-shield"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Privacy Policy</p>
            <p class="settings-tile-subtitle">How we collect, use, and protect your data</p>
        </div>
        <i class="fa-solid fa-chevron-right settings-tile-chevron"></i>
    </div>

    <div class="settings-tile clickable" onclick="openBloomModal('faqModal')">
        <div class="settings-tile-icon"><i class="fa-solid fa-circle-question"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">FAQ</p>
            <p class="settings-tile-subtitle"><?php echo $canManageShop ? 'View and manage frequently asked questions' : 'View frequently asked questions'; ?></p>
        </div>
        <i class="fa-solid fa-chevron-right settings-tile-chevron"></i>
    </div>

    <div class="settings-tile clickable" onclick="alert('For support, please contact your system administrator.')">
        <div class="settings-tile-icon"><i class="fa-solid fa-life-ring"></i></div>
        <div class="settings-tile-text">
            <p class="settings-tile-title">Help & Support</p>
            <p class="settings-tile-subtitle">Get assistance with the admin portal</p>
        </div>
    </div>
</main>

<?php if ($canManageShop): ?>
<!-- ============ CHANGE PASSWORD MODAL ============ -->
<div class="bloom-modal-overlay" id="passwordModal">
    <div class="bloom-modal bloom-modal-sm">
        <div class="bloom-modal-header">
            <h3><i class="fa-solid fa-key" style="color: #FF5252; margin-right: 8px;"></i>Change Password</h3>
            <button class="bloom-modal-close" onclick="closeBloomModal('passwordModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="bloom-modal-body">
            <div id="passwordModalError" class="modal-inline-error"></div>
            <form id="changePasswordForm">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" id="newPassword" required placeholder="Minimum 6 characters" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirmNewPassword" required placeholder="Confirm new password" autocomplete="new-password">
                </div>
                <button type="submit" id="changePasswordBtn" class="btn-save" style="background: #FF5252;">Update Password</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============ TERMS OF SERVICE MODAL ============ -->
<div class="bloom-modal-overlay" id="tosModal">
    <div class="bloom-modal">
        <div class="bloom-modal-header">
            <h3>Terms of Service</h3>
            <button class="bloom-modal-close" onclick="closeBloomModal('tosModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="bloom-modal-body">
            <p>By placing an order on this website or mobile application, you are agreeing to the following terms and conditions:</p>

            <h4>Order Acceptance Policy</h4>
            <p>All orders and online requests received are subject to acceptance by Bloominous Flower Shop. We reserve the right, at our absolute discretion, to reject or cancel any order without giving prior reasons (e.g., due to stock depletion or system fraud flags). In the event of an order rejection by our management, any payment received will be refunded or canceled in full via the original payment method used.</p>

            <h4>Delivery &amp; Pickup Policy</h4>
            <ol>
                <li>Flower deliveries are available from Mondays to Sundays.</li>
                <li>For online ordering, customers may select their preferred fulfillment mode: Cash on Delivery (COD), Store Pick-up, or E-Wallet payment.</li>
                <li>For same-day deliveries, orders must be placed within the available operational hours of the target branches.</li>
                <li>Specific time-slot deliveries are subject to local traffic conditions and courier availability. While we strive for punctuality, Bloominous Flower Shop cannot be held liable for late deliveries caused by severe weather conditions, extreme traffic, or factors outside our control.</li>
                <li>The sender is responsible for providing accurate recipient details (full name, complete address, and active contact number). If a delivery fails due to incorrect customer details or an uncontactable recipient, a re-delivery fee may apply.</li>
            </ol>

            <h4>Changes to Your Order</h4>
            <p>If you wish to make changes to your order (including delivery addresses or card greeting messages), please contact our team immediately through our official channels. For scheduled or pre-orders, request changes at least one day prior to the delivery date. For same-day orders, we will make every effort to accommodate modifications, but changes cannot be guaranteed once processing has begun.</p>

            <h4>Cancellation &amp; Refund Policy</h4>
            <ol>
                <li><strong>Advance / Pre-Orders:</strong> Cancellations requested before the order goes into the preparation pipeline may be granted and processed via store credit or account balance adjustment.</li>
                <li><strong>Same-Day &amp; Custom AR Orders:</strong> Orders that have already been prepared, assembled, or dispatched by our florists cannot be canceled or refunded due to the perishable nature of floral stocks.</li>
                <li><strong>Non-Refundable Policy:</strong> Strictly no cash refund transactions are permitted once a floral arrangement has been custom-built, accepted, or successfully delivered. If an order cannot be fulfilled by our shop due to unforeseen supply issues, a full replacement or reimbursement will be issued.</li>
            </ol>

            <h4>Product, AR Customization, and Substitution Policy</h4>
            <ol>
                <li><strong>Perishable Nature:</strong> Flowers are natural, perishable goods. Their actual color, shade, bouquet size, fillers and bloom stage may slightly vary from visual previews.</li>
                <li><strong>Augmented Reality (AR) Previews:</strong> The 3D and AR customization modules within our web and mobile application serve as interactive visual references. While AR models provide a 3D preview of arrangement styles, wrappers, and ribbons, minor differences between the digital preview and the physical hand-crafted arrangement may occur.</li>
                <li><strong>Substitutions:</strong> All items are subject to live stock availability. If specific flower species, wrapper colors, or accessories become unavailable, Bloominous Flower Shop reserves the right to substitute them with materials of equivalent or greater value and quality to maintain the design aesthetic.</li>
            </ol>

            <h4>Payments</h4>
            <ol>
                <li>We accept Cash on Pick-up, Cash on Delivery (COD), and digital E-Wallets (GCash, PayMaya, etc.).</li>
                <li>Online e-wallet transactions and digital receipts are validated securely through integrated payment channels.</li>
                <li>We do not store sensitive payment card credentials directly on our local servers.</li>
            </ol>

            <h4>Freshness &amp; Quality Assurance</h4>
            <p>We are committed to delivering fresh floral arrangements. Fresh flowers are visually scanned and monitored using our integrated freshness tracking tools. If you receive flowers that are severely damaged or defective upon arrival, you must report the issue within 24 hours of receipt by providing your Order ID and clear photographs of the product. Valid reports will be reviewed by our customer support for a replacement on the next available delivery date.</p>

            <h4>Promos &amp; Discount Vouchers</h4>
            <ol>
                <li>Discount vouchers and promotional promo codes may be issued periodically at our discretion.</li>
                <li>Promotional codes cannot be combined with other active discounts or retroactively applied to completed orders.</li>
                <li>Bloominous Flower Shop reserves the right to modify or discontinue promo offers without prior notice.</li>
            </ol>

            <h4>Force Majeure &amp; Uncontrollable Circumstances</h4>
            <p>Bloominous Flower Shop shall not be liable for delayed performance or delivery failures resulting from severe weather conditions (typhoons, heavy floods), acts of God, government restrictions, power outages, system network disruptions, or other events beyond our reasonable control.</p>

            <h4>Customer Information &amp; Privacy</h4>
            <p>We value your privacy. Personal information collected during account registration and checkout — such as your full name, contact details, email address, and recipient address — is strictly used to fulfill transactions, process orders, send status updates, and improve user service. We do not lease, sell, or rent your personal information to third parties.</p>

            <h4>Store Details &amp; Operating Headquarters</h4>
            <p><strong>Bloominous Flower Shop</strong><br>
            <strong>Operating Hours:</strong> 12 Hours Daily (Catering to Walk-in &amp; Online Customers)</p>
        </div>
    </div>
</div>

<!-- ============ PRIVACY POLICY MODAL (NEW) ============ -->
<div class="bloom-modal-overlay" id="privacyModal">
    <div class="bloom-modal">
        <div class="bloom-modal-header">
            <h3>Privacy Policy</h3>
            <button class="bloom-modal-close" onclick="closeBloomModal('privacyModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="bloom-modal-body">
            <h4>1. Information We Collect</h4>
            <p>We collect the following information to provide and improve our services:</p>
            <ul>
                <li><strong>Personal Information:</strong> Name, email address, and other profile details provided during account setup.</li>
                <li><strong>Device Information:</strong> Mobile device type, operating system version, and unique device identifiers.</li>
                <li><strong>Usage Data:</strong> Analytics regarding how you interact with specific features within the app.</li>
            </ul>

            <h4>2. How We Use Your Data</h4>
            <p>We use the collected data to:</p>
            <ul>
                <li>Operate, maintain, and optimize application performance.</li>
                <li>Process your requests and account transactions.</li>
                <li>Enhance system security and resolve technical errors.</li>
            </ul>

            <h4>3. Third-Party Data Sharing</h4>
            <p>We do not sell your personal data. Data may only be shared with trusted third-party service providers (such as cloud database hosting or authentication services) that assist in operating the app.</p>

            <h4>4. Data Security</h4>
            <p>We implement standard technical security measures, including encryption, to safeguard your personal information against unauthorized access.</p>

            <h4>5. Your Data Rights</h4>
            <p>You have the right to request access to, correction of, or permanent deletion of your personal data stored in our system. Please contact our support team to submit a request.</p>
        </div>
    </div>
</div>

<!-- ============ FAQ MODAL ============ -->
<div class="bloom-modal-overlay" id="faqModal">
    <div class="bloom-modal">
        <div class="bloom-modal-header">
            <h3>Frequently Asked Questions</h3>
            <button class="bloom-modal-close" onclick="closeBloomModal('faqModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="bloom-modal-body">
            <?php if ($canManageShop): ?>
            <form id="addFaqForm" style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                <div class="form-group">
                    <label>Question</label>
                    <input type="text" id="faqQuestion" required placeholder="e.g. What payment methods do you accept?">
                </div>
                <div class="form-group">
                    <label>Answer</label>
                    <textarea id="faqAnswer" rows="3" required placeholder="Write the answer customers will see..."></textarea>
                </div>
                <button type="submit" id="addFaqBtn" class="btn-save" style="margin-top: 0;">Add FAQ</button>
            </form>
            <?php endif; ?>

            <div id="faqList">
                <p style="font-size: 0.8rem; color: var(--text-light); font-style: italic; text-align: center; padding: 20px 0;">Loading FAQs...</p>
            </div>
        </div>
    </div>
</div>

<script>
    // --- Shared modal helpers: every modal on this page opens and
    // closes through these two functions. ---
    function openBloomModal(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('open');
    }

    function closeBloomModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('open');
        // Never leave typed passwords sitting in a hidden form.
        if (id === 'passwordModal') {
            const form = document.getElementById('changePasswordForm');
            if (form) form.reset();
            const err = document.getElementById('passwordModalError');
            if (err) err.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const canManageShop = <?php echo $canManageShop ? 'true' : 'false'; ?>;

        // --- Dark Mode toggle: reflects the current resolved theme on
        // load and flips it via window.toggleBloomTheme() (both helpers
        // live globally in header.php). ---
        const darkToggle = document.getElementById('darkModeToggle');
        if (darkToggle) {
            darkToggle.checked = window.isBloomThemeDark();
            darkToggle.addEventListener('change', () => {
                window.toggleBloomTheme();
            });
        }

        <?php if ($canManageShop): ?>
        // --- Change Password (inside the modal) ---
        const passwordError = document.getElementById('passwordModalError');
        const showPasswordError = (msg) => {
            passwordError.innerText = msg;
            passwordError.style.display = 'block';
        };

        document.getElementById('changePasswordForm').onsubmit = async (e) => {
            e.preventDefault();
            passwordError.style.display = 'none';
            const btn = document.getElementById('changePasswordBtn');
            const pass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmNewPassword').value;

            if (pass.length < 6) {
                showPasswordError('Password must be at least 6 characters.');
                return;
            }
            if (pass !== confirmPass) {
                showPasswordError('Passwords do not match.');
                return;
            }

            btn.disabled = true;
            btn.innerText = 'Updating...';

            try {
                const user = window.auth.currentUser;
                if (!user) throw new Error('No user is currently logged in via Firebase Auth.');
                await user.updatePassword(pass);
                closeBloomModal('passwordModal');
                showSuccess('Password updated successfully!');
            } catch (err) {
                if (err.code === 'auth/requires-recent-login') {
                    // Firebase requires a fresh login before sensitive changes.
                    showPasswordError('For security, please log out and log back in, then try changing your password again.');
                } else {
                    showPasswordError('Error: ' + err.message);
                }
            } finally {
                btn.disabled = false;
                btn.innerText = 'Update Password';
            }
        };
        <?php endif; ?>

        // --- FAQ: list is visible to everyone who can open this page;
        // the add-form only renders server-side for admin/super-admin. ---
        const faqList = document.getElementById('faqList');
        db.collection('faqs').orderBy('createdAt', 'asc').onSnapshot(snap => {
            if (snap.empty) {
                faqList.innerHTML = '<p style="font-size:0.8rem; color:var(--text-light); font-style:italic; text-align:center; padding:20px 0;">No FAQs added yet.</p>';
                return;
            }
            faqList.innerHTML = snap.docs.map(doc => {
                const f = doc.data();
                const deleteBtn = canManageShop
                    ? `<button onclick="deleteFaq('${doc.id}')" style="background:none; border:none; color:#ff7782; cursor:pointer; float:right; font-size:0.9rem;" title="Delete"><i class="fa-solid fa-trash"></i></button>`
                    : '';
                return `
                    <div class="faq-item">
                        ${deleteBtn}
                        <p class="faq-question">${escapeHtml(f.question || '')}</p>
                        <p class="faq-answer">${escapeHtml(f.answer || '')}</p>
                    </div>
                `;
            }).join('');
        });

        <?php if ($canManageShop): ?>
        document.getElementById('addFaqForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('addFaqBtn');
            btn.disabled = true;
            btn.innerText = 'Adding...';

            try {
                await db.collection('faqs').add({
                    question: document.getElementById('faqQuestion').value.trim(),
                    answer: document.getElementById('faqAnswer').value.trim(),
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                document.getElementById('addFaqForm').reset();
                showSuccess('FAQ added successfully!');
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Add FAQ';
            }
        };

        window.deleteFaq = async (id) => {
            if (!confirm('Remove this FAQ?')) return;
            try {
                await db.collection('faqs').doc(id).delete();
            } catch (err) {
                showError('Error: ' + err.message);
            }
        };
        <?php endif; ?>
    });

    // Close a modal when clicking its dark overlay (outside the card itself)
    document.querySelectorAll('.bloom-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeBloomModal(overlay.id);
        });
    });

    // Close any open modal with the Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.bloom-modal-overlay.open').forEach(overlay => closeBloomModal(overlay.id));
    });

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.innerText = str;
        return div.innerHTML;
    }

    function showSuccess(msg) {
        const s = document.getElementById('success-alert');
        if (s) {
            s.innerText = msg;
            s.style.display = 'block';
            setTimeout(() => s.style.display = 'none', 3000);
        }
    }

    function showError(msg) {
        const e = document.getElementById('error-alert');
        if (e) {
            e.innerText = msg;
            e.style.display = 'block';
            setTimeout(() => e.style.display = 'none', 3000);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>