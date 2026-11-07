<?php

declare(strict_types=1);

namespace Rhis\WebRules\Http;

use App\Models\Website;
use Illuminate\Http\Request;

/** Admins: the rules of every website. */
final class AdminWebRulesController extends WebRulesController
{
    protected string $area = 'admin';

    protected function authorizeSite(Request $request, Website $website): void {}
}
