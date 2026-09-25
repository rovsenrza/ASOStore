<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level immutability (IMPLEMENTATION_PLAN §5.3):
     *  - audit_logs rows can never be updated or deleted;
     *  - app_artifacts rows can never be deleted, and their file-identity and
     *    declaration columns can never change.
     *
     * Only created on MySQL. If the host forbids triggers, set
     * STOREFRONT_DB_IMMUTABILITY_TRIGGERS=false and apply the documented fallback.
     */
    public function up(): void
    {
        if (! $this->enabled()) {
            return;
        }

        DB::unprepared("CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");

        DB::unprepared("CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");

        DB::unprepared("CREATE TRIGGER app_artifacts_block_delete BEFORE DELETE ON app_artifacts FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'app_artifacts rows are immutable'");

        DB::unprepared("CREATE TRIGGER app_artifacts_protect_identity BEFORE UPDATE ON app_artifacts FOR EACH ROW
            BEGIN
                IF NOT (NEW.public_id <=> OLD.public_id)
                    OR NOT (NEW.app_id <=> OLD.app_id)
                    OR NOT (NEW.sha256 <=> OLD.sha256)
                    OR NOT (NEW.size_bytes <=> OLD.size_bytes)
                    OR NOT (NEW.storage_disk <=> OLD.storage_disk)
                    OR NOT (NEW.storage_path <=> OLD.storage_path)
                    OR NOT (NEW.original_filename <=> OLD.original_filename)
                    OR NOT (NEW.source_type <=> OLD.source_type)
                    OR NOT (NEW.uploaded_by <=> OLD.uploaded_by)
                    OR NOT (NEW.declaration_version <=> OLD.declaration_version)
                    OR NOT (NEW.declaration_accepted_at <=> OLD.declaration_accepted_at)
                    OR NOT (NEW.declaration_ip <=> OLD.declaration_ip)
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'app_artifacts identity columns are immutable';
                END IF;
            END");
    }

    public function down(): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach (['audit_logs_block_update', 'audit_logs_block_delete', 'app_artifacts_block_delete', 'app_artifacts_protect_identity'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function enabled(): bool
    {
        return DB::connection()->getDriverName() === 'mysql'
            && config('storefront.db_immutability_triggers');
    }
};
