<?php

declare(strict_types=1);

namespace Rhis\WebRules;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;
use Rhis\WebRules\Models\SiteWebRules;

/**
 * Redirects & access: keeps the rules of a website and sends all of them to
 * the agent (webrules.apply), which writes the Nginx configuration.
 */
final class WebRules
{
    /** Paths on the website: the same characters the agent accepts. */
    public const PATH = '#^/(?:[A-Za-z0-9._~@+,=!-]+/?)*$#';

    public const REDIRECT_CODES = [301, 302, 307, 308];

    public const ERROR_CODES = [400, 401, 403, 404, 410, 429, 500, 502, 503, 504];

    public const HSTS_AGES = [300 => '5 minutes', 86400 => '1 day', 2592000 => '30 days', 15768000 => '6 months', 31536000 => '1 year', 63072000 => '2 years'];

    /** ISO 3166-1 alpha-2 codes, for country blocking. */
    public const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ '
        .'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR '
        .'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP '
        .'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT '
        .'MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
        .'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG '
        .'UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW';

    public function __construct(private readonly AgentClient $agent) {}

    public function forSite(Website $website): SiteWebRules
    {
        return SiteWebRules::firstOrNew(['website_id' => $website->id])->setRelation('website', $website);
    }

    /**
     * Save the rules and apply them on the server. When the server refuses
     * them, nothing is saved, so the panel always shows what really runs.
     *
     * @return array the agent's answer (missing_error_pages, https_active, ...)
     *
     * @throws AgentException
     */
    public function save(SiteWebRules $rules): array
    {
        $result = $this->agent->call('webrules.apply', $rules->agentParams(), 120);
        $rules->applied_at = now();
        $rules->save();

        return $result;
    }

    /** What the server offers: whether country blocking is possible. */
    public function status(?Website $website = null): array
    {
        try {
            return $this->agent->call('webrules.status', $website ? ['domain' => $website->domain] : []);
        } catch (AgentException) {
            return ['geoip' => ['available' => false, 'updated_at' => null], 'unreachable' => true];
        }
    }

    public static function id(): string
    {
        return Str::lower(Str::random(10));
    }

    /** SHA-512 crypt: what Nginx checks on every request, fast enough per request and not plain text. */
    public static function hash(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';
        for ($i = 0; $i < 16; $i++) {
            $salt .= $alphabet[random_int(0, 63)];
        }

        return crypt($password, '$6$'.$salt.'$');
    }

    public static function normalisePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    public static function validPath(string $path): bool
    {
        return strlen($path) <= 200 && preg_match(self::PATH, $path) === 1
            && ! str_contains($path.'/', '/../') && ! str_contains($path.'/', '/./');
    }

    public static function validTarget(string $to): bool
    {
        if ($to === '' || strlen($to) > 2000 || preg_match('/[\s"\'\\\\${};<>`]/', $to)) {
            return false;
        }
        if (str_starts_with($to, '/')) {
            return ! str_starts_with($to, '//');
        }

        return preg_match('#^https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:[/?\#].*)?$#', $to) === 1;
    }

    /** @return array<string, string> code => name, sorted by name in the current language */
    public static function countries(): array
    {
        $list = [];
        foreach (explode(' ', self::COUNTRIES) as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, app()->getLocale()) : '';
            $list[$code] = $name && $name !== $code ? $name : $code;
        }
        asort($list, SORT_LOCALE_STRING);

        return $list;
    }
}
