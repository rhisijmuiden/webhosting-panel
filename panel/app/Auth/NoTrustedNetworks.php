<?php

namespace App\Auth;

final class NoTrustedNetworks implements TrustedNetworks
{
    public function contains(string $ip): bool
    {
        return false;
    }
}
