<?php

namespace App\Auth;

/**
 * Addresses the admin marked as trusted (office, home). A package that
 * keeps such a list (rhis/security) binds its own implementation; without
 * one nothing is trusted.
 */
interface TrustedNetworks
{
    public function contains(string $ip): bool;
}
