<?php

declare(strict_types=1);

namespace Rhis\DnsMail\ClientSetup;

/**
 * Thunderbird / K-9 / FairEmail / many others:
 * GET https://autoconfig.<domain>/mail/config-v1.1.xml?emailaddress=<address>
 */
final class Autoconfig
{
    public static function xml(MailClientSettings $s): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $domain = $e($s->domain());
        $host = $e($s->host);
        $brand = $e($s->brandName);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
  <emailProvider id="{$domain}">
    <domain>{$domain}</domain>
    <displayName>{$brand} Mail</displayName>
    <displayShortName>{$brand}</displayShortName>
    <incomingServer type="imap">
      <hostname>{$host}</hostname>
      <port>{$s->imapPort}</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>{$host}</hostname>
      <port>{$s->smtpPort}</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
    <outgoingServer type="smtp">
      <hostname>{$host}</hostname>
      <port>{$s->submissionPort}</port>
      <socketType>STARTTLS</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
  </emailProvider>
</clientConfig>

XML;
    }
}
