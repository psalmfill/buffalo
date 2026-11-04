<?php

use App\Http\Controllers\Security\AgencyFormationController;
use App\Http\Controllers\Security\CoverageReportController;
use App\Http\Controllers\Security\ReportAttachmentController;
use Illuminate\Support\Facades\Route;

// Security agencies (Phase 4A): formations and security reports (coverage
// reports in code; renamed in Phase 11G, routes keep their coverage-reports.* names).
// {formation} and {report} are bound through their scopes (jurisdiction and
// classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('formations', [AgencyFormationController::class, 'index'])->name('formations.index')->middleware('lens');
    Route::get('formations/create', [AgencyFormationController::class, 'create'])->name('formations.create');
    Route::post('formations', [AgencyFormationController::class, 'store'])->name('formations.store');

    Route::whereUlid('formation')->group(function () {
        Route::get('formations/{formation}', [AgencyFormationController::class, 'show'])->name('formations.show');
        Route::get('formations/{formation}/edit', [AgencyFormationController::class, 'edit'])->name('formations.edit');
        Route::put('formations/{formation}', [AgencyFormationController::class, 'update'])->name('formations.update');
        Route::delete('formations/{formation}', [AgencyFormationController::class, 'destroy'])->name('formations.destroy');
    });

    Route::get('security-reports', [CoverageReportController::class, 'index'])->name('coverage-reports.index')->middleware('lens');
    Route::get('security-reports/create', [CoverageReportController::class, 'create'])->name('coverage-reports.create');
    Route::post('security-reports', [CoverageReportController::class, 'store'])->name('coverage-reports.store');

    Route::whereUlid('report')->group(function () {
        Route::get('security-reports/{report}', [CoverageReportController::class, 'show'])->name('coverage-reports.show');
        Route::get('security-reports/{report}/edit', [CoverageReportController::class, 'edit'])->name('coverage-reports.edit');
        Route::put('security-reports/{report}', [CoverageReportController::class, 'update'])->name('coverage-reports.update');
        Route::post('security-reports/{report}/return', [CoverageReportController::class, 'returnReport'])->name('coverage-reports.return');
        Route::post('security-reports/{report}/review', [CoverageReportController::class, 'review'])->name('coverage-reports.review');
        Route::delete('security-reports/{report}', [CoverageReportController::class, 'destroy'])->name('coverage-reports.destroy');

        // Photos and videos (Phase 11G), stored and served like evidence.
        Route::post('security-reports/{report}/attachments', [ReportAttachmentController::class, 'store'])->name('coverage-reports.attachments.store');
    });

    Route::whereUlid('attachment')->group(function () {
        Route::put('security-report-attachments/{attachment}', [ReportAttachmentController::class, 'update'])->name('report-attachments.update');
        Route::delete('security-report-attachments/{attachment}', [ReportAttachmentController::class, 'destroy'])->name('report-attachments.destroy');
        Route::get('security-report-attachments/{attachment}/thumb', [ReportAttachmentController::class, 'thumb'])->name('report-attachments.thumb');
        Route::get('security-report-attachments/{attachment}/preview', [ReportAttachmentController::class, 'preview'])->name('report-attachments.preview');
        Route::get('security-report-attachments/{attachment}/stream', [ReportAttachmentController::class, 'stream'])->name('report-attachments.stream');
        Route::get('security-report-attachments/{attachment}/download', [ReportAttachmentController::class, 'download'])->name('report-attachments.download');
    });

    // Coverage reports were renamed security reports (Phase 11G): old links
    // (bookmarks, notifications) move permanently to the new address.
    Route::permanentRedirect('coverage-reports/{path?}', '/security-reports/{path?}')->where('path', '.*');
});
