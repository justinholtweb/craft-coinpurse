/**
 * Coin Purse's button runtime, as an ES module, for a site that bundles its own JavaScript.
 *
 *     import CoinPurse from './vendor/coinpurse.mjs';
 *     CoinPurse.start();   // mounts every button from its data-coinpurse-config
 *
 * Copy this file and coinpurse.js side by side. This is a wrapper rather than a second copy of the
 * runtime: coinpurse.js still sets `window.CoinPurse`, and a page that also has the injected
 * runtime ends up with one instance, not two.
 */
import './coinpurse.js';

export default window.CoinPurse;
