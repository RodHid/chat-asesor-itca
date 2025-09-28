<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create the chat-logs schema if it doesn't exist
        DB::statement('CREATE SCHEMA IF NOT EXISTS "chat-logs"');
        
        // Set the search path to use the new schema
        DB::statement('SET search_path TO "chat-logs", public');
        
        // Create cache table in the chat-logs schema (if using database cache)
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        // Create cache_locks table (for database cache locking)
        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        // Optional: Create a table to log chat sessions and interactions
        if (!Schema::hasTable('chat_sessions')) {
            Schema::create('chat_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id')->unique();
                $table->text('document_url')->nullable();
                $table->integer('document_length')->nullable();
                $table->timestamp('document_processed_at')->nullable();
                $table->integer('questions_count')->default(0);
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
                
                $table->index('session_id');
                $table->index('last_activity_at');
            });
        }

        // Optional: Create a table to log individual questions and responses
        if (!Schema::hasTable('chat_interactions')) {
            Schema::create('chat_interactions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id');
                $table->text('question');
                $table->text('response');
                $table->integer('response_time_ms')->nullable();
                $table->string('status')->default('success'); // success, error, timeout
                $table->json('metadata')->nullable(); // For additional data like error details
                $table->timestamps();
                
                $table->index('session_id');
                $table->index('status');
                $table->index('created_at');
                
                $table->foreign('session_id')->references('session_id')->on('chat_sessions')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set search path back to public
        DB::statement('SET search_path TO public');
        
        // Drop tables in the correct order (due to foreign keys)
        Schema::dropIfExists('chat_interactions');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        
        // Drop the schema (this will fail if there are other objects in it)
        DB::statement('DROP SCHEMA IF EXISTS "chat-logs" CASCADE');
    }
};
