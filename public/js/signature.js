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

        function clear() {
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, box.width, box.height);
            ctx.restore();
            drawn = false;
            output.value = '';
        }

        function showError(tonen) {
            error.textContent = tonen ? block.dataset.notification : '';
            error.classList.toggle('hidden', !tonen);
        }

        box.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            drawing = true;
            box.setPointerCapture(e.pointerId);
            const p = point(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        });

        box.addEventListener('pointermove', (e) => {
            if (!drawing) return;
            const p = point(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            drawn = true;
        });

        function stop() {
            if (!drawing) return;
            drawing = false;
            if (drawn) {
                output.value = box.toDataURL('image/png');
                showError(false);
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
                    showError(true);
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
            };
            image.src = output.value;
        }
    }

    document.querySelectorAll('[data-signature]').forEach(init);
})();
