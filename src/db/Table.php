<?php

namespace justinholtweb\coinpurse\db;

/**
 * Coin Purse's database tables.
 */
abstract class Table
{
    public const SESSIONS = '{{%coinpurse_sessions}}';
    public const LOG = '{{%coinpurse_log}}';
}
