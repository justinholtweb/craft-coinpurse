<?php

namespace justinholtweb\coinpurse\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\coinpurse\db\Table;
use justinholtweb\coinpurse\services\ExpressOrders;

/**
 * Adds the permanent express-order record behind the order index column and condition rule, and
 * the session column the wallet is noted in.
 *
 * Paid session rows still on disk are copied across with the wallet `unknown` — before this
 * release nobody wrote the wallet down. Older express orders whose sessions were already purged
 * cannot be recovered and stay unmarked.
 */
class m261009_000000_express_orders extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::SESSIONS, 'wallet')) {
            $this->addColumn(Table::SESSIONS, 'wallet', $this->string(32)->after('transactionId'));
        }

        if ($this->db->tableExists(Table::EXPRESS_ORDERS)) {
            return true;
        }

        $this->createTable(Table::EXPRESS_ORDERS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'wallet' => $this->string(32)->notNull(),
            'driver' => $this->string(64),
            'sessionUid' => $this->string(36),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::EXPRESS_ORDERS, ['orderId'], true);
        $this->createIndex(null, Table::EXPRESS_ORDERS, ['wallet']);
        $this->addForeignKey(null, Table::EXPRESS_ORDERS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $paid = (new Query())
            ->select(['s.orderId', 's.driver', 's.uid'])
            ->from(['s' => Table::SESSIONS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[s.orderId]]')
            ->where(['s.state' => 'paid'])
            ->andWhere(['not', ['s.orderId' => null]])
            ->all($this->db);

        $now = Db::prepareDateForDb(new \DateTime());
        $seen = [];

        foreach ($paid as $row) {
            $orderId = (int)$row['orderId'];

            if (isset($seen[$orderId])) {
                continue;
            }

            $seen[$orderId] = true;

            $this->insert(Table::EXPRESS_ORDERS, [
                'orderId' => $orderId,
                'wallet' => ExpressOrders::UNKNOWN,
                'driver' => $row['driver'],
                'sessionUid' => $row['uid'],
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::EXPRESS_ORDERS);

        if ($this->db->columnExists(Table::SESSIONS, 'wallet')) {
            $this->dropColumn(Table::SESSIONS, 'wallet');
        }

        return true;
    }
}
