/**
 * The button runtime, run for real in Node against a stub DOM.
 *
 *     node tests/integration/runtime.mjs
 *     ddev exec node /var/www/craft-coinpurse/tests/integration/runtime.mjs
 *
 * The PHP suite can only read coinpurse.js as text. This loads it the three ways a site can — the
 * injected classic script, a CommonJS bundle, and an ES-module bundle via coinpurse.mjs — and
 * watches what it actually requests and mounts.
 *
 * `COINPURSE_JS=/path/to/old/coinpurse.js node runtime.mjs` runs the same checks against another
 * copy of the runtime (e.g. `git show HEAD:src/web/assets/express/dist/coinpurse.js`).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import vm from 'node:vm';

const dist = join(dirname(fileURLToPath(import.meta.url)), '../../src/web/assets/express/dist');
const source = readFileSync(process.env.COINPURSE_JS || join(dist, 'coinpurse.js'), 'utf8');

const STRIPE_JS = 'https://js.stripe.com/v3/';
const GOOGLE_PAY_JS = 'https://pay.google.com/gp/p/js/pay.js';

let passed = 0;
let failed = 0;

async function check(label, test) {
    try {
        const result = await test();

        if (result === true) {
            passed++;
            console.log(`  ✓ ${label}`);
            return;
        }

        failed++;
        console.log(`  ✗ ${label}\n    ${typeof result === 'string' ? result : 'returned ' + JSON.stringify(result)}`);
    } catch (e) {
        failed++;
        console.log(`  ✗ ${label}\n    ${e && e.stack ? e.stack.split('\n').slice(0, 3).join('\n    ') : e}`);
    }
}

/** Let every pending fetch/promise chain run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 5));

/** What `Buttons::config()` produces, for one button. */
function buttonConfig(id, extra = {}) {
    return {
        id,
        driver: 'raw',
        payload: 'signed-' + id,
        endpoints: {
            start: '/actions/coinpurse/express/start',
            address: '/actions/coinpurse/express/address',
            shipping: '/actions/coinpurse/express/shipping',
            pay: '/actions/coinpurse/express/pay',
            complete: '/actions/coinpurse/express/complete',
            cancel: '/actions/coinpurse/express/cancel',
            validateMerchant: '/actions/coinpurse/apple-pay/validate-merchant',
            sessionInfo: '/actions/users/session-info',
        },
        style: { type: 'buy', theme: 'dark', height: 48 },
        onComplete: { redirect: '/thanks?number={number}' },
        requestShipping: false,
        shippingType: 'shipping',
        requestDetails: ['email'],
        variable: null,
        ...extra,
    };
}

/**
 * A page: the elements on it, a document that can still be loading, and everything the runtime
 * tries to fetch. `start` answers `express/start`; by default the server refuses, which keeps
 * third-party SDKs out of the picture unless a check asks for them.
 */
