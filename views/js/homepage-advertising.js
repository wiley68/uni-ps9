(function () {
    "use strict";

    document.addEventListener("click", function (event) {
        var toggle = event.target.closest(
            "[data-unipayment-advertising-toggle]",
        );
        if (toggle) {
            var panel = document.getElementById("unipayment-advertising-panel");
            if (!panel) {
                return;
            }
            var visible = panel.classList.toggle("is-visible");
            toggle.setAttribute("aria-expanded", visible ? "true" : "false");
            return;
        }

        var open = event.target.closest("[data-unipayment-advertising-open]");
        if (!open) {
            return;
        }
        var url = open.getAttribute("data-unipayment-advertising-open") || "";
        if (url) {
            window.open(url, "_blank", "noopener,noreferrer");
        }
    });
})();
