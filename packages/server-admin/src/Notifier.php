<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use Illuminate\Support\Facades\Mail;
use Rhis\ServerAdmin\Mail\ServerNotice;
use Throwable;

/** Mails to the administrators about maintenance, reboots and problems. */
final class Notifier
{
    public function __construct(private readonly Options $options) {}

    public function info(string $title, array $lines = [], ?string $intro = null): bool
    {
        return $this->send('info', $title, $lines, $intro);
    }

    public function warning(string $title, array $lines = [], ?string $intro = null): bool
    {
        return $this->send('warning', $title, $lines, $intro);
    }

    public function problem(string $title, array $lines = [], ?string $intro = null): bool
    {
        return $this->send('problem', $title, $lines, $intro);
    }

    private function send(string $level, string $title, array $lines, ?string $intro): bool
    {
        $to = $this->options->recipients();
        if (! $to) {
            return false;
        }
        try {
            Mail::to($to)->send(new ServerNotice($level, $title, array_values(array_filter($lines)), $intro, gethostname() ?: config('app.url')));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