function page({ readyState = 'complete', start = () => ({ success: false, error: 'refused in test' }), href = '' } = {}) {
    const attached = [];
    const sent = [];
    const events = [];
    const warnings = [];
    const docListeners = {};

    function element(tagName, attrs = {}) {
        return {
            tagName,
            id: attrs.id || '',
            attrs: { ...attrs },
            style: {},
            dataset: {},
            children: [],
            innerHTML: '',
            listeners: {},
            getAttribute(name) { return name === 'id' ? (this.id || null) : (name in this.attrs ? this.attrs[name] : null); },
            setAttribute(name, value) { if (name === 'id') { this.id = value; } else { this.attrs[name] = String(value); } },
            appendChild(child) { this.children.push(child); return child; },
            addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
            dispatchEvent(event) { events.push({ target: this, type: event.type, detail: event.detail }); return true; },
        };
    }

    const head = element('head');

    const document = {
        readyState,
        head,
        getElementById: (id) => attached.find((el) => el.id === id) || null,
        querySelectorAll(selector) {
            if (selector === '[data-coinpurse-config]') {
                return attached.filter((el) => el.getAttribute('data-coinpurse-config') !== null);
            }
            return [];
        },
        querySelector(selector) {
            const m = /^script\[src="(.+)"\]$/.exec(selector);
            return m ? head.children.find((c) => c.src === m[1]) || null : null;
        },
        createElement: (tag) => element(tag),
        addEventListener(type, fn) { (docListeners[type] ||= []).push(fn); },
    };

    function respond(url, body) {
        if (url.endsWith('/session-info')) {
            return { csrfTokenValue: 'csrf-from-session-info' };
        }
        if (url.endsWith('/express/start')) {
            return start(body);
        }
        return { success: true };
    }

    const window = {
        document,
        location: { href },
        // A real page URL brings the URL parser with it; the default stub has neither, which is the
        // fail-closed fallback for engines without one.
        ...(href ? { URL } : {}),
        console: { warn: (m) => warnings.push(String(m)), error: (m) => warnings.push(String(m)), log() {} },
        setTimeout,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        fetch(url, init = {}) {
            const body = init.body ? JSON.parse(init.body) : null;
            sent.push({ url, method: init.method || 'GET', body, headers: init.headers || {} });
            const data = respond(url, body);
            return Promise.resolve({ json: () => Promise.resolve(data) });
        },
    };
    window.window = window;

    /** Put a button's markup on the page, the way `Buttons::render()` writes it. */
    function addButton(id, config) {
        const attrs = { id, class: 'coinpurse-button', 'data-coinpurse': 'true' };
        if (config !== undefined) {
            attrs['data-coinpurse-config'] = typeof config === 'string' ? config : JSON.stringify(config);
        }
        const el = element('div', attrs);
        attached.push(el);
        return el;
    }

    /** The parser reaches the end of the document. */
    function ready() {
        document.readyState = 'interactive';
        (docListeners.DOMContentLoaded || []).splice(0).forEach((fn) => fn({ type: 'DOMContentLoaded' }));
    }

    const starts = () => sent.filter((r) => r.url.endsWith('/express/start'));

    return { window, document, head, sent, events, warnings, addButton, ready, starts };
}

/** Run coinpurse.js as a classic script — what Coin Purse's own injection does. */
function inject(p, module = undefined) {
    const context = p.window;
    if (module) {
        context.module = module;
    } else {
        delete context.module;
    }
    vm.runInNewContext(source, vm.isContext(context) ? context : vm.createContext(context));
    return p.window.CoinPurse;
}

console.log('\nBundled runtime');

await check('CoinPurse.start() mounts every button from its own data-coinpurse-config', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));
    p.addButton('coinpurse-b', buttonConfig('coinpurse-b'));

    inject(p).start();
    await settle();

    const payloads = p.starts().map((r) => r.body.payload).sort();

    return JSON.stringify(payloads) === JSON.stringify(['signed-coinpurse-a', 'signed-coinpurse-b'])
        && p.starts().every((r) => r.headers['X-CSRF-Token'] === 'csrf-from-session-info')
        || JSON.stringify(p.sent);
});

await check('started before the markup is parsed, it waits for the document and then mounts', async () => {
    const p = page({ readyState: 'loading' });
    const CoinPurse = inject(p);

    CoinPurse.start();
    p.addButton('coinpurse-late', buttonConfig('coinpurse-late'));
    await settle();

    const before = p.starts().length;

    p.ready();
    await settle();

    return before === 0 && p.starts().length === 1 || `before=${before} after=${p.starts().length}`;
});

await check('started long after the page loaded, it mounts at once', async () => {
    const p = page({ readyState: 'complete' });
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    inject(p).start();
    await settle();

    return p.starts().length === 1 || JSON.stringify(p.sent);
});

