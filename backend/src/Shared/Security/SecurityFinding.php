<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * One row of the security self-check (#247, ADR-0031 decision 3).
 *
 * The shape encodes the rule the ADR puts on this report: *a row is green only
 * when the effective state was observed*. So every row carries an `observed`
 * string — what `ini_get()`, `stat()` or the webserver actually answered — and
 * a row that could not be measured is `UNKNOWN` rather than a pass. There is
 * deliberately no field for the value we intended.
 *
 * `remedy` is required on everything that is not green, because the ADR
 * accepts an outcome this project cannot fix ("your host does not allow this")
 * only when the report says what to do about it — including, sometimes, that
 * there is nothing to do.
 *
 * Dependency-free on purpose: `install.php` runs before Composer's autoloader
 * exists and loads this file by path.
 */
final class SecurityFinding
{
    /** Observed to be in the state this deployment depends on. */
    public const PASS = 'pass';

    /** Observed to be weaker than intended, and the deployment still works. */
    public const WARN = 'warn';

    /** Observed to be broken in a way that exposes credentials or member data. */
    public const FAIL = 'fail';

    /** Not measurable from here — never a pass. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $label,
        public readonly string $status,
        public readonly string $observed,
        public readonly ?string $remedy,
    ) {}

    public static function pass(string $id, string $category, string $label, string $observed): self
    {
        return new self($id, $category, $label, self::PASS, $observed, null);
    }

    public static function warn(string $id, string $category, string $label, string $observed, string $remedy): self
    {
        return new self($id, $category, $label, self::WARN, $observed, $remedy);
    }

    public static function fail(string $id, string $category, string $label, string $observed, string $remedy): self
    {
        return new self($id, $category, $label, self::FAIL, $observed, $remedy);
    }

    public static function unknown(string $id, string $category, string $label, string $observed, string $remedy): self
    {
        return new self($id, $category, $label, self::UNKNOWN, $observed, $remedy);
    }

    public function isPass(): bool
    {
        return $this->status === self::PASS;
    }

    /**
     * @return array{id:string,category:string,label:string,status:string,observed:string,remedy:?string}
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'category' => $this->category,
            'label'    => $this->label,
            'status'   => $this->status,
            'observed' => $this->observed,
            'remedy'   => $this->remedy,
        ];
    }
}
