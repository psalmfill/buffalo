<?php

use App\Http\Controllers\CommunityInformation\ReligiousInstitutionController;
use App\Http\Controllers\CommunityInformation\SecurityNoteController;
use App\Http\Controllers\CommunityInformation\VulnerableFiguresController;
use Illuminate\Support\Facades\Route;

// Community information (Phase 2C). Records are created in an LGA ({lga}
// is bound within the user's jurisdiction, routes/local-government.php);
// {institution}, {figure} and {note} are bound through the jurisdiction
// (and, for notes, classification) scopes: anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::whereUlid('lga')->group(function () {
        Route::get('lgas/{lga}/religious-institutions/create', [ReligiousInstitutionController::class, 'create'])->name('lgas.religious-institutions.create');
        Route::post('lgas/{lga}/religious-institutions', [ReligiousInstitutionController::class, 'store'])->name('lgas.religious-institutions.store');
        Route::get('lgas/{lga}/vulnerable-population/create', [VulnerableFiguresController::class, 'create'])->name('lgas.vulnerable-population.create');
        Route::post('lgas/{lga}/vulnerable-population', [VulnerableFiguresController::class, 'store'])->name('lgas.vulnerable-population.store');
        Route::get('lgas/{lga}/security-notes/create', [SecurityNoteController::class, 'create'])->name('lgas.security-notes.create');
        Route::post('lgas/{lga}/security-notes', [SecurityNoteController::class, 'store'])->name('lgas.security-notes.store');
    });

    Route::whereUlid('institution')->group(function () {
        Route::get('religious-institutions/{institution}/edit', [ReligiousInstitutionController::class, 'edit'])->name('religious-institutions.edit');
        Route::put('religious-institutions/{institution}', [ReligiousInstitutionController::class, 'update'])->name('religious-institutions.update');
        Route::delete('religious-institutions/{institution}', [ReligiousInstitutionController::class, 'destroy'])->name('religious-institutions.destroy');
    });

    Route::whereUlid('figure')->group(function () {
        Route::get('vulnerable-population/{figure}/edit', [VulnerableFiguresController::class, 'edit'])->name('vulnerable-population.edit');
        Route::put('vulnerable-population/{figure}', [VulnerableFiguresController::class, 'update'])->name('vulnerable-population.update');
        Route::delete('vulnerable-population/{figure}', [VulnerableFiguresController::class, 'destroy'])->name('vulnerable-population.destroy');
    });

    Route::whereUlid('note')->group(function () {
        Route::get('security-notes/{note}', [SecurityNoteController::class, 'show'])->name('security-notes.show');
        Route::get('security-notes/{note}/edit', [SecurityNoteController::class, 'edit'])->name('security-notes.edit');
        Route::put('security-notes/{note}', [SecurityNoteController::class, 'update'])->name('security-notes.update');
        Route::post('security-notes/{note}/status', [SecurityNoteController::class, 'status'])->name('security-notes.status');
        Route::delete('security-notes/{note}', [SecurityNoteController::class, 'destroy'])->name('security-notes.destroy');
    });
});
