<?php

declare(strict_types=1);

namespace Rhis\Waf\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firewall choice of one website. A site without a row follows the server default.
 *
 * @property string|null $mode block, detect, off, or null for the server default
 * @property list<array{rule: int, path: ?string, param: ?string, comment: string, added_by: ?string, added_at: string}>|null $exceptions
 */
class WafSite extends Model
{
    protected $fillable = ['website_id', 'mode', 'exceptions'];

    protected function casts(): array
    {
        return ['exceptions' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
