<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ToolsCatalog;
use Illuminate\View\View;

class ToolsController extends Controller
{
    public function index(): View
    {
        return view('admin.tools.index', ['groups' => ToolsCatalog::groups()]);
    }
}
