<?php

namespace App\Http\Controllers;

use App\Services\DashboardSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSummary $summary): Response
    {
        return Inertia::render('dashboard', $summary->forUser($request->user()));
    }
}
