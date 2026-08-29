<?php

namespace justinholtweb\coinpurse\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\coinpurse\models\Check;
use justinholtweb\coinpurse\Plugin;
use yii\console\ExitCode;

/**
 * Why there is no wallet button, from the command line.
 *
 * Run as `craft coinpurse/diagnose`. Exits non-zero when something is actually broken, so it can
 * sit in a deploy script and stop a release that would have shipped a checkout with no button on
 * it — a failure nobody would otherwise notice until the week's sales came in low.
 */
class DiagnoseController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce isn’t installed or enabled.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $checks = Plugin::getInstance()->getDiagnostics()->run();
        $failed = 0;

        foreach ($checks as $check) {
            [$marker, $colour] = match ($check->status) {
                Check::PASS => ['✓', Console::FG_GREEN],
                Check::WARN => ['!', Console::FG_YELLOW],
                default => ['✕', Console::FG_RED],
            };

            if ($check->isFail()) {
                $failed++;
            }

            $this->stdout("{$marker} ", $colour);
            $this->stdout($check->label . ': ', Console::BOLD);
            $this->stdout($check->detail . "\n");

            if ($check->fix) {
                $this->stdout('    → ' . $check->fix . "\n", Console::FG_GREY);
            }
        }

        $this->stdout("\n");

        if ($failed) {
            $this->stderr("{$failed} problem(s) found.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Express checkout is ready.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
