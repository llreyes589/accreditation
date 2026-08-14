<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{Accreditation, AccreditationInspection, ChecklistItem, Finding, AccreditationDecision};
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

        // Auto-raise a finding for every non-compliant checklist item (preserves context,
        // avoids duplicates: skip items that already have a finding on this inspection).
        $accreditorId = $r->user()->id;
        foreach ($d['answers'] as $itemId => $answer) {
            if (empty($answer['compliant'])) {
                $item = ChecklistItem::find($itemId);
                $exists = Finding::where('accreditation_inspection_id', $inspection->id)
                    ->where('checklist_item_id', $itemId)->exists();
                if ($item && !$exists) {
                    Finding::create([
                        'accreditation_inspection_id' => $inspection->id,
                        'checklist_item_id' => $itemId,
                        'title' => 'Non-compliant: ' . ($item->criterion ?: "Item #$itemId"),
                        'description' => $answer['notes'] ?? 'Flagged as non-compliant during inspection.',
                        'severity' => $item->is_major ? 'major' : 'minor',
                        'status' => Finding::STATUS_OPEN,
                        'raised_by' => $accreditorId,
                    ]);
                }
            }
        }

        return response()->json([
            'accreditation' => $accreditation->fresh(),
            'inspection' => $inspection->fresh(),
        ]);
    }

    /**
     * Accreditor records a draft recommendation before the final decision.
     * Does NOT change accreditation status (stays inspected until a final decision).
     * Append-only ledger row (outcome = draft).
     */
    public function decisionDraft(Request $r, Accreditation $accreditation)
    {
        if ($accreditation->status !== Accreditation::STATUS_INSPECTED) {
            return response()->json([
                'message' => 'A draft recommendation can only be recorded after inspection.',
            ], 422);
        }

        $d = $r->validate([
            'outcome' => 'required|in:draft',
            'notes' => 'nullable|string|max:2000',
        ]);

        $decision = $accreditation->decisions()->create([
            'outcome' => AccreditationDecision::OUTCOME_DRAFT,
            'notes' => $d['notes'] ?? null,
            'decided_by' => $r->user()->id,
        ]);

        return response()->json($decision, 201);
    }
}
