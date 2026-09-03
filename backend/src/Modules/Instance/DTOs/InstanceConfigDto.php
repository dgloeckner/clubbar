<?php

declare(strict_types=1);

namespace App\Modules\Instance\DTOs;

use App\Shared\Time\ClubTimeZone;

final readonly class InstanceConfigDto
{
    public function __construct(
        public string $instanceName,
        /**
         * The zone the club reads in — an IANA name such as `Europe/Berlin`.
         *
         * Deployment configuration (`CLUB_TIMEZONE`), not a stored column, so
         * it is never written back by the Settings tab that owns the name.
         *
         * It travels on this endpoint because the admin panel needs it before
         * it can render a single timestamp: the API labels every instant "Z"
         * (#365) and the browser used to convert them with whatever zone the
         * *reader* happened to be in. That agrees with the club's own clock
         * only while the reader is in the clubhouse — a Kassenwart reconciling
         * from a laptop abroad saw times the Deckelauszug in their inbox
         * disagreed with, and neither was wrong by its own rule. The club's
         * books are stated in the club's zone, so the panel reads in it too.
         */
        public string $timeZone,
        /**
         * Whether that zone was stated by the deployment (`configured`), left
         * unstated (`default`), or stated as something unusable (`invalid`).
         *
         * The fallback has to be silent where it is used — a mail with the
         * wrong hour still reaches somebody, one that throws reaches nobody —
         * so this is the only place it can be reported. The panel warns on the
         * two states that are accidents, because a club reading its books on
         * the wrong clock has nothing on any screen to tell it so.
         */
        public string $timeZoneSource,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            instanceName: $row['instance_name'] ?? 'Club Bar',
            timeZone: ClubTimeZone::name(),
            timeZoneSource: ClubTimeZone::source(),
        );
    }

    public function toArray(): array
    {
        return [
            'instance_name' => $this->instanceName,
            'time_zone' => $this->timeZone,
            'time_zone_source' => $this->timeZoneSource,
        ];
    }
}
