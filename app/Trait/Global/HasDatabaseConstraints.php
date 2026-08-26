<?php

namespace App\Trait\Global;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers for integrity constraints that Laravel's Blueprint cannot express.
 *
 * The application runs on MySQL while the test suite runs on SQLite, and the two
 * disagree on what can be added after CREATE TABLE. These helpers apply the
 * constraint where the driver supports it and stay silent where it does not, so a
 * single migration is valid on both.
 */
trait HasDatabaseConstraints
{
    /**
     * Add a CHECK constraint.
     *
     * SQLite cannot add one through ALTER TABLE, so the constraint is skipped there;
     * the matching PHP enum cast remains the guard in tests.
     */
    protected function addCheck(string $table, string $constraint, string $expression): void
    {
        if (! $this->supportsAlterCheck()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$expression})");
    }

    protected function dropCheck(string $table, string $constraint): void
    {
        if (! $this->supportsAlterCheck() || ! Schema::hasTable($table)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
    }

    /**
     * Constrain a column to a fixed set of values.
     *
     * @param  array<int, string>  $values
     */
    protected function addEnumCheck(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $value) => "'{$value}'", $values));
        $expression = "`{$column}` IN ({$list})";

        if ($nullable) {
            $expression = "`{$column}` IS NULL OR {$expression}";
        }

        $this->addCheck($table, "{$table}_{$column}_check", $expression);
    }

    private function supportsAlterCheck(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
}
