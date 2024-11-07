<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('perfbase.database.connection');
        $table = config('perfbase.database.table');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('perfbase.database.connection');
        $table = config('perfbase.database.table');

        Schema::connection($connection)->dropIfExists($table);
    }
};
