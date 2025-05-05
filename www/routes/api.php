<?php

use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::post('/upload', [FileController::class, 'upload'])->middleware('api');
Route::delete('/files/{uuid}', [FileController::class, 'destroy'])->middleware('api');
