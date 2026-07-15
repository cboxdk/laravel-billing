<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\DatabaseLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-LEVEL append-only hardening for `billing_ledger_lines` (task #32).
 *
 * The package already guarantees immutability at the layer it fully controls: the
 * {@see Ledger} contract exposes no update or delete
 * of posted lines, and {@see DatabaseLedger} only ever inserts.
 * This migration adds defence in depth *below* the ORM, so a stray raw `UPDATE`/
 * `DELETE` (a console one-liner, a compromised query, a well-meaning data fix) is
 * refused by the database itself — corrections must be new reversing transactions.
 *
 * PORTABILITY: trigger-based immutability is NOT portable. This recipe targets
 * **MySQL/MariaDB** (BEFORE UPDATE / BEFORE DELETE triggers that `SIGNAL`). On any
 * other driver — including the sqlite used by the test suite — it is a documented
 * NO-OP, and the app-level guarantee (proven by the ledger's Pest tests) stands
 * alone. For PostgreSQL, the equivalent is a `BEFORE UPDATE OR DELETE` trigger
 * calling a `plpgsql` function that `RAISE EXCEPTION`s, or revoking UPDATE/DELETE
 * grants from the application role:
 *
 *     REVOKE UPDATE, DELETE ON billing_ledger_lines FROM billing_app;
 *
 * Grant-revocation is the strongest option where you control the role; the triggers
 * below are the portable-within-MySQL default that needs no role management.
 */
return new class extends Migration
{
    private const TABLE = 'billing_ledger_lines';

    public function up(): void
    {
        if (! $this->onMysql()) {
            return; // documented no-op on sqlite/postgres — see class docblock
        }

        $table = self::TABLE;

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_no_update
            BEFORE UPDATE ON {$table}
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'billing_ledger_lines is append-only: rows cannot be updated (post a reversing transaction instead).';
        SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_no_delete
            BEFORE DELETE ON {$table}
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'billing_ledger_lines is append-only: rows cannot be deleted (post a reversing transaction instead).';
        SQL);
    }

    public function down(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        $table = self::TABLE;

        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
    }

    private function onMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
