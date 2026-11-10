<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 予約ごとの宿泊者名簿 1 名分。旅館業法対応の原簿として R2 + DB で保管する。
 *
 * @property int $id
 * @property int $booking_id
 * @property int $guest_number
 * @property string $name
 * @property string $address
 * @property int|null $age
 * @property string|null $occupation
 * @property string $nationality
 * @property string|null $sex
 * @property bool $is_representative
 * @property Carbon|null $arrival_at
 * @property Carbon|null $departure_at
 * @property string|null $phone
 * @property string|null $previous_stay
 * @property string|null $next_destination
 * @property string|null $id_image_path
 * @property string|null $id_image_sha256
 * @property bool $id_image_compressed
 * @property string|null $id_image_verdict
 * @property string|null $id_image_ai_notes
 * @property array<string, mixed>|null $id_image_ai_extracted
 * @property Carbon|null $id_image_verified_at
 * @property string|null $admin_verdict
 * @property string|null $admin_verdict_reason
 * @property Carbon|null $admin_reviewed_at
 * @property int|null $admin_reviewed_by
 * @property string|null $submitter_ip
 * @property string|null $submitter_user_agent
 * @property Carbon $submitted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking $booking
 * @property-read User|null $adminReviewer
 */
#[Fillable([
    'booking_id',
    'guest_number',
    'name',
    'address',
    'age',
    'occupation',
    'nationality',
    'sex',
    'is_representative',
    'arrival_at',
    'departure_at',
    'phone',
    'previous_stay',
    'next_destination',
    'id_image_path',
    'id_image_sha256',
    'id_image_compressed',
    'id_image_verdict',
    'id_image_ai_notes',
    'id_image_ai_extracted',
    'id_image_verified_at',
    'admin_verdict',
    'admin_verdict_reason',
    'admin_reviewed_at',
    'admin_reviewed_by',
    'submitter_ip',
    'submitter_user_agent',
    'submitted_at',
])]
class GuestRegistration extends Model
{
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'guest_number' => 'integer',
            'is_representative' => 'boolean',
            'arrival_at' => 'datetime',
            'departure_at' => 'datetime',
            'id_image_compressed' => 'boolean',
            'id_image_ai_extracted' => 'array',
            'id_image_verified_at' => 'datetime',
            'admin_reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public const VERDICT_PENDING = 'pending';

    public const VERDICT_OK = 'ok';

    public const VERDICT_SUSPICIOUS = 'suspicious';

    public const VERDICT_INVALID = 'invalid';

    public const VERDICT_ERROR = 'error';

    /**
     * 表示・集約に使う実効 verdict。管理者判定が優先、無ければ AI 判定。
     */
    public function effectiveVerdict(): ?string
    {
        return $this->admin_verdict ?? $this->id_image_verdict;
    }

    public function isAdminApproved(): bool
    {
        return $this->admin_verdict === self::VERDICT_OK;
    }

    public function isAdminRejected(): bool
    {
        return $this->admin_verdict === self::VERDICT_INVALID;
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adminReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_reviewed_by');
    }
}
