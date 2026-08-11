<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single line item of the CART accreditation inspection checklist
 * (sections A–I from CHECKLIST-OF-ACCREDITATION.xls).
 * The master list is seeded once; accreditor answers live on AccreditationInspection.
 */
class ChecklistItem extends Model
{
    protected $fillable = ['section', 'code', 'criterion', 'is_major', 'notes_hint', 'sort_order'];
    protected $casts = ['is_major' => 'boolean', 'sort_order' => 'integer'];

    public function inspections()
    {
        return $this->hasMany(AccreditationInspection::class);
    }
}
