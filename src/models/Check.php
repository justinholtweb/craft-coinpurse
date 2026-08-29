<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * One diagnostic finding.
 *
 * Deliberately remediation-first: `fix` is the field that matters, because a wallet button that
 * does not appear gives the merchant nothing at all to search for.
 */
class Check extends Model
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public string $id = '';
    public string $label = '';
    public string $status = self::PASS;
    public string $detail = '';
    public string $fix = '';

    public function isFail(): bool
    {
        return $this->status === self::FAIL;
    }

    public function isWarn(): bool
    {
        return $this->status === self::WARN;
    }
}
