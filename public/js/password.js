/**
 * Strength meter and password suggestion for <x-password-fields>.
 *
 * The meter estimates strength from length and character variety, not on "contains an
 * uppercase letter and a digit": according to the current guideline (NIST SP 800-63B-4)
 * that is precisely not a good measure. The requirements (minimum, maximum) come from
 * the component, which reads them from the same rule the server enforces. Whether a
 * password has ever been leaked is checked by the server on saving.
 */
(function () {
    'use strict';

    // Without look-alike characters (0/O, 1/l/I), so a suggested
    // password can also be retyped.
    const SETS = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%&*-_+?='];
    const SUGGESTION_LENGTH = 20;

    /** Random number 0..n-1 from the cryptographic source, without modulo bias. */
    function randomInt(n) {
        const limit = Math.floor(0x100000000 / n) * n;
        const buffer = new Uint32Array(1);
        do {
            crypto.getRandomValues(buffer);
        } while (buffer[0] >= limit);

        return buffer[0] % n;
    }

    function pick(set) {
        return set[randomInt(set.length)];
    }

    function suggest(length) {
        // At least one of each kind, so it also satisfies sites that still
        // have composition rules; then fill up and shuffle.
        const characters = SETS.map(pick);
        const everything = SETS.join('');

        while (characters.length < length) {
            characters.push(pick(everything));
        }

        for (let i = characters.length - 1; i > 0; i--) {
            const j = randomInt(i + 1);
            [characters[i], characters[j]] = [characters[j], characters[i]];
        }

        return characters.join('');
    }

    /**
     * @returns {null|{level: number, shortfall: number}}  level 0 (weak) to 3 (strong)
     */
    function strength(value, min) {
        const characters = Array.from(value); // per character, also for emoji or accents

        if (characters.length === 0) {
            return null;
        }

        if (characters.length < min) {
            return { level: 0, shortfall: min - characters.length };
        }

        let kinds = 0;
        if (/[a-z]/.test(value)) kinds += 26;
        if (/[A-Z]/.test(value)) kinds += 26;
        if (/[0-9]/.test(value)) kinds += 10;
        if (/[^a-zA-Z0-9]/.test(value)) kinds += 33;

        // Repetition does not count as extra length: "aaaa..." or "abcabc..." is not
        // strong, however long it is.
        const effective = Math.min(characters.length, new Set(characters).size * 2);
        const bits = effective * Math.log2(kinds);

        return { level: bits < 50 ? 0 : bits < 65 ? 1 : bits < 80 ? 2 : 3, shortfall: 0 };
    }

    function init(block) {
        const min = parseInt(block.dataset.min, 10);
        const max = parseInt(block.dataset.max, 10) || null;
        const labels = JSON.parse(block.dataset.labels);
        const colors = JSON.parse(block.dataset.colors);

        const input = block.querySelector('[data-input]');
        const confirmation = block.querySelector('[data-repeat]');
        const bar = block.querySelector('[data-bar]');
        const label = block.querySelector('[data-label]');
        const generated = block.querySelector('[data-generated]');
        const copy = block.querySelector('[data-copy]');

        let lastSuggestion = null;

        function showStrength() {
            const s = strength(input.value, min);
            bar.classList.remove(...colors);

            if (s === null) {
                bar.style.width = '0';
                label.textContent = '';
                return;
            }

            bar.classList.add(colors[s.level]);

            if (s.shortfall > 0) {
                const progress = Array.from(input.value).length / min;
                bar.style.width = Math.max(5, Math.round(progress * 25)) + '%';
                label.textContent = block.dataset.toShort.replace(':count', s.shortfall);
            } else {
                bar.style.width = ((s.level + 1) * 25) + '%';
                label.textContent = labels[s.level];
            }
        }

        function visible(on) {
            input.type = confirmation.type = on ? 'text' : 'password';
        }

        input.addEventListener('input', () => {
            // Typed by hand: the suggestion no longer applies. An automatically
            // filled-in confirmation is removed, otherwise a confusing
            // message follows that they do not match.
            if (lastSuggestion !== null) {
                if (confirmation.value === lastSuggestion) confirmation.value = '';
                lastSuggestion = null;
                generated.classList.add('hidden');
                generated.classList.remove('flex');
            }
            showStrength();
        });

        block.querySelector('[data-show]').addEventListener('click', () => {
            visible(input.type === 'password');
        });

        block.querySelector('[data-proposal]').addEventListener('click', () => {
            let length = Math.max(min, SUGGESTION_LENGTH);
            if (max) length = Math.min(length, max);

            lastSuggestion = suggest(length);
            input.value = confirmation.value = lastSuggestion;

            visible(true);
            generated.classList.remove('hidden');
            generated.classList.add('flex');
            showStrength();
        });

        copy.addEventListener('click', () => {
            const text = copy.textContent;

            navigator.clipboard.writeText(input.value)
                .then(() => {
                    copy.textContent = block.dataset.copied;
                    setTimeout(() => { copy.textContent = text; }, 2000);
                })
                .catch(() => {
                    // Clipboard refused: select, so Ctrl+C still works.
                    visible(true);
                    input.select();
                });
        });

        showStrength();
    }

    document.querySelectorAll('[data-password]').forEach(init);
})();
