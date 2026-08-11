<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{Accreditation, AccreditationInspection, ChecklistItem};
use Illuminate\Http\Request;

class AccreditorController extends Controller
{
    /**
     * Capture the CART inspection checklist for an accreditation.
     * Body: { answers: { "<checklist_item_id>": { compliant: bool, notes?: string } } }
     * Every checklist item must be answered. Sets accreditation status -> inspected.
     */
    /** The master CART checklist (sections A–I) for the accreditor to complete. */
    public function listChecklistItems()
    {
        return response()->json(
            ChecklistItem::orderBy('sort_order')->get()
        );
    }

    /** Accreditations awaiting inspection (status = inspection_scheduled). */
    public function pendingInspections()
    {
        return response()->json(
            Accreditation::where('status', Accreditation::STATUS_INSPECTION_SCHEDULED)
                ->with('institution')
                ->orderBy('inspection_scheduled_at')
                ->get()
        );
    }

    public function submitInspection(Request $r, Accreditation $accreditation)
    {
        if ($accreditation->status !== Accreditation::STATUS_INSPECTION_SCHEDULED) {
            return response()->json([
                'message' => 'Inspection can only be submitted for a scheduled accreditation.',
            ], 422);
        }

        $d = $r->validate([
            'answers' => 'required|array',
            'answers.*.compliant' => 'required|boolean',
            'answers.*.notes' => 'nullable|string|max:5000',
        ]);

        $allIds = ChecklistItem::pluck('id')->all();
        $provided = array_keys($d['answers']);
        $missing = array_values(array_diff($allIds, $provided));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'All checklist items must be answered.',
                'missing_items' => $missing,
            ], 422);
        }

        $accreditorId = $r->user()->id;
        $inspection = $accreditation->inspections()->updateOrCreate(
            ['accreditor_id' => $accreditorId, 'status' => AccreditationInspection::STATUS_PENDING],
            [
                'inspection_scheduled_at' => $accreditation->inspection_scheduled_at,
                'conducted_at' => now(),
                'status' => AccreditationInspection::STATUS_SUBMITTED,
                'answers' => $d['answers'],
            ]
        );

        $accreditation->update(['status' => Accreditation::STATUS_INSPECTED]);

        return response()->json([
            'accreditation' => $accreditation->fresh(),
            'inspection' => $inspection->fresh(),
        ]);
    }
}
