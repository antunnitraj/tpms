<?php

namespace App\Models;

use App\Models\Concerns\BelongsToVehicle;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

class Note extends Model implements HasMedia
{
    use BelongsToVehicle, HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'tags' => 'array',
        ];
    }
}
