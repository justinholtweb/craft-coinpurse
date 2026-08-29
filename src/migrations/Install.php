<?php

namespace justinholtweb\coinpurse\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\coinpurse\db\Table;

/**
 * Coin Purse's schema.
 *
 * Both tables are diagnostic/transient. Nothing a merchant needs long term lives here — orders and
 * transactions stay where Commerce puts them — so uninstalling drops both without ceremony.
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
                'orderNumber' => $this->string(32),
                'lastError' => $this->text(),
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
    }
}
