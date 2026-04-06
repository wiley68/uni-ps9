/**
 * UniPayment — рекламен floater на начална страница (Classic / Hummingbird).
 */
"use strict";

function uniChangeContainer() {
    var uni_label_container = document.getElementsByClassName(
        "uni-label-container",
    )[0];
    if (!uni_label_container) {
        return;
    }
    if (uni_label_container.style.visibility === "visible") {
        uni_label_container.style.visibility = "hidden";
        uni_label_container.style.opacity = "0";
        uni_label_container.style.transition =
            "visibility 0s, opacity 0.5s ease";
    } else {
        uni_label_container.style.visibility = "visible";
        uni_label_container.style.opacity = "1";
    }
}

function uniGoTo() {
    var el = document.querySelector(".uni_float[data-uni-backurl]");
    var url = el ? el.getAttribute("data-uni-backurl") : "";
    if (url) {
        window.open(url, "_blank", "noopener,noreferrer");
    }
}

function uniHandleWidgetAction(el) {
    var action = el ? el.getAttribute("data-uni-action") : "";
    if (action === "toggle") {
        uniChangeContainer();
        return;
    }
    if (action === "goto") {
        uniGoTo();
    }
}

document.addEventListener("DOMContentLoaded", function () {
    var nodes = document.querySelectorAll(
        ".unipayment-unipanel-root .uni_float[data-uni-action]",
    );
    nodes.forEach(function (el) {
        el.addEventListener("click", function () {
            uniHandleWidgetAction(el);
        });
        el.addEventListener("keydown", function (event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                uniHandleWidgetAction(el);
            }
        });
    });
});
