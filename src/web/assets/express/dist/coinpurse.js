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
 */
(function () {
    'use strict';

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

    function post(endpoints, url, body) {
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

    function Button(config) {
        this.config = config;
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

        if (onComplete.js) {
            try {
                /* eslint-disable no-new-func */
                new Function('cwp', onComplete.js)(cwp);
            } catch (e) {
                if (window.console && console.error) {
                    console.error('[Coin Purse] onComplete.js threw', e);
                }
            }
        }

        if (onComplete.redirect) {
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

    // ---------------------------------------------------------------- Public API

    var CoinPurse = {
        buttons: {},
        lastOrder: null,

        mount: function (config) {
            var button = new Button(config);

            this.buttons[config.id] = button;

            if (config.variable) {
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

            button.start();

            return button;
        }
    };

    window.CoinPurse = CoinPurse;
})();
