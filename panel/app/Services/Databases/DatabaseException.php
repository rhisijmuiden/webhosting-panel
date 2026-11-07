<?php

namespace App\Services\Databases;

use RuntimeException;

/** A database action the package or the input does not allow; the message is for the user. */
class DatabaseException extends RuntimeException {}
