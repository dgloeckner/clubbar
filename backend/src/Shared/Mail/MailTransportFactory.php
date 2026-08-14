<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;

/**
 * Resolves `mail.dsn` to a transport (ADR-0038 rule 2).
 *
 * Absent DSN and `null://` are **not** the same thing, and keeping them apart
 * is the point of this class:
 *
 * | `mail.dsn`   | Meaning                          | Result                     |
 * |--------------|----------------------------------|----------------------------|
 * | unset/empty  | mail was never set up            | NullTransport, warns       |
 * | `null://null`| set up, and deliberately discards | Symfony's null transport   |
 * | anything else| a real transport                 | SymfonyMailerTransport     |
 *
 * The second row matters for a staging copy of a production database: it says
 * "yes, this is configured, and it must not reach members".
 */
class MailTransportFactory
{
    public function __construct(
        private AppConfig $config,
        private Logger $logger,
    ) {}

    /**
     * @throws InvalidMailDsnException when a DSN is present but unusable
     */
    public function create(): MailTransport
    {
        $dsn = $this->config->mailDsn;

        if ($dsn === null) {
            return new NullTransport($this->logger);
        }

        return new SymfonyMailerTransport(MailDsn::parse($dsn), $this->logger);
    }

    /**
     * The same resolution, but reported rather than performed — for the
     * self-check and the installer, neither of which may throw.
     */
    public function status(): MailTransportStatus
    {
        $dsn = $this->config->mailDsn;

        if ($dsn === null) {
            return MailTransportStatus::unconfigured();
        }

        try {
            return MailTransportStatus::ok(MailDsn::parse($dsn));
        } catch (InvalidMailDsnException $e) {
            return MailTransportStatus::invalid($e->getMessage());
        }
    }
}
