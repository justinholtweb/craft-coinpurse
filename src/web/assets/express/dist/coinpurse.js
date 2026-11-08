/**
 * Coin Purse — express wallet checkout for Craft Commerce.
 *
 * No build step, on purpose: this is the file that runs in a customer's browser at the moment
 * money moves, and whoever has to debug it in three years should be able to read it in the
 * sources panel exactly as it is written here.
 *
 * Three wallet dialects live behind one flow. The flow is always:
 *
 *     start  →  (address ⇄ shipping)*  →  pay  →  [confirm]  →  complete
 *
 * and the server owns every number in it. The browser never adds anything up; it renders the
 * `quote` it is given and hands back what the wallet said.
 *
 * Two ways in. Coin Purse injects this file and mounts each button with an inline
 * `CoinPurse.mount({...})`; or, with “Inject the button script” off, a site bundles this file
 * itself (or `coinpurse.mjs`, which wraps it) and calls `CoinPurse.start()`, which mounts every
 * button from the `data-coinpurse-config` attribute on its markup.
 */
(function () {
    'use strict';

    // Loaded twice — injected by Coin Purse *and* bundled by the site — the first copy wins, so a
    // button is only ever mounted once. A bundler still gets the live instance.
    if (window.CoinPurse) {
        if (typeof module === 'object' && module && module.exports) {
            module.exports = window.CoinPurse;
        }

        return;
    }

    var STRIPE_JS = 'https://js.stripe.com/v3/';

    /** Must match `ButtonOptions::PENDING_SHIPPING_OPTION`. */
    var PENDING_OPTION = '__pending__';
    var GOOGLE_PAY_JS = 'https://pay.google.com/gp/p/js/pay.js';

    var scriptPromises = {};

    /** Load a third-party script once, however many buttons are on the page. */
    function loadScript(src) {
        if (scriptPromises[src]) {
            return scriptPromises[src];
        }

        scriptPromises[src] = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[src="' + src + '"]');

            if (existing) {
                if (existing.dataset.coinpurseLoaded === '1') {
                    resolve();
                    return;
                }

                existing.addEventListener('load', function () { resolve(); });
                existing.addEventListener('error', function () { reject(new Error('Failed to load ' + src)); });
                return;
            }

            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.addEventListener('load', function () {
                script.dataset.coinpurseLoaded = '1';
                resolve();
            });
            script.addEventListener('error', function () { reject(new Error('Failed to load ' + src)); });
            document.head.appendChild(script);
        });

        return scriptPromises[src];
    }

    // ---------------------------------------------------------------- Transport

    var csrfToken = null;

    /**
     * Craft validates a CSRF token on every POST. Reading it from the page would break the moment
     * the page is cached — a stale token in static HTML fails every request — so it is fetched
     * from Craft's own session endpoint on first use instead.
     */
    function getCsrfToken(endpoints) {
        if (csrfToken) {
            return Promise.resolve(csrfToken);
        }

        if (!sameOrigin(endpoints.sessionInfo)) {
            return Promise.resolve(null);
        }

        return fetch(endpoints.sessionInfo, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                csrfToken = data.csrfTokenValue || null;
                return csrfToken;
            })
            .catch(function () { return null; });
    }

    /**
     * Whether a URL points at the page's own origin. The CSRF token, the signed payload and the
     * wallet's answers only ever go to Craft — and every request here is `credentials:
     * 'same-origin'`, so a cross-origin endpoint never had the session and could not have worked
     * anyway. Without a URL parser only a path-relative URL can be proven local, so the answer
     * fails closed.
     */
    function sameOrigin(url) {
        if (typeof url !== 'string' || url === '') {
            return false;
        }

        // Browsers ignore leading control characters and strip tabs and newlines anywhere, and
        // treat a backslash like a slash — `/\evil.test` is protocol-relative by the time it is used.
        var cleaned = url.replace(/^[\u0000- ]+/, '').replace(/[\t\n\r]/g, '');
        var base = window.location && window.location.href;

        if (typeof window.URL === 'function' && base) {
            try {
                var target = new window.URL(cleaned, base);
                var page = new window.URL(base);

                return target.protocol === page.protocol && target.host === page.host;
            } catch (e) {
                return false;
            }
        }

        return /^\/(?![\/\\])/.test(cleaned);
    }

    function post(endpoints, url, body) {
        if (!sameOrigin(url)) {
            return Promise.resolve({ success: false, error: 'Refused a request to another origin.' });
        }

        return getCsrfToken(endpoints).then(function (token) {
            var headers = {
                'Content-Type': 'application/json',
                // `requireAcceptsJson()` on the server checks this header, not the URL. Without it
                // every one of these calls comes back as a rendered HTML error page.
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };

            if (token) {
                headers['X-CSRF-Token'] = token;
            }

            return fetch(url, {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: JSON.stringify(body || {})
            }).then(function (response) {
                return response.json().catch(function () {
                    return { success: false, error: 'The server returned an unreadable response.' };
                });
            });
        });
    }

    // ---------------------------------------------------------------- Money

    /** Minor units to the major-unit decimal string Apple Pay and Google Pay both want. */
    function toDecimal(minor, decimals) {
        var negative = minor < 0;
        var digits = Math.abs(Math.round(minor)).toString();

        if (decimals === 0) {
            return (negative ? '-' : '') + digits;
        }

        while (digits.length <= decimals) {
            digits = '0' + digits;
        }

        var whole = digits.slice(0, digits.length - decimals);
        var fraction = digits.slice(digits.length - decimals);

        return (negative ? '-' : '') + whole + '.' + fraction;
    }

    // ---------------------------------------------------------------- The button

    /**
     * @param {Object} config
     * @param {boolean} declarative True when the config came from a `data-coinpurse-config`
     *     attribute. Attributes are data, never code: see `fromAttribute()`.
     */
    function Button(config, declarative) {
        this.config = config;
        this.declarative = !!declarative;
        this.el = document.getElementById(config.id);
        this.session = null;
        this.quote = null;
        this.driverConfig = null;
    }

    Button.prototype.endpoints = function () {
        return this.config.endpoints;
    };

    Button.prototype.post = function (name, body) {
        var payload = body || {};

        if (this.session) {
            payload.session = this.session;
        }

        return post(this.endpoints(), this.endpoints()[name], payload);
    };

    /** Open a session and hand off to whichever driver the server chose. */
    Button.prototype.start = function () {
        var self = this;

        if (!this.el) {
            return Promise.resolve();
        }

        return post(this.endpoints(), this.endpoints().start, { payload: this.config.payload })
            .then(function (data) {
                if (!data || !data.success) {
                    return self.fail(data && data.error);
                }

                self.session = data.session;
                self.quote = data.quote;
                self.driverConfig = data.config || {};

                if (data.driver === 'stripe') {
                    return self.mountStripe();
                }

                return self.mountRaw();
            })
            .catch(function (e) { self.fail(e && e.message); });
    };

    Button.prototype.fail = function (message) {
        if (message) {
            // Deliberately console-only. A failed wallet button must not put an error message in
            // front of a shopper who never asked for one, and the merchant has the log.
            if (window.console && console.warn) {
                console.warn('[Coin Purse] ' + message);
            }
        }

        if (this.el) {
            this.el.style.display = 'none';
        }
    };

    /** Everything succeeded. Fire the template's completion handling. */
    Button.prototype.finish = function (result) {
        var onComplete = this.config.onComplete || {};
        var cwp = { number: result.number, email: result.email };

        window.CoinPurse.lastOrder = cwp;

        emit(this.el, 'coinpurse:complete', { order: cwp, button: this });

        if (typeof onComplete.callback === 'function') {
            try {
                onComplete.callback(cwp, this);
            } catch (e) {
                if (window.console && console.error) {
                    console.error('[Coin Purse] onComplete threw', e);
                }
            }
        }

        // A string of JavaScript only ever runs from the injected call, which Craft wrote into a
        // <script>. From an attribute it would be a script gadget.
        if (onComplete.js && !this.declarative) {
            try {
                /* eslint-disable no-new-func */
                new Function('cwp', onComplete.js)(cwp);
            } catch (e) {
                if (window.console && console.error) {
                    console.error('[Coin Purse] onComplete.js threw', e);
                }
            }
        }

        if (onComplete.redirect && (!this.declarative || (isNavigable(onComplete.redirect) && sameOrigin(onComplete.redirect)))) {
            window.location.href = onComplete.redirect.replace('{number}', encodeURIComponent(result.number));
        }
    };

    /** Re-fetch the button and its session from scratch. */
    Button.prototype.reload = function () {
        if (this.el) {
            this.el.innerHTML = '';
            this.el.style.display = '';
        }

        this.session = null;

        return this.start().then(function () { return this; }.bind(this));
    };

    // ---------------------------------------------------------------- Stripe

    Button.prototype.mountStripe = function () {
        var self = this;
        var cfg = this.driverConfig;

        if (!cfg.publishableKey) {
            return this.fail('No Stripe publishable key.');
        }

        return loadScript(STRIPE_JS).then(function () {
            var stripe = window.Stripe(cfg.publishableKey, { locale: cfg.locale || 'auto' });

            var elements = stripe.elements({
                mode: 'payment',
                amount: self.quote.total,
                currency: self.quote.currency.toLowerCase(),
                paymentMethodTypes: undefined
            });

            var options = {
                buttonHeight: self.config.style.height,
                buttonTheme: {
                    applePay: self.config.style.theme === 'light' ? 'white' : (self.config.style.theme === 'light-outline' ? 'white-outline' : 'black'),
                    googlePay: self.config.style.theme === 'light' ? 'white' : 'black'
                },
                buttonType: {
                    applePay: self.config.style.type === 'default' ? 'plain' : self.config.style.type,
                    googlePay: self.config.style.type === 'default' ? 'plain' : self.config.style.type
                },
                business: { name: cfg.businessName },
                emailRequired: self.wants('email'),
                phoneNumberRequired: self.wants('phone'),
                shippingAddressRequired: !!self.config.requestShipping
            };

            if (cfg.paymentMethods && Object.keys(cfg.paymentMethods).length) {
                options.paymentMethods = cfg.paymentMethods;
            }

            var ece = elements.create('expressCheckout', options);

            self.stripe = { stripe: stripe, elements: elements, element: ece };

            // The sheet's opening state. Stripe wants the rates handed over at click time, not at
            // creation time, because they may have changed since the button was rendered.
            ece.on('click', function (event) {
                event.resolve({
                    emailRequired: self.wants('email'),
                    phoneNumberRequired: self.wants('phone'),
                    shippingAddressRequired: !!self.config.requestShipping,
                    lineItems: self.stripeLineItems(),
                    shippingRates: self.stripeRates(),
                    business: { name: cfg.businessName }
                });
            });

            ece.on('shippingaddresschange', function (event) {
                self.post('address', { address: { source: 'stripe', address: event.address } })
                    .then(function (data) {
                        if (!data.success) {
                            event.reject();
                            return;
                        }

                        self.quote = data.quote;

                        if (self.quote.shippingError) {
                            event.reject();
                            return;
                        }

                        elements.update({ amount: self.quote.total });

                        event.resolve({
                            lineItems: self.stripeLineItems(),
                            shippingRates: self.stripeRates()
                        });
                    })
                    .catch(function () { event.reject(); });
            });

            ece.on('shippingratechange', function (event) {
                self.post('shipping', { shippingOption: event.shippingRate.id })
                    .then(function (data) {
                        if (!data.success) {
                            event.reject();
                            return;
                        }

                        self.quote = data.quote;

                        elements.update({ amount: self.quote.total });

                        event.resolve({ lineItems: self.stripeLineItems() });
                    })
                    .catch(function () { event.reject(); });
            });

            ece.on('cancel', function () {
                self.post('cancel', {}).then(function () { return self.renew(); });
            });

            ece.on('confirm', function (event) {
                self.confirmStripe(event);
            });

            ece.mount('#' + self.config.id);
        });
    };

    Button.prototype.confirmStripe = function (event) {
        var self = this;
        var stripe = this.stripe.stripe;
        var elements = this.stripe.elements;

        elements.submit()
            .then(function (result) {
                if (result.error) {
                    throw new Error(result.error.message);
                }

                var billing = event.billingDetails || {};
                var shipping = event.shippingAddress || {};

                return self.post('pay', {
                    payer: {
                        name: billing.name || shipping.name || null,
                        email: billing.email || null,
                        phone: billing.phone || null
                    },
                    shippingAddress: shipping.address
                        ? { source: 'stripe', address: shipping.address, name: shipping.name }
                        : null,
                    billingAddress: billing.address
                        ? { source: 'stripe', address: billing.address, name: billing.name }
                        : null,
                    shippingOption: event.shippingRate ? event.shippingRate.id : null,
                    expectedTotal: self.quote.total,
                    // `apple_pay`, `google_pay`, `link`… — recorded for the order index only.
                    wallet: event.expressPaymentType || null,
                    payment: {}
                });
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.error || 'Payment failed.');
                }

                if (data.confirmed) {
                    return null;
                }

                // Commerce created the PaymentIntent and left it unconfirmed; the wallet's
                // authorisation confirms it here. `redirect: 'if_required'` keeps a payment that
                // needs no 3-D Secure step on this page.
                return stripe.confirmPayment({
                    elements: elements,
                    clientSecret: data.clientSecret,
                    confirmParams: { return_url: data.returnUrl },
                    redirect: 'if_required'
                }).then(function (confirmation) {
                    if (confirmation.error) {
                        throw new Error(confirmation.error.message);
                    }

                    return confirmation;
                });
            })
            .then(function () {
                return self.post('complete', {});
            })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.error || 'The order couldn’t be completed.');
                }

                self.finish(data);
            })
            .catch(function (e) {
                event.paymentFailed({ reason: 'fail' });
                self.post('cancel', {}).then(function () { return self.renew(); });

                if (window.console && console.warn) {
                    console.warn('[Coin Purse] ' + (e && e.message));
                }
            });
    };

    Button.prototype.stripeLineItems = function () {
        return (this.quote.lines || []).map(function (line) {
            return { name: line.name, amount: line.amount };
        });
    };

    Button.prototype.stripeRates = function () {
        var rates = (this.quote.shippingOptions || []).map(function (option) {
            return { id: option.id, displayName: option.label, amount: option.amount };
        });

        // Stripe refuses `shippingAddressRequired` with an empty rate list, and the sheet dies
        // with an opaque error. A single placeholder keeps it open long enough for the address
        // change to fetch the real ones.
        if (!rates.length && this.config.requestShipping) {
            rates = [{ id: PENDING_OPTION, displayName: 'Calculated at next step', amount: 0 }];
        }

        return rates;
    };

    // ---------------------------------------------------------------- Raw wallets

    Button.prototype.mountRaw = function () {
        var mounted = [];

        if (this.driverConfig.applePay && window.ApplePaySession && window.ApplePaySession.canMakePayments()) {
            mounted.push(this.mountApplePay());
        }

        if (this.driverConfig.googlePay) {
            mounted.push(this.mountGooglePay());
        }

        if (!mounted.length) {
            this.fail('No wallet is available in this browser.');
        }

        return Promise.all(mounted);
    };

    Button.prototype.mountApplePay = function () {
        var self = this;
        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'coinpurse-apple-pay coinpurse-apple-pay--' + this.config.style.theme;
        button.style.height = this.config.style.height + 'px';
        button.setAttribute('aria-label', 'Apple Pay');

        button.addEventListener('click', function () { self.beginApplePay(); });

        this.el.appendChild(button);

        return Promise.resolve();
    };

    Button.prototype.beginApplePay = function () {
        var self = this;
        var cfg = this.driverConfig.applePay;

        var request = {
            countryCode: cfg.countryCode,
            currencyCode: this.quote.currency,
            supportedNetworks: cfg.supportedNetworks,
            merchantCapabilities: cfg.merchantCapabilities,
            total: this.appleTotal(),
            lineItems: this.appleLines(),
            requiredBillingContactFields: ['postalAddress']
        };

        var contactFields = ['email'];

        if (this.wants('name')) { contactFields.push('name'); }
        if (this.wants('phone')) { contactFields.push('phone'); }
        if (this.config.requestShipping) { contactFields.push('postalAddress'); }

        request.requiredShippingContactFields = contactFields;

        if (this.config.requestShipping) {
            request.shippingType = this.config.shippingType;
            request.shippingMethods = this.appleMethods();
        }

        var session = new window.ApplePaySession(cfg.version || 3, request);

        session.onvalidatemerchant = function (event) {
            post(self.endpoints(), self.endpoints().validateMerchant, { validationUrl: event.validationURL })
                .then(function (data) {
                    if (!data.success) {
                        session.abort();
                        return;
                    }

                    session.completeMerchantValidation(data.merchantSession);
                })
                .catch(function () { session.abort(); });
        };

        session.onshippingcontactselected = function (event) {
            self.post('address', { address: { source: 'applePay', address: event.shippingContact } })
                .then(function (data) {
                    if (!data.success) {
                        session.completeShippingContactSelection({
                            errors: [new window.ApplePayError('shippingContactInvalid')],
                            newTotal: self.appleTotal(),
                            newLineItems: self.appleLines(),
                            newShippingMethods: []
                        });
                        return;
                    }

                    self.quote = data.quote;

                    session.completeShippingContactSelection({
                        newTotal: self.appleTotal(),
                        newLineItems: self.appleLines(),
                        newShippingMethods: self.appleMethods(),
                        errors: self.quote.shippingError
                            ? [new window.ApplePayError('addressUnserviceable', 'postalAddress', self.quote.shippingError)]
                            : []
                    });
                });
        };

        session.onshippingmethodselected = function (event) {
            self.post('shipping', { shippingOption: event.shippingMethod.identifier })
                .then(function (data) {
                    if (data.success) {
                        self.quote = data.quote;
                    }

                    session.completeShippingMethodSelection({
                        newTotal: self.appleTotal(),
                        newLineItems: self.appleLines()
                    });
                });
        };

        session.onpaymentauthorized = function (event) {
            var payment = event.payment;
            var contact = payment.shippingContact || {};
            var billing = payment.billingContact || {};

            self.post('pay', {
                payer: {
                    name: [contact.givenName, contact.familyName].filter(Boolean).join(' ') || null,
                    email: contact.emailAddress || null,
                    phone: contact.phoneNumber || null
                },
                shippingAddress: { source: 'applePay', address: contact },
                billingAddress: { source: 'applePay', address: billing },
                shippingOption: null,
                expectedTotal: self.quote.total,
                wallet: 'applePay',
                payment: { token: payment.token }
            })
                .then(function (data) {
                    if (!data.success) {
                        session.completePayment({
                            status: window.ApplePaySession.STATUS_FAILURE,
                            errors: [new window.ApplePayError('unknown', undefined, data.error)]
                        });
                        return null;
                    }

                    return self.post('complete', {});
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }

                    if (!data.success) {
                        session.completePayment({ status: window.ApplePaySession.STATUS_FAILURE });
                        return;
                    }

                    session.completePayment({ status: window.ApplePaySession.STATUS_SUCCESS });
                    self.finish(data);
                })
                .catch(function () {
                    session.completePayment({ status: window.ApplePaySession.STATUS_FAILURE });
                });
        };

        session.oncancel = function () {
            self.post('cancel', {}).then(function () { return self.renew(); });
        };

        session.begin();
    };

    Button.prototype.appleTotal = function () {
        return {
            label: this.driverConfig.applePay.displayName,
            amount: toDecimal(this.quote.total, this.quote.decimals),
            type: 'final'
        };
    };

    Button.prototype.appleLines = function () {
        var decimals = this.quote.decimals;

        return (this.quote.lines || []).map(function (line) {
            return { label: line.name, amount: toDecimal(line.amount, decimals), type: 'final' };
        });
    };

    Button.prototype.appleMethods = function () {
        var decimals = this.quote.decimals;

        return (this.quote.shippingOptions || []).map(function (option) {
            return {
                label: option.label,
                detail: option.detail || '',
                amount: toDecimal(option.amount, decimals),
                identifier: option.id
            };
        });
    };

    Button.prototype.mountGooglePay = function () {
        var self = this;
        var cfg = this.driverConfig.googlePay;

        return loadScript(GOOGLE_PAY_JS).then(function () {
            var client = new window.google.payments.api.PaymentsClient({
                environment: cfg.environment,
                paymentDataCallbacks: {
                    onPaymentDataChanged: function (data) { return self.googleDataChanged(data); },
                    onPaymentAuthorized: function (data) { return self.googleAuthorized(data); }
                }
            });

            return client.isReadyToPay({
                apiVersion: cfg.apiVersion,
                apiVersionMinor: cfg.apiVersionMinor,
                allowedPaymentMethods: cfg.allowedPaymentMethods
            }).then(function (response) {
                if (!response.result) {
                    return;
                }

                var button = client.createButton({
                    onClick: function () { self.beginGooglePay(client); },
                    buttonType: self.config.style.type === 'default' ? 'plain' : self.config.style.type,
                    buttonColor: self.config.style.theme === 'light' ? 'white' : 'black',
                    buttonSizeMode: 'fill'
                });

                button.style.height = self.config.style.height + 'px';

                self.el.appendChild(button);
            });
        });
    };

    Button.prototype.googleRequest = function () {
        var cfg = this.driverConfig.googlePay;

        var request = {
            apiVersion: cfg.apiVersion,
            apiVersionMinor: cfg.apiVersionMinor,
            allowedPaymentMethods: cfg.allowedPaymentMethods,
            merchantInfo: cfg.merchantInfo,
            transactionInfo: this.googleTransactionInfo(),
            emailRequired: true,
            callbackIntents: ['PAYMENT_AUTHORIZATION']
        };

        if (this.config.requestShipping) {
            request.shippingAddressRequired = true;
            request.shippingAddressParameters = { phoneNumberRequired: this.wants('phone') };
            request.shippingOptionRequired = true;
            request.shippingOptionParameters = this.googleShippingParameters();
            request.callbackIntents = ['SHIPPING_ADDRESS', 'SHIPPING_OPTION', 'PAYMENT_AUTHORIZATION'];
        }

        return request;
    };

    Button.prototype.googleTransactionInfo = function () {
        var cfg = this.driverConfig.googlePay;
        var decimals = this.quote.decimals;

        return {
            countryCode: cfg.countryCode,
            currencyCode: this.quote.currency,
            totalPriceStatus: 'FINAL',
            totalPrice: toDecimal(this.quote.total, decimals),
            totalPriceLabel: 'Total',
            displayItems: (this.quote.lines || []).map(function (line) {
                return {
                    label: line.name,
                    type: line.type === 'tax' ? 'TAX' : (line.type === 'shipping' ? 'LINE_ITEM' : 'LINE_ITEM'),
                    price: toDecimal(line.amount, decimals)
                };
            })
        };
    };

    Button.prototype.googleShippingParameters = function () {
        var options = (this.quote.shippingOptions || []).map(function (option) {
            return { id: option.id, label: option.label, description: option.detail || '' };
        });

        if (!options.length) {
            options = [{ id: PENDING_OPTION, label: 'Calculated at next step', description: '' }];
        }

        return {
            defaultSelectedOptionId: this.quote.selectedShippingOption || options[0].id,
            shippingOptions: options
        };
    };

    Button.prototype.beginGooglePay = function (client) {
        var self = this;

        client.loadPaymentData(this.googleRequest()).catch(function (e) {
            // `CANCELED` is the customer closing the sheet, which is not an error.
            if (e && e.statusCode !== 'CANCELED' && window.console && console.warn) {
                console.warn('[Coin Purse] Google Pay: ' + (e.statusMessage || e.statusCode));
            }

            self.post('cancel', {}).then(function () { return self.renew(); });
        });
    };

    Button.prototype.googleDataChanged = function (intermediate) {
        var self = this;
        var trigger = intermediate.callbackTrigger;

        var request;

        if (trigger === 'SHIPPING_OPTION') {
            request = this.post('shipping', { shippingOption: intermediate.shippingOptionData.id });
        } else {
            request = this.post('address', {
                address: { source: 'googlePay', address: intermediate.shippingAddress || {} }
            });
        }

        return request.then(function (data) {
            if (!data.success) {
                return { error: { reason: 'SHIPPING_ADDRESS_UNSERVICEABLE', message: data.error, intent: 'SHIPPING_ADDRESS' } };
            }

            self.quote = data.quote;

            if (self.quote.shippingError) {
                return {
                    error: {
                        reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
                        message: self.quote.shippingError,
                        intent: 'SHIPPING_ADDRESS'
                    }
                };
            }

            var update = { newTransactionInfo: self.googleTransactionInfo() };

            if (trigger !== 'SHIPPING_OPTION') {
                update.newShippingOptionParameters = self.googleShippingParameters();
            }

            return update;
        });
    };

    Button.prototype.googleAuthorized = function (paymentData) {
        var self = this;
        var shipping = paymentData.shippingAddress || {};
        var billing = (paymentData.paymentMethodData &&
            paymentData.paymentMethodData.info &&
            paymentData.paymentMethodData.info.billingAddress) || {};

        return this.post('pay', {
            payer: {
                name: shipping.name || billing.name || null,
                email: paymentData.email || null,
                phone: shipping.phoneNumber || billing.phoneNumber || null
            },
            shippingAddress: { source: 'googlePay', address: shipping },
            billingAddress: { source: 'googlePay', address: billing },
            shippingOption: paymentData.shippingOptionData ? paymentData.shippingOptionData.id : null,
            expectedTotal: this.quote.total,
            wallet: 'googlePay',
            payment: { token: paymentData.paymentMethodData.tokenizationData.token }
        })
            .then(function (data) {
                if (!data.success) {
                    return { transactionState: 'ERROR', error: { reason: 'PAYMENT_DATA_INVALID', message: data.error, intent: 'PAYMENT_AUTHORIZATION' } };
                }

                return self.post('complete', {}).then(function (completion) {
                    if (!completion.success) {
                        return { transactionState: 'ERROR', error: { reason: 'OTHER_ERROR', message: completion.error, intent: 'PAYMENT_AUTHORIZATION' } };
                    }

                    // The redirect has to wait until Google's sheet has been told the transaction
                    // succeeded, or it closes on an error the customer never caused.
                    window.setTimeout(function () { self.finish(completion); }, 0);

                    return { transactionState: 'SUCCESS' };
                });
            })
            .catch(function (e) {
                return { transactionState: 'ERROR', error: { reason: 'OTHER_ERROR', message: e.message, intent: 'PAYMENT_AUTHORIZATION' } };
            });
    };

    // ---------------------------------------------------------------- Helpers

    Button.prototype.wants = function (detail) {
        return (this.config.requestDetails || []).indexOf(detail) !== -1;
    };

    /**
     * Re-read the cart and re-open the session, without touching the mounted element.
     *
     * A dismissed sheet has had its session cancelled and its cart snapshot restored, so the
     * button needs a fresh one before it can be pressed again. Doing it here, in the background,
     * is why a customer can open and dismiss Apple Pay five times and still buy on the sixth.
     */
    Button.prototype.renew = function () {
        var self = this;

        return post(this.endpoints(), this.endpoints().start, { payload: this.config.payload })
            .then(function (data) {
                if (data && data.success) {
                    self.session = data.session;
                    self.quote = data.quote;
                }
            })
            .catch(function () { /* The next press will try again. */ });
    };

    /** Re-read the cart from the server. Call after changing the cart on the page. */
    Button.prototype.refresh = function () {
        return this.renew();
    };

    // ---------------------------------------------------------------- Declarative buttons

    var IDENTIFIER_PATH = /^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/;

    /** Options passed to `CoinPurse.start()`, applied over every button it mounts. */
    var startOptions = {};
    var scanQueued = false;

    function emit(el, name, detail) {
        if (!el || typeof el.dispatchEvent !== 'function' || typeof window.CustomEvent !== 'function') {
            return;
        }

        try {
            el.dispatchEvent(new window.CustomEvent(name, { bubbles: true, detail: detail }));
        } catch (e) { /* An event nobody could construct is not worth failing a purchase over. */ }
    }

    /** A relative URL, or an absolute http(s) one — never `javascript:` and friends. */
    function isNavigable(url) {
        if (typeof url !== 'string') {
            return false;
        }

        // Browsers ignore leading control characters and strip tabs and newlines anywhere in a
        // URL, so `\tjava\nscript:` is still a script URL by the time it is followed.
        var cleaned = url.replace(/^[\u0000-\u0020]+/, '').replace(/[\t\n\r]/g, '');
        var scheme = /^([A-Za-z][A-Za-z0-9+.\-]*):/.exec(cleaned);

        return !scheme || /^https?$/i.test(scheme[1]);
    }

    /** `window.payButton`, `app.checkout.button` — a name to assign to, never a statement. */
    function assignPath(path, value) {
        if (typeof path !== 'string' || !IDENTIFIER_PATH.test(path)) {
            return false;
        }

        var parts = path.split('.');

        if (parts[0] === 'window') {
            parts.shift();
        }

        var target = window;

        for (var i = 0; i < parts.length; i++) {
            if (parts[i] === '__proto__' || parts[i] === 'prototype' || parts[i] === 'constructor') {
                return false;
            }

            if (i === parts.length - 1) {
                target[parts[i]] = value;
                return true;
            }

            target = target[parts[i]];

            if (!target || (typeof target !== 'object' && typeof target !== 'function')) {
                return false;
            }
        }

        return false;
    }

    function isPlainObject(value) {
        return !!value && typeof value === 'object' && Object.prototype.toString.call(value) === '[object Object]';
    }

    /**
     * `overrides` over `base`, one level deep for `style` and `onComplete`, so
     * `start({ style: { theme: 'light' } })` keeps the height and `start({ onComplete: fn })`
     * keeps the template's redirect.
     */
    function merge(base, overrides) {
        var result = {};
        var key;

        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                result[key] = base[key];
            }
        }

        for (key in overrides || {}) {
            if (!Object.prototype.hasOwnProperty.call(overrides, key) || key === 'id') {
                continue;
            }

            var value = overrides[key];

            if (key === 'onComplete' && typeof value === 'function') {
                value = { callback: value };
            }

            if (isPlainObject(value) && isPlainObject(result[key])) {
                result[key] = merge(result[key], value);
            } else {
                result[key] = value;
            }
        }

        return result;
    }

    /**
     * Read a button's `data-coinpurse-config`. Malformed JSON is ignored, not thrown, and the
     * button is hidden rather than left as an empty box: the same thing a button the server
     * refuses does.
     */
    function fromAttribute(el) {
        var config;

        try {
            config = JSON.parse(el.getAttribute('data-coinpurse-config'));
        } catch (e) {
            config = null;
        }

        if (!isPlainObject(config) || typeof config.payload !== 'string' || !isPlainObject(config.endpoints)) {
            if (window.console && console.warn) {
                console.warn('[Coin Purse] Ignoring a button with an unreadable data-coinpurse-config.');
            }

            if (el.style) {
                el.style.display = 'none';
            }

            return null;
        }

        // Markup can be planted where a script cannot: a sanitiser that keeps `data-*` attributes
        // would otherwise let a forged button point the CSRF token and the wallet at another host.
        for (var name in config.endpoints) {
            if (Object.prototype.hasOwnProperty.call(config.endpoints, name) && !sameOrigin(config.endpoints[name])) {
                if (window.console && console.warn) {
                    console.warn('[Coin Purse] Ignoring a button whose endpoints are not on this site.');
                }

                if (el.style) {
                    el.style.display = 'none';
                }

                return null;
            }
        }

        // The element is the source of truth for which element this config mounts into.
        if (el.id) {
            config.id = el.id;
        } else if (typeof config.id === 'string' && config.id) {
            el.id = config.id;
        } else {
            return null;
        }

        // Data, not code: Coin Purse never writes `onComplete.js` into an attribute, and this
        // refuses one somebody else did.
        if (isPlainObject(config.onComplete) && config.onComplete.js) {
            config.onComplete = merge(config.onComplete, {});
            delete config.onComplete.js;
        }

        return config;
    }

    function scan() {
        scanQueued = false;

        var nodes = document.querySelectorAll('[data-coinpurse-config]');

        for (var i = 0; i < nodes.length; i++) {
            var config = fromAttribute(nodes[i]);

            if (config) {
                mountConfig(merge(config, startOptions), true);
            }
        }
    }

    /**
     * The one path into a mounted button. Idempotent per element, so the injected call and a
     * bundled `start()` on the same page — or `start()` called again after new buttons arrive —
     * never mount a button twice.
     */
    function mountConfig(config, declarative) {
        if (!config || typeof config.id !== 'string' || !config.id) {
            return null;
        }

        var existing = CoinPurse.buttons[config.id];

        if (existing && existing.el) {
            return existing;
        }

        if (!document.getElementById(config.id)) {
            // A runtime that runs ahead of the markup (in <head>, say) waits for it rather than
            // registering a button with nowhere to draw — which would also block the real mount.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { mountConfig(config, declarative); });
            }

            return null;
        }

        var button = new Button(config, declarative);

        CoinPurse.buttons[config.id] = button;

        if (config.variable) {
            if (declarative) {
                if (!assignPath(config.variable, button) && window.console && console.warn) {
                    console.warn('[Coin Purse] Could not assign to ' + config.variable);
                }
            } else {
                // `js: 'window.payButton'` in the template hands the caller a handle on this
                // button, the same way the plugin this replaces did.
                try {
                    /* eslint-disable no-new-func */
                    new Function('button', config.variable + ' = button;')(button);
                } catch (e) {
                    if (window.console && console.warn) {
                        console.warn('[Coin Purse] Could not assign to ' + config.variable);
                    }
                }
            }
        }

        button.start();

        return button;
    }

    // ---------------------------------------------------------------- Public API

    var CoinPurse = {
        buttons: {},
        lastOrder: null,

        /** Mount one button from a full config. This is what the injected inline call uses. */
        mount: function (config) {
            return mountConfig(config, false);
        },

        /**
         * Mount every button on the page from its `data-coinpurse-config`, for a site that bundles
         * this file itself. Safe to call before the markup exists (it waits for the document) and
         * again after more buttons arrive (it mounts only the new ones). Options passed here —
         * `onComplete: function (order, button) {}`, `style`, `variable` — apply over each
         * button's own config.
         */
        start: function (options) {
            if (isPlainObject(options)) {
                startOptions = merge(startOptions, options);
            }

            if (document.readyState === 'loading') {
                if (!scanQueued) {
                    scanQueued = true;
                    document.addEventListener('DOMContentLoaded', scan);
                }

                return;
            }

            scan();
        }
    };

    window.CoinPurse = CoinPurse;

    // For a site that bundles the runtime: a CommonJS bundler gets the same object as the global.
    // ES-module bundlers use coinpurse.mjs instead, which wraps this file rather than copying it.
    if (typeof module === 'object' && module && module.exports) {
        module.exports = CoinPurse;
    }
})();
