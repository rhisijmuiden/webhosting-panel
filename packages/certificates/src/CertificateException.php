<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use RuntimeException;

/** A certificate that cannot be used, with a message for the user (already translated). */
final class CertificateException extends RuntimeException {}
