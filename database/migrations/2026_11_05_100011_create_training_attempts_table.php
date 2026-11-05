<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each quiz attempt in the training centre: evidence that users were
        // trained before using the system (DHA certification / ODPC).
        Schema::create('training_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 60);
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('total');
            $table->unsignedTinyInteger('percent');
            $table->boolean('passed');
            $table->json('answers');
            $table->timestamps();

            $table->index(['tenant_id', 'module', 'passed']);
            $table->index(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attempts');
    }
};
