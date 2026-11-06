<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64)->unique();
            $table->json('users')->nullable();
            $table->string('origin')->default('panel'); // panel, migration, app
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_databases');
    }
};
