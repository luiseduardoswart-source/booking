/**
 * Keeps track of which files and folders are ticked in the media manager.
 *
 * As with image-resize.js, the logic is separate from the buttons so it can be tested
 * (tests/js/media-selection.test.js). The selection is stored by path, not by
 * position in the list: that way it stays correct when searching or filtering.
 */
(function (root) {
    'use strict';

    function MediaSelection() {
        this.paths = [];        // ticked paths, in the order they were clicked
        this.types = {};        // path -> 'file' | 'dir'
        this.last = null;    // last clicked path, for selecting with shift
    }

    MediaSelection.prototype = {
        has: function (path) {
            return this.paths.indexOf(path) !== -1;
        },

        get count() {
            return this.paths.length;
        },

        /** What is sent to the server: [{path, type}]. */
        items: function () {
            var self = this;

            return this.paths.map(function (path) {
                return { path: path, type: self.types[path] || 'file' };
            });
        },

        add: function (path, type) {
            if (!path) return this;
            if (!this.has(path)) this.paths.push(path);
            this.types[path] = type === 'dir' ? 'dir' : 'file';

            return this;
        },

        remove: function (path) {
            var i = this.paths.indexOf(path);
            if (i !== -1) this.paths.splice(i, 1);
            delete this.types[path];

            return this;
        },

        toggle: function (path, type) {
            return this.has(path) ? this.remove(path) : this.add(path, type);
        },

        clear: function () {
            this.paths = [];
            this.types = {};
            this.last = null;

            return this;
        },

        /**
         * Clicking from the list. visible = [{path, type}] in screen order.
         * With shift held, everything between the previous and this one is switched on or
         * off, as in a file manager.
         */
        click: function (path, visible, shift) {
            visible = visible || [];
            var from = this.last;
            var on = !this.has(path);

            if (shift && from && from !== path) {
                var i = indexByPath(visible, from);
                var j = indexByPath(visible, path);
                if (i !== -1 && j !== -1) {
                    var of = Math.min(i, j);
                    var until = Math.max(i, j);
                    for (var k = of; k <= until; k++) {
                        if (on) {
                            this.add(visible[k].path, visible[k].type);
                        } else {
                            this.remove(visible[k].path);
                        }
                    }
                    this.last = path;

                    return this;
                }
            }

            var row = find(visible, path);
            this.toggle(path, row ? row.type : 'file');
            this.last = path;

            return this;
        },

        /** Tick everything currently shown, or untick everything. */
        setAll: function (visible, on) {
            var self = this;
            (visible || []).forEach(function (row) {
                on ? self.add(row.path, row.type) : self.remove(row.path);
            });

            return this;
        },

        /** Is everything currently visible ticked? (empty = no) */
        allSelected: function (visible) {
            var self = this;

            return !!(visible && visible.length) && visible.every(function (row) {
                return self.has(row.path);
            });
        },

        /**
         * Drops ticked paths that no longer exist — for example after the
         * deletion itself, or when someone else removed a file.
         */
        prune: function (existing) {
            var valid = {};
            (existing || []).forEach(function (row) {
                valid[row.path] = true;
            });
            var self = this;
            this.paths.slice().forEach(function (path) {
                if (!valid[path]) self.remove(path);
            });
            if (this.last && !valid[this.last]) this.last = null;

            return this;
        },
    };

    function indexByPath(list, path) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].path === path) return i;
        }

        return -1;
    }

    function find(list, path) {
        var i = indexByPath(list, path);

        return i === -1 ? null : list[i];
    }

    root.MediaSelection = MediaSelection;
})(typeof self !== 'undefined' ? self : this);
