<?php

use Database\Seeders\IcdStarterSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ICD-11 MMS classification (global reference data, shared by all tenants)
 * and the code system recorded on each diagnosis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icd_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title', 500);
            $table->string('chapter', 4)->nullable();
            $table->string('system', 10)->default('icd11');
            $table->boolean('is_leaf')->default(true);
            $table->boolean('is_common')->default(false); // shown first in search (Kenyan OPD/IPD burden)
            $table->timestamps();
            $table->index('title');
        });

        Schema::table('diagnoses', function (Blueprint $table) {
            $table->string('code_system', 10)->nullable()->after('code'); // icd11, icd10, free
        });

        // Reference data: the starter list is always present (full list via hms:import-icd11).
        (new IcdStarterSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('diagnoses', fn (Blueprint $t) => $t->dropColumn('code_system'));
        Schema::dropIfExists('icd_codes');
    }
};
