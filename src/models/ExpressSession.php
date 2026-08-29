<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;

/**
 * One press of a wallet button, from the moment the sheet opens to the moment the order is
 * complete or abandoned.
 *
 * The browser holds only `uid`. It never sees an order id, an order number before the order is
 * paid for, or the payload's signature secret.
 */
class ExpressSession extends Model
{
    public const STATE_OPEN = 'open';
    public const STATE_PAYING = 'paying';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public ?int $id = null;
    public string $mode = ButtonOptions::MODE_ITEMS;
    public string $driver = '';
    public ?int $orderId = null;
    public ?int $siteId = null;
    public ?int $gatewayId = null;
    public string $state = self::STATE_OPEN;
    public string $payloadHash = '';
    public array $payload = [];
    public array $snapshot = [];
    public ?int $transactionId = null;
    public ?string $orderNumber = null;
    public ?string $lastError = null;
    public mixed $dateCreated = null;
    public ?string $uid = null;

    private ?Order $_order = null;

    private ?ButtonOptions $_options = null;

    public function getOrder(): ?Order
    {
        if ($this->_order !== null) {
            return $this->_order;
        }

        if (!$this->orderId) {
            return null;
        }

        return $this->_order = Commerce::getInstance()->getOrders()->getOrderById($this->orderId);
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
        $this->orderId = $order?->id;
    }

    /**
     * The button configuration this session was opened with, rebuilt from the stored payload.
     */
    public function getOptions(): ButtonOptions
    {
        if ($this->_options !== null) {
            return $this->_options;
        }

        $payload = $this->payload;

        return $this->_options = new ButtonOptions([
            'mode' => $payload['mode'] ?? ButtonOptions::MODE_ITEMS,
            'items' => $payload['items'] ?? [],
            'requestShipping' => $payload['requestShipping'] ?? false,
            'requestDetails' => $payload['requestDetails'] ?? [],
            'siteId' => $payload['siteId'] ?? null,
            'gatewayId' => $payload['gatewayId'] ?? null,
            'expires' => (int)($payload['expires'] ?? 0),
        ]);
    }

    /**
     * Whether this session may still be worked on. A paid session is finished; a cancelled one has
     * had its snapshot restored and must not touch the cart again.
     */
    public function isLive(): bool
    {
        return in_array($this->state, [self::STATE_OPEN, self::STATE_PAYING], true);
    }

    public function isPaid(): bool
    {
        return $this->state === self::STATE_PAID;
    }
}
