/**
 * Drawing box for <x-signature-field>: draw with mouse, finger or pen, and put the
 * drawing as a PNG in the hidden field. The server
 * (App\Support\Signature) checks and stores it; this script only takes
 * care of the drawing and prevents the form from being submitted without
 * a signature.
 */
(function () {
    'use strict';

    function init(block) {
        const box = block.querySelector('[data-box]');
        const output = block.querySelector('[data-output]');
        const error = block.querySelector('[data-error]');
        const form = block.closest('form');
        const ctx = box.getContext('2d');

        let drawing = false;
        let drawn = false;
        // The area drawn on (CSS pixels): a dot or a dash is not a signature.
        let bounds = null;
        const minWidth = parseFloat(block.dataset.minWidth) || 0;
        const minHeight = parseFloat(block.dataset.minHeight) || 0;

        // The box at screen resolution, so the line does not get blurry on a
        // sharp screen. A new size clears the drawing, so only as long as
        // there is nothing in it yet.
        function size() {
            const dpr = window.devicePixelRatio || 1;
            const rect = box.getBoundingClientRect();
            box.width = Math.round(rect.width * dpr);
            box.height = Math.round(rect.height * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#1c1917';
        }

        function point(e) {
            const rect = box.getBoundingClientRect();
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
        }

        function extend(p) {
            bounds = bounds
                ? { left: Math.min(bounds.left, p.x), right: Math.max(bounds.right, p.x), top: Math.min(bounds.top, p.y), bottom: Math.max(bounds.bottom, p.y) }
                : { left: p.x, right: p.x, top: p.y, bottom: p.y };
        }

        // The same minimum size as the server (App\Support\Signature): width
        // and height both as a share of the box height, which is the same on
        // every screen, the line itself included.
        function bigEnough() {
            const rect = box.getBoundingClientRect();
            return bounds !== null
                && bounds.right - bounds.left + ctx.lineWidth >= rect.height * minWidth
                && bounds.bottom - bounds.top + ctx.lineWidth >= rect.height * minHeight;
        }

        function clear() {
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, box.width, box.height);
            ctx.restore();
            drawn = false;
            bounds = null;
            output.value = '';
        }

        function showError(message) {
            error.textContent = message || '';
            error.classList.toggle('hidden', !message);
        }

        box.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            drawing = true;
            box.setPointerCapture(e.pointerId);
            const p = point(e);
            extend(p);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        });

        box.addEventListener('pointermove', (e) => {
            if (!drawing) return;
            const p = point(e);
            extend(p);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            drawn = true;
        });

        function stop() {
            if (!drawing) return;
            drawing = false;
            // Only a drawing of a real size goes along; a small start (the dot
            // on an i) can still grow into one, so no message while drawing.
            if (drawn && bigEnough()) {
                output.value = box.toDataURL('image/png');
                showError(false);
            } else {
                output.value = '';
            }
        }

        box.addEventListener('pointerup', stop);
        box.addEventListener('pointercancel', stop);
        block.querySelector('[data-clear]').addEventListener('click', clear);

        // Read the signer's name along from the name field.
        const nameField = form && form.querySelector('[name="' + block.dataset.nameField + '"]');
        const nameRule = block.querySelector('[data-signer-rule]');
        const name = block.querySelector('[data-signer]');

        function showName() {
            const value = nameField ? nameField.value.trim() : '';
            name.textContent = value;
            nameRule.classList.toggle('hidden', value === '');
        }

        if (nameField) {
            nameField.addEventListener('input', showName);
            showName();
        }

        // Do not submit without a signature.
        if (form) {
            form.addEventListener('submit', (e) => {
                if (output.value === '') {
                    e.preventDefault();
                    showError(drawn ? block.dataset.tooSmall : block.dataset.notification);
                    block.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        size();
        window.addEventListener('resize', () => { if (!drawn) size(); });

        // Back after an error elsewhere in the form: the signature
        // is still there, so show it again instead of making the guest draw.
        if (output.value.startsWith('data:image/png;base64,')) {
            const image = new Image();
            image.onload = () => {
                const rect = box.getBoundingClientRect();
                ctx.drawImage(image, 0, 0, rect.width, rect.height);
                drawn = true;
                // Accepted by the server already: counts as big enough.
                bounds = { left: 0, right: rect.width, top: 0, bottom: rect.height };
            };
            image.src = output.value;
        }
    }

    document.querySelectorAll('[data-signature]').forEach(init);
})();
