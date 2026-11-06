<?php

declare(strict_types=1);

namespace Rhis\Subdomains;

/** A subdomain or clone request that cannot be done; the message is shown to the user. */
final class SubdomainException extends \RuntimeException {}
