(function (global) {
    "use strict";

    // Applicability is supplied by PHP. Each instance belongs to one rendered form.
    function createShopConsents(root) {
        var rows = Array.from(root.querySelectorAll("[data-unipayment-consent-row]"));
        var generation = 0;
        var selection = null;
        var identity = null;
        var context = null;
        var active = false;
        var destroyed = false;

        function id(row) { return row.getAttribute("data-unipayment-consent-row"); }
        function box(row) { return row.querySelector("[data-unipayment-consent-checkbox]"); }
        function clearScoped() {
            rows.forEach(function (row) {
                if (row.getAttribute("data-unipayment-interest-scope") !== "all" && box(row)) box(row).checked = false;
            });
        }
        function hide() {
            rows.forEach(function (row) {
                row.hidden = true;
                if (box(row)) box(row).disabled = true;
            });
        }
        function begin(key) {
            if (selection !== null && selection !== key) clearScoped();
            selection = key;
            active = false;
            context = null;
            hide();
            return ++generation;
        }
        function isCurrent(token) {
            return !destroyed && root.isConnected !== false && token === generation;
        }
        function apply(token, next) {
            if (!isCurrent(token)) return false;
            active = false;
            if (!next || next.valid !== true || !Array.isArray(next.applicable_ids) ||
                !Array.isArray(next.required_ids) || typeof next.scheme_identity !== "string") {
                hide();
                return false;
            }
            if (identity !== null && identity !== next.scheme_identity) clearScoped();
            identity = next.scheme_identity;
            context = next;
            var applicable = next.applicable_ids.map(String);
            rows.forEach(function (row) {
                var visible = applicable.indexOf(id(row)) !== -1;
                row.hidden = !visible;
                if (box(row)) {
                    box(row).disabled = !visible;
                    if (!visible) box(row).checked = false;
                }
            });
            // A new mandatory ID absent from this HTML requires a fresh page render.
            active = next.required_ids.every(function (required) {
                return rows.some(function (row) { return id(row) === String(required) && !row.hidden && !!box(row); });
            });
            if (!active) hide();
            return active;
        }
        function resolved() { return active && !destroyed && root.isConnected !== false; }
        function ready() {
            return resolved() && context.required_ids.every(function (required) {
                return rows.some(function (row) {
                    return id(row) === String(required) && !row.hidden && box(row) && !box(row).disabled && box(row).checked;
                });
            });
        }
        function acceptedIds() {
            return resolved() ? rows.filter(function (row) {
                return !row.hidden && box(row) && !box(row).disabled && box(row).checked;
            }).map(id) : [];
        }
        hide();
        return {
            begin: begin, isCurrent: isCurrent, apply: apply, resolved: resolved, ready: ready, acceptedIds: acceptedIds,
            destroy: function () { begin(selection); destroyed = true; }
        };
    }

    global.createUnipaymentShopConsents = createShopConsents;
    if (typeof module === "object" && module.exports) module.exports = createShopConsents;
})(typeof window === "undefined" ? globalThis : window);