await check('calling start() again mounts only the buttons that are new', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    const CoinPurse = inject(p);
    CoinPurse.start();
    await settle();

    p.addButton('coinpurse-b', buttonConfig('coinpurse-b'));
    CoinPurse.start();
    await settle();

    const payloads = p.starts().map((r) => r.body.payload);

    return payloads.length === 2 && payloads[1] === 'signed-coinpurse-b' || JSON.stringify(payloads);
});

await check('the injected mount and a bundled start() on the same button open one session', async () => {
    const p = page();
    const config = buttonConfig('coinpurse-a');
    p.addButton('coinpurse-a', config);

    const CoinPurse = inject(p);
    const injected = CoinPurse.mount(config);
    CoinPurse.start();
    await settle();

    return p.starts().length === 1 && CoinPurse.buttons['coinpurse-a'] === injected
        || `starts=${p.starts().length}`;
});

await check('malformed data-coinpurse-config is ignored rather than thrown, and the box is hidden', async () => {
    const p = page();
    const broken = p.addButton('coinpurse-broken', '{not json');
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    inject(p).start();
    await settle();

    return broken.style.display === 'none' && p.starts().length === 1
        || `display=${broken.style.display} starts=${p.starts().length}`;
});

await check('a button the server refuses is hidden, not left as an empty box', async () => {
    const p = page();
    const el = p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    inject(p).start();
    await settle();

    return el.style.display === 'none' || `display=${el.style.display}`;
});

await check('options passed to start() win over the attribute, one level deep', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    const CoinPurse = inject(p);
    CoinPurse.start({ style: { theme: 'light' } });
    await settle();

    const style = CoinPurse.buttons['coinpurse-a'].config.style;

    return style.theme === 'light' && style.height === 48 && style.type === 'buy' || JSON.stringify(style);
});

await check('start({ onComplete: fn }) runs the function, fires coinpurse:complete and keeps the redirect', async () => {
    const p = page();
    const el = p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));
    const calls = [];

    const CoinPurse = inject(p);
    CoinPurse.start({ onComplete: (order) => calls.push(order.number) });
    await settle();

    CoinPurse.buttons['coinpurse-a'].finish({ number: 'abc123', email: 'a@example.com' });

    const event = p.events.find((e) => e.type === 'coinpurse:complete');

    return calls[0] === 'abc123'
        && event && event.target === el && event.detail.order.number === 'abc123'
        && p.window.location.href === '/thanks?number=abc123'
        && CoinPurse.lastOrder.number === 'abc123'
        || `calls=${calls} event=${!!event} href=${p.window.location.href}`;
});

console.log('\nAttributes are data, not code');

await check('onComplete.js in an attribute is never executed', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a', {
        onComplete: { redirect: null, js: 'window.pwned = true;' },
    }));

    const CoinPurse = inject(p);
    CoinPurse.start();
    await settle();
    CoinPurse.buttons['coinpurse-a'].finish({ number: 'n1', email: 'e' });

    return p.window.pwned !== true || 'onComplete.js from data-coinpurse-config ran';
});

await check('variable in an attribute is assigned as a name, and a statement is refused', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a', { variable: 'window.payButton' }));
    p.addButton('coinpurse-b', buttonConfig('coinpurse-b', { variable: 'window.pwned = true; window.x' }));

    const CoinPurse = inject(p);
    CoinPurse.start();
    await settle();

    return p.window.payButton === CoinPurse.buttons['coinpurse-a']
        && p.window.pwned !== true
        || `payButton=${!!p.window.payButton} pwned=${p.window.pwned}`;
});

await check('a javascript: redirect in an attribute is not followed', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a', {
        onComplete: { redirect: ' java\tscript:window.pwned=1//{number}' },
    }));

    const CoinPurse = inject(p);
    CoinPurse.start();
    await settle();
    CoinPurse.buttons['coinpurse-a'].finish({ number: 'n1', email: 'e' });

    return p.window.location.href === '' || `href=${p.window.location.href}`;
});

