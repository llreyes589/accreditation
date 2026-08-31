<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\{AuthController, VerificationController, AdminController, DomainController, PlacesController, AccreditorController, FindingsController, ReportsController, KanbanController};
use App\Models\Institution;

Route::get('/places/search', [PlacesController::class, 'search']);

Route::get('/institutions', function () {
    return Institution::where('registration_status', 'approved')->select('id', 'name', 'address', 'hospital_level')->get();
});
Route::post('/register/institution', [AuthController::class, 'registerInstitution']);
Route::post('/register/resident', [AuthController::class, 'registerResident']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/email/verification-notification', [VerificationController::class, 'notice']);
    Route::post('/email/verification-notification', [VerificationController::class, 'resend'])->middleware('throttle:6,1');
    Route::get('/pending-approval', [DomainController::class, 'pending']);
    Route::middleware(['verified', 'approved'])->group(function () {
        Route::get('/me', [DomainController::class, 'me']);
        Route::get('/dashboard', [DomainController::class, 'dashboard'])->middleware('role:TrainingOfficer|TrainingInstitution|Resident');
        Route::get('/notifications', [DomainController::class, 'notifications']);
        Route::post('/notifications/{id}/read', [DomainController::class, 'readNotification']);
        Route::get('/notification-preferences', [DomainController::class, 'getPreferences']);
        Route::put('/notification-preferences', [DomainController::class, 'updatePreferences']);
        Route::middleware('role:TrainingOfficer|TrainingInstitution')->group(function () {
            Route::get('/institution-profile', [DomainController::class, 'institutionProfile']);
            Route::put('/institution-profile', [DomainController::class, 'updateInstitutionProfile']);
            Route::get('/documents', [DomainController::class, 'documents']);
            Route::post('/documents', [DomainController::class, 'storeDocument']);
            Route::get('/consultants', [DomainController::class, 'consultants']);
            Route::post('/consultants', [DomainController::class, 'storeConsultant']);
            Route::get('/quizzes', [DomainController::class, 'quizzes']);
            Route::post('/quizzes', [DomainController::class, 'storeQuiz']);
            Route::post('/quizzes/{quiz}/results', [DomainController::class, 'storeResult']);
            Route::get('/research-papers', [DomainController::class, 'papers']);
            Route::post('/research-papers', [DomainController::class, 'storePaper']);
            Route::get('/case-logs', [DomainController::class, 'cases']);
            Route::post('/case-logs', [DomainController::class, 'storeCase']);
            Route::get('/accreditations', [DomainController::class, 'accreditations']);
            Route::get('/kanban', [KanbanController::class, 'index']);
            Route::post('/accreditations', [DomainController::class, 'submitAccreditation']);
            Route::get('/accreditations/{accreditation}', [DomainController::class, 'accreditationShow']);
            Route::get('/training-officers', [DomainController::class, 'trainingOfficers']);
            Route::post('/training-officers', [DomainController::class, 'storeTrainingOfficer']);
            Route::get('/residents', [DomainController::class, 'residents']);
            Route::post('/residents', [DomainController::class, 'storeResident']);
            Route::get('/residents/{resident}', [DomainController::class, 'residentPortfolio']);
            Route::post('/residents/{resident}/advance-year', [DomainController::class, 'advanceResidentYear']);
            Route::post('/residents/{resident}/review-completion', [DomainController::class, 'reviewResidentCompletion']);
            Route::post('/residents/{resident}/period-complete', [DomainController::class, 'markPeriodComplete']);
            Route::post('/residents/{resident}/transfers', [DomainController::class, 'requestTransfer']);
            Route::get('/transfers/incoming', [DomainController::class, 'incomingTransfers']);
            Route::post('/transfers/{transfer}/accept', [DomainController::class, 'acceptTransfer']);
            Route::post('/transfers/{transfer}/reject', [DomainController::class, 'rejectTransfer']);
            Route::get('/consultants/{consultant}/documents', [DomainController::class, 'consultantDocuments']);
            Route::post('/consultants/{consultant}/documents', [DomainController::class, 'storeConsultantDocument']);
            Route::get('/rotations', [DomainController::class, 'rotations']);
            Route::post('/rotations', [DomainController::class, 'storeRotation']);
            Route::post('/rotations/{rotation}/assignments', [DomainController::class, 'storeRotationAssignment']);
            Route::put('/rotation-assignments/{assignment}', [DomainController::class, 'updateRotationAssignment']);

            /* Remaining flowchart stages: consultant review, evaluation, remediation, archive */
            Route::get('/consultant-reviews', [DomainController::class, 'consultantReviews']);
            Route::post('/consultant-reviews', [DomainController::class, 'storeConsultantReview']);
            Route::get('/consultant-evaluations', [DomainController::class, 'consultantEvaluations']);
            Route::post('/consultant-evaluations', [DomainController::class, 'storeConsultantEvaluation']);
            Route::get('/remediation-plans', [DomainController::class, 'remediationPlans']);
            Route::post('/remediation-plans', [DomainController::class, 'storeRemediationPlan']);
            Route::put('/remediation-plans/{plan}', [DomainController::class, 'updateRemediationPlan']);
            Route::get('/portfolio-archives', [DomainController::class, 'portfolioArchives']);
            Route::post('/portfolio-archives', [DomainController::class, 'storePortfolioArchive']);
            Route::post('/portfolio-archives/{portfolio}', [DomainController::class, 'archivePortfolio']);

            /* Findings & Corrective Actions — institution (Training Officer) side */
            Route::get('/corrective-actions', [FindingsController::class, 'actions']);
            Route::post('/corrective-actions', [FindingsController::class, 'storeAction']);
            Route::post('/corrective-actions/{action}/evidence', [FindingsController::class, 'uploadEvidence']);
            Route::post('/corrective-actions/{action}/resolve', [FindingsController::class, 'resolve']);
        });
        Route::prefix('admin')->middleware('role:Admin')->group(function () {
            Route::get('/pending', [AdminController::class, 'pending']);
            Route::post('/staff', [AdminController::class, 'createStaff']);
            Route::post('/users/{user}/approve', [AdminController::class, 'approveUser']);
            Route::post('/users/{user}/reject', [AdminController::class, 'rejectUser']);
            Route::post('/accreditations/{accreditation}/mark-requirements-completed', [AdminController::class, 'markRequirementsCompleted']);
            Route::post('/accreditations/{accreditation}/schedule-inspection', [AdminController::class, 'scheduleInspection']);
            Route::post('/accreditations/{accreditation}/start-deliberation', [AdminController::class, 'startDeliberation']);
            Route::post('/accreditations/{accreditation}/checklist', [AdminController::class, 'editChecklist']);
            Route::post('/accreditations/{accreditation}/inspections/{inspection}/accreditors', [AdminController::class, 'assignAccreditor']);
            Route::post('/accreditations/{accreditation}/inspections/{inspection}/lead', [AdminController::class, 'changeLeadAccreditor']);
            Route::delete('/accreditations/{accreditation}/inspections/{inspection}/accreditors/{userId}', [AdminController::class, 'removeAccreditor']);
            Route::get('/accreditations/{accreditation}', [AdminController::class, 'accreditationShow']);
            Route::get('/accreditations/{accreditation}/inspections/{inspection}', [AdminController::class, 'inspectionShow']);
            Route::get('/accreditors', [AdminController::class, 'listAccreditors']);
            Route::put('/settings', [AdminController::class, 'settings']);
        });

        // Accreditor: read the checklist + submit a captured inspection.
        Route::prefix('accreditor')->middleware('role:Accreditor')->group(function () {
            Route::get('/checklist-items', [AccreditorController::class, 'listChecklistItems']);
            Route::get('/inspections/pending', [AccreditorController::class, 'pendingInspections']);
            Route::post('/accreditations/{accreditation}/submit-inspection', [AccreditorController::class, 'submitInspection']);
            Route::post('/accreditations/{accreditation}/decision-draft', [AccreditorController::class, 'decisionDraft']);
        });

        // Staff (Admin OR Accreditor): final accreditation decision.
        Route::prefix('staff')->middleware('role:Admin|Accreditor')->group(function () {
            Route::post('/accreditations/{accreditation}/decision', [AdminController::class, 'recordDecision']);
            Route::get('/accreditations/{accreditation}/decisions', [AdminController::class, 'listDecisions']);

            /* Findings & Corrective Actions — reviewer (Admin/Accreditor) writes */
            Route::post('/findings', [FindingsController::class, 'store']);
            Route::post('/findings/{finding}/approve', [FindingsController::class, 'approve']);
            Route::post('/corrective-actions/{action}/verify', [FindingsController::class, 'verify']);
            Route::get('/kanban', [KanbanController::class, 'index']);
        });

        // Findings read — reviewer AND institution (controller scopes by institution for Training Officer)
        Route::get('/staff/findings', [FindingsController::class, 'index'])
            ->middleware('role:Admin|Accreditor|TrainingOfficer|TrainingInstitution');
        Route::get('/staff/inspections', [FindingsController::class, 'inspections'])
            ->middleware('role:Admin|Accreditor');

        // Reports — PSP/CART (all institutions) and institution users (own only, enforced in controller).
        Route::prefix('reports')->middleware('role:Admin|Accreditor|TrainingOfficer|TrainingInstitution')->group(function () {
            Route::get('/accreditations', [ReportsController::class, 'accreditations']);
            Route::get('/renewals', [ReportsController::class, 'renewals']);
            Route::get('/findings', [ReportsController::class, 'findings']);
            Route::get('/inspections', [ReportsController::class, 'inspections']);
        });
    });
});
