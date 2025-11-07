<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BrsComparisonController;


// Route::get('/', function () {
//     return view('welcome');
// });
Route::redirect('/', '/admin/login');
Route::post('/brs/compare', [BrsComparisonController::class, 'compare'])->name('brs.compare');

