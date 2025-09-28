<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'question',
        'response',
        'response_time_ms',
        'status',
        'metadata',
    ];

    protected $casts = [
        'response_time_ms' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Get the chat session that owns this interaction.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }

    /**
     * Create a new interaction record
     */
    public static function logInteraction(
        string $sessionId,
        string $question,
        string $response,
        int $responseTimeMs = null,
        string $status = 'success',
        array $metadata = []
    ): self {
        return static::create([
            'session_id' => $sessionId,
            'question' => $question,
            'response' => $response,
            'response_time_ms' => $responseTimeMs,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }
}
