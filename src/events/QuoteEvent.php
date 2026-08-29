<?php

namespace justinholtweb\coinpurse\events;

use craft\commerce\elements\Order;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Quote;
use yii\base\Event;

/**
 * Raised once the quote is built and before any wallet sees it.
 *
 * Handlers may relabel or reorder lines, drop shipping options, or set `shippingError` to tell the
 * wallet this address cannot be served. They must **not** change `total`: the total is the order's,
 * and a quote that disagrees with the order it came from is the one bug this plugin is built to
 * make impossible.
 */
class QuoteEvent extends Event
{
    public Quote $quote;
    public Order $order;
    public ExpressSession $session;
}
