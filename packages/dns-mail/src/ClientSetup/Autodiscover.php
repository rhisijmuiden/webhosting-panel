<?php

declare(strict_types=1);

namespace Rhis\DnsMail\ClientSetup;

use DOMDocument;

/**
 * Outlook (classic, Windows/Mac) "POX" autodiscover:
 * POST https://autodiscover.<domain>/autodiscover/autodiscover.xml
 */
final class Autodiscover
{
    /** Extract the address from Outlook's request body, or null when it is not a valid request. */
    public static function emailFromRequest(string $body): ?string
    {
        if ($body === '' || strlen($body) > 10_000) {
            return null;
        }
        $doc = new DOMDocument();
        // LIBXML_NONET and no entity substitution: the body comes from the internet.
        if (!@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return null;
        }
        $nodes = $doc->getElementsByTagName('EMailAddress');
        $email = $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : '';

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function xml(MailClientSettings $s): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $host = $e($s->host);
        $email = $e($s->email);
        $name = $e($s->displayName !== '' ? $s->displayName : $s->email);

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <User>
      <DisplayName>{$name}</DisplayName>
    </User>
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
      <Protocol>
        <Type>IMAP</Type>
        <Server>{$host}</Server>
        <Port>{$s->imapPort}</Port>
        <LoginName>{$email}</LoginName>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$host}</Server>
        <Port>{$s->smtpPort}</Port>
        <LoginName>{$email}</LoginName>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
        <UsePOPAuth>off</UsePOPAuth>
        <SMTPLast>off</SMTPLast>
      </Protocol>
    </Account>
  </Response>
</Autodiscover>

XML;
    }
}
