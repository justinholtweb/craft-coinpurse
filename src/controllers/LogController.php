<?php

namespace justinholtweb\coinpurse\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\coinpurse\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('coinpurse-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        // Not `action`: that query-string name is how Craft routes an action request, so a filter
        // called `action` would send the browser somewhere else entirely.
        $criteria = [
            'action' => $this->request->getQueryParam('kind'),
            'level' => $this->request->getQueryParam('level'),
        ];

        return $this->renderTemplate('coinpurse/log/_index', [
            'entries' => Plugin::getInstance()->getLog()->getEntries(array_filter($criteria)),
            'criteria' => $criteria,
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($id);

        if (!$entry) {
            throw new NotFoundHttpException('Log entry not found.');
        }

        return $this->renderTemplate('coinpurse/log/_detail', ['entry' => $entry]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('coinpurse-manage');

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->setSuccessFlash(Craft::t('coinpurse', '{count} log entries deleted.', ['count' => $deleted]));

        return $this->redirect('coinpurse/log');
    }
}
