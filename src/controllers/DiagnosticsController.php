<?php

namespace justinholtweb\coinpurse\controllers;

use craft\web\Controller;
use justinholtweb\coinpurse\Plugin;
use yii\web\Response;

class DiagnosticsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('coinpurse-manage');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('coinpurse/diagnostics/_index', [
            'plugin' => $plugin,
            'checks' => $plugin->getDiagnostics()->run(),
            'summary' => Plugin::commerceIsReady() ? $plugin->getLog()->getSummary() : [],
            'commerceReady' => Plugin::commerceIsReady(),
        ]);
    }
}
