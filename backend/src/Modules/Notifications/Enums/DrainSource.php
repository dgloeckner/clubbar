<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * How a drain run was triggered (ADR-0038 rule 3).
 *
 * Two triggers, one behaviour — the `DrainService` does not branch on this and
 * must not start to. It is recorded because the two have different failure
 * modes and the person diagnosing a stalled queue needs to know which one they
 * are looking at:
 *
 * | Source | Preferred? | What goes wrong with it |
 * |---|---|---|
 * | `cli` | **Yes** | The panel's crontab picks a different PHP binary than the web — see {@see \App\Shared\Config\PhpRuntime} |
 * | `url` | Fallback | A gateway read timeout cuts the run mid-batch, and on the bare query-string variant the secret is in somebody's access log |
 *
 * A heartbeat that flips from `cli` to `url` is also a signal in itself: it
 * means the installation quietly changed how it is scheduled.
 */
enum DrainSource: string
{
    case CLI = 'cli';
    case URL = 'url';
}
