<?php

namespace justinholtweb\coinpurse\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\coinpurse\db\Table;

/**
 * Coin Purse's schema.
 *
 * Sessions and the log are diagnostic/transient. The one long-lived table, `coinpurse_orders`,
 * only says which orders were placed through express checkout and with which wallet; orders and
 * transactions stay where Commerce puts them, so uninstalling drops all three without ceremony.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::EXPRESS_ORDERS);
        $this->dropTableIfExists(Table::SESSIONS);
        $this->dropTableIfExists(Table::LOG);

        return true;
    }

    private function createTables(): void
    {
        if (!$this->db->tableExists(Table::SESSIONS)) {
            $this->createTable(Table::SESSIONS, [
                'id' => $this->primaryKey(),
                // 'cart' (the live session cart) or 'items' (a detached buy-now order).
                'mode' => $this->string(16)->notNull(),
                'driver' => $this->string(64)->notNull(),
                'orderId' => $this->integer(),
                'siteId' => $this->integer(),
                'gatewayId' => $this->integer(),
                // open | paying | paid | failed | cancelled
                'state' => $this->string(16)->notNull()->defaultValue('open'),
                // The hash of the signed button payload this session was opened with. A session is
                // only ever allowed to charge for the payload it was opened with.
                'payloadHash' => $this->string(64)->notNull(),
                'payload' => $this->text(),
                // The cart's pre-flight address / shipping method / email, restored on cancel.
                'snapshot' => $this->text(),
                'transactionId' => $this->integer(),
                // The wallet the customer paid with, as the browser reported it at `express/pay`.
                'wallet' => $this->string(32),
                'orderNumber' => $this->string(32),
                'lastError' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::EXPRESS_ORDERS)) {
            // One row per completed express order. Outlives the session row that produced it.
            $this->createTable(Table::EXPRESS_ORDERS, [
                'id' => $this->primaryKey(),
                'orderId' => $this->integer()->notNull(),
                // A wallet handle (`applePay`, `googlePay`…), or `unknown`.
                'wallet' => $this->string(32)->notNull(),
                'driver' => $this->string(64),
                'sessionUid' => $this->string(36),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        if (!$this->db->tableExists(Table::LOG)) {
            $this->createTable(Table::LOG, [
                'id' => $this->primaryKey(),
                'action' => $this->string(64)->notNull(),
                'level' => $this->string(16)->notNull()->defaultValue('info'),
                'driver' => $this->string(64),
                'wallet' => $this->string(32),
                'sessionUid' => $this->string(36),
                'orderNumber' => $this->string(32),
                'durationMs' => $this->integer(),
                'ip' => $this->string(45),
                'userAgent' => $this->string(255),
                'summary' => $this->string(255),
                'message' => $this->text(),
                'request' => $this->mediumText(),
                'response' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::SESSIONS, ['uid'], true);
        $this->createIndex(null, Table::SESSIONS, ['state', 'dateCreated']);
        $this->createIndex(null, Table::SESSIONS, ['orderId']);
        $this->createIndex(null, Table::EXPRESS_ORDERS, ['orderId'], true);
        $this->createIndex(null, Table::EXPRESS_ORDERS, ['wallet']);
        $this->createIndex(null, Table::LOG, ['dateCreated']);
        $this->createIndex(null, Table::LOG, ['action']);
        $this->createIndex(null, Table::LOG, ['sessionUid']);
    }

    private function addForeignKeys(): void
    {
        // Orders are elements; when one is hard-deleted the session row that produced it is
        // meaningless, so it goes too.
        $this->addForeignKey(null, Table::SESSIONS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SESSIONS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        // The express record goes when the order is hard-deleted, and not before.
        $this->addForeignKey(null, Table::EXPRESS_ORDERS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
