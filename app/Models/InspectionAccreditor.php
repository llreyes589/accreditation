<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row linking one accreditor (lead or member) to one accreditation
 * inspection. Lifecycle: invited -> accepted | declined | removed.
 */
class InspectionAccreditor extends Model
{
    protected $table = 'accreditation_inspection_accreditors';

    protected $fillable = [
        'accreditation_inspection_id', 'user_id', 'role', 'status',
        'assigned_at', 'responded_at', 'decline_reason',
    ];
    protected $casts = [
        'assigned_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public const ROLE_LEAD = 'lead';
    public const ROLE_MEMBER = 'member';

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_REMOVED = 'removed';

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(AccreditationInspection::class, 'accreditation_inspection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
