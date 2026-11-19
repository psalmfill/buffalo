<?php

use App\Http\Controllers\Taskings\TaskingAttachmentController;
use App\Http\Controllers\Taskings\TaskingController;
use App\Http\Controllers\Taskings\TaskingFollowUpController;
use Illuminate\Support\Facades\Route;

// Taskings (Phase 11H): instructions from a State Director or Deputy to the
// LGA Security Chiefs of their state. {tasking}, {recipient} and
// {attachment} are bound through their scopes (jurisdiction and
// classification; an LGA chief reaches only their own LGA's row): anything
// else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('taskings', [TaskingController::class, 'index'])->name('taskings.index')->middleware('lens');
    Route::get('taskings/create', [TaskingController::class, 'create'])->name('taskings.create');
    Route::post('taskings', [TaskingController::class, 'store'])->name('taskings.store');

    Route::whereUlid('tasking')->group(function () {
        Route::get('taskings/{tasking}', [TaskingController::class, 'show'])->name('taskings.show');
        Route::get('taskings/{tasking}/edit', [TaskingController::class, 'edit'])->name('taskings.edit');
        Route::put('taskings/{tasking}', [TaskingController::class, 'update'])->name('taskings.update');
        Route::delete('taskings/{tasking}', [TaskingController::class, 'destroy'])->name('taskings.destroy');
        Route::post('taskings/{tasking}/recall', [TaskingController::class, 'recall'])->name('taskings.recall');
        Route::post('taskings/{tasking}/notes', [TaskingFollowUpController::class, 'note'])->name('taskings.notes.store');
        Route::post('taskings/{tasking}/attachments', [TaskingAttachmentController::class, 'store'])->name('taskings.attachments.store');
    });

    Route::whereUlid('recipient')->group(function () {
        Route::post('tasking-recipients/{recipient}/acknowledge', [TaskingFollowUpController::class, 'acknowledge'])->name('tasking-recipients.acknowledge');
        Route::post('tasking-recipients/{recipient}/complete', [TaskingFollowUpController::class, 'complete'])->name('tasking-recipients.complete');
        Route::post('tasking-recipients/{recipient}/responses', [TaskingFollowUpController::class, 'respond'])->name('tasking-recipients.responses.store');
        Route::post('tasking-recipients/{recipient}/attachments', [TaskingAttachmentController::class, 'storeForResponse'])->name('tasking-recipients.attachments.store');
    });

    Route::whereUlid('attachment')->group(function () {
        Route::put('tasking-attachments/{attachment}', [TaskingAttachmentController::class, 'update'])->name('tasking-attachments.update');
        Route::delete('tasking-attachments/{attachment}', [TaskingAttachmentController::class, 'destroy'])->name('tasking-attachments.destroy');
        Route::get('tasking-attachments/{attachment}/thumb', [TaskingAttachmentController::class, 'thumb'])->name('tasking-attachments.thumb');
        Route::get('tasking-attachments/{attachment}/preview', [TaskingAttachmentController::class, 'preview'])->name('tasking-attachments.preview');
        Route::get('tasking-attachments/{attachment}/stream', [TaskingAttachmentController::class, 'stream'])->name('tasking-attachments.stream');
        Route::get('tasking-attachments/{attachment}/download', [TaskingAttachmentController::class, 'download'])->name('tasking-attachments.download');
    });
});