const evil = {
    start: 'https://evil.test/collect', address: 'https://evil.test/collect', shipping: 'https://evil.test/collect',
    pay: 'https://evil.test/collect', complete: 'https://evil.test/collect', cancel: 'https://evil.test/collect',
    validateMerchant: 'https://evil.test/collect', sessionInfo: '/actions/users/session-info',
};

await check('a forged button pointing at another host sends nothing and is hidden', async () => {
    const p = page({ href: 'https://shop.test/product' });
    const el = p.addButton('coinpurse-x', buttonConfig('coinpurse-x', { endpoints: evil }));

    inject(p).start();
    await settle();

    return p.sent.length === 0 && el.style.display === 'none'
        || JSON.stringify(p.sent.map((r) => r.url));
});

await check('without a URL parser, protocol-relative and backslash endpoints are refused too', async () => {
    for (const host of ['//evil.test/collect', '/\\evil.test/collect', ' \t//evil.test/collect']) {
        const p = page();
        p.addButton('coinpurse-x', buttonConfig('coinpurse-x', {
            endpoints: { ...buttonConfig('x').endpoints, start: host },
        }));

        inject(p).start();
        await settle();

        if (p.sent.length !== 0) {
            return `${JSON.stringify(host)} sent ${JSON.stringify(p.sent.map((r) => r.url))}`;
        }
    }

    return true;
});

await check('absolute same-origin endpoints, as actionUrl() writes them, still work and carry the token', async () => {
    const p = page({ href: 'https://shop.test/product' });
    const absolute = {};
    for (const [name, url] of Object.entries(buttonConfig('x').endpoints)) {
        absolute[name] = 'https://shop.test' + url;
    }
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a', { endpoints: absolute }));

    inject(p).start();
    await settle();

    return p.starts().length === 1 && p.starts()[0].headers['X-CSRF-Token'] === 'csrf-from-session-info'
        || JSON.stringify(p.sent);
});

await check('the CSRF token is never sent to another origin, even from the injected mount', async () => {
    const p = page({ href: 'https://shop.test/product' });
    p.addButton('coinpurse-a');

    inject(p).mount(buttonConfig('coinpurse-a', { endpoints: evil }));
    await settle();

    return p.sent.every((r) => !r.url.includes('evil.test'))
        || JSON.stringify(p.sent.map((r) => r.url));
});

await check('an attribute redirect is followed on this site and refused to another', async () => {
    const results = [];

    for (const redirect of ['https://shop.test/thanks/{number}', 'https://evil.test/{number}']) {
        const p = page({ href: 'https://shop.test/product' });
        p.addButton('coinpurse-a', buttonConfig('coinpurse-a', { onComplete: { redirect } }));

        const CoinPurse = inject(p);
        CoinPurse.start();
        await settle();
        CoinPurse.buttons['coinpurse-a'].finish({ number: 'n1', email: 'e' });
        results.push(p.window.location.href);
    }

    return results[0] === 'https://shop.test/thanks/n1' && results[1] === 'https://shop.test/product'
        || JSON.stringify(results);
});

console.log('\nThe injected path, unchanged');

await check('CoinPurse.mount(config) still mounts, assigns `js` and runs onComplete.js', async () => {
    const p = page();
    p.addButton('coinpurse-a');

    const CoinPurse = inject(p);
    const button = CoinPurse.mount(buttonConfig('coinpurse-a', {
        variable: 'window.payButton',
        onComplete: { redirect: '/thanks?number={number}', js: 'window.completed = cwp.number;' },
    }));
    await settle();

    button.finish({ number: 'n7', email: 'e' });

    return p.starts().length === 1
        && p.window.payButton === button
        && p.window.completed === 'n7'
        && p.window.location.href === '/thanks?number=n7'
        || `starts=${p.starts().length} completed=${p.window.completed} href=${p.window.location.href}`;
});

await check('a page with no data-coinpurse-config is left alone by start()', async () => {
    const p = page();
    p.addButton('coinpurse-a');

    inject(p).start();
    await settle();

    return p.sent.length === 0 || JSON.stringify(p.sent);
});

