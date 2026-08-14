<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Enums\MailKind;
use App\Shared\Mail\MailMessage;

/**
 * Which builder renders which kind.
 *
 * The drain (#403) holds one of these and nothing else about content. Adding a
 * notification type is then a new `MailContentBuilder` and one line in the
 * service factory — not an edit to the sending loop, which is the piece that
 * must stay boring.
 *
 * An unknown kind throws rather than returning null. A kind reaches this class
 * only because something enqueued it, so "nothing can render this" is a
 * programming error that has already written a row somebody is waiting on, and
 * the drain records it against the message where the Kassenwart can see it.
 */
final class MailContentRegistry
{
    /** @var list<MailContentBuilder> */
    private array $builders;

    public function __construct(MailContentBuilder ...$builders)
    {
        $this->builders = array_values($builders);
    }

    public function build(array $outboxRow): MailMessage
    {
        return $this->builderFor(MailKind::from((string) $outboxRow['kind']))->build($outboxRow);
    }

    public function supports(MailKind $kind): bool
    {
        foreach ($this->builders as $builder) {
            if ($builder->supports($kind)) {
                return true;
            }
        }

        return false;
    }

    private function builderFor(MailKind $kind): MailContentBuilder
    {
        foreach ($this->builders as $builder) {
            if ($builder->supports($kind)) {
                return $builder;
            }
        }

        throw new \LogicException(sprintf(
            'Nothing can render a %s message. It was queued by something that has no content for it — '
            . 'the row will stay unsent until a MailContentBuilder claims the kind.',
            $kind->value,
        ));
    }
}
