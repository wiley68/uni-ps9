(function () {
    "use strict";

    function isHttpUrl(url) {
        return (
            typeof url === "string" &&
            (url.indexOf("https://") === 0 || url.indexOf("http://") === 0)
        );
    }

    function cleanupOverlay(overlay, html, body) {
        html.style.overflow = "";
        body.style.overflow = "";
        if (overlay) {
            overlay.classList.remove("unipay-fs-on");
            overlay.style.display = "none";
        }
    }

    function run() {
        var overlay = document.getElementById("unipay-fullscreen-overlay");
        if (!overlay) {
            return;
        }

        var html = document.documentElement;
        var body = document.body;
        overlay.classList.add("unipay-fs-on");
        html.style.overflow = "hidden";
        body.style.overflow = "hidden";

        var target = overlay.getAttribute("data-redirect-url") || "";
        if (isHttpUrl(target)) {
            window.location.href = target;
            return;
        }

        cleanupOverlay(overlay, html, body);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", run);
        return;
    }

    run();
})();