console.log('\nPayment SDKs stay on their own origins');

await check('the Stripe driver loads Stripe.js from js.stripe.com, on demand', async () => {
    const p = page({
        start: () => ({
            success: true,
            session: 'session-1',
            driver: 'stripe',
            quote: { total: 1000, currency: 'USD', decimals: 2, lines: [], shippingOptions: [] },
            config: { publishableKey: 'pk_test_123' },
        }),
    });
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a', { driver: 'stripe' }));

    const CoinPurse = inject(p);
    const before = p.head.children.length;
    CoinPurse.start();
    await settle();

    const srcs = p.head.children.map((c) => c.src);

    return before === 0 && srcs.length === 1 && srcs[0] === STRIPE_JS || JSON.stringify(srcs);
});

await check('the direct driver loads Google Pay from pay.google.com, on demand', async () => {
    const p = page({
        start: () => ({
            success: true,
            session: 'session-1',
            driver: 'raw',
            quote: { total: 1000, currency: 'USD', decimals: 2, lines: [], shippingOptions: [] },
            config: { googlePay: { environment: 'TEST' } },
        }),
    });
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    inject(p).start();
    await settle();

    const srcs = p.head.children.map((c) => c.src);

    return srcs.length === 1 && srcs[0] === GOOGLE_PAY_JS || JSON.stringify(srcs);
});

await check('the runtime names no script origin but Stripe’s and Google’s', () => {
    const urls = source.match(/https?:\/\/[^'"\s)]+/g) || [];
    const unexpected = urls.filter((u) => u !== STRIPE_JS && u !== GOOGLE_PAY_JS);

    return unexpected.length === 0 || unexpected.join(', ');
});

console.log('\nLoading');

await check('a CommonJS bundler gets the same object as window.CoinPurse', () => {
    const p = page();
    const module = { exports: {} };
    const CoinPurse = inject(p, module);

    return typeof CoinPurse.start === 'function' && module.exports === CoinPurse;
});

await check('loaded twice, the first copy wins and a bundler gets the live instance', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    const first = inject(p);
    first.start();
    await settle();

    const module = { exports: {} };
    const second = inject(p, module);
    second.start();
    await settle();

    return second === first && module.exports === first && p.starts().length === 1
        && !!second.buttons['coinpurse-a']
        || `same=${second === first} export=${module.exports === first} starts=${p.starts().length}`;
});

await check('coinpurse.mjs default-exports the runtime and still sets window.CoinPurse', async () => {
    const p = page();
    p.addButton('coinpurse-a', buttonConfig('coinpurse-a'));

    // Imported from an .mjs, coinpurse.js is loaded as CommonJS, against Node's own globals.
    const saved = {};
    for (const key of ['window', 'document', 'fetch', 'CustomEvent', 'console']) {
        saved[key] = Object.getOwnPropertyDescriptor(globalThis, key);
        Object.defineProperty(globalThis, key, { value: p.window[key] ?? p.window, configurable: true, writable: true });
    }
    globalThis.window = p.window;

    try {
        const { default: CoinPurse } = await import(pathToFileURL(join(dist, 'coinpurse.mjs')).href);
        CoinPurse.start();
        await settle();

        return CoinPurse === p.window.CoinPurse && p.starts().length === 1 || `starts=${p.starts().length}`;
    } finally {
        for (const key of Object.keys(saved)) {
            if (saved[key]) {
                Object.defineProperty(globalThis, key, saved[key]);
            } else {
                delete globalThis[key];
            }
        }
    }
});

await check('coinpurse.js still parses as an ES5 classic script (no module syntax)', () => {
    // `new vm.Script` compiles as a classic script; an `export` or `import` would be a SyntaxError.
    new vm.Script(source);
    return !/\b(const|let|class)\s|=>/.test(source.replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '')) || 'ES2015+ syntax in coinpurse.js';
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);
