/**
 * BLOOMINOUS - Saved Address Book (web checkout)
 *
 * Lets a signed-in customer save delivery addresses once and pick one at checkout
 * instead of retyping it for every order.
 *
 * Storage : customers/{uid}/addresses/{addressId}   (private to that account)
 * Fields  : fullName, phone, address, isDefault, createdAt, updatedAt
 *
 * It does NOT replace checkout's own inputs. Picking an address copies the values
 * into the existing name / phone / address boxes and fires their normal
 * 'input' + 'change' events, so checkout's existing handlers (phone normalizing,
 * phone verification, delivery fee) react exactly as if the customer typed it.
 *
 * Needs the Firebase compat SDK (app + auth + firestore) already loaded on the page.
 *
 * Usage (after the checkout markup):
 *   <script src="../assets/script/address_book.js"></script>
 *   <script>BloomAddressBook.init();</script>
 * or with explicit selectors:
 *   BloomAddressBook.init({ nameInput: '#recipientName', phoneInput: '#phone',
 *                           addressInput: '#address', mount: '#savedAddressMount' });
 */
(function () {
    'use strict';

    const MAX_ADDRESSES = 10;

    // Common ids/names checkout pages use. First match wins. Override with init options.
    const AUTO_SELECTORS = {
        nameInput: ['#recipientName', '#recipient_name', '#recipient', '#fullName', '#full_name', '#customerName', '#name', 'input[name="name"]', 'input[name="recipientName"]'],
        phoneInput: ['#phone', '#recipientPhone', '#contactNumber', '#contact', 'input[name="phone"]', 'input[type="tel"]'],
        addressInput: ['#address', '#deliveryAddress', '#delivery_address', '#fullAddress', 'textarea[name="address"]', 'input[name="address"]']
    };

    const CSS = `
    .abk-card{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;padding:16px 18px;border:1px solid var(--border-color,#eee);border-radius:14px;background:var(--surface,#fff);color:var(--text-main,#222);margin-bottom:14px}
    .abk-main{flex:1;min-width:0}
    .abk-title{font-weight:800;font-size:.9rem;margin-bottom:4px}
    .abk-line{font-size:.85rem;line-height:1.5;word-break:break-word}
    .abk-muted{color:var(--text-light,#777);font-size:.8rem}
    .abk-tag{display:inline-block;margin-top:6px;padding:1px 8px;border:1px solid var(--primary,#F59E0B);color:var(--primary,#F59E0B);border-radius:4px;font-size:.7rem;font-weight:700}
    .abk-btn{border:1px solid var(--border-color,#ddd);background:transparent;color:var(--text-main,#222);padding:10px 16px;border-radius:10px;font-weight:700;font-size:.8rem;cursor:pointer}
    .abk-btn:hover{border-color:var(--primary,#F59E0B)}
    .abk-btn-primary{background:var(--primary,#F59E0B);border-color:var(--primary,#F59E0B);color:#fff}
    .abk-btn-danger{color:#dc2626;border-color:rgba(220,38,38,.4)}
    .abk-btn:disabled{opacity:.5;cursor:not-allowed}
    .abk-link{background:none;border:none;color:var(--primary,#F59E0B);font-weight:700;font-size:.8rem;cursor:pointer;padding:0}
    .abk-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center;padding:16px}
    .abk-overlay.open{display:flex}
    .abk-modal{background:var(--surface,#fff);color:var(--text-main,#222);border-radius:18px;width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 60px rgba(0,0,0,.25)}
    .abk-head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid var(--border-color,#eee);font-weight:800}
    .abk-x{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-light,#777)}
    .abk-body{padding:6px 22px;overflow-y:auto}
    .abk-foot{display:flex;gap:10px;justify-content:flex-end;padding:14px 22px;border-top:1px solid var(--border-color,#eee)}
    .abk-row{display:flex;gap:12px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--border-color,#eee);cursor:pointer}
    .abk-row:last-child{border-bottom:none}
    .abk-row input[type=radio]{margin-top:4px;accent-color:var(--primary,#F59E0B)}
    .abk-field{margin:14px 0}
    .abk-field label{display:block;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--text-light,#777);margin-bottom:6px}
    .abk-field input[type=text],.abk-field textarea{width:100%;padding:12px 14px;border:1px solid var(--border-color,#ddd);border-radius:10px;background:var(--surface-alt,#fafafa);color:var(--text-main,#222);font-size:.9rem;outline:none;box-sizing:border-box}
    .abk-field input:focus,.abk-field textarea:focus{border-color:var(--primary,#F59E0B)}
    .abk-error{color:#dc2626;font-size:.8rem;font-weight:700;min-height:1.2em;margin:4px 0}
    html[data-theme="dark"] .abk-card,html[data-theme="dark"] .abk-modal{background:#2A241D;color:#EAE6DF;border-color:#3F382F}
    html[data-theme="dark"] .abk-field input[type=text],html[data-theme="dark"] .abk-field textarea{background:#221D17;color:#EAE6DF;border-color:#3F382F}
    `;

    const state = {
        uid: null,
        addresses: [],
        selectedId: null,   // the address currently copied into checkout
        pendingId: null,    // the radio choice inside the popup (applied on Confirm)
        editingId: null,    // null = adding a new address
        unsub: null,
        fields: {},
        summary: null,
        listModal: null,
        formModal: null
    };

    // ---------- tiny helpers ----------
    function h(tag, props, ...children) {
        const node = document.createElement(tag);
        Object.entries(props || {}).forEach(([k, v]) => {
            if (v === false || v == null) return;
            if (k === 'class') node.className = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        children.flat().forEach(c => {
            if (c == null || c === false) return;
            node.append(c.nodeType ? c : document.createTextNode(String(c)));
        });
        return node;
    }

    function findField(option, key) {
        const list = option ? [option] : AUTO_SELECTORS[key];
        for (const sel of list) {
            const found = document.querySelector(sel);
            if (found) return found;
        }
        return null;
    }

    function cleanPhone(raw) { return String(raw || '').replace(/[\s\-()]/g, ''); }
    function isValidPhone(p) { return /^(\+?63|0)9\d{9}$/.test(p); }   // PH mobile: 09XXXXXXXXX or +639XXXXXXXXX
    function col() { return firebase.firestore().collection('customers').doc(state.uid).collection('addresses'); }

    // ---------- copy a saved address into checkout's own inputs ----------
    function setValue(input, value) {
        if (!input) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function applyToCheckout(addr) {
        setValue(state.fields.nameInput, addr.fullName);
        setValue(state.fields.phoneInput, addr.phone);
        setValue(state.fields.addressInput, addr.address);
        state.selectedId = addr.id;
        renderSummary();
    }

    // ---------- live data ----------
    function startListening() {
        stopListening();
        state.unsub = col().onSnapshot(snap => {
            state.addresses = snap.docs.map(d => ({ id: d.id, ...d.data({ serverTimestamps: 'estimate' }) }));
            state.addresses.sort((a, b) => {
                if (!!b.isDefault !== !!a.isDefault) return b.isDefault ? 1 : -1;
                const am = a.updatedAt && a.updatedAt.toMillis ? a.updatedAt.toMillis() : 0;
                const bm = b.updatedAt && b.updatedAt.toMillis ? b.updatedAt.toMillis() : 0;
                return bm - am;
            });

            // The selected address was deleted elsewhere -> forget it.
            if (state.selectedId && !state.addresses.some(a => a.id === state.selectedId)) state.selectedId = null;

            // First load: pre-fill checkout with the default address, but never overwrite what
            // the customer already typed.
            if (!state.selectedId && state.addresses.length) {
                const addressBoxEmpty = !state.fields.addressInput || !state.fields.addressInput.value.trim();
                if (addressBoxEmpty) applyToCheckout(state.addresses.find(a => a.isDefault) || state.addresses[0]);
            }
            renderSummary();
            if (state.listModal.classList.contains('open')) renderList();
        }, err => {
            console.error('Address book listener failed:', err);
            state.summary.replaceChildren(h('div', { class: 'abk-muted' }, 'Could not load saved addresses. You can still type your address below.'));
        });
    }

    function stopListening() {
        if (state.unsub) { state.unsub(); state.unsub = null; }
    }

    // ---------- summary card (sits above the address box) ----------
    function renderSummary() {
        const box = state.summary;
        box.replaceChildren();

        if (!state.uid) {
            box.append(h('div', { class: 'abk-muted' }, 'Sign in to use your saved addresses.'));
            return;
        }

        const sel = state.addresses.find(a => a.id === state.selectedId);
        if (!sel) {
            const hasAny = state.addresses.length > 0;
            box.append(h('div', { class: 'abk-card' },
                h('div', { class: 'abk-main' },
                    h('div', { class: 'abk-title' }, 'Delivery Address'),
                    h('div', { class: 'abk-muted' }, hasAny ? 'Choose one of your saved addresses.' : 'No saved address yet. Save one so you never have to retype it.')
                ),
                h('button', { type: 'button', class: 'abk-btn abk-btn-primary', onclick: hasAny ? openList : () => openForm(null) }, hasAny ? 'Choose' : 'Add address')
            ));
            return;
        }

        box.append(h('div', { class: 'abk-card' },
            h('div', { class: 'abk-main' },
                h('div', { class: 'abk-title' }, 'Delivery Address'),
                h('div', { class: 'abk-line' }, h('strong', {}, sel.fullName), '  ', sel.phone),
                h('div', { class: 'abk-line' }, sel.address),
                sel.isDefault ? h('span', { class: 'abk-tag' }, 'Default') : null
            ),
            h('button', { type: 'button', class: 'abk-link', onclick: openList }, 'Change')
        ));
    }

    // ---------- "My Address" popup ----------
    function openList() {
        state.pendingId = state.selectedId || (state.addresses[0] && state.addresses[0].id) || null;
        renderList();
        state.listModal.classList.add('open');
    }

    function renderList() {
        const body = $(state.listModal, '.abk-body');
        body.replaceChildren();

        if (!state.addresses.length) body.append(h('p', { class: 'abk-muted', style: 'padding:18px 0' }, 'You have no saved addresses yet.'));

        state.addresses.forEach(a => {
            body.append(h('div', { class: 'abk-row', onclick: () => { state.pendingId = a.id; renderList(); } },
                h('input', { type: 'radio', name: 'abk-pick', checked: state.pendingId === a.id }),
                h('div', { class: 'abk-main' },
                    h('div', { class: 'abk-line' }, h('strong', {}, a.fullName), '  |  ', a.phone),
                    h('div', { class: 'abk-muted' }, a.address),
                    a.isDefault ? h('span', { class: 'abk-tag' }, 'Default') : null
                ),
                h('button', { type: 'button', class: 'abk-link', onclick: (e) => { e.stopPropagation(); openForm(a); } }, 'Edit')
            ));
        });

        const full = state.addresses.length >= MAX_ADDRESSES;
        const foot = $(state.listModal, '.abk-foot');
        foot.replaceChildren(
            h('button', { type: 'button', class: 'abk-btn', disabled: full, title: full ? 'You can save up to ' + MAX_ADDRESSES + ' addresses' : null, onclick: () => openForm(null) }, '+ Add New Address'),
            h('button', { type: 'button', class: 'abk-btn abk-btn-primary', disabled: !state.pendingId, onclick: confirmPick }, 'Confirm')
        );
    }

    function confirmPick() {
        const addr = state.addresses.find(a => a.id === state.pendingId);
        if (addr) applyToCheckout(addr);
        state.listModal.classList.remove('open');
    }

    function $(root, sel) { return root.querySelector(sel); }

    // ---------- add / edit form ----------
    function openForm(addr) {
        state.editingId = addr ? addr.id : null;
        const m = state.formModal;
        $(m, '.abk-head-title').textContent = addr ? 'Edit Address' : 'New Address';
        $(m, '#abk-name').value = addr ? addr.fullName : '';
        $(m, '#abk-phone').value = addr ? addr.phone : '';
        $(m, '#abk-address').value = addr ? addr.address : '';

        const defBox = $(m, '#abk-default');
        const isOnlyOrDefault = addr ? !!addr.isDefault : state.addresses.length === 0;
        defBox.checked = isOnlyOrDefault;
        defBox.disabled = isOnlyOrDefault;   // you can't un-default the default; make another one default instead

        $(m, '.abk-delete').style.display = addr ? '' : 'none';
        $(m, '.abk-error').textContent = '';
        m.classList.add('open');
        $(m, '#abk-name').focus();
    }

    async function saveForm() {
        const m = state.formModal;
        const err = $(m, '.abk-error');
        const saveBtn = $(m, '.abk-save');

        const fullName = $(m, '#abk-name').value.trim();
        const phone = cleanPhone($(m, '#abk-phone').value);
        const address = $(m, '#abk-address').value.trim().replace(/\s+/g, ' ');
        const wantsDefault = $(m, '#abk-default').checked;

        if (!fullName || fullName.length > 100) { err.textContent = 'Please enter the recipient name (up to 100 characters).'; return; }
        if (!isValidPhone(phone)) { err.textContent = 'Enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).'; return; }
        if (address.length < 10 || address.length > 300) { err.textContent = 'Please enter the full address (10 to 300 characters).'; return; }
        if (!state.editingId && state.addresses.length >= MAX_ADDRESSES) { err.textContent = 'You can save up to ' + MAX_ADDRESSES + ' addresses.'; return; }

        saveBtn.disabled = true;
        err.textContent = '';
        try {
            const batch = firebase.firestore().batch();
            const ref = state.editingId ? col().doc(state.editingId) : col().doc();
            const makeDefault = wantsDefault || state.addresses.length === 0;

            // Only one default at a time: clear the flag on any other default first.
            if (makeDefault) {
                state.addresses.filter(a => a.isDefault && a.id !== ref.id)
                    .forEach(a => batch.update(col().doc(a.id), { isDefault: false }));
            }

            const data = {
                fullName, phone, address,
                isDefault: makeDefault || (state.editingId && state.addresses.find(a => a.id === state.editingId)?.isDefault) === true,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            };
            if (!state.editingId) data.createdAt = firebase.firestore.FieldValue.serverTimestamp();
            batch.set(ref, data, { merge: true });
            await batch.commit();

            // If the address being edited is the one in checkout, refresh checkout's boxes too.
            if (state.selectedId === ref.id) applyToCheckout({ id: ref.id, fullName, phone, address, isDefault: data.isDefault });
            // A brand-new address becomes the active choice (what the customer expects).
            if (!state.editingId) applyToCheckout({ id: ref.id, fullName, phone, address, isDefault: data.isDefault });

            state.pendingId = ref.id;
            m.classList.remove('open');
        } catch (e) {
            console.error('Save address failed:', e);
            err.textContent = 'Could not save the address: ' + e.message;
        } finally {
            saveBtn.disabled = false;
        }
    }

    async function deleteCurrent() {
        if (!state.editingId) return;
        if (!window.confirm('Delete this saved address?')) return;
        const err = $(state.formModal, '.abk-error');
        try {
            const batch = firebase.firestore().batch();
            const gone = state.addresses.find(a => a.id === state.editingId);
            batch.delete(col().doc(state.editingId));

            // Deleted the default? Promote the next one so there is always a default.
            if (gone && gone.isDefault) {
                const next = state.addresses.find(a => a.id !== gone.id);
                if (next) batch.update(col().doc(next.id), { isDefault: true });
            }
            await batch.commit();
            if (state.selectedId === state.editingId) state.selectedId = null;
            if (state.pendingId === state.editingId) state.pendingId = null;
            state.formModal.classList.remove('open');
        } catch (e) {
            console.error('Delete address failed:', e);
            err.textContent = 'Could not delete the address: ' + e.message;
        }
    }

    // ---------- optional: save what the customer typed by hand ----------
    // Call after a successful order so a typed address is remembered next time.
    // Returns true if a new address was saved, false if it was already saved or invalid.
    async function rememberFromForm() {
        if (!state.uid) return false;
        const fullName = (state.fields.nameInput?.value || '').trim();
        const phone = cleanPhone(state.fields.phoneInput?.value);
        const address = (state.fields.addressInput?.value || '').trim().replace(/\s+/g, ' ');
        if (!fullName || !isValidPhone(phone) || address.length < 10 || address.length > 300) return false;
        if (state.addresses.length >= MAX_ADDRESSES) return false;

        const norm = s => s.toLowerCase().replace(/\s+/g, ' ');
        if (state.addresses.some(a => norm(a.address) === norm(address) && cleanPhone(a.phone) === phone)) return false;

        await col().add({
            fullName, phone, address,
            isDefault: state.addresses.length === 0,
            createdAt: firebase.firestore.FieldValue.serverTimestamp(),
            updatedAt: firebase.firestore.FieldValue.serverTimestamp()
        });
        return true;
    }

    // ---------- build the DOM once ----------
    function buildModals() {
        state.listModal = h('div', { class: 'abk-overlay', role: 'dialog', 'aria-modal': 'true' },
            h('div', { class: 'abk-modal' },
                h('div', { class: 'abk-head' }, h('span', {}, 'My Address'), h('button', { type: 'button', class: 'abk-x', 'aria-label': 'Close', onclick: () => state.listModal.classList.remove('open') }, '×')),
                h('div', { class: 'abk-body' }),
                h('div', { class: 'abk-foot' })
            ));

        state.formModal = h('div', { class: 'abk-overlay', role: 'dialog', 'aria-modal': 'true' },
            h('div', { class: 'abk-modal' },
                h('div', { class: 'abk-head' }, h('span', { class: 'abk-head-title' }, 'New Address'), h('button', { type: 'button', class: 'abk-x', 'aria-label': 'Close', onclick: () => state.formModal.classList.remove('open') }, '×')),
                h('div', { class: 'abk-body' },
                    h('div', { class: 'abk-field' }, h('label', { for: 'abk-name' }, 'Full name'), h('input', { type: 'text', id: 'abk-name', maxlength: '100', autocomplete: 'name' })),
                    h('div', { class: 'abk-field' }, h('label', { for: 'abk-phone' }, 'Phone number'), h('input', { type: 'text', id: 'abk-phone', maxlength: '20', inputmode: 'tel', placeholder: '09XXXXXXXXX', autocomplete: 'tel' })),
                    h('div', { class: 'abk-field' }, h('label', { for: 'abk-address' }, 'Full address'), h('textarea', { id: 'abk-address', rows: '3', maxlength: '300', placeholder: 'House/Unit, Street, Barangay, City, Province, Postal code' })),
                    h('label', { style: 'display:flex;gap:8px;align-items:center;font-size:.85rem;margin:6px 0 10px' }, h('input', { type: 'checkbox', id: 'abk-default' }), 'Set as default address'),
                    h('div', { class: 'abk-error' })
                ),
                h('div', { class: 'abk-foot' },
                    h('button', { type: 'button', class: 'abk-btn abk-btn-danger abk-delete', onclick: deleteCurrent }, 'Delete'),
                    h('button', { type: 'button', class: 'abk-btn', onclick: () => state.formModal.classList.remove('open') }, 'Cancel'),
                    h('button', { type: 'button', class: 'abk-btn abk-btn-primary abk-save', onclick: saveForm }, 'Save')
                )
            ));

        document.body.append(state.listModal, state.formModal);

        // Click on the dark backdrop or press Escape closes whichever popup is open.
        [state.listModal, state.formModal].forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') [state.listModal, state.formModal].forEach(m => m.classList.remove('open'));
        });
    }

    // ---------- public entry point ----------
    function init(options) {
        const opts = options || {};
        if (typeof firebase === 'undefined' || !firebase.auth || !firebase.firestore) {
            console.warn('BloomAddressBook: Firebase auth + firestore must be loaded first.');
            return;
        }

        state.fields = {
            nameInput: findField(opts.nameInput, 'nameInput'),
            phoneInput: findField(opts.phoneInput, 'phoneInput'),
            addressInput: findField(opts.addressInput, 'addressInput')
        };
        if (!state.fields.addressInput) {
            console.warn('BloomAddressBook: could not find the address input. Pass { addressInput: "#yourId" } to init().');
            return;
        }

        const style = h('style', {});
        style.textContent = CSS;
        document.head.append(style);

        state.summary = h('div', { class: 'abk-summary' });
        const mountPoint = opts.mount ? document.querySelector(opts.mount) : null;
        if (mountPoint) mountPoint.append(state.summary);
        else state.fields.addressInput.closest('div, label, p').before(state.summary);

        buildModals();
        renderSummary();

        firebase.auth().onAuthStateChanged(user => {
            stopListening();
            state.addresses = [];
            state.selectedId = null;
            state.uid = user ? user.uid : null;
            if (user) startListening(); else renderSummary();
        });
    }

    window.BloomAddressBook = { init, rememberFromForm };
})();