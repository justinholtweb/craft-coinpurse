<?php

namespace justinholtweb\coinpurse\helpers;

use Craft;
use craft\commerce\errors\PaymentException;
use yii\base\InvalidArgumentException;

/**
 * What the anonymous express endpoints need that a signed payload does not give them.
 *
 * The payload proves the button was rendered by this site, but it sits in the page's HTML, so
 * anyone can replay it — and every `express/start` saves an order. And the errors these endpoints
 * catch come from Commerce, the gateway, Guzzle and the file system, not all of which were written
 * for a stranger to read.
 */
abstract class Guard
{
    /**
     * Whether this address may make another request to `$bucket` this minute.
     *
     * The count is read and written under a mutex: without it, requests sent in parallel all read
     * the same number and none is ever refused. If the lock is busy the request is refused rather
     * than queued — a wallet sheet can retry, a worker held up waiting cannot serve anyone.
     */
    public static function withinRateLimit(string $bucket, int $perMinute): bool
    {
        $key = sprintf('coinpurse:rate:%s:%s:%d', $bucket, sha1((string)Craft::$app->getRequest()->getUserIP()), intdiv(time(), 60));
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 2)) {
            return false;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= $perMinute) {
                return false;
            }

            $cache->set($key, $count + 1, 120);

            return true;
        } finally {
            $mutex->release($key);
        }
    }

    /**
     * The message a customer may see for an exception.
     *
     * Commerce's `PaymentException` carries sentences written for the customer — a declined card, a
     * total that moved — and the session service's own refusals are plain enough to show too.
     * Anything else (a certificate path, a Guzzle error with a URL in it, a database message) is
     * replaced, and the detail only shown in devMode; callers log the real message.
     */
    public static function customerMessage(\Throwable $e): string
    {
        if ($e instanceof PaymentException || $e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return $e->getMessage();
        }

        return Craft::t('coinpurse', 'Something went wrong. Nothing has been charged — please try again.');
    }
}
