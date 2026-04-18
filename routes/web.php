<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teste\Teste01Controller;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Actl\PostalCodeController;

Route::get('/', function () {
    return redirect()->route('login');
    
});

Route::get('/dashboard', function () {
    return view('admin.index');
})->middleware(['auth', 'verified'])->name('dashboard');

// Admin All Route
Route::controller(AdminController::class)->group(function () {
    Route::get('/admin/logout', 'destroy')->name('admin.logout');
    Route::get('/admin/profile', 'profile')->name('admin.profile');
    Route::get('/edit/profile', 'editProfile')->name('edit.profile');
    Route::post('/store/profile', 'StoreProfile')->name('store.profile');

    Route::get('/change/password', 'ChangePassword')->name('change.password');
    Route::post('/update/password', 'UpdatePassword')->name('update.password');    
});

// Testes MVC
Route::controller(Teste01Controller::class)->group(function () {
    Route::post('/acercade', 'Facerca')->name('acercade.pagina')->middleware('valida_idade');
    Route::get('/contactos', 'Fcontactos')->name('contactos.pagina');
});


// ROTAS DO ERP4U
Route::controller(PostalCodeController::class)->group(function () {
    Route::get('/postalCode/all', 'PostalCodeAll')->name('postalCode.all');
    Route::get('/postalCode/add', 'PostalCodeAdd')->name('postalCode.add');
    Route::post('/postalCode/store', 'PostalCodeStore')->name('postalCode.store');
    Route::get('/postalCode/edit/{id}', 'PostalCodeEdit')->name('postalCode.edit');
    Route::post('/postalCode/update', 'PostalCodeUpdate')->name('postalCode.update');
    Route::get('/postalCode/delete/{id}', 'PostalCodeDelete')->name('postalCode.delete');
});

require __DIR__.'/auth.php';

