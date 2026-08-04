<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConsultantDocument extends Model { protected $fillable = ['consultant_id','type','file_path','expires_at']; protected $casts = ['expires_at' => 'date']; public function consultant(){ return $this->belongsTo(Consultant::class); } }
