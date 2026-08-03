<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\{AuthController, VerificationController, AdminController, DomainController};
use App\Models\Institution;

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
        Route::get('/dashboard', [DomainController::class, 'dashboard'])->middleware('role:TrainingOfficer|Resident');
        Route::get('/notifications', [DomainController::class, 'notifications']);
        Route::post('/notifications/{id}/read', [DomainController::class, 'readNotification']);
        Route::middleware('role:TrainingOfficer')->group(function () {
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
            Route::post('/accreditations', [DomainController::class, 'submitAccreditation']);
        });
        Route::prefix('admin')->middleware('role:Admin')->group(function () {
            Route::get('/pending', [AdminController::class, 'pending']);
            Route::post('/staff', [AdminController::class, 'createStaff']);
            Route::post('/users/{user}/approve', [AdminController::class, 'approveUser']);
            Route::post('/users/{user}/reject', [AdminController::class, 'rejectUser']);
            Route::post('/accreditations/{accreditation}/approve', [AdminController::class, 'approveAccreditation']);
            Route::post('/accreditations/{accreditation}/reject', [AdminController::class, 'rejectAccreditation']);
            Route::put('/settings', [AdminController::class, 'settings']);
        });
    });
});
