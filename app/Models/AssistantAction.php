<?php

namespace App\Models;

use Database\Factories\AssistantActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantAction extends Model
{
    /** @use HasFactory<AssistantActionFactory> */
    use HasFactory;

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    public const DISMISSED = 'dismissed';

    protected $fillable = ['type', 'status', 'summary', 'result', 'subject_type', 'subject_id', 'subject_title', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }
}
