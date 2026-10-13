<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lead forms for service businesses: the contact's e-mail is optional (the phone is what the
 * team works with), and the value, source and owner appear in the quick-create card.
 * Both can be changed again in Configurações → Campos Personalizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attributes')
            ->where('entity_type', 'persons')
            ->where('code', 'emails')
            ->update(['is_required' => 0]);

        DB::table('attributes')
            ->where('entity_type', 'leads')
            ->whereIn('code', ['lead_value', 'lead_source_id', 'user_id'])
            ->update(['quick_add' => 1]);
    }

    public function down(): void
    {
        // Left as it is: restoring would override choices made in the settings screen since.
    }
};
