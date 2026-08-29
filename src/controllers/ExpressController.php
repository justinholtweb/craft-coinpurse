<?php

namespace justinholtweb\coinpurse\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\coinpurse\helpers\Address;
use justinholtweb\coinpurse\helpers\Signature;
use justinholtweb\coinpurse\models\ButtonOptions;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\LogEntry;
use justinholtweb\coinpurse\models\WalletAddress;
use justinholtweb\coinpurse\models\WalletPayer;
use justinholtweb\coinpurse\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The express checkout endpoints.
 *
 * All of these are anonymous, because the customer has not logged in and in all likelihood never
 * will. What stands in for authentication is the signed payload: every session is opened from a
 * payload this site rendered and signed with Craft's own security key, and every subsequent call
 * is bound to that session's row. A browser cannot invent a purchasable, a quantity or a price.
 */
class ExpressController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::commerceIsReady()) {
            throw new BadRequestHttpException('Craft Commerce isn’t available.');
        }

        return true;
    }

    /**
     * Open a session: turn a signed button payload into an order and a first quote.
     */
    public function actionStart(): Response
    {
        $options = $this->requireSignedOptions();

        $drivers = Plugin::getInstance()->getDrivers();
        $gateway = $drivers->getGateway();
        $driver = $drivers->resolve($gateway);

        if (!$gateway || !$driver) {
            return $this->failure(Craft::t('coinpurse', 'Express checkout isn’t available right now.'));
        }

        // A button rendered before the gateway was changed must not open a session against the old
        // one, and a payload signed for one site must not open a session on another. The signature
        // covers both precisely so this can be caught.
        if ($options->gatewayId !== $gateway->id) {
            return $this->failure(Craft::t('coinpurse', 'This page is out of date. Reload and try again.'));
        }

        if ($options->siteId !== null && $options->siteId !== Craft::$app->getSites()->getCurrentSite()->id) {
            return $this->failure(Craft::t('coinpurse', 'This page is out of date. Reload and try again.'));
        }

        $session = Plugin::getInstance()->getSessions()->open($options, $driver::handle());

        $order = $session->getOrder();

        if (!$order || !$order->getLineItems()) {
            Plugin::getInstance()->getSessions()->cancel($session, 'Empty order.');

            return $this->failure(Craft::t('coinpurse', 'There’s nothing to pay for.'));
        }

        $quote = Plugin::getInstance()->getQuotes()->build($session);

        Plugin::getInstance()->getLog()->write('start', [
            'sessionUid' => $session->uid,
            'driver' => $driver::handle(),
            'summary' => Craft::t('coinpurse', 'Express session opened for order {number}.', ['number' => $order->number]),
            'request' => json_encode($options->signablePayload()),
        ]);

        return $this->asJson([
            'success' => true,
            'session' => $session->uid,
            'quote' => $quote->toArray(),
            'driver' => $driver::handle(),
            'config' => $driver->getClientConfig($session, $quote),
        ]);
    }

    /**
     * The customer picked a shipping address inside the sheet. Re-price.
     */
    public function actionAddress(): Response
    {
        $session = $this->requireSession();
        $address = $this->readAddress($this->request->getBodyParam('address'));

        if (!$address) {
            return $this->failure(Craft::t('coinpurse', 'No address was supplied.'));
        }

        $quote = Plugin::getInstance()->getCheckout()->applyShippingAddress($session, $address);

        return $this->asJson(['success' => true, 'quote' => $quote->toArray()]);
    }

    /**
     * The customer picked a shipping method inside the sheet. Re-price.
     */
    public function actionShipping(): Response
    {
        $session = $this->requireSession();
        $option = (string)$this->request->getBodyParam('shippingOption');

        if ($option === '') {
            return $this->failure(Craft::t('coinpurse', 'No shipping option was supplied.'));
        }

        // The placeholder the sheet opens with is not a real method. Selecting it is not an error
        // — it is what a wallet does before it knows where it is shipping to — so answer with the
        // current quote and let the address change fetch the real rates.
        if ($option === ButtonOptions::PENDING_SHIPPING_OPTION) {
            $quote = Plugin::getInstance()->getQuotes()->build($session);

            return $this->asJson(['success' => true, 'quote' => $quote->toArray()]);
        }

        $quote = Plugin::getInstance()->getCheckout()->applyShippingOption($session, $option);

        return $this->asJson(['success' => true, 'quote' => $quote->toArray()]);
    }

    /**
     * The customer authorised the payment. Charge it.
     */
    public function actionPay(): Response
    {
        $session = $this->requireSession();

        $drivers = Plugin::getInstance()->getDrivers();
        $gateway = $drivers->getGateway();
        $driver = $drivers->getByHandle($session->driver);

        if (!$gateway || !$driver) {
            return $this->failure(Craft::t('coinpurse', 'Express checkout isn’t available right now.'));
        }

        $payer = $this->readPayer($this->request->getBodyParam('payer'));
        $shippingAddress = $this->readAddress($this->request->getBodyParam('shippingAddress'));
        $billingAddress = $this->readAddress($this->request->getBodyParam('billingAddress'));
        $shippingOption = $this->request->getBodyParam('shippingOption');
        $payment = $this->request->getBodyParam('payment') ?: [];
        $expectedTotal = $this->request->getBodyParam('expectedTotal');

        $options = $session->getOptions();

        if ($options->needsShipping() && (!$shippingAddress || !$shippingAddress->isComplete())) {
            return $this->failure(Craft::t('coinpurse', 'A complete shipping address is needed to place this order.'));
        }

        if ($options->wantsDetail('email') && !$payer->email) {
            // Commerce cannot complete an order without one, and finding that out after the money
            // has moved is far worse than refusing here.
            return $this->failure(Craft::t('coinpurse', 'An email address is needed to place this order.'));
        }

        $started = microtime(true);

        try {
            $result = Plugin::getInstance()->getCheckout()->prepare(
                $session,
                $gateway,
                $payer,
                $shippingAddress,
                $billingAddress,
                    is_string($shippingOption)
                && $shippingOption !== ''
                && $shippingOption !== ButtonOptions::PENDING_SHIPPING_OPTION
                    ? $shippingOption
                    : null,
                static fn(Order $order) => $driver->createPaymentForm($session, $gateway, is_array($payment) ? $payment : []),
                $expectedTotal !== null && $expectedTotal !== '' ? (int)$expectedTotal : null,
            );
        } catch (\Throwable $e) {
            Plugin::getInstance()->getSessions()->setState($session, ExpressSession::STATE_FAILED, $e->getMessage());

            Plugin::getInstance()->getLog()->write('pay', [
                'level' => LogEntry::LEVEL_ERROR,
                'sessionUid' => $session->uid,
                'driver' => $session->driver,
                'summary' => Craft::t('coinpurse', 'Payment failed.'),
                'message' => $e->getMessage(),
                'durationMs' => (int)((microtime(true) - $started) * 1000),
            ]);

            return $this->failure($e->getMessage());
        }

        $payload = $driver->getPaymentResult(
            $session,
            $result['transaction'],
            $result['redirect'],
            $result['redirectData'],
        );

        Plugin::getInstance()->getLog()->write('pay', [
            'sessionUid' => $session->uid,
            'driver' => $session->driver,
            'orderNumber' => $session->orderNumber,
            'summary' => Craft::t('coinpurse', 'Payment processed.'),
            'durationMs' => (int)((microtime(true) - $started) * 1000),
        ]);

        return $this->asJson(['success' => true] + $payload);
    }

    /**
     * The wallet is done. Finish the order.
     */
    public function actionComplete(): Response
    {
        $uid = (string)$this->request->getBodyParam('session');
        $session = Plugin::getInstance()->getSessions()->getByUid($uid);

        if (!$session) {
            return $this->failure(Craft::t('coinpurse', 'Unknown express session.'));
        }

        try {
            $result = Plugin::getInstance()->getCheckout()->complete($session);
        } catch (\Throwable $e) {
            Plugin::getInstance()->getLog()->write('complete', [
                'level' => LogEntry::LEVEL_ERROR,
                'sessionUid' => $session->uid,
                'driver' => $session->driver,
                'summary' => Craft::t('coinpurse', 'Completion failed.'),
                'message' => $e->getMessage(),
            ]);

            return $this->failure($e->getMessage());
        }

        return $this->asJson([
            'success' => true,
            'number' => $result['number'],
            'email' => $result['order']->getEmail(),
        ]);
    }

    /**
     * The customer dismissed the sheet. Put the cart back.
     */
    public function actionCancel(): Response
    {
        $uid = (string)$this->request->getBodyParam('session');
        $session = Plugin::getInstance()->getSessions()->getByUid($uid);

        if ($session) {
            Plugin::getInstance()->getSessions()->cancel($session, 'Dismissed.');
        }

        return $this->asJson(['success' => true]);
    }

    // ---------------------------------------------------------------- Internals

    private function requireSignedOptions(): ButtonOptions
    {
        $signed = (string)$this->request->getBodyParam('payload');

        $payload = $signed !== '' ? Signature::verify($signed) : null;

        if ($payload === null) {
            throw new BadRequestHttpException('Invalid express checkout payload.');
        }

        $options = new ButtonOptions([
            'mode' => $payload['mode'] ?? ButtonOptions::MODE_ITEMS,
            'items' => $payload['items'] ?? [],
            'requestShipping' => $payload['requestShipping'] ?? false,
            'requestDetails' => $payload['requestDetails'] ?? [],
            'siteId' => $payload['siteId'] ?? null,
            'gatewayId' => $payload['gatewayId'] ?? null,
            'expires' => (int)($payload['expires'] ?? 0),
        ]);

        if ($options->isExpired()) {
            throw new BadRequestHttpException('This express checkout button has expired. Reload the page.');
        }

        return $options;
    }

    private function requireSession(): ExpressSession
    {
        $uid = (string)$this->request->getBodyParam('session');

        if ($uid === '') {
            throw new BadRequestHttpException('No express session was supplied.');
        }

        return Plugin::getInstance()->getSessions()->getLiveByUid($uid);
    }

    private function readAddress(mixed $raw): ?WalletAddress
    {
        if (!is_array($raw) || !$raw) {
            return null;
        }

        $source = (string)($raw['source'] ?? 'stripe');
        $data = is_array($raw['address'] ?? null) ? $raw['address'] : $raw;
        $name = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : null;

        return match ($source) {
            'applePay' => Address::fromApplePay($data),
            'googlePay' => Address::fromGooglePay($data),
            default => Address::fromStripe($data, $name),
        };
    }

    private function readPayer(mixed $raw): WalletPayer
    {
        if (!is_array($raw)) {
            return new WalletPayer();
        }

        return new WalletPayer([
            'name' => $this->str($raw['name'] ?? null),
            'email' => $this->str($raw['email'] ?? null),
            'phone' => $this->str($raw['phone'] ?? null),
        ]);
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * A handled business failure — an address we cannot ship to, a total that moved, a card the
     * gateway declined.
     *
     * These come back as HTTP 200 with `success: false` on purpose. Wallet sheets have their own
     * error affordances and the message has to reach them; a 4xx makes some browsers discard the
     * body before the page can read it.
     */
    private function failure(string $message): Response
    {
        return $this->asJson(['success' => false, 'error' => $message]);
    }
}
