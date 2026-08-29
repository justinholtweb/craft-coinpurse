<?php

namespace justinholtweb\coinpurse\web\assets\express;

use craft\web\AssetBundle;

/**
 * The express checkout runtime.
 *
 * No build step, in keeping with the rest of the family: `coinpurse.js` is a plain script and
 * `coinpurse.css` is plain CSS, both readable in a browser's sources panel by whoever has to debug
 * a payment three years from now.
 *
 * Nothing third-party is bundled. Stripe.js must be loaded from Stripe's own domain (they require
 * it, and PCI SAQ-A depends on it), and Google's `pay.js` likewise; both are fetched on demand by
 * the driver that needs them, so a store on Stripe never loads Google's script and a store that
 * renders no button loads neither.
 */
class ExpressAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $css = ['coinpurse.css'];

    public $js = ['coinpurse.js'];
}
