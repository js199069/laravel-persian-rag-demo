<?php

use App\Http\Controllers\RagDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RagDemoController::class, 'index']);
Route::get('/rag/search', [RagDemoController::class, 'search']);
Route::get('/rag/answer', [RagDemoController::class, 'answer']);