<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Accreditation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Presentational Kanban board for accreditation application stages.
 *
 * Returns accreditations grouped into the six board columns:
 *   Application -> For Inspection -> Inspection -> Compliance -> Deliberation -> Decision
 *
 * These columns are a coarse view over the granular Accreditation statuses.
 * Staff (Admin/Accreditor) see every institution; Training Officers / Training
 * Institutions see only their own institution's applications.
 */
class KanbanController extends Controller
{
    /** Maps a granular Accreditation status to one of the six board stages. */
    private const STAGE_OF = [
        Accreditation::STATUS_PENDING => 'application',
        Accreditation::STATUS_REQUIREMENTS_COMPLETED => 'for_inspection',
        Accreditation::STATUS_INSPECTION_SCHEDULED => 'for_inspection',
        Accreditation::STATUS_INSPECTED => 'inspection',
        Accreditation::STATUS_APPROVED => 'decision',
        Accreditation::STATUS_PROBATIONARY => 'decision',
        Accreditation::STATUS_REJECTED => 'decision',
    ];

    private const STAGES = [
        ['id' => 'application', 'title' => 'Application', 'description' => 'Submitted, completeness review, revisions'],
        ['id' => 'for_inspection', 'title' => 'For Inspection', 'description' => 'Cleared for inspection, schedule set'],
        ['id' => 'inspection', 'title' => 'Inspection', 'description' => 'On-site inspection, findings issued'],
        ['id' => 'compliance', 'title' => 'Compliance', 'description' => 'Corrective actions, final evaluation'],
        ['id' => 'deliberation', 'title' => 'Deliberation', 'description' => 'Decision pending, deferred'],
        ['id' => 'decision', 'title' => 'Decision', 'description' => 'Approved, probationary, not approved, renewal'],
    ];

    public function index(Request $r)
    {
        /** @var User $user */
        $user = $r->user();
        $isStaff = $user->hasRole('Admin') || $user->hasRole('Accreditor');

        $query = Accreditation::query()->with('institution:id,name,city,region');

        if (!$isStaff) {
            // Training Officer / Training Institution: only their own institution.
            $inst = $this->institutionId($user);
            $query->where('institution_id', $inst);
        }

        $apps = $query->latest()->get()->each(function (Accreditation $a) {
            $a->stage = $this->stageOf($a);
        });

        $columns = collect(self::STAGES)->map(function ($stage) use ($apps) {
            $items = $apps->where('stage', $stage['id'])->values();

            return [
                'stage' => $stage,
                'applications' => $items->map(function (Accreditation $a) {
                    return [
                        'id' => 'ACC-' . $a->id,
                        'applicantName' => $a->institution->name ?? 'Unknown institution',
                        'institution' => $a->institution->name ?? '—',
                        'program' => $a->submission_type ?? 'accreditation',
                        'enteredStageAt' => $a->submitted_at->toDateString(),
                        'note' => $a->status,
                    ];
                })->all(),
            ];
        })->all();

        return response()->json([
            'stages' => self::STAGES,
            'columns' => $columns,
            'total' => $apps->count(),
        ]);
    }

    /** Resolve the institution id for a Training Officer / Training Institution. */
    private function institutionId(User $user): ?int
    {
        if ($user->hasRole('TrainingInstitution')) {
            return optional($user->institution)->id;
        }
        if ($user->hasRole('TrainingOfficer')) {
            return optional(optional($user->trainingOfficer)->institution)->id;
        }
        return null;
    }

    /** Append a `stage` attribute derived from the granular status. */
    protected function stageOf(Accreditation $a): string
    {
        return self::STAGE_OF[$a->status] ?? 'application';
    }
}
