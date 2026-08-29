<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?string $driver = null;
    public ?string $wallet = null;
    public ?string $sessionUid = null;
    public ?string $orderNumber = null;
    public ?int $durationMs = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public DateTime|string|null $dateCreated = null;
    public ?string $uid = null;

    public function init(): void
    {
        parent::init();

        if (is_string($this->dateCreated)) {
            $this->dateCreated = DateTimeHelper::toDateTime($this->dateCreated) ?: null;
        }
    }

    public function getLevelClass(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'error',
            self::LEVEL_WARNING => 'warning',
            default => 'success',
        };
    }
}
