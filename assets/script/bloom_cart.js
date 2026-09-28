/**
 * BLOOMINOUS - Firestore Cart Service
 *
 * The ONE place in BloominousWeb that knows how the cart is stored.
 * shop.php and checkout.php both call window.BloomCart instead of
 * touching localStorage or Firestore directly.
 *
 * --- DATA CONTRACT (shared with BloominousApp / Flutter) ---
 * Collection: carts
 * Document ID: the customer's Firebase Auth UID
 * {
 *   userId:    "<uid>",                      // must equal the doc ID
 *   items: {
 *     "<productId>": {                       // inventory doc ID
 *       id:       "<productId>",
 *       name:     "Red Rose Bouquet",
 *       price:    450,                       // number, display only (see note)
 *       qty:      2,                         // number, always > 0
 *       branchId: "main_branch" | null,      // branch the product came from
 *       image:    "https://..." | null
 *     }
 *   },
 *   updatedAt: <server timestamp>
 * }
 *
 * NOTE: price here is a convenience copy for showing totals. The server
 * (submit_order.php) should always be the authority on what gets charged.
 */
(function () {
    const CART_COLLECTION = 'carts';
    const LEGACY_KEY = 'bloom_cart'; // the old localStorage key from shop.php

    // --- Internal helpers -------------------------------------------------

    // Returns the signed-in Firebase user, or throws a readable error.
    function requireUser() {
        const user = firebase.auth().currentUser;
        if (!user) {
            throw new Error('You need to be signed in to use the cart.');
        }
        return user;
    }

    // Points at carts/{uid} for whoever is signed in right now.
    function cartRef() {
        const user = requireUser();
        return firebase.firestore().collection(CART_COLLECTION).doc(user.uid);
    }

    // Cleans raw Firestore/legacy data into a safe shape:
    // forces numbers, drops anything with qty <= 0.
    function normalizeItems(rawItems) {
        const clean = {};
        Object.entries(rawItems || {}).forEach(([productId, item]) => {
            const qty = Number(item && item.qty) || 0;
            if (qty > 0) {
                clean[productId] = {
                    id: productId,
                    name: (item && item.name) || 'Unnamed',
                    price: Number(item && item.price) || 0,
                    qty: qty,
                    branchId: (item && item.branchId) || null,
                    image: (item && item.image) || null
                };
            }
        });
        return clean;
    }

    // --- Public API -------------------------------------------------------

    // Waits until Firebase Auth has finished restoring the session.
    // Resolves with the user, or rejects if nobody is signed in.
    function whenSignedIn() {
        return new Promise((resolve, reject) => {
            const unsubscribe = firebase.auth().onAuthStateChanged(user => {
                unsubscribe(); // we only need the first answer
                if (user) {
                    resolve(user);
                } else {
                    reject(new Error('Not signed in.'));
                }
            });
        });
    }

    // One-time read of the cart. Returns { productId: item, ... }.
    async function load() {
        const snap = await cartRef().get();
        return snap.exists ? normalizeItems(snap.data().items) : {};
    }

    // Live listener. Calls onChange(items) now and on every change
    // (from this tab, another tab, or the Flutter app).
    // Returns an unsubscribe function to stop listening.
    function listen(onChange, onError) {
        return cartRef().onSnapshot(
            snap => {
                onChange(snap.exists ? normalizeItems(snap.data().items) : {});
            },
            err => {
                console.error('Cart listener error:', err);
                if (onError) onError(err);
            }
        );
    }

    // Adds `qty` of a product. Uses a server-side increment so two quick
    // taps (or two devices) never overwrite each other's count.
    async function add(product, qty = 1) {
        if (!product || !product.id) {
            throw new Error('Invalid product.');
        }
        const user = requireUser();
        const FieldValue = firebase.firestore.FieldValue;

        await cartRef().set({
            userId: user.uid,
            items: {
                [product.id]: {
                    id: product.id,
                    name: product.name || 'Unnamed',
                    price: Number(product.price) || 0,
                    branchId: product.branchId || null, // Firestore rejects `undefined`
                    image: product.image || null,
                    qty: FieldValue.increment(qty)
                }
            },
            updatedAt: FieldValue.serverTimestamp()
        }, { merge: true });
    }

    // Sets an exact quantity. 0 or less removes the item.
    async function setQty(productId, qty) {
        const newQty = Number(qty) || 0;
        if (newQty <= 0) {
            return remove(productId);
        }
        const FieldValue = firebase.firestore.FieldValue;
        const FieldPath = firebase.firestore.FieldPath;

        await cartRef().update(
            new FieldPath('items', productId, 'qty'), newQty,
            'updatedAt', FieldValue.serverTimestamp()
        );
    }

    // Removes a single product from the cart.
    async function remove(productId) {
        const FieldValue = firebase.firestore.FieldValue;
        const FieldPath = firebase.firestore.FieldPath;

        await cartRef().update(
            new FieldPath('items', productId), FieldValue.delete(),
            'updatedAt', FieldValue.serverTimestamp()
        );
    }

    // Empties the whole cart (e.g., after a successful order).
    async function clear() {
        await cartRef().delete();
    }

    // Moves an old localStorage cart (from before this update) into
    // Firestore exactly once, then deletes the old key.
    // Returns how many products were moved.
    async function migrateLegacy() {
        let legacy = {};
        try {
            legacy = JSON.parse(localStorage.getItem(LEGACY_KEY) || '{}');
        } catch (e) {
            legacy = {}; // corrupted JSON: nothing worth rescuing
        }

        const legacyItems = normalizeItems(legacy);
        const ids = Object.keys(legacyItems);

        if (ids.length === 0) {
            localStorage.removeItem(LEGACY_KEY);
            return 0;
        }

        const user = requireUser();
        const FieldValue = firebase.firestore.FieldValue;
        const items = {};

        ids.forEach(id => {
            const item = legacyItems[id];
            items[id] = {
                id: id,
                name: item.name,
                price: item.price,
                branchId: item.branchId,
                image: item.image,
                qty: FieldValue.increment(item.qty) // merge with anything already in Firestore
            };
        });

        await cartRef().set({
            userId: user.uid,
            items: items,
            updatedAt: FieldValue.serverTimestamp()
        }, { merge: true });

        // Only delete the old key AFTER Firestore confirmed the write.
        localStorage.removeItem(LEGACY_KEY);
        return ids.length;
    }

    // Shared math so shop and checkout always agree.
    function count(items) {
        return Object.values(items || {}).reduce((sum, item) => sum + item.qty, 0);
    }

    function total(items) {
        return Object.values(items || {}).reduce((sum, item) => sum + (item.price * item.qty), 0);
    }

    window.BloomCart = {
        whenSignedIn,
        load,
        listen,
        add,
        setQty,
        remove,
        clear,
        migrateLegacy,
        count,
        total
    };
})();