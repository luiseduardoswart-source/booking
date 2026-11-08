/**
 * Calculations for resizing in the photo editor.
 *
 * Deliberately separate from the buttons and fields: this is pure arithmetic without DOM, so it
 * can be tested (tests/js/image-resize.test.js) and the editor itself only has to
 * show what comes out here.
 *
 * The state that is passed around:
 *   width, height  the output size in pixels
 *   ratio          width/height of the crop
 *   locked         keep the ratio (the padlock)
 *   touched        has the user typed a size themselves? If not, the
 *                  fields simply follow the crop
 */
(function (root) {
    'use strict';

    // Above this size the browser runs out of memory when drawing on canvas;
    // it is also the limit the editor already used when cropping.
    var MAX = 4096;

    /** To a whole number of pixels within 1..max, or null if it is not a number. */
    function clamp(value, max) {
        max = max || MAX;
        var n = Math.round(Number(value));
        if (!isFinite(n) || n < 1) {
            return null;
        }
        return Math.min(n, max);
    }

    /**
     * The counterpart for a fixed ratio: a width yields a height, and vice versa.
     * Deliberately does not cap at the maximum — linkedPair() does that for both sides at once.
     */
    function counterpart(value, ratio, from) {
        var n = Number(value);
        if (!isFinite(ratio) || ratio <= 0 || !isFinite(n) || n <= 0) {
            return null;
        }
        var other = Math.round(from === 'width' ? n / ratio : n * ratio);

        return other < 1 ? null : other;
    }

    /**
     * The width/height pair for a fixed ratio, fitted to the maximum.
     * If the counterpart exceeds the limit, both sides shrink along so the photo
     * is not stretched.
     */
    function linkedPair(value, ratio, axis, max) {
        var other = counterpart(value, ratio, axis);
        if (other === null) {
            return null;
        }

        return fit(
            axis === 'width' ? value : other,
            axis === 'width' ? other : value,
            max
        );
    }

    /**
     * Fits a size in the allowed range without disturbing the ratio: too large
     * means shrinking both sides proportionally, not cutting off one side (that would
     * stretch the photo).
     */
    function fit(width, height, max) {
        max = max || MAX;
        var w = Math.round(Number(width));
        var h = Math.round(Number(height));
        if (!isFinite(w) || !isFinite(h) || w < 1 || h < 1) {
            return null;
        }
        var factor = Math.min(1, max / w, max / h);

        return {
            width: Math.max(1, Math.round(w * factor)),
            height: Math.max(1, Math.round(h * factor)),
        };
    }

    /**
     * Calculates the new state after an action.
     *
     * change.type:
     *   'width' / 'height'  user types a size (value)
     *   'lock'              toggle the padlock (value = on/off)
     *   'crop'              crop changed (cropWidth, cropHeight)
     *   'reset'             back to the crop (cropWidth, cropHeight)
     *
     * Always returns a new object; the state passed in is left untouched.
     */
    function apply(state, change) {
        var max = state.max || MAX;
        var next = {
            width: state.width,
            height: state.height,
            ratio: state.ratio,
            locked: !!state.locked,
            touched: !!state.touched,
            max: max,
        };

        if (change.type === 'width' || change.type === 'height') {
            var axis = change.type;
            var value = clamp(change.value, max);
            next.touched = true;
            if (value === null) {
                return next;      // half typed or nonsense: leave what was there
            }
            next[axis] = value;
            if (next.locked) {
                var pair = linkedPair(value, next.ratio, axis, max);
                if (pair) {
                    next.width = pair.width;
                    next.height = pair.height;
                }
            }

            return next;
        }

        if (change.type === 'lock') {
            next.locked = !!change.value;
            // straighten immediately when switching on, otherwise the padlock does not match the image
            if (next.locked) {
                var snapped = linkedPair(next.width, next.ratio, 'width', max);
                if (snapped) {
                    next.width = snapped.width;
                    next.height = snapped.height;
                }
            }

            return next;
        }

        if (change.type === 'crop' || change.type === 'reset') {
            var cropped = fit(change.cropWidth, change.cropHeight, max);
            if (!cropped) {
                return next;
            }
            next.ratio = change.cropWidth / change.cropHeight;

            if (change.type === 'reset') {
                return {
                    width: cropped.width,
                    height: cropped.height,
                    ratio: next.ratio,
                    locked: true,
                    touched: false,
                    max: max,
                };
            }

            if (!next.touched) {
                // user has not typed anything yet: the fields follow the crop
                next.width = cropped.width;
                next.height = cropped.height;
            } else if (next.locked) {
                // keep own width, let the height move with the new crop
                var moved = linkedPair(next.width, next.ratio, 'width', max);
                if (moved) {
                    next.width = moved.width;
                    next.height = moved.height;
                }
            }

            return next;
        }

        return next;
    }

    /** Initial state for a fresh crop. */
    function start(cropWidth, cropHeight, max) {
        return apply(
            { width: null, height: null, ratio: NaN, locked: true, touched: false, max: max || MAX },
            { type: 'reset', cropWidth: cropWidth, cropHeight: cropHeight }
        );
    }

    root.ImageResize = {
        MAX: MAX,
        clamp: clamp,
        counterpart: counterpart,
        linkedPair: linkedPair,
        fit: fit,
        apply: apply,
        start: start,
    };
})(typeof self !== 'undefined' ? self : this);
