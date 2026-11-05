"use strict";

// Core model: click -> asynchronous orderability -> synthetic handlers -> form.submit().
// A direct call deliberately emits NO native submit event.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const protect = require("../../views/js/checkout-payment.js").protectCheckoutForm;
let assertions = 0;
function check(value, label) { assertions++; assert.ok(value, label); }
function fixture() {
    let ready = true, generation = 1, sends = 0, marked = 0;
    const listeners = new Map();
    const form = { isConnected: true,
        submit() { sends++; },
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); }
    };
    const guard = protect(form, () => ready, () => generation, () => marked++);
    function nativeEvent() {
        const event = { defaultPrevented: false, stopped: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped=true; } };
        for (const fn of listeners.get("submit") || []) { fn(event); if (event.stopped) break; }
        if (!event.defaultPrevented) sends++;
        return event;
    }
    return {form, guard, nativeEvent, listeners, setReady(value) { ready=value; }, invalidate() { generation++; },
        get sends() { return sends; }, get marked() { return marked; }};
}
(async () => {
    // Original F2 reproduction: the same DOM form survives its old root/controller.
    const same = fixture();
    same.guard.destroy();
    const newOwner = protect(same.form, () => true, () => 2, () => {});
    check(newOwner.claim() && same.form.submit() && same.sends === 1, "F2 same-form remount invokes genuine native submit once");
    check(!same.form.submit() && same.sends === 1, "same-form B duplicate direct submit blocked");

    const transfer = fixture();
    const oldSubmit = transfer.form.submit;
    const oldEvent = [...transfer.listeners.get("submit")][0];
    let readyB = false, generationB = 2, markedB = 0, blockedB = 0;
    transfer.guard.claim();
    const ownerB = protect(transfer.form, () => readyB, () => generationB, () => markedB++, () => blockedB++);
    const currentSubmit = transfer.form.submit;
    transfer.guard.destroy(); transfer.guard.release(); transfer.invalidate();
    check(transfer.form.submit === currentSubmit && transfer.listeners.get("submit").size === 1, "A -> B -> destroy A preserves B wrapper and listener");
    check(!transfer.guard.claim() && !oldSubmit() && blockedB === 0, "retained A callback cannot submit or invalidate B");
    const obsoleteEvent = {preventDefault() { throw new Error("obsolete A listener blocked B"); }, stopImmediatePropagation() { throw new Error("obsolete A listener stopped B"); }};
    oldEvent(obsoleteEvent);
    check(transfer.sends === 0 && markedB === 0, "obsolete A event callback is inert");
    check(!transfer.form.submit() && transfer.sends === 0 && blockedB === 1, "invalid B context blocks direct submit after remount");
    readyB = true;
    check(ownerB.claim(), "valid B can claim confirmation after remount");
    await Promise.resolve(); generationB++;
    check(!transfer.form.submit() && transfer.sends === 0, "B context change during asynchronous orderability blocks final submit");
    ownerB.release();
    check(ownerB.claim() && transfer.form.submit() && transfer.sends === 1 && markedB === 1, "reviewed B context submits natively exactly once");
    transfer.guard.destroy();
    check(!transfer.form.submit() && transfer.sends === 1, "old destroy cannot undo B duplicate protection");
    const ownerC = protect(transfer.form, () => true, () => 3, () => {});
    check(!ownerC.claim() && !transfer.form.submit() && transfer.sends === 1, "remount after send cannot duplicate the same form's native request");

    const remountNative = fixture();
    remountNative.guard.destroy();
    protect(remountNative.form, () => true, () => 1, () => {});
    check(!remountNative.nativeEvent().defaultPrevented && remountNative.sends === 1, "remounted native event path invokes actual submission");
    check(remountNative.nativeEvent().defaultPrevented && remountNative.sends === 1, "remounted native duplicate prevented");

    const reentrant = fixture(); reentrant.guard.destroy();
    let nested;
    protect(reentrant.form, () => true, () => 1, () => { nested = reentrant.form.submit(); });
    check(reentrant.form.submit() && nested === false && reentrant.sends === 1, "submitted callback cannot reenter direct native submission");

    const e = fixture();
    check(e.guard.claim(), "first confirmation claims intent");
    check(!e.guard.claim(), "second click blocked while orderability pending");
    await Promise.resolve();
    e.form.submit(); // synthetic path's final native method invocation
    check(e.sends === 1 && e.marked === 1, "synthetic/direct final boundary sends once");
    e.form.submit();
    check(e.sends === 1, "duplicate synthetic final submit blocked");
    const stale = fixture();
    stale.guard.claim();
    await Promise.resolve();
    stale.invalidate();
    check(!stale.form.submit() && stale.sends === 0, "generation change during async check blocks final submit even when ready again");
    stale.guard.release();
    stale.setReady(false);
    check(!stale.form.submit(), "direct submit blocked during pending/error");
    stale.setReady(true);
    check(!stale.form.submit(), "watchdog/release cannot authorize an obsolete async generation");
    check(stale.guard.claim() && stale.form.submit() && stale.sends === 1, "reviewed current confirmation allows final direct submit");
    const native = fixture();
    check(!native.nativeEvent().defaultPrevented && native.sends === 1, "native event path succeeds");
    check(native.nativeEvent().defaultPrevented && native.sends === 1, "native duplicate blocked");
    const invalid = fixture(); invalid.setReady(false);
    check(invalid.nativeEvent().defaultPrevented && invalid.sends === 0, "native invalid blocked");
    const old = fixture(); old.guard.claim(); old.form.isConnected=false;
    check(!old.form.submit(), "detached final submit blocked");
    old.guard.destroy(); old.form.isConnected=true;
    check(!old.form.submit(), "disposed reference blocked");
    const replacement = fixture();
    check(replacement.form.submit() && replacement.sends === 1, "replacement owns a fresh guard");
    let otherSends=0; const other={submit() { otherSends++; }};
    other.submit(); check(otherSends===1, "other payment forms unaffected");
    const source = fs.readFileSync(require.resolve("../../views/js/checkout-payment.js"), "utf8");
    check(!/HTMLFormElement\.prototype\s*[.=\[]/.test(source), "no global native form prototype mutation");
    check(!/jQuery|\$\s*\(/.test(source), "checkout controller has no jQuery dependency");
    console.log("OK checkout final submit boundary " + assertions + " assertions");
})().catch(error => { console.error(error); process.exitCode=1; });
