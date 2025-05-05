<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            // Use UUID as the primary identifier for the record
            $table->uuid('uuid')->primary();

            // UUID of the original file record. Nullable for the original file itself.
            // Corrected: Use uuid() and chain nullable()
            $table->uuid('original_file_uuid')->nullable();

            // File details
            $table->string('file_name');
            $table->string('file_path');
            $table->string('extension', 20); // A short string for file extensions

            // Target dimensions for converted files (nullable for original)
            $table->integer('target_width')->nullable();
            $table->integer('target_height')->nullable();

            // Standard timestamps
            $table->timestamps();

            // Add an index on original_file_uuid for faster querying of related files
            $table->index('original_file_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};