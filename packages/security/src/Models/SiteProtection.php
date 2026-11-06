<?php

declare(strict_types=1);

namespace Rhis\Security\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Login protection choices of one website. A site without a row is fully
 * protected: monitoring on, WordPress login protection on, no allowlist.
 *
 * @property bool $enabled Watch this site's logs at all (attack detection and WordPress logins).
 * @property bool $wordpress Count failed WordPress logins (wp-login.php, xmlrpc.php).
 * @property list<array{address: string, comment: string}>|null $allowlist
 */
class SiteProtection extends Model
{
    protected $fillable = ['website_id', 'enabled', 'wordpress', 'allowlist'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'wordpress' => 'boolean', 'allowlist' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** True when WordPress logins are watched for this site. Other packages may read this. */
    public static function wordpressProtected(Website $website): bool
    {
        $p = static::where('website_id', $website->id)->first();

        return $p === null || ($p->enabled && $p->wordpress);
    }
}
