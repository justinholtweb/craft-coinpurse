<?php

namespace justinholtweb\coinpurse\records;

use craft\db\ActiveRecord;
use justinholtweb\coinpurse\db\Table;

/**
 * @property int $id
 * @property string $action
 * @property string $level
 * @property string|null $driver
 * @property string|null $wallet
 * @property string|null $sessionUid
 * @property string|null $orderNumber
 * @property int|null $durationMs
 * @property string|null $ip
 * @property string|null $userAgent
 * @property string|null $summary
 * @property string|null $message
 * @property string|null $request
 * @property string|null $response
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
