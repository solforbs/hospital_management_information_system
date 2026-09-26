<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('type')->default('hospital'); // hospital, clinic, medical_centre
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('county')->nullable();
            $table->string('country')->default('Kenya');
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('locale')->default('en');
            $table->string('currency')->default('KES');
            $table->json('branding')->nullable();
            $table->json('settings')->nullable();
            $table->string('subscription_plan')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('status')->default('active'); // active, suspended, deactivated
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
