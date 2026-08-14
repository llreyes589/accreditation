<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    use HasFactory, Notifiable, AuditableTrait, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'status',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'email_verified_at'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }
    public function trainingOfficer()
    {
        return $this->hasOne(TrainingOfficer::class);
    }
    public function institution()
    {
        return $this->hasOne(Institution::class);
    }
    public function resident()
    {
        return $this->hasOne(Resident::class);
    }

    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** Has the user opted out of this category on this channel? */
    public function hasOptedOut(string $category, string $channel): bool
    {
        $pref = $this->notificationPreferences()
            ->where('category', $category)
            ->where('channel', $channel)
            ->first();
        return $pref ? !$pref->enabled : false;
    }

    /** Is the user currently inside their quiet-hours window for this category? */
    public function inQuietHours(string $category, ?\DateTimeInterface $now = null): bool
    {
        $pref = $this->notificationPreferences()
            ->where('category', $category)
            ->whereNotNull('quiet_hours_start')
            ->first();
        return $pref ? $pref->inQuietHours($now) : false;
    }

    public function hasRole($role)
    {
        return $this->roles()->where('name', $role)->exists();
    }
    public function assignRole($role)
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $this->roles()->syncWithoutDetaching([$roleModel->id]);
        $this->touch();
    }
    public function isApproved()
    {
        return $this->status === 'approved';
    }
}
