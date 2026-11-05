<?php

declare(strict_types=1);

namespace Rhis\DnsMail\ClientSetup;

/**
 * Configuration profile for iPhone, iPad and Mac Mail. The user downloads it
 * from the client portal or webmail and taps "Install"; the password is
 * asked on the device, so it never sits in the file.
 *
 * Sign it with the server's TLS certificate (sign()) so iOS shows
 * "Verified" instead of "Not verified".
 */
final class AppleMobileconfig
{
    public static function plist(MailClientSettings $s, string $organisation = 'RHIS'): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $domain = $s->domain();
        $reverse = implode('.', array_reverse(explode('.', $domain)));
        $id = $e($reverse . '.mail.' . preg_replace('/[^a-z0-9]+/', '-', strtolower($s->email)));
        $profileUuid = self::uuid('profile:' . $s->email);
        $payloadUuid = self::uuid('mail:' . $s->email);
        $email = $e($s->email);
        $host = $e($s->host);
        $name = $e($s->displayName !== '' ? $s->displayName : $s->email);
        $org = $e($organisation);
        $domainE = $e($domain);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>EmailAccountDescription</key><string>{$email}</string>
      <key>EmailAccountName</key><string>{$name}</string>
      <key>EmailAccountType</key><string>EmailTypeIMAP</string>
      <key>EmailAddress</key><string>{$email}</string>
      <key>IncomingMailServerAuthentication</key><string>EmailAuthPassword</string>
      <key>IncomingMailServerHostName</key><string>{$host}</string>
      <key>IncomingMailServerPortNumber</key><integer>{$s->imapPort}</integer>
      <key>IncomingMailServerUseSSL</key><true/>
      <key>IncomingMailServerUsername</key><string>{$email}</string>
      <key>OutgoingMailServerAuthentication</key><string>EmailAuthPassword</string>
      <key>OutgoingMailServerHostName</key><string>{$host}</string>
      <key>OutgoingMailServerPortNumber</key><integer>{$s->smtpPort}</integer>
      <key>OutgoingMailServerUseSSL</key><true/>
      <key>OutgoingMailServerUsername</key><string>{$email}</string>
      <key>OutgoingPasswordSameAsIncomingPassword</key><true/>
      <key>PayloadDisplayName</key><string>{$email}</string>
      <key>PayloadIdentifier</key><string>{$id}.account</string>
      <key>PayloadType</key><string>com.apple.mail.managed</string>
      <key>PayloadUUID</key><string>{$payloadUuid}</string>
      <key>PayloadVersion</key><integer>1</integer>
      <key>PreventAppSheet</key><false/>
      <key>PreventMove</key><false/>
      <key>SMIMEEnabled</key><false/>
    </dict>
  </array>
  <key>PayloadDescription</key><string>Mail for {$email}</string>
  <key>PayloadDisplayName</key><string>{$org} Mail ({$domainE})</string>
  <key>PayloadIdentifier</key><string>{$id}</string>
  <key>PayloadOrganization</key><string>{$org}</string>
  <key>PayloadRemovalDisallowed</key><false/>
  <key>PayloadType</key><string>Configuration</string>
  <key>PayloadUUID</key><string>{$profileUuid}</string>
  <key>PayloadVersion</key><integer>1</integer>
</dict>
</plist>

XML;
    }

    /**
     * Sign the profile (DER PKCS#7) with a certificate and key the web user can read.
     * Returns null when signing is not possible, so the caller can serve it unsigned.
     */
    public static function sign(string $plist, string $certFile, string $keyFile, ?string $chainFile = null): ?string
    {
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'mc-in');
        $out = tempnam($dir, 'mc-out');
        if ($in === false || $out === false) {
            return null;
        }
        try {
            file_put_contents($in, $plist);
            $ok = openssl_pkcs7_sign(
                $in, $out, 'file://' . $certFile, 'file://' . $keyFile, [],
                PKCS7_BINARY | PKCS7_NOATTR, $chainFile,
            );
            if (!$ok) {
                return null;
            }
            // openssl_pkcs7_sign writes S/MIME; keep the base64 body and decode to DER.
            $smime = (string) file_get_contents($out);
            $parts = preg_split("/\r?\n\r?\n/", $smime, 2);
            $der = base64_decode(preg_replace('/\s+/', '', $parts[1] ?? '') ?? '', true);

            return $der === false || $der === '' ? null : $der;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** Stable UUID so re-downloading replaces the profile instead of adding a second one. */
    private static function uuid(string $seed): string
    {
        $h = md5('rhis-webhosting-panel:' . strtolower($seed));

        return strtoupper(sprintf('%s-%s-5%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)) . substr($h, 17, 3), substr($h, 20, 12)));
    }
}
