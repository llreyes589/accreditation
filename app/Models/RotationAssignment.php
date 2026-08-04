<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RotationAssignment extends Model { protected $fillable = ['rotation_block_id','resident_id','status','grade']; public function resident(){ return $this->belongsTo(Resident::class); } public function rotationBlock(){ return $this->belongsTo(RotationBlock::class); } }
