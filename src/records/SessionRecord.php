<?php

namespace justinholtweb\coinpurse\records;

use craft\db\ActiveRecord;
use justinholtweb\coinpurse\db\Table;

/**
 * An express checkout session: one press of a wallet button, from the moment the sheet opens to
 * the moment the order is complete or abandoned.
 *
 * @property int $id
 * @property string $mode
 * @property string $driver
 * @property int|null $orderId
 * @property int|null $siteId
 * @property int|null $gatewayId
 * @property string $state
 * @property string $payloadHash
 * @property string|null $payload
 * @property string|null $snapshot
 * @property int|null $transactionId
 * @property string|null $orderNumber
 * @property string|null $lastError
 * @property string $uid
 */
class SessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SESSIONS;
    }
}
