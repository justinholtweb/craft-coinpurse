<?php

namespace justinholtweb\coinpurse\helpers;

use Craft;

/**
 * Signing for button payloads.
 *
 * The express endpoints are anonymous by necessity — the customer has not logged in and may never
 * — so the only thing standing between them and an attacker is that the payload has to be one this
 * site actually rendered. `Security::hashData()` is Craft's own keyed MAC over the app's security
 * key; it prefixes the hash to the data and `validateData()` returns the data or `false`, in
 * constant time.
 */
abstract class Signature
{
    /**
     * Sign a canonical payload array. Returns the opaque string the browser holds.
     */
    public static function sign(array $payload): string
    {
        return Craft::$app->getSecurity()->hashData(self::encode($payload));
    }

    /**
     * Verify a signed string and return the payload, or null if it has been altered.
     */
    public static function verify(string $signed): ?array
    {
        $data = Craft::$app->getSecurity()->validateData($signed);

        if ($data === false) {
            return null;
        }

        $decoded = json_decode(base64_decode($data, true) ?: '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A stable fingerprint of a payload, for the session row. Two sessions opened from the same
     * button share it; a session may only ever charge for the payload it was opened with.
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    private static function encode(array $payload): string
    {
        // JSON_UNESCAPED_SLASHES keeps the encoding stable across PHP builds that differ on
        // whether they escape a forward slash; the MAC is over bytes, so it matters.
        return base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
