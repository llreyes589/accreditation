<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioArchive extends Model
{
    protected $fillable = ['resident_id', 'summary', 'status', 'archived_at'];
    protected $casts = ['archived_at' => 'date'];

    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }
}
