<?php

namespace justinholtweb\coinpurse\events;

use craft\commerce\elements\Order;
use justinholtweb\coinpurse\models\ExpressSession;
use craft\events\CancelableEvent;

/**
 * Raised around an express order — once with the order fully addressed and priced but not yet
 * charged, and again once it is complete.
 *
 * The "before" event is cancellable: set `isValid = false` and `message` to refuse the sale, and
 * the wallet sheet shows the message rather than a generic failure.
 */
class ExpressOrderEvent extends CancelableEvent
{
    public Order $order;
    public ExpressSession $session;
    public ?string $message = null;
}
