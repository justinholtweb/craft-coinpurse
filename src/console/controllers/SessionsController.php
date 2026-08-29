<?php

namespace justinholtweb\coinpurse\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\coinpurse\Plugin;
use yii\console\ExitCode;

/**
 * Express session housekeeping.
 *
 * `reap` is the one that matters: a customer who opens a wallet sheet and then closes the tab
 * never sends a cancel, so their cart is left holding whatever address the sheet wrote to it.
 * Running this on a schedule puts those carts back. `purge` only deletes rows.
 */
class SessionsController extends Controller
{
    /** @var int|null Delete sessions older than this many minutes. */
    public ?int $minutes = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'purge' ? ['minutes'] : []);
    }

    public function actionReap(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce isn’t available.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $reaped = Plugin::getInstance()->getSessions()->reapAbandoned();

        $this->stdout("Restored {$reaped} abandoned express session(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionPurge(): int
    {
        $deleted = Plugin::getInstance()->getSessions()->purge($this->minutes);

        $this->stdout("Deleted {$deleted} express session row(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
