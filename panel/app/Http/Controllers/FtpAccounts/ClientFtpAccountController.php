<?php

namespace App\Http\Controllers\FtpAccounts;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Customers: FTP accounts of their own websites, within the limit of their package. */
class ClientFtpAccountController extends FtpAccountController
{
    protected string $area = 'client';

    protected function websites(Request $request): Builder
    {
        return Website::query()->where('customer_id', $request->user()->customer_id ?? -1);
    }

    protected function canManage(): bool
    {
        return (bool) config('ftp-accounts.customer_manage');
    }
}
