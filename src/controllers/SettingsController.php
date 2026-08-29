<?php

namespace justinholtweb\coinpurse\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\coinpurse\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Coin Purse's own settings screen.
 *
 * It is served from a plugin CP route rather than Craft's generic plugin-settings page, because the
 * screen has to show live state — which driver resolved, what the gateway is, what is still
 * missing — alongside the fields. The trade-off to remember is that Craft is **not** namespacing
 * this template's output, so field names here are written `settings[foo]`. On the generic page they
 * would have to be written bare, and writing them this way there saves nothing at all.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('coinpurse-manage');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('coinpurse/settings/_index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'gatewayOptions' => $this->gatewayOptions(),
            'driverOptions' => $plugin->getDrivers()->getDriverOptions(),
            'commerceReady' => Plugin::commerceIsReady(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Admin changes are disabled in this environment.');
        }

        $plugin = Plugin::getInstance();
        $posted = $this->request->getBodyParam('settings', []);

        // `savePluginSettings()` replaces the whole settings block in project config. Passing only
        // the fields this screen posted would silently erase every setting it does not show —
        // which is how a plugin loses its API credentials on an unrelated save.
        $settings = array_merge($plugin->getSettings()->toArray(), is_array($posted) ? $posted : []);

        // Unchecked lightswitches post nothing at all, so they have to be read back as false
        // rather than left at whatever they were.
        foreach ($this->booleanSettings() as $attribute) {
            $settings[$attribute] = (bool)($posted[$attribute] ?? false);
        }

        foreach (['allowedShippingCountries', 'allowedCardNetworks', 'allowedAuthMethods'] as $attribute) {
            $settings[$attribute] = $this->normaliseList($posted[$attribute] ?? []);
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('coinpurse', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('coinpurse', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return string[]
     */
    private function booleanSettings(): array
    {
        return [
            'enableApplePay', 'enableGooglePay', 'enableLink', 'enablePaypal', 'enableAmazonPay',
            'enableKlarna', 'requireEmail', 'requirePhone', 'requireName', 'stripeAutoWallets',
            'loggingEnabled', 'logPayloads', 'registerWebPaymentsAlias',
        ];
    }

    private function normaliseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        // Craft's editable tables post `[['value' => 'US'], …]`; a plain multi-select posts a flat
        // list. Both arrive here.
        $flat = array_map(
            static fn($item) => is_array($item) ? (string)($item['value'] ?? '') : (string)$item,
            $value
        );

        return array_values(array_filter(array_map('trim', $flat)));
    }

    private function gatewayOptions(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $options = [['label' => Craft::t('coinpurse', 'Select a gateway…'), 'value' => '']];

        foreach (Commerce::getInstance()->getGateways()->getAllGateways() as $gateway) {
            $options[] = ['label' => $gateway->name, 'value' => (string)$gateway->id];
        }

        return $options;
    }
}
