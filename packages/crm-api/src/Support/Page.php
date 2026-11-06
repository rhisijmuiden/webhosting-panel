<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Cursor pagination on the primary key: {data, next_cursor}. */
final class Page
{
    public static function of(Builder $query, Request $request, callable $present): array
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $key = $query->getModel()->getKeyName();
        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $after = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($after === false) {
                throw new ApiError(422, 'validation_failed', 'Invalid cursor', ['cursor' => ['Invalid cursor']]);
            }
            $query->where($key, '>', $after);
        }
        $rows = $query->orderBy($key)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'data' => $rows->map($present)->values()->all(),
            'next_cursor' => $more ? rtrim(strtr(base64_encode((string) $rows->last()->getKey()), '+/', '-_'), '=') : null,
        ];
    }
}
