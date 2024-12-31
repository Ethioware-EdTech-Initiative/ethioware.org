<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DownloadController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/increment-download', [DownloadController::class, 'incrementDownload']);
