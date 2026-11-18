<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function signer(Request $r, string $key = 'signer_id'): int
    {
        return (int) $r->input($key);
    }

    protected function ok(string $msg)
    {
        return back()->with('status', $msg);
    }
}
