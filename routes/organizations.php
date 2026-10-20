<?php

use App\Http\Controllers\Organizations\OrganizationContactController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrganizationLogoController;
use App\Http\Controllers\Organizations\OrganizationSiteController;
use App\Http\Controllers\Organizations\SiteFormationController;
use App\Http\Controllers\Organizations\SitePhotoController;
use Illuminate\Support\Facades\Route;

// Organizations (Phase 4B). {organization} is a national directory entry;
// {site} and {contact} are bound through their scopes (jurisdiction and
// classification): anything else is 404.
Route::middleware(['auth'])->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index')->middleware('lens');
    Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizations/similar', [OrganizationController::class, 'similar'])->name('organizations.similar');
    Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');

    Route::whereUlid('organization')->group(function () {
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::get('organizations/{organization}/edit', [OrganizationController::class, 'edit'])->name('organizations.edit');
        Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
        Route::get('organizations/{organization}/sites/create', [OrganizationSiteController::class, 'create'])->name('organization-sites.create');
        Route::post('organizations/{organization}/sites', [OrganizationSiteController::class, 'store'])->name('organization-sites.store');
        // Logos (Phase 11D): the media ULID is in the URL; replaced logos are 404.
        Route::post('organizations/{organization}/logo', [OrganizationLogoController::class, 'store'])->name('organizations.logo.store');
        Route::delete('organizations/{organization}/logo', [OrganizationLogoController::class, 'destroy'])->name('organizations.logo.destroy');
        Route::get('organizations/{organization}/logo/{media}/{conversion?}', [OrganizationLogoController::class, 'show'])
            ->whereUlid('media')
            ->whereIn('conversion', ['thumb'])
            ->name('organizations.logo.show');
    });

    Route::whereUlid('site')->group(function () {
        Route::get('organization-sites/{site}', [OrganizationSiteController::class, 'show'])->name('organization-sites.show');
        Route::get('organization-sites/{site}/edit', [OrganizationSiteController::class, 'edit'])->name('organization-sites.edit');
        Route::put('organization-sites/{site}', [OrganizationSiteController::class, 'update'])->name('organization-sites.update');
        Route::delete('organization-sites/{site}', [OrganizationSiteController::class, 'destroy'])->name('organization-sites.destroy');
        Route::post('organization-sites/{site}/formations', [SiteFormationController::class, 'store'])->name('organization-sites.formations.store');
        Route::delete('organization-sites/{site}/formations/{link}', [SiteFormationController::class, 'destroy'])->whereUlid('link')->name('organization-sites.formations.destroy');
        Route::post('organization-sites/{site}/contacts', [OrganizationContactController::class, 'store'])->name('organization-contacts.store');
        // Site photos (Phase 11D): {photo} is bound through its scopes and must be the site's.
        Route::post('organization-sites/{site}/photos', [SitePhotoController::class, 'store'])->name('organization-site-photos.store');
        Route::put('organization-sites/{site}/photos/order', [SitePhotoController::class, 'reorder'])->name('organization-site-photos.reorder');
        Route::whereUlid('photo')->group(function () {
            Route::put('organization-sites/{site}/photos/{photo}', [SitePhotoController::class, 'update'])->name('organization-site-photos.update');
            Route::put('organization-sites/{site}/photos/{photo}/cover', [SitePhotoController::class, 'cover'])->name('organization-site-photos.cover');
            Route::delete('organization-sites/{site}/photos/{photo}', [SitePhotoController::class, 'destroy'])->name('organization-site-photos.destroy');
            Route::get('organization-sites/{site}/photos/{photo}/thumb', [SitePhotoController::class, 'thumb'])->name('organization-site-photos.thumb');
            Route::get('organization-sites/{site}/photos/{photo}/preview', [SitePhotoController::class, 'preview'])->name('organization-site-photos.preview');
            Route::get('organization-sites/{site}/photos/{photo}/original', [SitePhotoController::class, 'original'])->name('organization-site-photos.original');
        });
    });

    Route::whereUlid('contact')->group(function () {
        Route::put('organization-contacts/{contact}', [OrganizationContactController::class, 'update'])->name('organization-contacts.update');
        Route::delete('organization-contacts/{contact}', [OrganizationContactController::class, 'destroy'])->name('organization-contacts.destroy');
    });
});
