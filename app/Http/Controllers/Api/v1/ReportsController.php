<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{Accreditation, Finding, CorrectiveAction, AccreditationInspection, Institution, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ReportsController extends Controller
{
    /** Resolve the institution scope: institution users are forced to their own. */
    private function scopeInstitutionId(Request $r): ?int
    {
        $user = $r->user();
        if ($user->hasRole('TrainingOfficer') || $user->hasRole('TrainingInstitution')) {
            // TrainingInstitution users own their institution directly; TrainingOfficer
            // users are linked via the training_officers pivot row.
            $inst = Institution::where('user_id', $user->id)->first()
                ?? optional($user->trainingOfficer)->institution;
            if (!$inst) abort(403, 'No institution associated with this account.');
            // Institution users cannot request a different institution.
            if ($r->filled('institution_id') && (int) $r->institution_id !== $inst->id) {
                abort(403, 'You may only generate reports for your own institution.');
            }
            return $inst->id;
        }
        // PSP/CART users may pass any institution_id (or none = all).
        return $r->filled('institution_id') ? (int) $r->institution_id : null;
    }

    private function streamCsv(string $filename, array $headers, array $rows)
    {
        return Response::streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function dateRange(Request $r, $q, string $column)
    {
        if ($r->filled('date_from')) $q->whereDate($column, '>=', $r->date_from);
        if ($r->filled('date_to')) $q->whereDate($column, '<=', $r->date_to);
        return $q;
    }

    public function accreditations(Request $r)
    {
        $instId = $this->scopeInstitutionId($r);
        $q = Accreditation::with(['institution', 'decisions'])->when($instId, fn($q) => $q->where('institution_id', $instId));
        if ($r->filled('status')) $q->where('status', $r->status);
        if ($r->filled('outcome')) $q->whereHas('decisions', fn($d) => $d->where('outcome', $r->outcome));
        $q = $this->dateRange($r, $q, 'created_at');

        $rows = [];
        foreach ($q->get() as $a) {
            $rows[] = [
                $a->id,
                $a->institution->name ?? '',
                $a->submission_type ?? 'new',
                $a->status,
                $a->latestInspection && $a->latestInspection->conducted_at ? $a->latestInspection->conducted_at->toDateString() : '',
                $a->decisions->last() ? $a->decisions->last()->outcome : '',
                $a->valid_from ? $a->valid_from->toDateString() : '',
                $a->valid_until ? $a->valid_until->toDateString() : '',
            ];
        }
        return $this->streamCsv('accreditations.csv', ['ID', 'Institution', 'Type', 'Status', 'Inspection Date', 'Outcome', 'Valid From', 'Valid Until'], $rows);
    }

    public function renewals(Request $r)
    {
        $instId = $this->scopeInstitutionId($r);
        $q = Accreditation::with('institution')
            ->where('submission_type', 'renew')
            ->when($instId, fn($q) => $q->where('institution_id', $instId));
        if ($r->filled('status')) $q->where('status', $r->status);
        $q = $this->dateRange($r, $q, 'valid_until');

        $rows = [];
        foreach ($q->get() as $a) {
            $days = $a->valid_until ? (int) now()->diffInDays($a->valid_until, false) : null;
            $rows[] = [
                $a->id,
                $a->institution->name ?? '',
                $a->decisions->last() ? $a->decisions->last()->outcome : '',
                $a->valid_until ? $a->valid_until->toDateString() : '',
                $a->status,
                $a->valid_until ? $a->valid_until->toDateString() : '',
                $days ?? '',
            ];
        }
        return $this->streamCsv('renewals.csv', ['ID', 'Institution', 'Outcome', 'Valid Until', 'Renewal Status', 'Due At', 'Days Remaining'], $rows);
    }

    public function findings(Request $r)
    {
        $instId = $this->scopeInstitutionId($r);
        $q = Finding::with(['inspection.accreditation.institution', 'actions'])
            ->when($instId, fn($q) => $q->whereHas('inspection.accreditation', fn($a) => $a->where('institution_id', $instId)));
        if ($r->filled('severity')) $q->where('severity', $r->severity);
        if ($r->filled('status')) $q->where('status', $r->status);
        $q = $this->dateRange($r, $q, 'created_at');

        $rows = [];
        foreach ($q->get() as $f) {
            $action = $f->actions->first();
            $due = $action->due_date;
            $daysOver = $due && $action && $action->status !== 'resolved' && $action->status !== 'verified'
                ? (int) now()->diffInDays($due, false) : null;
            $rows[] = [
                $f->id,
                $f->inspection->accreditation->id ?? '',
                $f->severity,
                $f->status,
                optional($due)->toDateString() ?? '',
                $action->assigned_to ?? '',
                $daysOver ?? '',
            ];
        }
        return $this->streamCsv('findings.csv', ['Finding ID', 'Accreditation', 'Severity', 'Status', 'Due Date', 'Responsible', 'Days Overdue'], $rows);
    }

    public function inspections(Request $r)
    {
        $instId = $this->scopeInstitutionId($r);
        $q = AccreditationInspection::with(['accreditation.institution', 'accreditor'])
            ->when($instId, fn($q) => $q->whereHas('accreditation', fn($a) => $a->where('institution_id', $instId)));
        if ($r->filled('status')) $q->where('status', $r->status);
        $q = $this->dateRange($r, $q, 'inspection_scheduled_at');

        $rows = [];
        foreach ($q->get() as $i) {
            $rows[] = [
                $i->id,
                $i->accreditation->id ?? '',
                $i->inspection_scheduled_at ? $i->inspection_scheduled_at->toDateString() : '',
                $i->status,
                $i->accreditor->name ?? '',
            ];
        }
        return $this->streamCsv('inspections.csv', ['Inspection ID', 'Accreditation', 'Scheduled Date', 'Status', 'Lead Inspector'], $rows);
    }
}
