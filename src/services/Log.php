<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\coinpurse\db\Table;
use justinholtweb\coinpurse\models\LogEntry;
use justinholtweb\coinpurse\Plugin;

/**
 * The express checkout log.
 *
 * Wallet buttons fail in the least debuggable way there is: they do not appear. There is no error
 * on the page, nothing in the console, and the merchant's own phone shows the button perfectly
 * because their phone has a card in it and the customer's did not. "It didn't work on my phone" is
 * unanswerable without this table.
 */
class Log extends Component
{
    /** Bodies longer than this are truncated. Nobody reads past the first screen of a payload. */
    public const MAX_PAYLOAD = 65535;

    /**
     * @param array{
     *     level?: string,
     *     driver?: string|null,
     *     wallet?: string|null,
     *     sessionUid?: string|null,
     *     orderNumber?: string|null,
     *     durationMs?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'driver' => $data['driver'] ?? null,
                'wallet' => $data['wallet'] ?? null,
                'sessionUid' => $data['sessionUid'] ?? null,
                'orderNumber' => $data['orderNumber'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'ip' => $this->clientIp(),
                'userAgent' => $this->userAgent(),
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                // Payloads carry the customer's address and contact details, so they are off by
                // default and the retention sweep is the only thing that keeps them bounded.
                'request' => $settings->logPayloads ? $this->truncate($data['request'] ?? null) : null,
                'response' => $settings->logPayloads ? $this->truncate($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write it must not take down the
            // payment it was describing.
            Craft::warning('Coin Purse could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100): array
    {
        $query = (new Query())
            ->select([
                'id', 'action', 'level', 'driver', 'wallet', 'sessionUid', 'orderNumber',
                'durationMs', 'ip', 'userAgent', 'summary', 'message', 'dateCreated', 'uid',
            ])
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['sessionUid'])) {
            $query->andWhere(['sessionUid' => $criteria['sessionUid']]);
        }

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        // Explicit columns, not `SELECT *`: the table carries `dateUpdated`, which the model has
        // no property for, and Yii throws on an unknown property rather than ignoring it.
        $row = (new Query())
            ->select([
                'id', 'action', 'level', 'driver', 'wallet', 'sessionUid', 'orderNumber',
                'durationMs', 'ip', 'userAgent', 'summary', 'message', 'request', 'response',
                'dateCreated', 'uid',
            ])
            ->from([Table::LOG])
            ->where(['id' => $id])
            ->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * @return array<string, int> Action => count, over the last `$days` days.
     */
    public function getSummary(int $days = 7): array
    {
        $cutoff = (new DateTime())->modify("-{$days} days");

        $rows = (new Query())
            ->select(['action', 'level', 'count' => 'COUNT(*)'])
            ->from([Table::LOG])
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->groupBy(['action', 'level'])
            ->all();

        $summary = [];

        foreach ($rows as $row) {
            $key = $row['action'] . ':' . $row['level'];
            // COUNT(*) comes back as a string from PDO on MySQL; casting once here keeps every
            // caller from having to remember that.
            $summary[$key] = (int)$row['count'];
        }

        return $summary;
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->getEffectiveLogRetentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    // ---------------------------------------------------------------- Internals

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > self::MAX_PAYLOAD
            ? mb_substr($value, 0, self::MAX_PAYLOAD) . '… [truncated]'
            : $value;
    }

    private function clientIp(): ?string
    {
        $request = Craft::$app->getRequest();

        // `craft\console\Request` has no client to ask, and the console commands write here too.
        return $request->getIsConsoleRequest() ? null : $request->getUserIP();
    }

    private function userAgent(): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        $agent = $request->getUserAgent();

        return $agent ? mb_substr($agent, 0, 255) : null;
    }
}
