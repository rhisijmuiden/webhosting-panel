<?php
/**
 * Plugin Name: Website mail (hosting panel)
 * Description: Sends all WordPress mail through the mail server of this hosting server, from an address of this website and signed with DKIM. Managed from the hosting panel; turn it off there.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

(static function () {
    $cfg = @include __DIR__ . '/whp-mail/config.php';
    if (!is_array($cfg) || empty($cfg['from_email']) || empty($cfg['port'])) {
        return;
    }
    // A copy of this website (clone, staging copy, restore somewhere else) carries
    // these files along but must never send as the live website.
    $here = rtrim((string) realpath(ABSPATH), '/');
    if ($here === '' || $here !== rtrim((string) ($cfg['abspath'] ?? ''), '/')) {
        return;
    }
    if (!defined('WHP_MAIL')) {
        define('WHP_MAIL', true);
    }

    $domains = array_map('strtolower', (array) ($cfg['domains'] ?? []));
    $own = static function ($address) use ($domains) {
        $at = strrpos((string) $address, '@');

        return $at !== false && in_array(strtolower(substr((string) $address, $at + 1)), $domains, true);
    };

    $log = static function (array $entry) use ($cfg) {
        $file = (string) ($cfg['log'] ?? '');
        if ($file === '') {
            return;
        }
        $entry = ['t' => time(), 'install' => (string) ($cfg['path'] ?? '')] + $entry;
        if (@filesize($file) > 1048576) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    };

    $cut = static function ($text, $length) {
        $text = (string) $text;

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    };

    $addresses = static function ($list) {
        $out = [];
        foreach ((array) $list as $row) {
            $out[] = is_array($row) ? (string) ($row[0] ?? '') : (string) $row;
        }

        return array_slice(array_values(array_filter($out)), 0, 20);
    };

    // Last in line, so it also wins over SMTP plugins that hook into the same mailer.
    add_action('phpmailer_init', static function ($mailer) use ($cfg, $own, $log, $addresses, $cut) {
        if (defined('WHP_STAGING')) {
            return; // A staging copy holds mail back before it gets here; never send from one.
        }
        $from = (string) $mailer->From;
        $name = (string) $mailer->FromName;
        $default = $from === '' || stripos($from, 'wordpress@') === 0;
        if ($default || (!$own($from) && !empty($cfg['force_from']))) {
            // A contact form that sends "from" the visitor: answer goes to the visitor,
            // the message itself comes from the website so SPF, DKIM and DMARC pass.
            if (!$default && $from !== '' && !$mailer->getReplyToAddresses()) {
                $mailer->addReplyTo($from, $name);
            }
            $mailer->From = (string) $cfg['from_email'];
            if (($name === '' || $name === 'WordPress') && !empty($cfg['from_name'])) {
                $mailer->FromName = (string) $cfg['from_name'];
            }
        } elseif ($name === 'WordPress' && !empty($cfg['from_name'])) {
            $mailer->FromName = (string) $cfg['from_name'];
        }
        // Envelope sender in the same domain as the From header: SPF and DMARC line up.
        $mailer->Sender = $mailer->From;

        $mailer->isSMTP();
        $mailer->Host = '127.0.0.1';
        $mailer->Port = (int) $cfg['port'];
        $mailer->SMTPAuth = false;
        $mailer->Username = '';
        $mailer->Password = '';
        $mailer->SMTPSecure = '';
        $mailer->SMTPAutoTLS = false; // loopback: nothing to encrypt
        $mailer->SMTPOptions = [];
        $mailer->Timeout = 20;
        $mailer->addCustomHeader('X-Website', (string) ($cfg['site'] ?? ''));

        $mailer->action_function = static function ($ok, $to, $cc, $bcc, $subject, $body, $sender, $extra = []) use ($log, $addresses, $cut) {
            if ($ok) {
                $log(['ok' => true, 'to' => $addresses(array_merge((array) $to, (array) $cc, (array) $bcc)),
                    'from' => (string) $sender, 'subject' => $cut($subject, 150),
                    'queue' => is_array($extra) ? ($extra['smtp_transaction_id'] ?? null) : null]);
            }
        };
    }, PHP_INT_MAX);

    add_action('wp_mail_failed', static function ($error) use ($log, $addresses, $cut) {
        $data = is_object($error) && method_exists($error, 'get_error_data') ? (array) $error->get_error_data() : [];
        $log(['ok' => false, 'to' => $addresses($data['to'] ?? []), 'subject' => $cut($data['subject'] ?? '', 150),
            'error' => is_object($error) && method_exists($error, 'get_error_message') ? $cut($error->get_error_message(), 500) : 'unknown error']);
    });
})();
