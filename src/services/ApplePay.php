<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\coinpurse\models\LogEntry;
use justinholtweb\coinpurse\Plugin;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;

/**
 * Apple Pay merchant validation, and the domain association file that has to exist before any of
 * it works.
 *
 * Merchant validation is the one part of Apple Pay on the Web that cannot happen in the browser:
 * the request is mutual-TLS, authenticated by the merchant identity certificate, and Apple is
 * explicit that it must never be made from the client. So the browser hands the validation URL to
 * this service, and this service — and nothing else — decides whether that URL may be called.
 *
 * That decision is a server-side request forgery boundary. `validationURL` arrives from the page,
 * and a page can be persuaded to send anything; without the allow-list below, an attacker could
 * use this endpoint to make the site POST its merchant identity certificate to a host of their
 * choosing. Apple's own guidance is a strict allow list, and this is it.
 */
class ApplePay extends Component
{
    /**
     * The hosts Apple documents for merchant validation, global and China, production and sandbox.
     *
     * The pattern is deliberately a shade wider than the four literal names: Apple warns that "the
     * URL you receive can vary", and has historically issued regional pod hostnames of the form
     * `apple-pay-gateway-nc-pod1.apple.com`. It can still only ever resolve to an `apple.com` host
     * carrying Apple's own gateway prefix.
     */
    public const HOST_PATTERN = '/^(cn-)?apple-pay-gateway(-[a-z0-9\-]+)?\.apple\.com$/i';

    public const DOCUMENTED_HOSTS = [
        'apple-pay-gateway.apple.com',
        'cn-apple-pay-gateway.apple.com',
        'apple-pay-gateway-cert.apple.com',
        'cn-apple-pay-gateway-cert.apple.com',
    ];

    public const DOMAIN_ASSOCIATION_PATH = '.well-known/apple-developer-merchantid-domain-association';

    /**
     * Ask Apple for a merchant session.
     *
     * @param string $validationUrl The URL from the browser's `onvalidatemerchant` event.
     * @return array The opaque merchant session, to be handed straight back to the browser.
     */
    public function createMerchantSession(string $validationUrl): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $this->assertValidationUrlIsApple($validationUrl);

        $merchantId = $settings->getApplePayMerchantId();
        $certPath = $settings->getApplePayCertPath();
        $keyPath = $settings->getApplePayKeyPath();

        if (!$merchantId || !$certPath) {
            throw new InvalidConfigException('Apple Pay is not configured: a merchant ID and identity certificate are both required.');
        }

        if (!is_readable($certPath)) {
            throw new InvalidConfigException("The Apple Pay merchant identity certificate at {$certPath} can’t be read.");
        }

        if ($keyPath && !is_readable($keyPath)) {
            throw new InvalidConfigException("The Apple Pay merchant identity key at {$keyPath} can’t be read.");
        }

        $body = [
            'merchantIdentifier' => $merchantId,
            // Apple caps this at 64 UTF-8 characters and shows it on the Touch Bar; a longer name
            // is rejected outright rather than truncated.
            'displayName' => mb_substr($settings->getMerchantName(), 0, 64),
            'initiative' => 'web',
            'initiativeContext' => $settings->getInitiativeContext(),
        ];

        $password = $settings->getApplePayKeyPassword();

        $options = [
            'json' => $body,
            'timeout' => 15,
            'cert' => $password && !$keyPath ? [$certPath, $password] : $certPath,
        ];

        if ($keyPath) {
            $options['ssl_key'] = $password ? [$keyPath, $password] : $keyPath;
        }

        $started = microtime(true);

        try {
            $response = Craft::createGuzzleClient()->request('POST', $validationUrl, $options);
            $session = json_decode((string)$response->getBody(), true);
        } catch (GuzzleException $e) {
            Plugin::getInstance()->getLog()->write('apple-pay-validate', [
                'level' => LogEntry::LEVEL_ERROR,
                'wallet' => 'applePay',
                'summary' => 'Merchant validation failed.',
                'message' => $e->getMessage(),
                'request' => json_encode($body),
                'durationMs' => (int)((microtime(true) - $started) * 1000),
            ]);

            throw $e;
        }

        if (!is_array($session)) {
            throw new \RuntimeException('Apple returned a merchant session that could not be read.');
        }

        Plugin::getInstance()->getLog()->write('apple-pay-validate', [
            'wallet' => 'applePay',
            'summary' => 'Merchant session created.',
            'request' => json_encode($body),
            'durationMs' => (int)((microtime(true) - $started) * 1000),
        ]);

        return $session;
    }

    /**
     * @throws BadRequestHttpException if the URL is not one of Apple's.
     */
    public function assertValidationUrlIsApple(string $validationUrl): void
    {
        $parts = parse_url($validationUrl);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || !preg_match(self::HOST_PATTERN, $parts['host'])
        ) {
            Plugin::getInstance()->getLog()->write('apple-pay-validate', [
                'level' => LogEntry::LEVEL_ERROR,
                'wallet' => 'applePay',
                'summary' => 'Refused a merchant validation URL that is not Apple’s.',
                'message' => $validationUrl,
            ]);

            throw new BadRequestHttpException('Invalid Apple Pay validation URL.');
        }
    }

    /**
     * The contents of the domain association file Apple looks for, or null if none is configured.
     */
    public function getDomainAssociation(): ?string
    {
        return Plugin::getInstance()->getSettings()->getApplePayDomainAssociation();
    }

    /**
     * Whether the merchant identity certificate is present and readable — the check the
     * diagnostics screen runs, because the failure it catches is otherwise invisible until a real
     * customer taps the button.
     *
     * @return string[] Problems, empty if all is well.
     */
    public function getProblems(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $problems = [];

        if (!$settings->getApplePayMerchantId()) {
            $problems[] = Craft::t('coinpurse', 'No Apple Pay merchant ID is set.');
        }

        $certPath = $settings->getApplePayCertPath();

        if (!$certPath) {
            $problems[] = Craft::t('coinpurse', 'No Apple Pay merchant identity certificate is set.');
        } elseif (!is_readable($certPath)) {
            $problems[] = Craft::t('coinpurse', 'The Apple Pay merchant identity certificate can’t be read at {path}.', ['path' => $certPath]);
        }

        $keyPath = $settings->getApplePayKeyPath();

        if ($keyPath && !is_readable($keyPath)) {
            $problems[] = Craft::t('coinpurse', 'The Apple Pay merchant identity key can’t be read at {path}.', ['path' => $keyPath]);
        }

        if (!$settings->getApplePayDomainAssociation()) {
            $problems[] = Craft::t('coinpurse', 'No domain association file is set, so Apple can’t verify this domain.');
        }

        return $problems;
    }
}
