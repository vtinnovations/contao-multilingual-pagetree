<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Schema;

use Vtinnovations\ContaoMultilingualPagetree\Storage\DatabaseRequestLedger;

/**
 * Authoritative schema contract for bundle-owned tables and named indexes.
 *
 * DCA schema declarations, Doctrine schema generation and legacy repair
 * migrations all consume these definitions so their column order, names and
 * uniqueness cannot drift independently.
 */
final class BundleSchema
{
    /**
     * @var array<string, array{sql: string, type: string, options: array<string, mixed>}>
     */
    public const LEDGER_COLUMNS = [
        'request_id' => ['sql' => 'varchar(64) NOT NULL', 'type' => 'string', 'options' => ['length' => 64, 'notnull' => true]],
        'nonce_digest' => ['sql' => 'char(64) NOT NULL', 'type' => 'string', 'options' => ['length' => 64, 'fixed' => true, 'notnull' => true]],
        'fingerprint' => ['sql' => 'char(64) NOT NULL', 'type' => 'string', 'options' => ['length' => 64, 'fixed' => true, 'notnull' => true]],
        'result' => ['sql' => 'varchar(16) NOT NULL', 'type' => 'string', 'options' => ['length' => 16, 'notnull' => true]],
        'document_version' => ['sql' => 'int DEFAULT NULL', 'type' => 'integer', 'options' => ['notnull' => false]],
        'claimed_at' => ['sql' => 'int NOT NULL', 'type' => 'integer', 'options' => ['notnull' => true]],
        'completed_at' => ['sql' => 'int DEFAULT NULL', 'type' => 'integer', 'options' => ['notnull' => false]],
    ];

    /** @var list<string> */
    public const LEDGER_PRIMARY_KEY = ['request_id'];

    /**
     * @var list<array{table: string, name: string, columns: list<string>, unique: bool}>
     */
    public const LEDGER_INDEXES = [
        ['table' => DatabaseRequestLedger::TABLE, 'name' => 'uniq_cmp_channel_nonce', 'columns' => ['nonce_digest'], 'unique' => true],
        ['table' => DatabaseRequestLedger::TABLE, 'name' => 'idx_cmp_channel_claimed', 'columns' => ['claimed_at'], 'unique' => false],
    ];

    /**
     * @var list<array{table: string, name: string, columns: list<string>, unique: bool}>
     */
    public const INTEGRITY_INDEXES = [
        ['table' => 'tl_article', 'name' => 'clfmp_owner', 'columns' => ['cmpLanguage', 'cmpLanguageRoot'], 'unique' => false],
        ['table' => 'tl_article_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_article_translation', 'name' => 'clfmp_review', 'columns' => ['reviewStatus'], 'unique' => false],
        ['table' => 'tl_calendar_events_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_calendar_events_translation', 'name' => 'clfmp_review', 'columns' => ['reviewStatus'], 'unique' => false],
        ['table' => 'tl_content', 'name' => 'clfmp_owner', 'columns' => ['cmpLanguage', 'cmpLanguageRoot'], 'unique' => false],
        ['table' => 'tl_content_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_faq_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_faq_translation', 'name' => 'clfmp_review', 'columns' => ['reviewStatus'], 'unique' => false],
        ['table' => 'tl_inline_language', 'name' => 'clfmp_root_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        // The language URL mapping is looked up by root and by exact hostname;
        // both lookups happen on every frontend request of a configured site.
        ['table' => 'tl_inline_language', 'name' => 'clfmp_lang_url', 'columns' => ['pid', 'urlDomain', 'urlEntryPoint'], 'unique' => false],
        ['table' => 'tl_inline_language', 'name' => 'clfmp_lang_host', 'columns' => ['urlDomain'], 'unique' => false],
        ['table' => 'tl_news_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_news_translation', 'name' => 'clfmp_review', 'columns' => ['reviewStatus'], 'unique' => false],
        ['table' => 'tl_page_translation', 'name' => 'clfmp_pid_lang', 'columns' => ['pid', 'language'], 'unique' => false],
        ['table' => 'tl_page_translation', 'name' => 'clfmp_review', 'columns' => ['reviewStatus'], 'unique' => false],
    ];

    /**
     * @return list<array{table: string, name: string, columns: list<string>, unique: bool}>
     */
    public static function namedIndexes(): array
    {
        return [...self::LEDGER_INDEXES, ...self::INTEGRITY_INDEXES];
    }

    public static function createLedgerSql(): string
    {
        $columns = [];

        foreach (self::LEDGER_COLUMNS as $name => $definition) {
            $columns[] = $name.' '.$definition['sql'];
        }

        $columns[] = 'PRIMARY KEY ('.implode(', ', self::LEDGER_PRIMARY_KEY).')';

        foreach (self::LEDGER_INDEXES as $index) {
            $columns[] = ($index['unique'] ? 'UNIQUE INDEX ' : 'INDEX ').$index['name'].' ('.implode(', ', $index['columns']).')';
        }

        return 'CREATE TABLE IF NOT EXISTS '.DatabaseRequestLedger::TABLE.' ('.implode(', ', $columns).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
