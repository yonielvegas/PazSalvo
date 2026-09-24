<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class InstitutionalAccessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return auth()->check()
            ? redirect()->route('paz-salvo.index')
            : redirect()->route('login');
    }
}
