<?php
    
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;
    
    return new class extends Migration
    {
        public function up(): void
        {
            DB::statement("
            UPDATE event_comments
            SET authorable_type = created_by_class,
                authorable_id = CAST(NULLIF(created_by_id, '') AS UNSIGNED)
            WHERE authorable_type IS NULL
        ");
            
            DB::statement("
            UPDATE event_comments
            SET status = CASE
                WHEN is_approved = 1 THEN 'visible'
                ELSE 'hidden'
            END
            WHERE status IS NULL OR status = ''
        ");
        }
        
        public function down(): void
        {
            //
        }
    };
