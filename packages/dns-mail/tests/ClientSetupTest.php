<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Rhis\DnsMail\ClientSetup\AppleMobileconfig;
use Rhis\DnsMail\ClientSetup\Autoconfig;
use Rhis\DnsMail\ClientSetup\Autodiscover;
use Rhis\DnsMail\ClientSetup\MailClientSettings;

final class ClientSetupTest extends TestCase
{
    private function settings(): MailClientSettings
    {
        return new MailClientSettings('jan@klant.nl', 'mail.rhis.nl', 'Jan & Co');
    }

    private static function load(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($xml));

        return $doc;
    }

    public function testAutoconfig(): void
    {
        $doc = self::load(Autoconfig::xml($this->settings()));
        self::assertSame('klant.nl', $doc->getElementsByTagName('domain')->item(0)->textContent);
        self::assertSame('993', $doc->getElementsByTagName('port')->item(0)->textContent);
    }

    public function testAutodiscoverRoundTrip(): void
    {
        $request = '<?xml version="1.0"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006"><Request><EMailAddress>jan@klant.nl</EMailAddress><AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>';
        self::assertSame('jan@klant.nl', Autodiscover::emailFromRequest($request));
        self::assertNull(Autodiscover::emailFromRequest('<x><EMailAddress>nope</EMailAddress></x>'));
        self::assertNull(Autodiscover::emailFromRequest('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x><EMailAddress>&e;</EMailAddress></x>'));

        $doc = self::load(Autodiscover::xml($this->settings()));
        self::assertSame('Jan & Co', $doc->getElementsByTagName('DisplayName')->item(0)->textContent);
    }

    public function testMobileconfigIsAValidStablePlist(): void
    {
        $a = AppleMobileconfig::plist($this->settings());
        $doc = self::load($a);
        self::assertSame('plist', $doc->documentElement->nodeName);
        self::assertSame($a, AppleMobileconfig::plist($this->settings()));
        self::assertMatchesRegularExpression('/<key>PayloadUUID<\/key><string>[0-9A-F]{8}-[0-9A-F]{4}-5[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}<\/string>/', $a);
        self::assertStringNotContainsString('<key>IncomingPassword</key>', $a);
    }

    public function testMobileconfigSigning(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'mail.rhis.nl'], $key), null, $key, 1);
        $dir = sys_get_temp_dir();
        $certFile = $dir . '/mc-test.crt';
        $keyFile = $dir . '/mc-test.key';
        openssl_x509_export_to_file($cert, $certFile);
        openssl_pkey_export_to_file($key, $keyFile);
        try {
            $der = AppleMobileconfig::sign(AppleMobileconfig::plist($this->settings()), $certFile, $keyFile);
            self::assertNotNull($der);
            self::assertSame("\x30", $der[0]);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }
}
