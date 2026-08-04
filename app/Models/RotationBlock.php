<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RotationBlock extends Model { protected $fillable = ['institution_id','consultant_id','title','category','starts_at','ends_at','notes']; protected $casts = ['starts_at' => 'date', 'ends_at' => 'date']; public function consultant(){ return $this->belongsTo(Consultant::class); } public function assignments(){ return $this->hasMany(RotationAssignment::class); } }
