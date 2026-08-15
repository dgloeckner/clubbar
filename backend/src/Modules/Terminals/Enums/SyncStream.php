<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * A delta-sync stream a terminal carries its own cursor for (ADR-0041).
 *
 * The three pull endpoints advance independently — `SyncCursor` issues a
 * watermark per entity, and the terminal stores three separate values in its
 * `sync_state` store. A regression is therefore a fact about one stream, not
 * about the terminal, which is why the cursor table is keyed by both.
 *
 * The push endpoint has no cursor (the terminal drives it from a local
 * `synced` flag), and `GET /terminal/transactions/{memberId}` takes a `since`
 * that is an ISO datetime filter rather than a delta cursor — neither belongs
 * here, and neither should ever be observed as one.
 */
enum SyncStream: string
{
    case MEMBERS = 'members';
    case PRODUCTS = 'products';
    case CATEGORIES = 'categories';
}
