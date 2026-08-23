<?php

namespace App\Services;

use App\Exceptions\InspectionAssignmentException;
use App\Models\AccreditationInspection;
use App\Models\InspectionAccreditor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manages accreditor assignment to an inspection: lead + members, assignable
 * at schedule time or afterwards, with the per-accreditor daily cap enforced
 * server-side (an accreditor may cover at most three inspections per calendar
 * day, across any institution).
 */
class InspectionAssignmentService
{
    /**
     * Assign an accreditor to an inspection.
     *
     * @throws InspectionAssignmentException
     */
    public function assign(
        AccreditationInspection $inspection,
        User $accreditor,
        string $role = InspectionAccreditor::ROLE_MEMBER,
        ?int $ignoreInspectionId = null
    ): InspectionAccreditor {
        if (! $accreditor->hasRole('Accreditor')) {
            throw InspectionAssignmentException::notAccreditor();
        }

        if ($inspection->accreditorAssignments()
            ->where('user_id', $accreditor->id)
            ->where('status', '!=', InspectionAccreditor::STATUS_REMOVED)
            ->exists()
        ) {
            throw InspectionAssignmentException::duplicate();
        }

        $this->assertWithinDailyLimit($accreditor->id, $inspection->inspection_scheduled_at, $ignoreInspectionId);

        $assignment = DB::transaction(function () use ($inspection, $accreditor, $role) {
            // Reactivate a previously-removed assignment for this user, otherwise create a new one.
            $assignment = $inspection->accreditorAssignments()
                ->where('user_id', $accreditor->id)
                ->where('status', InspectionAccreditor::STATUS_REMOVED)
                ->first();

            if ($assignment) {
                $assignment->forceFill([
                    'role' => $role,
                    'status' => InspectionAccreditor::STATUS_INVITED,
                    'assigned_at' => now(),
                    'responded_at' => null,
                    'decline_reason' => null,
                ])->save();
            } else {
                $assignment = $inspection->accreditorAssignments()->create([
                    'user_id' => $accreditor->id,
                    'role' => $role,
                    'status' => InspectionAccreditor::STATUS_INVITED,
                    'assigned_at' => now(),
                ]);
            }

            // Keep the denormalized lead pointer in sync.
            if ($role === InspectionAccreditor::ROLE_LEAD) {
                $inspection->forceFill(['accreditor_id' => $accreditor->id])->save();
            }

            return $assignment;
        });

        return $assignment->refresh();
    }

    /**
     * Reassign the lead accreditor. Demotes the previous lead to member and
     * promotes the new one, enforcing the daily cap for the incoming lead.
     *
     * @throws InspectionAssignmentException
     */
    public function changeLead(
        AccreditationInspection $inspection,
        User $newLead,
        ?int $ignoreInspectionId = null
    ): void {
        if (! $newLead->hasRole('Accreditor')) {
            throw InspectionAssignmentException::notAccreditor();
        }

        $this->assertWithinDailyLimit($newLead->id, $inspection->inspection_scheduled_at, $ignoreInspectionId);

        DB::transaction(function () use ($inspection, $newLead) {
            $inspection->accreditorAssignments()
                ->where('role', InspectionAccreditor::ROLE_LEAD)
                ->where('status', '!=', InspectionAccreditor::STATUS_REMOVED)
                ->update(['role' => InspectionAccreditor::ROLE_MEMBER]);

            $assignment = $inspection->accreditorAssignments()
                ->where('user_id', $newLead->id)
                ->where('status', '!=', InspectionAccreditor::STATUS_REMOVED)
                ->first();

            if ($assignment) {
                $assignment->forceFill(['role' => InspectionAccreditor::ROLE_LEAD])->save();
            } else {
                $inspection->accreditorAssignments()->create([
                    'user_id' => $newLead->id,
                    'role' => InspectionAccreditor::ROLE_LEAD,
                    'status' => InspectionAccreditor::STATUS_INVITED,
                    'assigned_at' => now(),
                ]);
            }

            $inspection->forceFill(['accreditor_id' => $newLead->id])->save();
        });
    }

    /**
     * Remove an accreditor from the inspection (soft: marked removed, not deleted).
     */
    public function remove(AccreditationInspection $inspection, InspectionAccreditor $assignment): void
    {
        if ($assignment->accreditation_inspection_id !== $inspection->id) {
            throw InspectionAssignmentException::notBelongsToInspection();
        }

        $assignment->forceFill(['status' => InspectionAccreditor::STATUS_REMOVED])->save();

        // If the removed accreditor was the lead, clear the denormalized pointer.
        if ($assignment->role === InspectionAccreditor::ROLE_LEAD) {
            $inspection->forceFill(['accreditor_id' => null])->save();
        }
    }

    /**
     * Enforce the per-accreditor daily inspection cap.
     *
     * Counts the accreditor's non-removed assignments whose inspection is
     * scheduled on the same calendar day and is not yet cancelled/completed.
     *
     * @throws InspectionAssignmentException
     */
    public function assertWithinDailyLimit(int $userId, $date, ?int $ignoreInspectionId): void
    {
        if ($date === null) {
            return;
        }

        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : \Carbon\Carbon::parse($date)->format('Y-m-d');

        $count = InspectionAccreditor::query()
            ->where('user_id', $userId)
            ->where('status', '!=', InspectionAccreditor::STATUS_REMOVED)
            ->where('accreditation_inspection_id', '!=', $ignoreInspectionId ?? -1)
            ->whereHas('inspection', function ($query) use ($day) {
                $query->whereNotNull('inspection_scheduled_at')
                    ->whereDate('inspection_scheduled_at', $day)
                    ->where('status', '!=', AccreditationInspection::STATUS_SUBMITTED);
            })
            ->count();

        if ($count >= AccreditationInspection::MAX_PER_ACCREDITOR_PER_DAY) {
            throw InspectionAssignmentException::dailyLimitExceeded(
                AccreditationInspection::MAX_PER_ACCREDITOR_PER_DAY,
                $count,
                $day,
            );
        }
    }
}
