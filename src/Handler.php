<?php

namespace perfectwebteam\anonymize;

use yii\db\Connection;

/**
 * Rewrites the rows of tables a plain rule cannot describe, such as a plugin's
 * own content format (Formie's submissions). A preset or a project names its
 * class in `handlers`.
 *
 * prepare() runs in the dump's snapshot before any table streams: look up what
 * row() needs there. While a table streams the connection takes no other query,
 * so row() must not query.
 *
 * row() runs after whatever else rewrites the table: the rules of the project
 * and the presets, and the script's own work on users, assets, elements_sites
 * and the like. It gets the row as they made it, so it can change more or drop
 * the row, but not switch them off. Handlers on the same table run in the order
 * of `handlers`.
 */
interface Handler
{
    /** In the snapshot, before the dump. */
    public function prepare(Plan $plan, Connection $db): void;

    /**
     * @return array<string, list<string>> table without prefix => the columns row() reads; a table this
     * database lacks is skipped
     */
    public function tables(): array;

    /**
     * @param string $table without prefix
     * @param array<string, string|null> $row
     * @return array<string, string|null>|false the columns that change, [] to keep the row, false to drop it
     */
    public function row(string $table, array $row): array|false;
}
