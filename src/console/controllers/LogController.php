<?php

namespace justinholtweb\coinpurse\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\coinpurse\Plugin;
use yii\console\ExitCode;

/**
 * Log housekeeping. Run as `craft coinpurse/log/prune`.
 */
class LogController extends Controller
{
    /** @var int|null Override the configured retention, in days. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'prune' ? ['days'] : []);
    }

    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("Deleted {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionClear(): int
    {
        if (!$this->confirm('Delete every Coin Purse log entry?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Deleted {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
