<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 13 ships a bare base controller. AuthorizesRequests is pulled in here
 * so `$this->authorize()` is available everywhere -- authorisation is a
 * cross-cutting requirement in this application (FR-1.6, FR-3.6), not something
 * individual controllers should opt into and occasionally forget.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
