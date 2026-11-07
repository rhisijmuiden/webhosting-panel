<?php

namespace App\Http\Controllers\FtpAccounts;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Admins manage the FTP accounts of every website; package limits do not apply to them. */
class AdminFtpAccountController extends FtpAccountController
{
    protected string $area = 'admin';

    protected function websites(Request $request): Builder
    {
        return Website::query();
    }

    protected function enforceLimit(): bool
    {
        return false;
    }
}
