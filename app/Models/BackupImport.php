<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupImport extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNDONE = 'undone';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'preview' => 'array',
            'summary' => 'array',
            'warnings' => 'array',
            'imported_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
