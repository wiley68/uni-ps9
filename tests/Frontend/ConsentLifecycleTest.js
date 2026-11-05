'use strict';

// Executes the production controllers with fake DOM, timers and fetch; no shop/network.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
let assertions = 0;
function check(ok, label) { assertions++; assert.ok(ok, label); }
class Node {
    constructor(attrs = {}) {
        this.attrs = attrs; this.dataset = {}; this.events = {}; this.nodes = {}; this.children = [];
        this.hidden = false; this.disabled = false; this.checked = false; this.readOnly = false;
        this.value = ''; this.defaultValue = ''; this.isConnected = true; this.style = { removeProperty() {}, setProperty() {} };
        this.nodeType = 1; this.offsetParent = {}; this.classes = new Set();
        this.classList = { add: (...keys) => keys.forEach(k => this.classes.add(k)), remove: (...keys) => keys.forEach(k => this.classes.delete(k)), toggle: (k, on) => on ? this.classes.add(k) : this.classes.delete(k), contains: k => this.classes.has(k) };
    }
    getAttribute(k) { return this.attrs[k] ?? null; }
    setAttribute(k, v) { this.attrs[k] = v; }
    removeAttribute(k) { delete this.attrs[k]; }
    hasAttribute(k) { return k in this.attrs; }
    addEventListener(k, fn) { (this.events[k] ||= []).push(fn); }
    removeEventListener(k, fn) { this.events[k] = (this.events[k] || []).filter(f => f !== fn); }
    emit(k, target = this) {
        const event = { target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, stopImmediatePropagation() {} };
        (this.events[k] || []).forEach(fn => fn(event)); return event;
    }
    querySelector(k) { return this.nodes[k] || null; }
    querySelectorAll(k) {
        if (k === '[data-unipayment-consent-row]') return this.rows || [];
        if (k === '[data-unipayment-consent-checkbox]') return (this.rows || []).map(r => r.box).filter(Boolean);
        if (k === 'input') return this.inputs || [];
        return [];
    }
    matches(k) { return k.includes('data-unipayment-checkout'); }
    closest(k) { return k === 'form' ? this.form || null : null; }
    appendChild(node) { this.children.push(node); if (node.selected) this.value = node.value; }
    submit() { this.nativeSubmits = (this.nativeSubmits || 0) + 1; }
    dispatchEvent() {}
    focus() {}
    contains(node) { return node === this || this.children.includes(node); }
}
const contexts = zero => ({ valid: true, interest_state: zero ? 'zero' : 'nonzero', scheme_identity: JSON.stringify([zero ? 'ZERO' : 'STD', 12, 0, 'standard', zero ? 'zero' : 'nonzero']), applicable_ids: zero ? [1,2,3,4] : [1,2,5,6], required_ids: zero ? [2,4] : [2,6] });
function environment(mode) {
    const root = new Node(); const document = new Node(); document.readyState = 'complete'; document.body = new Node();
    const form = new Node(); root.form = form;
    const rows = ['all','all','zero_interest','zero_interest','nonzero_interest','nonzero_interest'].map((scope, i) => {
        const row = new Node({ 'data-unipayment-consent-row': String(i + 1), 'data-unipayment-interest-scope': scope });
        row.box = i % 2 ? new Node() : null;
        if (row.box) { row.box.value = String(i + 1); row.nodes['[data-unipayment-consent-checkbox]'] = row.box; }
        return row;
    }); root.rows = rows;
    const get = key => root.nodes[key] ||= new Node();
    const select = get(mode === 'checkout' ? '[data-unipayment-scheme]' : '[data-unipayment-schemes]');
    const first = get('[data-unipayment-first]'); first.value = '0';
    get('input[name="unipayment_cart_snapshot"]').value = 'initial-signature';
    const modal = get('[data-unipayment-modal]'); modal.hidden = true;
    for (const key of ['[data-unipayment-first-row]','[data-unipayment-checkout-error]','[data-unipayment-scheme-hidden]','[data-unipayment-kop]','[data-unipayment-submit]','[data-unipayment-apply]','[data-unipayment-secondary]','[data-unipayment-popup-error]','[data-unipayment-submit-error]','[data-unipayment-customer-form]','[data-unipayment-step="1"]','[data-unipayment-step="2"]','[data-unipayment-step="3"]']) get(key);
    get('[data-unipayment-display="glp"]'); get('[data-unipayment-display="gpr"]');
    const customer = get('[data-unipayment-customer-form]');
    for (const [name,value] of Object.entries({first_name:'Ivan',last_name:'Ivanov',address:'Sofia 1',phone:'+359888123',email:'ivan@example.test'})) {
        const field = new Node(); field.value = field.defaultValue = value;
        customer.nodes['[name="' + name + '"]'] = field;
        customer.nodes['[data-unipayment-field="' + name + '"]'] = field;
        root.nodes['[data-unipayment-field-error="' + name + '"]'] = new Node();
    }
    const standard = {key:'STD|12',scheme_type:'standard',kop_code:'STD',months:12,filter_id:0,consent_context:contexts(false)};
    const zero = {...standard,key:'ZERO|12',kop_code:'ZERO',consent_context:contexts(true)};
    const config = {schemes:[standard,zero],offers:{standard:{schemes:[standard,zero],preferred_scheme_key:standard.key}},process2:false};
    select.value = standard.key;
    root.attrs['data-config'] = root.attrs['data-calculator'] = JSON.stringify(config);
    root.attrs['data-calculate-endpoint'] = root.attrs['data-popup-endpoint'] = root.attrs['data-endpoint'] = '/fake';
    root.attrs['data-popup-token'] = 'test'; root.attrs['data-product-id'] = '42';
    if (mode === 'cart') root.attrs['data-unipayment-source'] = 'cart';
    const payment = new Node(); payment.checked = true;
    document.nodes['input[name="payment-option"][data-module-name="unipayment"]'] = payment;
    document.nodes['#payment-confirmation button'] = new Node();
    document.nodes['[data-unipayment-cart-calculator]'] = root;
    let attached = true, activeRoot = root;
    document.querySelectorAll = key => attached && (key === '[data-unipayment-checkout]' || key === '[data-unipayment-calculator]') ? [activeRoot] : [];
    const requests = [], timers = new Map(), bus = {}, observers = [];
    let timer = 0;
    const sandbox = { document, console, URLSearchParams, HTMLElement: Node, CustomEvent: class {},
        MutationObserver: class { constructor(fn) { observers.push(fn); } observe() {} },
        setTimeout: (fn, delay) => { if (delay === 30000) return ++timer; timers.set(++timer, fn); return timer; }, clearTimeout: id => timers.delete(id),
        getComputedStyle: () => ({visibility:'visible',display:'block'}), matchMedia: () => ({matches:true}),
        prestashop: { on: (key,fn) => (bus[key] ||= []).push(fn) },
        fetch: (url,options) => new Promise((resolve,reject) => requests.push({url,options,resolve(body,status=true) { resolve({ok:status,json:()=>Promise.resolve(body)}); },reject})),
        location: {assign(){}},
    }; sandbox.window = sandbox;
    document.createElement = () => new Node();
    vm.createContext(sandbox);
    const execute = file => vm.runInContext(fs.readFileSync(path.join(__dirname,'../../views/js',file),'utf8'),sandbox,{filename:file});
    execute('shop-consents.js'); execute(mode === 'checkout' ? 'checkout-payment.js' : 'product-calculator.js');
    if (mode === 'cart') execute('cart-calculator.js');
    function click(selector, attrs = {}) {
        const target = new Node(attrs); target.closest = key => key === selector ? target : null;
        root.emit('click',target);
    }
    function scheme(zero) { select.value = zero ? 'ZERO|12' : 'STD|12'; select.emit('change'); }
    function resolve(request, zero, extra = {}) {
        request.resolve({success:true,cart_snapshot:extra.cart_snapshot,calculation:{...config.schemes[zero?1:0],scheme_key:zero?'ZERO|12':'STD|12',first_installment:0,glp_display:'0.00',gpr_display:'0.00',consent_context:contexts(zero),...extra},popup_submission_token:'test-token'});
    }
    function remountCheckout() {
        // New payment HTML/controller, same live form, same production module/WeakMap.
        const copies = new Map();
        function copy(node) {
            if (copies.has(node)) return copies.get(node);
            const next = new Node({...node.attrs}); copies.set(node, next);
            for (const key of ['value', 'defaultValue', 'readOnly', 'hidden']) next[key] = node[key];
            for (const [key, child] of Object.entries(node.nodes)) next.nodes[key] = copy(child);
            if (node.rows) next.rows = node.rows.map(copy);
            if (node.box) next.box = copy(node.box);
            return next;
        }
        activeRoot = copy(root); activeRoot.form = form;
        observers.forEach(fn => fn([{addedNodes:[activeRoot],removedNodes:[]}]));
        return {root:activeRoot, rows:activeRoot.rows,
            get: key => activeRoot.querySelector(key),
            checkIds: (...ids) => activeRoot.rows.forEach((row,i) => { if (row.box) row.box.checked=ids.includes(i+1); }),
        };
    }
    return {root,rows,select,first,modal,form,requests,timers,bus,observers,sandbox,config,click,scheme,resolve,get,
        remountCheckout,
        setPayment: value => { payment.checked=value; },
        coreFinish: () => { if (form.coreDisabled) return false; form.coreDisabled=true; return form.submit(); },
        confirm: () => { const button=new Node(); button.closest=k=>k==='#payment-confirmation button'?button:null; return document.emit('click',button); },
        checkIds: (...ids) => rows.forEach((row,i)=>{if(row.box) row.box.checked=ids.includes(i+1);}),
        flushTimers: () => { const current=[...timers.values()]; timers.clear(); current.forEach(fn=>fn()); },
        event: key => (bus[key]||[]).forEach(fn=>fn()),
        detach: () => { root.isConnected=false; attached=false; observers.forEach(fn=>fn([{addedNodes:[],removedNodes:[root]}])); },
    };
}
async function flush() { for (let i=0;i<12;i++) await Promise.resolve(); }
(async () => {
    for (const mode of ['product','cart','checkout']) {
        const e=environment(mode);
        if (mode !== 'checkout') { e.click('[data-unipayment-offer]', {'data-unipayment-offer':'standard'}); e.resolve(e.requests.at(-1),false); await flush(); }
        check(e.rows[2].hidden && e.rows[3].box.disabled && !e.rows[4].hidden, mode+' nonzero visibility');
        e.checkIds(2,6);
        e.scheme(true);
        check(e.rows.every(r=>r.hidden) && e.rows[1].box.checked && !e.rows[5].box.checked,mode+' immediate scheme invalidation/scoped reset/all retention');
        e.flushTimers(); e.resolve(e.requests.at(-1),true); await flush();
        check(!e.rows[2].hidden && e.rows[4].hidden && !e.rows[3].box.checked,mode+' zero needs reacceptance');
        e.checkIds(2,4);
        e.scheme(false); e.flushTimers(); const old=e.requests.at(-1);
        e.scheme(true); e.flushTimers(); const current=e.requests.at(-1);
        e.resolve(current,true); await flush(); e.resolve(old,false); await flush();
        check(!e.rows[2].hidden && e.rows[4].hidden,mode+' old success ignored');
        e.checkIds(2,4);
        e.first.value='10'; e.first.emit('input');
        check(e.rows[3].box.checked && e.rows[3].box.disabled && e.rows.every(r=>r.hidden),mode+' first installment retains checked while pending');
        e.flushTimers(); e.resolve(e.requests.at(-1),true); await flush();
        check(e.rows[3].box.checked && !e.rows[3].box.disabled,mode+' same scheme restores acceptance');
        // A previously valid in-flight response arriving during the debounce must stay stale.
        e.first.emit('change'); const inFlight=e.requests.at(-1);
        e.first.value='20'; e.first.emit('input'); e.resolve(inFlight,true); await flush();
        check(e.rows.every(r=>r.hidden),mode+' input invalidates before debounce');
        e.flushTimers(); e.requests.at(-1).reject(new Error('network')); await flush();
        check(e.rows.every(r=>r.hidden) && e.rows[1].box.disabled,mode+' current error stays blocked');
        e.first.emit('change'); const afterError=e.requests.at(-1); e.resolve(afterError,true); await flush(); e.checkIds(2,4);
        if (mode==='cart') {
            e.event('updateCart');
            check(e.rows.every(r=>r.hidden),'cart update invalidates before refresh');
            e.event('updatedCart'); const oldRefresh=e.requests.at(-1);
            e.event('updatedCart'); const newRefresh=e.requests.at(-1);
            newRefresh.resolve({success:true,calculator:e.config}); await flush();
            e.resolve(e.requests.at(-1),false); await flush();
            oldRefresh.resolve({success:true,calculator:null}); await flush();
            check(!e.modal.hidden && !e.rows[4].hidden,'cart late refresh cannot close newer popup');
            e.scheme(true); e.resolve(e.requests.at(-1),true); await flush(); e.checkIds(2,4);
        }
        if (mode==='checkout') {
            e.event('updateCart');
            check(e.form.emit('submit').defaultPrevented,'native submit blocked before cart refresh resolves');
            e.event('updatedCart'); e.resolve(e.requests.at(-1),true); await flush();
            check(e.rows[3].box.checked && !e.rows[3].box.disabled,'checkout refresh revalidates same form');
            e.first.emit('change'); const previous=e.requests.at(-1);
            e.scheme(false); e.flushTimers(); const latest=e.requests.at(-1);
            e.resolve(latest,false,{cart_snapshot:'fresh-signature'}); await flush(); previous.reject(new Error('late')); await flush();
            check(!e.rows[4].hidden && e.rows[2].hidden,'checkout old error ignored');
            check(e.get('input[name="unipayment_cart_snapshot"]').value==='fresh-signature','checkout refresh uses the current signed cart snapshot');
            e.checkIds(2,6);
            check(!e.form.emit('submit').defaultPrevented,'native submit succeeds with applicable acceptance');
        } else {
            e.click('[data-unipayment-submit]'); await flush();
            const applied=e.requests.at(-1);
            e.resolve(applied,true); await flush();
            const body=e.requests.at(-1).options.body;
            check(JSON.stringify(body.getAll('unipayment_consent[]'))==='["2","4"]',mode+' sends only applicable accepted IDs');
        }
        e.first.emit('change'); const detached=e.requests.at(-1);
        e.detach(); e.resolve(detached,true); await flush();
        if (mode==='checkout') check(e.rows.every(r=>r.hidden),'removed checkout disposed');
        else check(e.get('[data-unipayment-apply]').disabled,'removed popup ignores response');
    }
    const e=environment('product');
    e.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
    const closed=e.requests.at(-1); e.click('[data-unipayment-close]'); e.resolve(closed,false); await flush();
    check(e.modal.hidden && e.rows.every(r=>r.hidden),'closed popup ignores in-flight calculation');
    const c=environment('checkout'); c.checkIds(2,6); c.first.emit('change');
    c.resolve(c.requests.at(-1),false,{consent_context:{...contexts(false),required_ids:[2,6,99]}}); await flush();
    check(c.rows.every(r=>r.hidden) && c.form.emit('submit').defaultPrevented,'new mandatory ID absent from HTML blocks');
    const helper=c.sandbox.createUnipaymentShopConsents(c.root);
    let generation=helper.begin('same'); helper.apply(generation,contexts(false)); c.checkIds(2,6);
    generation=helper.begin('same'); helper.apply(generation,{...contexts(false),scheme_identity:'changed KOP'});
    check(c.rows[1].box.checked && !c.rows[5].box.checked,'server identity change clears scoped acceptance under same select key');
    generation=helper.begin('same');
    check(!helper.apply(generation,{...contexts(false),valid:false}) && !helper.ready() && helper.acceptedIds().length===0,'unknown context blocks serialization');
    generation=helper.begin('same'); helper.destroy();
    check(!helper.apply(generation,contexts(false)),'destroyed helper ignores late context');
    const emptyRoot=new Node();
    const emptyHelper=c.sandbox.createUnipaymentShopConsents(emptyRoot);
    generation=emptyHelper.begin('empty');
    check(emptyHelper.apply(generation,{valid:true,interest_state:'unknown',scheme_identity:'empty',applicable_ids:[],required_ids:[]}) && emptyHelper.ready(),'empty consent list adds no requirement');
    const replacement=environment('checkout');
    check(!replacement.rows[1].box.checked && !replacement.rows[5].box.checked,'new checkout form has no transferred acceptance');
    // H1: an immediate refresh calculation must cancel the first-installment debounce.
    for (const mode of ['product', 'cart']) {
        const race=environment(mode);
        const redirects=[];
        race.sandbox.location.assign=url=>redirects.push(url);
        race.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        race.resolve(race.requests.at(-1),false); await flush();
        race.first.value='20'; race.first.emit('input');
        check(race.timers.size===1,mode+' H1 starts with pending debounce');
        const obsoleteTimer=[...race.timers.values()][0];
        if (mode==='cart') {
            race.event('updatedCart');
            race.requests.at(-1).resolve({success:true,calculator:race.config}); await flush();
        } else race.root.unipaymentUpdate(race.config);
        race.resolve(race.requests.at(-1),false); await flush();
        check(race.timers.size===0,mode+' H1 immediate calculation cancels old debounce');
        race.checkIds(2,6);
        race.click('[data-unipayment-apply]');
        race.resolve(race.requests.at(-1),false); await flush();
        race.click('[data-unipayment-submit]'); race.click('[data-unipayment-submit]'); await flush();
        const apply=race.requests.at(-1);
        check(apply.options.body.get('popup_action')==='apply',mode+' H1 sends apply');
        const count=race.requests.length;
        // Also force a callback already dequeued before clearTimeout could cancel it.
        obsoleteTimer(); race.flushTimers(); await flush();
        check(race.requests.length===count,mode+' H1 obsolete timer starts no calculate');
        // Real context changes during apply invalidate previews, not the sent operation.
        race.first.value='50'; race.first.emit('input'); race.first.emit('change');
        race.scheme(true);
        race.root.unipaymentInvalidatePopup();
        race.root.unipaymentUpdate(null);
        race.root.unipaymentRefresh();
        race.click('[data-unipayment-submit]');
        race.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        race.flushTimers(); await flush();
        check(race.requests.length===count,mode+' H1 context change starts neither preview nor duplicate apply');
        check(!race.modal.hidden && race.root.hidden!==true,mode+' active apply keeps processing UI visible');
        if (mode==='cart') {
            race.event('updateCart'); race.event('updatedCart');
            race.requests.at(-1).resolve({success:true,calculator:null}); await flush();
            check(race.requests.length===count+1,'cart refresh during apply starts no follow-up calculate');
            check(!race.modal.hidden && race.root.hidden!==true,'cart refresh response preserves active submission UI');
        }
        check(apply.options.body.get('kop_code')==='STD',mode+' sent operation retains original selection');
        apply.resolve({success:true,step:'order_created',order:{id_order:123},redirect_url:'https://example.test/confirmation'}); await flush();
        check(race.root.unipaymentOrderResult?.id_order===123,mode+' H1 successful apply result retained');
        check(redirects.length===1,mode+' H1 successful apply redirects exactly once');
        check(race.requests.filter(r=>r.options?.body?.get('popup_action')==='apply').length===1,mode+' H1 only one apply');
    }
    // An operation result remains authoritative after context changes, without an automatic retry.
    for (const outcome of ['confirmation','error','destroyed']) {
        const e=environment('cart'); const redirects=[];
        e.sandbox.location.assign=url=>redirects.push(url);
        e.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        e.resolve(e.requests.at(-1),false); await flush(); e.checkIds(2,6);
        e.click('[data-unipayment-apply]'); e.resolve(e.requests.at(-1),false); await flush(); e.click('[data-unipayment-submit]'); await flush();
        const sent=e.requests.at(-1);
        e.first.value='40'; e.first.emit('input');
        if (outcome==='destroyed') e.detach();
        if (outcome==='error') sent.resolve({success:false,message:'Rejected',errors:{consents:'Rejected'}},false);
        else sent.resolve({success:true,step:'order_created',order:{id_order:456},...(outcome==='destroyed'?{redirect_url:'https://example.test/old'}:{})});
        await flush();
        if (outcome==='confirmation') check(e.root.unipaymentOrderResult?.id_order===456 && !e.get('[data-unipayment-step="3"]').hidden,'changed context still receives native popup confirmation');
        if (outcome==='error') {
            check(!e.get('[data-unipayment-step="2"]').hidden && e.get('[data-unipayment-submit]').disabled,'changed context plus apply error requires fresh review');
            check(e.get('[data-unipayment-submit-error]').textContent.includes('Обновете'),'changed context error requests page refresh');
        }
        if (outcome==='destroyed') check(!e.root.unipaymentOrderResult && redirects.length===0,'destroyed popup ignores old apply success');
        check(e.requests.filter(r=>r.options?.body?.get('popup_action')==='apply').length===1,outcome+' never resubmits automatically');
    }
    // Selection changes before the request is sent must still cancel submission.
    for (const mode of ['product','cart']) {
        const pending=environment(mode);
        pending.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        pending.resolve(pending.requests.at(-1),false); await flush(); pending.checkIds(2,6);
        pending.click('[data-unipayment-apply]');
        pending.resolve(pending.requests.at(-1),false); await flush();
        pending.click('[data-unipayment-submit]');
        pending.first.value='30'; pending.first.emit('input'); await flush();
        check(!pending.requests.some(r=>r.options?.body?.get('popup_action')==='apply'),mode+' changed selection before fetch cancels apply');
        pending.click('[data-unipayment-close]'); pending.flushTimers();
        check(pending.modal.hidden,mode+' cancelled preparation permits close');
    }
    // Actual integrated PS9 core model: no native event before the final direct call.
    const asyncCheckout=environment('checkout'); asyncCheckout.checkIds(2,6);
    check(!asyncCheckout.confirm().defaultPrevented,'valid click enters async orderability');
    check(asyncCheckout.confirm().defaultPrevented,'duplicate click during async orderability blocked');
    await flush();
    asyncCheckout.first.value='30'; asyncCheckout.first.emit('input');
    asyncCheckout.flushTimers(); asyncCheckout.resolve(asyncCheckout.requests.at(-1),false); await flush();
    check(!asyncCheckout.coreFinish() && !asyncCheckout.form.nativeSubmits,'new ready generation cannot satisfy old async confirmation');
    check(!asyncCheckout.coreFinish() && !asyncCheckout.form.nativeSubmits,'core disabled-data guard remains intact after rejection; page refresh is required');
    const validCheckout=environment('checkout'); validCheckout.checkIds(2,6);
    check(!validCheckout.confirm().defaultPrevented,'fresh reviewed form claims confirmation');
    await flush();
    check(validCheckout.coreFinish() && validCheckout.form.nativeSubmits===1,'synthetic final direct call actually reaches native submission once');
    check(!validCheckout.coreFinish() && validCheckout.form.nativeSubmits===1,'second synthetic final call blocked');
    const disposedCheckout=environment('checkout'); disposedCheckout.checkIds(2,6); disposedCheckout.confirm();
    disposedCheckout.detach();
    check(!disposedCheckout.form.submit() && !disposedCheckout.form.nativeSubmits,'disposed form blocks late core direct call');
    const remountedCheckout=environment('checkout');
    check(!remountedCheckout.form.submit(),'remounted form has no inherited acceptance');
    for (const mode of ['product','cart']) {
        const tokenRace=environment(mode);
        tokenRace.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
        tokenRace.resolve(tokenRace.requests.at(-1),false); await flush();
        tokenRace.click('[data-unipayment-apply]'); const oldToken=tokenRace.requests.at(-1);
        tokenRace.scheme(true); const newCalculation=tokenRace.requests.at(-1);
        tokenRace.resolve(oldToken,false); await flush();
        check(!tokenRace.root.unipaymentPopupSubmissionToken,mode+' stale token ignored');
        tokenRace.resolve(newCalculation,true); await flush();
        check(tokenRace.rows[4].hidden && !tokenRace.rows[2].hidden,mode+' stale token cannot restore old context');
        tokenRace.checkIds(2,4); tokenRace.click('[data-unipayment-apply]');
        const closedToken=tokenRace.requests.at(-1); tokenRace.click('[data-unipayment-close]');
        tokenRace.resolve(closedToken,true); await flush();
        check(tokenRace.modal.hidden && !tokenRace.root.unipaymentPopupSubmissionToken,mode+' closed popup rejects token response');
    }
    const switched=environment('checkout'); switched.checkIds(2,6);
    check(!switched.confirm().defaultPrevented,'UniPayment confirmation starts before payment switch');
    switched.setPayment(false);
    check(!switched.coreFinish() && !switched.form.nativeSubmits,'late UniPayment core callback blocked after selecting another module');
    check(!switched.confirm().defaultPrevented,'other module confirmation is not intercepted');
    for (const destroyFirst of [true, false]) {
        const same=environment('checkout'); same.checkIds(2,6);
        const oldSubmit=same.form.submit, oldDestroy=same.root.unipaymentCheckoutDestroy;
        if (destroyFirst) oldDestroy();
        const next=same.remountCheckout();
        oldDestroy(); // A -> B -> destroy A must leave B in control.
        check(!oldSubmit() && !same.form.nativeSubmits,'remount: retained A submit stays inert');
        check(same.root.rows.every(row=>row.hidden),'remount retires the old live-root controller');
        check(!same.form.submit() && !same.form.nativeSubmits,'remount: new HTML requires its own consent acceptance');
        next.checkIds(2,6);
        check(!same.confirm().defaultPrevented,'remount: old document confirmation handler does not block B');
        same.root.unipaymentCheckoutRelease();
        check(same.sandbox.document.querySelector('#payment-confirmation button').getAttribute('aria-busy')==='true','remount: obsolete release cannot clear B confirmation state');
        next.get('[data-unipayment-first]').value='10'; next.get('[data-unipayment-first]').emit('input');
        same.flushTimers(); same.resolve(same.requests.at(-1),false); await flush();
        check(!same.form.submit() && !same.form.nativeSubmits,'remount: async confirmation generation remains protected');
        check(!same.confirm().defaultPrevented && same.form.submit() && same.form.nativeSubmits===1,'remount: reviewed B invokes actual native method once');
        check(!same.form.submit() && same.form.nativeSubmits===1,'remount: B duplicate blocked');
        const count=same.requests.length;
        same.first.emit('change'); same.flushTimers(); await flush();
        check(same.requests.length===count,'remount: disposed old input callbacks start no request');
    }
    const combination=environment('product');
    combination.click('[data-unipayment-offer]',{'data-unipayment-offer':'standard'});
    combination.resolve(combination.requests.at(-1),false); await flush(); combination.checkIds(2,6);
    combination.event('updateProduct');
    check(combination.rows.every(row=>row.hidden),'combination change invalidates before product AJAX replacement');
    combination.click('[data-unipayment-submit]'); await flush();
    check(!combination.requests.some(request=>request.options?.body?.get('popup_action')==='apply'),'pending combination cannot submit the old selection');
    console.log('OK consent frontend '+assertions+' assertions');
})().catch(error=>{console.error(error);process.exitCode=1;});
