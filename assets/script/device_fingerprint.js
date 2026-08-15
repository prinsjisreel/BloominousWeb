/**
 * BLOOMINOUS - Device Fingerprint Helper
 *
 * Produces a semi-stable per-browser hash used only as a FRAUD SIGNAL, not
 * as proof of identity. It combines a random anchor persisted in
 * localStorage (the main source of stability — cleared if the user wipes
 * site data or uses a different browser/profile) with a few coarse device
 * characteristics, so that simply clearing localStorage after a ban still
 * changes the rest of the signature less easily than regenerating the
 * anchor alone.
 *
 * Limitations (by design, disclose these to whoever reviews this):
 *  - Private/incognito windows and "clear browsing data" reset the anchor.
 *  - A different browser or device produces a different hash entirely.
 *  - This is one signal among several (IP, email risk, velocity, geo) —
 *    never gate a hard block on this alone; see submit_order.php.
 */
(function () {
    async function sha256Hex(input) {
        const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function getStableAnchor() {
        const key = 'bloom_device_anchor';
        let anchor = localStorage.getItem(key);
        if (!anchor) {
            anchor = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
            localStorage.setItem(key, anchor);
        }
        return anchor;
    }

    function getCanvasSignature() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('bloom-fp-\u{1F338}', 2, 2);
            return canvas.toDataURL();
        } catch (e) {
            return 'no-canvas';
        }
    }

    async function bloomGetDeviceId() {
        const parts = [
            getStableAnchor(),
            navigator.userAgent || '',
            navigator.language || '',
            navigator.platform || '',
            String(navigator.hardwareConcurrency || ''),
            (screen.width || '') + 'x' + (screen.height || ''),
            (Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
            getCanvasSignature()
        ];
        return sha256Hex(parts.join('|'));
    }

    window.bloomGetDeviceId = bloomGetDeviceId;
})();