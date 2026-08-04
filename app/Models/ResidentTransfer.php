<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ResidentTransfer extends Model { protected $fillable = ['resident_id','from_institution_id','to_institution_id','status','requested_by','decided_by','decided_at','reason']; protected $casts = ['decided_at' => 'datetime']; public function resident(){ return $this->belongsTo(Resident::class); } public function destination(){ return $this->belongsTo(Institution::class, 'to_institution_id'); } }
