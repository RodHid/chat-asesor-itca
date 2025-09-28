<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'document_url',
        'document_length',
        'document_processed_at',
        'questions_count',
        'last_activity_at',
    ];

    protected $casts = [
        'document_processed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'document_length' => 'integer',
        'questions_count' => 'integer',
    ];

    /**
     * Get the chat interactions for this session.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(ChatInteraction::class, 'session_id', 'session_id');
    }

    /**
     * Increment the questions count
     */
    public function incrementQuestions(): void
    {
        $this->increment('questions_count');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Find or create a session by session_id
     */
    public static function findOrCreateBySessionId(string $sessionId): self
    {
        return static::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'last_activity_at' => now(),
                'questions_count' => 0,
            ]
        );
    }
}
