<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_trx_final_state BEFORE UPDATE ON transactions
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('proceed', 'expired') AND NEW.status <> OLD.status THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proceed/expired is final';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_trx_final_state');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_no_update');
    }
};
