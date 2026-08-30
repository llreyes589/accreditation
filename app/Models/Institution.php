<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Institution extends Model implements Auditable
{
    use SoftDeletes, AuditableTrait;
    protected $fillable = ['name', 'address', 'hospital_level', 'laboratory_level', 'bsf_category', 'director', 'chairman', 'contact_number', 'email', 'year_program_opened', 'region', 'province', 'city', 'latitude', 'longitude', 'registration_status', 'approved_at', 'approved_by', 'rejection_reason', 'user_id'];
    protected $casts = ['approved_at' => 'datetime', 'year_program_opened' => 'integer', 'latitude' => 'float', 'longitude' => 'float'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function trainingOfficers()
    {
        return $this->hasMany(TrainingOfficer::class);
    }
    public function residents()
    {
        return $this->hasMany(Resident::class);
    }
    public function documents()
    {
        return $this->hasMany(InstitutionDocument::class);
    }
    public function accreditations()
    {
        return $this->hasMany(Accreditation::class);
    }

    /**
     * Tracks this institution is accredited to train, derived from its
     * approved/probationary accreditations. Used to validate a resident's
     * declared training track at registration/enrollment (t_f18a9c4a).
     */
    public function accreditedTracks(): array
    {
        $tracks = [];
        foreach ($this->accreditations()
            ->whereIn('status', [Accreditation::STATUS_APPROVED, Accreditation::STATUS_PROBATIONARY])
            ->get() as $acc) {
            foreach ($acc->accreditedTracks() as $t) {
                $tracks[$t] = true;
            }
        }
        return array_keys($tracks);
    }
}
