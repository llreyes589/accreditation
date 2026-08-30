<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ResidentTransfer extends Model {
    protected $fillable = ['resident_id','from_institution_id','to_institution_id','status','requested_by','decided_by','decided_at','reason'];
    protected $casts = ['decided_at' => 'datetime'];

    /** Disposition vocabulary for a transfer request (distinct from an accreditation decision). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DENIED = 'denied';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function resident(){ return $this->belongsTo(Resident::class); }
    public function destination(){ return $this->belongsTo(Institution::class, 'to_institution_id'); }
}
