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
}
