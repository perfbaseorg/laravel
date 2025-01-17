<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('perfbase.cache.config.database.connection');
        $table = config('perfbase.cache.config.database.table');
        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->id();
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('perfbase.cache.config.database.connection');
        $table = config('perfbase.cache.config.database.table');
        Schema::connection($connection)->dropIfExists($table);
    }
};
