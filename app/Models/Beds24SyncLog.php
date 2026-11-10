<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $resource
 * @property string $status
 * @property int $credential_id
 * @property int $records_fetched
 * @property int $records_created
 * @property int $records_updated
 * @property int|null $credit_consumed
 * @property int $pages_fetched
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property string|null $error_message
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
#[Fillable([
    'resource',
    'status',
    'credential_id',
    'records_fetched',
    'records_created',
    'records_updated',
    'credit_consumed',
    'pages_fetched',
    'started_at',
    'completed_at',
    'error_message',
    'context',
])]
class Beds24SyncLog extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RUNNING = 'running';

    public const RESOURCE_MESSAGES = 'messages';

    public const RESOURCE_BOOKINGS = 'bookings';

    public const RESOURCE_PROPERTIES = 'properties';

    public const RESOURCE_ROOM_BLOCKS = 'room_blocks';

    protected $table = 'beds24_sync_logs';

    protected function casts(): array
    {
        return [
            'records_fetched' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'credit_consumed' => 'integer',
            'pages_fetched' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Beds24Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Beds24Credential::class, 'credential_id');
    }
}
