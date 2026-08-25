<?php

declare(strict_types=1);

namespace App;

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Logging\Logger;
use App\Shared\Validation\Validator;

// Repositories
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Reports\Repositories\ReportsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\KeyRotationService;
use App\Modules\Security\Controllers\EncryptionKeysController;
use App\Shared\Security\IbanSealedBox;
use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\CreditLimits\Repositories\CreditLimitConfigRepository;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Repositories\DeckelStatementRepository;
use App\Modules\Notifications\Repositories\StatementRecipientsRepository;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use App\Modules\Transactions\Services\JugendschutzViolationService;
use App\Modules\Transactions\Repositories\TransactionsRepository;

// BankCodes
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\BankCodes\Controllers\AdminController as BankCodesAdminController;

// Services
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\AuditLog\Services\AuditLogService;
use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Services\AuditService;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Auth\Services\TotpService;
use App\Modules\Products\Services\CategoriesService;
use App\Shared\Services\HealthCheckService;
use App\Shared\Http\CurlHttpClient;
use App\Shared\Services\SecurityCheckService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Products\Services\ProductsService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\CreditLimits\Repositories\NearLimitRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Notifications\Services\CredentialExpiryMailBuilder;
use App\Modules\Notifications\Services\EncryptionKeyEventMailBuilder;
use App\Modules\Notifications\Services\CredentialExpiryNotifier;
use App\Modules\Notifications\Services\DeckelStatementMailBuilder;
use App\Modules\Notifications\Services\DeckelStatementService;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\DrainService;
use App\Modules\Notifications\Services\HeartbeatPinger;
use App\Modules\Notifications\Services\MailDeliveryCheck;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\CreditLimitDigestMailBuilder;
use App\Modules\Notifications\Services\CreditLimitDigestNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestService;
use App\Modules\Notifications\Services\MailContentRegistry;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\PeriodicEnqueueService;
use App\Modules\Notifications\Services\TestMailService;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Modules\Notifications\Services\SettlementMailBuilder;
use App\Modules\Notifications\Services\JugendschutzViolationMailBuilder;
use App\Modules\Notifications\Services\TerminalAnomalyMailBuilder;
use App\Modules\Notifications\Services\TerminalTokenIssuedMailBuilder;
use App\Modules\Notifications\Services\AdminSecurityMailBuilder;
use App\Shared\Mail\MailTransportFactory;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Terminals\Services\TerminalAnomalyDetector;
use App\Modules\Terminals\Services\TerminalsService;
use App\Modules\Terminals\Services\TerminalSyncCursorService;
use App\Modules\Terminals\Services\TerminalTokenAuthenticator;
use App\Modules\Transactions\Services\TransactionsService;

// Controllers
use App\Modules\AdminUsers\Controllers\AdminController as AdminUsersAdminController;
use App\Modules\AuditLog\Controllers\AdminController as AuditLogAdminController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Dashboard\Controllers\AdminController as DashboardAdminController;
use App\Shared\Controllers\HealthController;
use App\Shared\Controllers\SecurityCheckController;
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Products\Controllers\SyncController as ProductsSyncController;
use App\Modules\Settlements\Controllers\AdminController as SettlementsAdminController;
use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\Instance\Controllers\InstanceConfigController;
use App\Modules\CreditLimits\Controllers\CreditLimitConfigController;
use App\Modules\CreditLimits\Controllers\SyncController as CreditLimitSyncController;
use App\Modules\Backups\Controllers\BackupCronController;
use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupKeyring;
use App\Modules\Backups\Services\BackupService;
use App\Modules\Backups\Services\DatabaseDump;
use App\Modules\Notifications\Controllers\CronController;
use App\Modules\Notifications\Controllers\MailConfigController;
use App\Modules\Notifications\Controllers\NotificationsController;
use App\Modules\Notifications\Controllers\SchedulerController;
use App\Modules\Terminals\Controllers\AdminController as TerminalsAdminController;
use App\Modules\Terminals\Controllers\PairingController;
use App\Modules\Terminals\Services\PairingService;
use App\Modules\Transactions\Controllers\AdminController as TransactionsAdminController;
use App\Modules\Transactions\Controllers\SyncController as TransactionsSyncController;

// Reports
use App\Modules\Reports\Controllers\AdminController as ReportsAdminController;
use App\Modules\Reports\Services\ReportsService;

// Middleware
use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Shared\Middleware\CorsMiddleware;
use App\Shared\Middleware\CsrfMiddleware;
use App\Shared\Middleware\ErrorHandler;
use App\Shared\Middleware\JsonBodyParser;
use App\Shared\Middleware\RateLimitMiddleware;
use App\Shared\Middleware\SecurityHeaders;
use App\Shared\Middleware\TerminalOasValidator;
use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;

use PDO;
use Psr\Container\ContainerInterface;

class ServiceFactory implements ContainerInterface
{
    private array $instances = [];

    /**
     * Maps FQCN to getter method names, since multiple modules have identically-named
     * classes (e.g. AdminController). Slim resolves controllers via ContainerInterface::get($fqcn).
     */
    private const FQCN_MAP = [
        // Shared
        HealthController::class => 'getHealthController',
        SecurityCheckController::class => 'getSecurityCheckController',
        EncryptionKeysController::class => 'getEncryptionKeysController',

        // Members
        MembersAdminController::class => 'getMembersAdminController',
        MembersSyncController::class => 'getMembersSyncController',

        // Products
        ProductsAdminController::class => 'getProductsAdminController',
        ProductsSyncController::class => 'getProductsSyncController',

        // Transactions
        TransactionsAdminController::class => 'getTransactionsAdminController',
        TransactionsSyncController::class => 'getTransactionsSyncController',

        // Settlements
        SettlementsAdminController::class => 'getSettlementsAdminController',
        SepaConfigController::class => 'getSepaConfigController',
        InstanceConfigController::class => 'getInstanceConfigController',
        CreditLimitConfigController::class => 'getCreditLimitConfigController',
        CreditLimitSyncController::class => 'getCreditLimitSyncController',

        // Notifications
        MailConfigController::class => 'getMailConfigController',
        NotificationsController::class => 'getNotificationsController',
        SchedulerController::class => 'getSchedulerController',
        BackupCronController::class => 'getBackupCronController',
        CronController::class => 'getCronController',

        // AdminUsers
        AdminUsersAdminController::class => 'getAdminUsersAdminController',

        // AuditLog
        AuditLogAdminController::class => 'getAuditLogAdminController',
        AuditLogService::class => 'getAuditLogService',

        // Terminals
        TerminalsAdminController::class => 'getTerminalsAdminController',
        PairingController::class => 'getPairingController',

        // Dashboard
        DashboardAdminController::class => 'getDashboardAdminController',
        DashboardService::class => 'getDashboardService',
        JugendschutzViolationsRepository::class => 'getJugendschutzViolationsRepository',
        JugendschutzViolationService::class => 'getJugendschutzViolationService',

        // Reports
        ReportsAdminController::class => 'getReportsAdminController',
        ReportsService::class => 'getReportsService',
        ReportsRepository::class => 'getReportsRepository',

        // BankCodes
        BankCodesAdminController::class => 'getBankCodesAdminController',

        // Auth
        AuthController::class => 'getAuthController',

        // Middleware
        AdminSessionAuth::class => 'getAdminSessionAuth',
        TerminalTokenAuth::class => 'getTerminalTokenAuth',
        CorsMiddleware::class => 'getCorsMiddleware',
        CsrfMiddleware::class => 'getCsrfMiddleware',
        JsonBodyParser::class => 'getJsonBodyParser',
        ErrorHandler::class => 'getErrorHandler',
        RateLimitMiddleware::class => 'getRateLimitMiddleware',
        SecurityHeaders::class => 'getSecurityHeaders',
    ];

    public function __construct(
        private PDO $pdo,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getConfig(): AppConfig
    {
        return $this->config;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getValidator(): Validator
    {
        return $this->resolve(Validator::class, fn() => new Validator($this->pdo));
    }

    // --- Repositories ---

    public function getAdminUsersRepository(): AdminUsersRepository
    {
        return $this->resolve(AdminUsersRepository::class, fn() => new AdminUsersRepository($this->pdo, $this->logger));
    }

    public function getAdminUserRolesRepository(): AdminUserRolesRepository
    {
        return $this->resolve(
            AdminUserRolesRepository::class,
            fn() => new AdminUserRolesRepository($this->pdo, $this->logger)
        );
    }

    public function getAuditLogRepository(): AuditLogRepository
    {
        return $this->resolve(AuditLogRepository::class, fn() => new AuditLogRepository($this->pdo, $this->logger));
    }

    public function getCategoriesRepository(): CategoriesRepository
    {
        return $this->resolve(CategoriesRepository::class, fn() => new CategoriesRepository($this->pdo, $this->logger));
    }

    public function getMembersRepository(): MembersRepository
    {
        return $this->resolve(MembersRepository::class, fn() => new MembersRepository($this->pdo, $this->logger, $this->getIbanSealedBox(), $this->getEncryptionKeysRepository()));
    }

    public function getProductsRepository(): ProductsRepository
    {
        return $this->resolve(ProductsRepository::class, fn() => new ProductsRepository($this->pdo, $this->logger));
    }

    public function getSepaConfigRepository(): SepaConfigRepository
    {
        return $this->resolve(SepaConfigRepository::class, fn() => new SepaConfigRepository($this->pdo, $this->logger));
    }

    public function getInstanceConfigRepository(): InstanceConfigRepository
    {
        return $this->resolve(InstanceConfigRepository::class, fn() => new InstanceConfigRepository($this->pdo, $this->logger));
    }

    public function getCreditLimitConfigRepository(): CreditLimitConfigRepository
    {
        return $this->resolve(CreditLimitConfigRepository::class, fn() => new CreditLimitConfigRepository($this->pdo, $this->logger));
    }

    public function getMailConfigRepository(): MailConfigRepository
    {
        return $this->resolve(MailConfigRepository::class, fn() => new MailConfigRepository($this->pdo, $this->logger));
    }

    public function getMailOutboxRepository(): MailOutboxRepository
    {
        return $this->resolve(MailOutboxRepository::class, fn() => new MailOutboxRepository($this->pdo, $this->logger));
    }

    public function getCronHeartbeatRepository(): CronHeartbeatRepository
    {
        return $this->resolve(CronHeartbeatRepository::class, fn() => new CronHeartbeatRepository($this->pdo));
    }

    public function getLoginAttemptsRepository(): LoginAttemptsRepository
    {
        return $this->resolve(LoginAttemptsRepository::class, fn() => new LoginAttemptsRepository($this->pdo));
    }

    /**
     * The same repository pointed at `terminal_auth_attempts`, which has no
     * `email` column: terminal auth presents a token, not an account.
     */
    public function getTerminalAuthAttemptsRepository(): LoginAttemptsRepository
    {
        return $this->resolve(
            LoginAttemptsRepository::class . ':terminal',
            fn() => new LoginAttemptsRepository($this->pdo, 'terminal_auth_attempts'),
        );
    }

    public function getSettlementsRepository(): SettlementsRepository
    {
        return $this->resolve(SettlementsRepository::class, fn() => new SettlementsRepository($this->pdo, $this->logger));
    }

    public function getSettlementAnnouncementsRepository(): SettlementAnnouncementsRepository
    {
        return $this->resolve(SettlementAnnouncementsRepository::class, fn() => new SettlementAnnouncementsRepository($this->pdo, $this->logger));
    }

    public function getSettlementReversalsRepository(): SettlementReversalsRepository
    {
        return $this->resolve(SettlementReversalsRepository::class, fn() => new SettlementReversalsRepository($this->pdo, $this->logger));
    }

    public function getCollectionHoldRepository(): CollectionHoldRepository
    {
        return $this->resolve(CollectionHoldRepository::class, fn() => new CollectionHoldRepository($this->pdo, $this->logger));
    }

    public function getTerminalsRepository(): TerminalsRepository
    {
        return $this->resolve(TerminalsRepository::class, fn() => new TerminalsRepository($this->pdo, $this->logger));
    }

    public function getTerminalIpSightingsRepository(): TerminalIpSightingsRepository
    {
        return $this->resolve(TerminalIpSightingsRepository::class, fn() => new TerminalIpSightingsRepository($this->pdo));
    }

    public function getTerminalSyncCursorsRepository(): TerminalSyncCursorsRepository
    {
        return $this->resolve(TerminalSyncCursorsRepository::class, fn() => new TerminalSyncCursorsRepository($this->pdo));
    }

    public function getTerminalAnomaliesRepository(): TerminalAnomaliesRepository
    {
        return $this->resolve(TerminalAnomaliesRepository::class, fn() => new TerminalAnomaliesRepository($this->pdo));
    }

    public function getTransactionsRepository(): TransactionsRepository
    {
        return $this->resolve(TransactionsRepository::class, fn() => new TransactionsRepository($this->pdo, $this->logger));
    }

    public function getBankCodesRepository(): BankCodesRepository
    {
        return $this->resolve(BankCodesRepository::class, fn() => new BankCodesRepository($this->pdo, $this->logger));
    }

    // --- Services ---

    public function getAuditService(): AuditService
    {
        return $this->resolve(AuditService::class, fn() => new AuditService($this->getAuditLogRepository()));
    }

    public function getAuthService(): AuthService
    {
        return $this->resolve(AuthService::class, fn() => new AuthService($this->getAdminUsersRepository(), $this->logger));
    }

    public function getTotpService(): TotpService
    {
        return $this->resolve(TotpService::class, fn() => new TotpService($this->getInstanceConfigService()));
    }

    /**
     * Takes the notifications service so an email change can tell the address
     * it moved from. Not a cycle: `NotificationsService` reaches for the admin
     * *repository*, never this service.
     */
    public function getAdminUsersService(): AdminUsersService
    {
        return $this->resolve(AdminUsersService::class, fn() => new AdminUsersService(
            $this->getAdminUsersRepository(),
            $this->getAuditService(),
            $this->getNotificationsService(),
            $this->getAdminUserRolesRepository(),
            $this->getAdminNotifier(),
        ));
    }

    public function getStepUpAuthService(): StepUpAuthService
    {
        return $this->resolve(StepUpAuthService::class, fn() => new StepUpAuthService(
            $this->getAdminUsersService(),
            $this->getTotpService(),
            $this->getAuditService(),
            $this->getLoginAttemptsRepository(),
        ));
    }

    public function getIbanSealedBox(): IbanSealedBox
    {
        return $this->resolve(IbanSealedBox::class, fn() => new IbanSealedBox(
            Env::get('IBAN_FINGERPRINT_KEY', ''),
            Env::get('APP_ENV', 'production'),
        ));
    }

    public function getEncryptionKeysRepository(): EncryptionKeysRepository
    {
        return $this->resolve(EncryptionKeysRepository::class, fn() => new EncryptionKeysRepository($this->pdo, $this->logger));
    }

    public function getSealedIbanRepository(): SealedIbanRepository
    {
        return $this->resolve(SealedIbanRepository::class, fn() => new SealedIbanRepository($this->pdo));
    }

    public function getEncryptionKeyService(): EncryptionKeyService
    {
        return $this->resolve(EncryptionKeyService::class, fn() => new EncryptionKeyService(
            $this->getEncryptionKeysRepository(),
            $this->getSealedIbanRepository(),
            $this->getIbanSealedBox(),
            $this->getAuditService(),
            // AdminNotifier rather than the whole NotificationsService: that one
            // reaches MembersRepository -> IbanSealedBox for the money mail, and
            // a key service made to satisfy IBAN_FINGERPRINT_KEY before it can
            // announce anything is the coupling ADR-0043 split this out to break.
            $this->getAdminNotifier(),
            $this->getLogger(),
        ));
    }

    public function getKeyRotationService(): KeyRotationService
    {
        return $this->resolve(KeyRotationService::class, fn() => new KeyRotationService(
            $this->getEncryptionKeysRepository(),
            $this->getSealedIbanRepository(),
            $this->getEncryptionKeyService(),
            $this->getIbanSealedBox(),
            $this->getAuditService(),
        ));
    }

    public function getEncryptionKeysController(): EncryptionKeysController
    {
        return $this->resolve(EncryptionKeysController::class, fn() => new EncryptionKeysController(
            $this->getEncryptionKeyService(),
            $this->getKeyRotationService(),
            $this->getStepUpAuthService(),
            $this->getValidator(),
        ));
    }

    public function getCategoriesService(): CategoriesService
    {
        return $this->resolve(CategoriesService::class, fn() => new CategoriesService($this->getCategoriesRepository(), $this->getAuditService()));
    }

    public function getMembersService(): MembersService
    {
        return $this->resolve(MembersService::class, fn() => new MembersService($this->getMembersRepository(), $this->getTransactionsRepository(), $this->getAuditService(), $this->getAuditLogRepository(), $this->getNotificationsService(), $this->pdo, $this->getBankCodeService()));
    }

    public function getProductsService(): ProductsService
    {
        return $this->resolve(ProductsService::class, fn() => new ProductsService($this->getProductsRepository(), $this->getCategoriesRepository(), $this->getAuditService()));
    }

    public function getSepaConfigService(): SepaConfigService
    {
        return $this->resolve(SepaConfigService::class, fn() => new SepaConfigService($this->getSepaConfigRepository(), $this->getAuditService()));
    }

    public function getInstanceConfigService(): InstanceConfigService
    {
        return $this->resolve(InstanceConfigService::class, fn() => new InstanceConfigService($this->getInstanceConfigRepository(), $this->getAuditService()));
    }

    /**
     * The club's credit ceiling and warning band (ADR-0047). Reading it never
     * throws — see the service for why that matters to the dashboard and the
     * nightly statement run.
     */
    public function getCreditLimitConfigService(): CreditLimitConfigService
    {
        return $this->resolve(CreditLimitConfigService::class, fn() => new CreditLimitConfigService(
            $this->getCreditLimitConfigRepository(),
            $this->getAuditService(),
        ));
    }

    public function getMailTransportFactory(): MailTransportFactory
    {
        return $this->resolve(MailTransportFactory::class, fn() => new MailTransportFactory($this->config, $this->logger));
    }

    public function getMailConfigService(): MailConfigService
    {
        return $this->resolve(MailConfigService::class, fn() => new MailConfigService(
            $this->getMailConfigRepository(),
            $this->getInstanceConfigService(),
            $this->getMailTransportFactory(),
            $this->getAuditService(),
            $this->config,
        ));
    }

    /**
     * The admin fan-out on its own (ADR-0043).
     *
     * Deliberately narrow: the queue, the admin list and the audit log. A
     * caller that only wants to tell the admins something resolves this instead
     * of the whole notifications service, which drags `MembersRepository` and
     * with it the IBAN sealed box's required key.
     */
    public function getAdminNotifier(): AdminNotifier
    {
        return $this->resolve(AdminNotifier::class, fn() => new AdminNotifier(
            $this->getMailOutboxRepository(),
            $this->getAdminUsersRepository(),
            $this->getAuditService(),
            $this->getMailConfigRepository(),
            $this->logger,
        ));
    }

    public function getNotificationsService(): NotificationsService
    {
        return $this->resolve(NotificationsService::class, fn() => new NotificationsService(
            $this->getMailOutboxRepository(),
            $this->getMembersRepository(),
            $this->getAuditService(),
            $this->getAdminUsersRepository(),
            $this->getSettlementAnnouncementsRepository(),
            $this->logger,
        ));
    }

    public function getSchedulerStatusService(): SchedulerStatusService
    {
        return $this->resolve(SchedulerStatusService::class, fn() => new SchedulerStatusService(
            $this->getCronHeartbeatRepository(),
            $this->config,
            $this->getMailConfigService(),
        ));
    }

    public function getSettlementMailBuilder(): SettlementMailBuilder
    {
        return $this->resolve(SettlementMailBuilder::class, fn() => new SettlementMailBuilder(
            $this->getSettlementsRepository(),
            $this->getMembersRepository(),
            $this->getSepaConfigRepository(),
        ));
    }

    public function getAdminSecurityMailBuilder(): AdminSecurityMailBuilder
    {
        return $this->resolve(AdminSecurityMailBuilder::class, fn() => new AdminSecurityMailBuilder(
            $this->getAdminUsersRepository(),
            $this->getMailConfigService(),
            $this->getAdminUserRolesRepository(),
        ));
    }

    /**
     * What the drain (#403) asks to turn a claimed row into a message. One
     * builder per subject; #410 and #438 add theirs here and the drain does not
     * change.
     */
    public function getMailContentRegistry(): MailContentRegistry
    {
        return $this->resolve(MailContentRegistry::class, fn() => new MailContentRegistry(
            $this->getSettlementMailBuilder(),
            $this->getTerminalAnomalyMailBuilder(),
            $this->getJugendschutzViolationMailBuilder(),
            $this->getAdminSecurityMailBuilder(),
            $this->getDeckelStatementMailBuilder(),
            $this->getCredentialExpiryMailBuilder(),
            $this->getTerminalTokenIssuedMailBuilder(),
            $this->getEncryptionKeyEventMailBuilder(),
            $this->getCreditLimitDigestMailBuilder(),
        ));
    }

    /**
     * ADR-0043. The counterpart to the expiry warning: that one is sent because
     * a secret is running out, this one because a secret was created.
     */
    public function getTerminalTokenIssuedMailBuilder(): TerminalTokenIssuedMailBuilder
    {
        return $this->resolve(TerminalTokenIssuedMailBuilder::class, fn() => new TerminalTokenIssuedMailBuilder(
            $this->getTerminalsRepository(),
            $this->getAdminUsersRepository(),
        ));
    }

    /**
     * ADR-0036. Key lifecycle notices share `MailSubject::ENCRYPTION_KEY` with
     * the expiry warning, but that builder claims work by naming its two kinds,
     * so these need a builder of their own.
     */
    public function getEncryptionKeyEventMailBuilder(): EncryptionKeyEventMailBuilder
    {
        return $this->resolve(EncryptionKeyEventMailBuilder::class, fn() => new EncryptionKeyEventMailBuilder(
            $this->getEncryptionKeysRepository(),
            $this->getAdminUsersRepository(),
        ));
    }

    /**
     * #438. Claims both expiry kinds — they are one message about two subjects.
     */
    public function getCredentialExpiryMailBuilder(): CredentialExpiryMailBuilder
    {
        return $this->resolve(CredentialExpiryMailBuilder::class, fn() => new CredentialExpiryMailBuilder(
            $this->getEncryptionKeysRepository(),
            $this->getTerminalsRepository(),
            $this->getAdminUsersRepository(),
        ));
    }

    /**
     * The near-limit query, shared by the dashboard panel and the digest
     * (ADR-0047 rule 1). One instance, one spelling of the boundary cent — the
     * two surfaces must never name different members.
     */
    public function getNearLimitRepository(): NearLimitRepository
    {
        return $this->resolve(NearLimitRepository::class, fn() => new NearLimitRepository($this->pdo));
    }

    /** Who is near their ceiling, as the digest reports it (ADR-0047). */
    public function getCreditLimitDigestService(): CreditLimitDigestService
    {
        return $this->resolve(CreditLimitDigestService::class, fn() => new CreditLimitDigestService(
            $this->getNearLimitRepository(),
            $this->getCreditLimitConfigService(),
        ));
    }

    /**
     * The near-limit digest's content, rendered at send time.
     *
     * The first builder that reads *no* subject at all: `subject_id` names the
     * club's credit-limit configuration, and everything the message says is a
     * live query. See {@see CreditLimitDigestMailBuilder}.
     */
    public function getCreditLimitDigestMailBuilder(): CreditLimitDigestMailBuilder
    {
        return $this->resolve(CreditLimitDigestMailBuilder::class, fn() => new CreditLimitDigestMailBuilder(
            $this->getCreditLimitDigestService(),
            $this->getMailConfigService(),
            $this->getAdminUsersRepository(),
        ));
    }

    /**
     * The near-limit digest scan (ADR-0047, migration 054).
     *
     * Called by `bin/cron.php` before the drain, for the same reason the
     * anomaly scan, the statement enqueue and the expiry scan are: a message
     * queued by a tick should leave on that tick rather than waiting for the
     * next one.
     */
    public function getCreditLimitDigestNotifier(): CreditLimitDigestNotifier
    {
        return $this->resolve(CreditLimitDigestNotifier::class, fn() => new CreditLimitDigestNotifier(
            $this->getCreditLimitDigestService(),
            $this->getAdminNotifier(),
            $this->getMailConfigService(),
            $this->getLogger(),
        ));
    }

    /**
     * The credential expiry scan (#438, ADR-0036).
     *
     * Called by `bin/cron.php` before the drain, for the same reason the anomaly
     * scan and the statement enqueue are: a warning raised by a tick should leave
     * on that tick rather than waiting for the next one.
     */
    public function getCredentialExpiryNotifier(): CredentialExpiryNotifier
    {
        return $this->resolve(CredentialExpiryNotifier::class, fn() => new CredentialExpiryNotifier(
            $this->getEncryptionKeysRepository(),
            $this->getTerminalsRepository(),
            $this->getAdminNotifier(),
            $this->getMailConfigService(),
            $this->getLogger(),
        ));
    }

    /**
     * ADR-0039. The first builder whose subject is the *member* rather than
     * something that happened to them — and the reason the registry is a list
     * of builders rather than a `match` in the drain.
     */
    public function getDeckelStatementMailBuilder(): DeckelStatementMailBuilder
    {
        return $this->resolve(DeckelStatementMailBuilder::class, fn() => new DeckelStatementMailBuilder(
            $this->getDeckelStatementService(),
        ));
    }

    public function getDeckelStatementService(): DeckelStatementService
    {
        return $this->resolve(DeckelStatementService::class, fn() => new DeckelStatementService(
            $this->getDeckelStatementRepository(),
            $this->getMembersRepository(),
            $this->getMailConfigService(),
            $this->getCreditLimitConfigService(),
        ));
    }

    public function getDeckelStatementRepository(): DeckelStatementRepository
    {
        return $this->resolve(
            DeckelStatementRepository::class,
            fn() => new DeckelStatementRepository($this->pdo),
        );
    }

    /**
     * ADR-0041. The first admin-addressed builder — `warnAdmins()` has been able
     * to queue since #438, and until this was registered nothing could render
     * what it queued.
     */
    public function getJugendschutzViolationMailBuilder(): JugendschutzViolationMailBuilder
    {
        return $this->resolve(JugendschutzViolationMailBuilder::class, fn() => new JugendschutzViolationMailBuilder(
            $this->getTransactionsRepository(),
            $this->getProductsRepository(),
            $this->getAdminUsersRepository(),
        ));
    }

    public function getTerminalAnomalyMailBuilder(): TerminalAnomalyMailBuilder
    {
        return $this->resolve(TerminalAnomalyMailBuilder::class, fn() => new TerminalAnomalyMailBuilder(
            $this->getTerminalsRepository(),
            $this->getTerminalAnomaliesRepository(),
            $this->getAdminUsersRepository(),
        ));
    }

    public function getStatementRecipientsRepository(): StatementRecipientsRepository
    {
        return $this->resolve(
            StatementRecipientsRepository::class,
            fn() => new StatementRecipientsRepository($this->pdo),
        );
    }

    /**
     * The Deckelauszug's scheduled enqueue (ADR-0039 decision 1).
     *
     * Called by `bin/cron.php` *before* the drain, so a statement queued by a
     * tick leaves on that same tick rather than waiting for the next one — the
     * same ordering, and the same reason, as the terminal anomaly scan.
     */
    public function getPeriodicEnqueueService(): PeriodicEnqueueService
    {
        return $this->resolve(PeriodicEnqueueService::class, fn() => new PeriodicEnqueueService(
            $this->getMailConfigService(),
            $this->getStatementRecipientsRepository(),
            $this->getMailOutboxRepository(),
            $this->getLogger(),
        ));
    }

    /**
     * The only sender (ADR-0038 rule 3). Reached by `bin/cron.php` and by the
     * URL fallback route, which is why it is wired here rather than assembled
     * in the CLI entrypoint — the two triggers cannot drift apart.
     *
     * It takes the registry rather than a builder: the sending loop is the
     * piece that has to stay boring, and #410 and #438 add a notification type
     * by registering a builder above rather than by editing it.
     *
     * Both dials are passed as `null` unless the environment pins them, because
     * their normal home is `mail_config` — operational dials with no secret in
     * either, which a treasurer on a stricter relay or a host whose scheduler
     * times out sooner should be able to turn without editing a file (ADR-0039
     * decision 5, extended to the run budget by #473). `config.php` still
     * overrides both, for a host that has to pin them outside the database.
     */
    public function getDrainService(): DrainService
    {
        return $this->resolve(DrainService::class, fn() => new DrainService(
            $this->getNotificationsService(),
            $this->getMailContentRegistry(),
            $this->getMailTransportFactory(),
            $this->getMailConfigService(),
            $this->getCronHeartbeatRepository(),
            $this->getHeartbeatPinger(),
            $this->getLogger(),
            self::positiveEnvOrNull('MAIL_DRAIN_BATCH_SIZE'),
            self::positiveEnvOrNull('MAIL_DRAIN_BUDGET_SECONDS'),
        ));
    }

    /**
     * The external alarm (#406).
     *
     * Configured or not, the object exists: a null check URL makes every ping a
     * no-op, which keeps the drain free of `if (monitoring enabled)` branches —
     * and a branch that only runs on installations nobody develops against is
     * the branch that rots.
     */
    public function getHeartbeatPinger(): HeartbeatPinger
    {
        return $this->resolve(HeartbeatPinger::class, fn() => new HeartbeatPinger(
            new CurlHttpClient(),
            $this->getLogger(),
            $this->config->cronHeartbeatUrl,
        ));
    }

    /** The delivery rows of the security self-check (#406). */
    public function getMailDeliveryCheck(): MailDeliveryCheck
    {
        return $this->resolve(MailDeliveryCheck::class, fn() => new MailDeliveryCheck(
            $this->getMailConfigService(),
            $this->getSchedulerStatusService(),
            $this->getNotificationsService(),
        ));
    }

    /** An unset, unparseable or non-positive value falls back to the default. */
    private static function positiveEnv(string $key, int $default): int
    {
        $value = (int) Env::get($key, '');

        return $value > 0 ? $value : $default;
    }

    /**
     * Like {@see positiveEnv()} but with no default to fall back to: `null`
     * means "nothing was pinned here", which lets a caller go on to ask the
     * database rather than being handed a compiled-in number.
     */
    private static function positiveEnvOrNull(string $key): ?int
    {
        $value = (int) Env::get($key, '');

        return $value > 0 ? $value : null;
    }

    public function getSepaExportService(): SepaExportService
    {
        return $this->resolve(SepaExportService::class, fn() => new SepaExportService(
            $this->getSepaConfigRepository(),
            $this->getMembersRepository(),
            $this->getSettlementsRepository(),
            $this->getSettlementReversalsRepository(),
            $this->getLogger(),
        ));
    }

    public function getSettlementsService(): SettlementsService
    {
        return $this->resolve(SettlementsService::class, fn() => new SettlementsService(
            $this->getSettlementsRepository(),
            $this->getMembersRepository(),
            $this->getTransactionsRepository(),
            $this->getAuditService(),
            $this->pdo,
            $this->getSettlementReversalsRepository(),
            $this->getNotificationsService(),
            $this->getSchedulerStatusService(),
            $this->getSettlementAnnouncementsRepository(),
        ));
    }

    public function getSettlementReversalService(): SettlementReversalService
    {
        return $this->resolve(SettlementReversalService::class, fn() => new SettlementReversalService(
            $this->getSettlementsRepository(),
            $this->getSettlementReversalsRepository(),
            $this->getCollectionHoldRepository(),
            $this->getAuditService(),
            $this->pdo,
        ));
    }

    public function getCollectionHoldService(): CollectionHoldService
    {
        return $this->resolve(CollectionHoldService::class, fn() => new CollectionHoldService(
            $this->getCollectionHoldRepository(),
            $this->getMembersRepository(),
            $this->getAuditService(),
        ));
    }

    public function getTerminalsService(): TerminalsService
    {
        return $this->resolve(TerminalsService::class, fn() => new TerminalsService(
            $this->getTerminalsRepository(),
            $this->getAuditService(),
            $this->config,
            $this->getTerminalAnomaliesRepository(),
            // ADR-0043: minting a credential announces itself to every active
            // admin. The narrow collaborator rather than the whole notifications
            // service, so enrolling a terminal does not require the IBAN
            // configuration the money mail needs. The dependency points this way
            // — terminals ask the queue to carry something — and never back, so
            // nothing in notifications knows what a terminal is.
            $this->getAdminNotifier(),
            $this->logger,
        ));
    }

    /**
     * Owns what happens to a token while it authenticates (#395): promoting a
     * pending one on first use, and recording an expiry once. Shared by the
     * middleware so every terminal request goes through the same rules.
     */
    public function getTerminalTokenAuthenticator(): TerminalTokenAuthenticator
    {
        return $this->resolve(TerminalTokenAuthenticator::class, fn() => new TerminalTokenAuthenticator(
            $this->getTerminalsRepository(),
            $this->getAuditService(),
        ));
    }

    /**
     * ADR-0041. Called from the three delta endpoints on every pull, so it is
     * memoised like any other service and holds no per-request state.
     */
    public function getTerminalSyncCursorService(): TerminalSyncCursorService
    {
        return $this->resolve(TerminalSyncCursorService::class, fn() => new TerminalSyncCursorService(
            $this->getTerminalSyncCursorsRepository(),
            $this->getLogger(),
        ));
    }

    /**
     * ADR-0041. Runs on the cron tick, not on a request — the sustained-overlap
     * rule is a question about a window, and asking it per request would repeat
     * the same aggregate scan hundreds of times an hour.
     */
    public function getTerminalAnomalyDetector(): TerminalAnomalyDetector
    {
        return $this->resolve(TerminalAnomalyDetector::class, fn() => new TerminalAnomalyDetector(
            $this->getTerminalIpSightingsRepository(),
            $this->getTerminalSyncCursorsRepository(),
            $this->getTerminalAnomaliesRepository(),
            $this->getAdminNotifier(),
            $this->getAuditService(),
            $this->getLogger(),
            self::positiveEnv('TERMINAL_ANOMALY_LOOKBACK_MINUTES', TerminalAnomalyDetector::DEFAULT_LOOKBACK_MINUTES),
            self::positiveEnv('TERMINAL_ANOMALY_MIN_OVERLAP_MINUTES', TerminalAnomalyDetector::DEFAULT_MIN_OVERLAP_MINUTES),
            self::positiveEnv('TERMINAL_IP_RETENTION_DAYS', TerminalAnomalyDetector::DEFAULT_RETENTION_DAYS),
            self::positiveEnv(
                'TERMINAL_ANOMALY_DUAL_STACK_MAX_REQUESTS_PER_HOUR',
                TerminalAnomalyDetector::DEFAULT_DUAL_STACK_MAX_REQUESTS_PER_HOUR,
            ),
        ));
    }

    public function getPairingService(): PairingService
    {
        return $this->resolve(PairingService::class, fn() => new PairingService(
            $this->getInstanceConfigService(),
            $this->getAuditService(),
        ));
    }

    public function getTransactionsService(): TransactionsService
    {
        return $this->resolve(TransactionsService::class, fn() => new TransactionsService(
            $this->getTransactionsRepository(),
            $this->getMembersRepository(),
            $this->getProductsRepository(),
            $this->getAuditService(),
            $this->logger,
            // AdminNotifier rather than NotificationsService: that one drags in
            // MembersRepository -> IbanSealedBox and a required
            // IBAN_FINGERPRINT_KEY, which syncing a transaction has no business
            // needing (the same coupling ADR-0043 split this class out over).
            $this->getAdminNotifier(),
        ));
    }

    public function getBankCodeService(): BankCodeService
    {
        return $this->resolve(BankCodeService::class, fn() => new BankCodeService($this->getBankCodesRepository(), $this->logger));
    }

    // --- Middleware ---

    public function getAdminSessionAuth(): AdminSessionAuth
    {
        return $this->resolve(AdminSessionAuth::class, fn() => new AdminSessionAuth(
            $this->getAdminUsersRepository(),
            $this->config,
            $this->getAdminUserRolesRepository(),
        ));
    }

    public function getTerminalTokenAuth(): TerminalTokenAuth
    {
        return $this->resolve(TerminalTokenAuth::class, fn() => new TerminalTokenAuth(
            $this->getTerminalsRepository(),
            $this->getTerminalAuthAttemptsRepository(),
            $this->getTerminalTokenAuthenticator(),
            $this->getTerminalIpSightingsRepository(),
            $this->getLogger(),
        ));
    }

    public function getCsrfMiddleware(): CsrfMiddleware
    {
        return $this->resolve(CsrfMiddleware::class, fn() => new CsrfMiddleware());
    }

    public function getCorsMiddleware(): CorsMiddleware
    {
        $origins = Env::get('CORS_ORIGINS', '*');
        $allowedOrigins = $origins === '*' ? ['*'] : array_map('trim', explode(',', $origins));
        return $this->resolve(CorsMiddleware::class, fn() => new CorsMiddleware($allowedOrigins));
    }

    public function getJsonBodyParser(): JsonBodyParser
    {
        return $this->resolve(JsonBodyParser::class, fn() => new JsonBodyParser());
    }

    public function getErrorHandler(): ErrorHandler
    {
        return $this->resolve(ErrorHandler::class, fn() => new ErrorHandler($this->logger, $this->config->debug));
    }

    public function getSecurityHeaders(): SecurityHeaders
    {
        return $this->resolve(SecurityHeaders::class, fn() => new SecurityHeaders());
    }

    /**
     * Password step: the account under attack is named in the request body.
     */
    public function getRateLimitMiddleware(): RateLimitMiddleware
    {
        return $this->resolve(RateLimitMiddleware::class, fn() => new RateLimitMiddleware(
            $this->getLoginAttemptsRepository(),
            5,
            15,
            $this->loginRateLimitDisabled(),
            static function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
                $body = $request->getParsedBody();
                $email = is_array($body) ? ($body['email'] ?? null) : null;
                return is_string($email) ? $email : null;
            },
        ));
    }

    /**
     * MFA step: the request body carries only a code, so the account comes from
     * the MFA-pending session written by the password step (#78).
     */
    public function getMfaRateLimitMiddleware(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $this->getLoginAttemptsRepository(),
            5,
            15,
            $this->loginRateLimitDisabled(),
            function (): ?string {
                if (session_status() === PHP_SESSION_NONE) {
                    session_name($this->config->sessionCookieName);
                    session_start();
                }
                $email = $_SESSION['mfa_pending_email'] ?? null;
                return is_string($email) ? $email : null;
            },
        );
    }

    /**
     * Disabled via DISABLE_LOGIN_RATE_LIMITING=true (e.g. in test environments,
     * where the suite deliberately fails logins). The dedicated rate-limit specs
     * are excluded from the default E2E run and require it switched back on.
     */
    private function loginRateLimitDisabled(): bool
    {
        return Env::get('DISABLE_LOGIN_RATE_LIMITING', 'false') === 'true';
    }

    /**
     * Step-up re-authentication (2FA reset, cross-account password reset,
     * #337): the account under attack is the caller re-entering their own
     * password, not a target named in the body — so, like the MFA step, the
     * account dimension resolves from the session admin rather than the
     * request body.
     */
    public function getStepUpRateLimitMiddleware(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $this->getLoginAttemptsRepository(),
            5,
            15,
            $this->loginRateLimitDisabled(),
            static function (\Psr\Http\Message\ServerRequestInterface $request): ?string {
                $admin = $request->getAttribute('admin_user');
                $email = is_array($admin) ? ($admin['email'] ?? null) : null;
                return is_string($email) ? $email : null;
            },
        );
    }

    public function getTerminalRateLimitMiddleware(): RateLimitMiddleware
    {
        // Not cached via resolve() — returns a fresh instance with terminal-specific config.
        // Uses a different table and higher threshold than the login rate limiter, and no
        // account dimension: terminal auth presents a token, not an account.
        // Disabled via DISABLE_TERMINAL_RATE_LIMITING=true (e.g. in test environments).
        $disabled = Env::get('DISABLE_TERMINAL_RATE_LIMITING', 'false') === 'true';
        return new RateLimitMiddleware(
            $this->getTerminalAuthAttemptsRepository(),
            10,
            15,
            $disabled,
        );
    }

    public function getTerminalOasValidator(): \Psr\Http\Server\MiddlewareInterface
    {
        $specPath = realpath(__DIR__ . '/../../api/terminal.yaml');
        if ($specPath === false) {
            throw new \RuntimeException('OAS spec not found: api/terminal.yaml');
        }
        $validator = (new ValidationMiddlewareBuilder())
            ->fromYamlFile($specPath)
            ->getValidationMiddleware();

        return new TerminalOasValidator($validator);
    }

    // --- Controllers ---

    public function getHealthController(): HealthController
    {
        return $this->resolve(HealthController::class, fn() => new HealthController(
            new \App\Shared\Services\HealthCheckService($this->getInstanceConfigService()),
        ));
    }

    public function getSecurityCheckController(): SecurityCheckController
    {
        return $this->resolve(SecurityCheckController::class, fn() => new SecurityCheckController(
            new SecurityCheckService($this->config, $this->getMailDeliveryCheck()),
        ));
    }

    public function getAuthController(): AuthController
    {
        return $this->resolve(AuthController::class, fn() => new AuthController(
            $this->getAuthService(),
            $this->getAdminUsersService(),
            $this->getAdminUsersRepository(),
            $this->getTotpService(),
            $this->getAuditService(),
            $this->getValidator(),
            $this->getLoginAttemptsRepository(),
            $this->config,
            $this->getStepUpAuthService(),
        ));
    }

    public function getMembersSyncController(): MembersSyncController
    {
        return $this->resolve(MembersSyncController::class, fn() => new MembersSyncController(
            $this->getMembersService(),
            $this->getValidator(),
            $this->getTerminalSyncCursorService(),
        ));
    }

    public function getMembersAdminController(): MembersAdminController
    {
        return $this->resolve(MembersAdminController::class, fn() => new MembersAdminController(
            $this->getMembersService(),
            $this->getValidator(),
            $this->getSettlementsService(),
            $this->getCollectionHoldService(),
        ));
    }

    public function getProductsAdminController(): ProductsAdminController
    {
        return $this->resolve(ProductsAdminController::class, fn() => new ProductsAdminController($this->getCategoriesService(), $this->getProductsService(), $this->getValidator()));
    }

    public function getProductsSyncController(): ProductsSyncController
    {
        return $this->resolve(ProductsSyncController::class, fn() => new ProductsSyncController(
            $this->getCategoriesService(),
            $this->getProductsService(),
            $this->getTerminalSyncCursorService(),
        ));
    }

    public function getTransactionsAdminController(): TransactionsAdminController
    {
        return $this->resolve(TransactionsAdminController::class, fn() => new TransactionsAdminController(
            $this->getTransactionsService(),
            $this->getValidator(),
            $this->getJugendschutzViolationService(),
        ));
    }

    public function getTransactionsSyncController(): TransactionsSyncController
    {
        return $this->resolve(TransactionsSyncController::class, fn() => new TransactionsSyncController($this->getTransactionsService()));
    }

    public function getAdminUsersAdminController(): AdminUsersAdminController
    {
        return $this->resolve(AdminUsersAdminController::class, fn() => new AdminUsersAdminController(
            $this->getAdminUsersService(),
            $this->getValidator(),
            $this->getStepUpAuthService(),
        ));
    }

    public function getAuditLogService(): AuditLogService
    {
        return $this->resolve(AuditLogService::class, fn() => new AuditLogService($this->getAuditLogRepository()));
    }

    public function getAuditLogAdminController(): AuditLogAdminController
    {
        return $this->resolve(AuditLogAdminController::class, fn() => new AuditLogAdminController($this->getAuditLogService()));
    }

    public function getSettlementsAdminController(): SettlementsAdminController
    {
        return $this->resolve(SettlementsAdminController::class, fn() => new SettlementsAdminController($this->getSettlementsService(), $this->getSepaExportService(), $this->getValidator(), $this->getSettlementReversalService(), $this->getEncryptionKeyService(), $this->getStepUpAuthService(), $this->getAuditService()));
    }

    public function getSepaConfigController(): SepaConfigController
    {
        return $this->resolve(SepaConfigController::class, fn() => new SepaConfigController($this->getSepaConfigService(), $this->getValidator()));
    }

    public function getMailConfigController(): MailConfigController
    {
        return $this->resolve(MailConfigController::class, fn() => new MailConfigController(
            $this->getMailConfigService(),
            $this->getValidator(),
            $this->getTestMailService(),
            $this->getStepUpAuthService(),
        ));
    }

    /**
     * The mail queue's read/retry surface (#407).
     */
    public function getNotificationsController(): NotificationsController
    {
        return $this->resolve(NotificationsController::class, fn() => new NotificationsController(
            $this->getNotificationsService(),
        ));
    }

    /**
     * The test-mail diagnostic. Separate from the drain on purpose: it is not a
     * queue sender, and keeping it out of DrainService is what keeps that
     * class's "exactly two callers" check meaningful.
     */
    public function getTestMailService(): TestMailService
    {
        return $this->resolve(TestMailService::class, fn() => new TestMailService(
            $this->getMailConfigService(),
            $this->getMailTransportFactory(),
            $this->getAdminUsersRepository(),
            $this->getAuditService(),
        ));
    }

    public function getSchedulerController(): SchedulerController
    {
        return $this->resolve(SchedulerController::class, fn() => new SchedulerController($this->getSchedulerStatusService()));
    }

    public function getBackupKeyring(): BackupKeyring
    {
        return $this->resolve(BackupKeyring::class, fn() => new BackupKeyring());
    }

    /**
     * The backup, wired from `config.php` and nothing else.
     *
     * No repositories: the run writes nothing into the database it dumps
     * (ADR-0049 decision 8). Its record is the archive header and the journal
     * beside the archives; its configuration is the four values below, of which
     * only the recipient keys are ever set on an ordinary installation.
     */
    public function getBackupService(): BackupService
    {
        return $this->resolve(BackupService::class, fn() => new BackupService(
            new DatabaseDump($this->pdo),
            $this->getBackupKeyring(),
            $this->getLogger(),
            $this->config->dataDir . '/' . BackupService::DIRECTORY,
            $this->config->backupRecipientPublicKeys,
            BackupRetention::fromOverrides(
                $this->config->backupLocalRetentionDays,
                $this->config->backupLocalMaxBytes,
                $this->config->backupRemoteRetentionDays,
            ),
            $this->config->env,
        ));
    }

    public function getBackupCronController(): BackupCronController
    {
        return $this->resolve(BackupCronController::class, fn() => new BackupCronController(
            $this->getBackupService(),
            $this->config,
            $this->getLogger(),
            $this->getMailConfigService(),
        ));
    }

    public function getCronController(): CronController
    {
        return $this->resolve(CronController::class, fn() => new CronController(
            $this->getDrainService(),
            $this->config,
            $this->getLogger(),
            $this->getTerminalAnomalyDetector(),
            $this->getMailConfigService(),
        ));
    }

    public function getInstanceConfigController(): InstanceConfigController
    {
        return $this->resolve(InstanceConfigController::class, fn() => new InstanceConfigController($this->getInstanceConfigService(), $this->getValidator()));
    }

    public function getCreditLimitConfigController(): CreditLimitConfigController
    {
        return $this->resolve(CreditLimitConfigController::class, fn() => new CreditLimitConfigController(
            $this->getCreditLimitConfigService(),
            $this->getValidator(),
        ));
    }

    /** The club policy a terminal caches, on GET /api/sync/config (ADR-0047). */
    public function getCreditLimitSyncController(): CreditLimitSyncController
    {
        return $this->resolve(CreditLimitSyncController::class, fn() => new CreditLimitSyncController(
            $this->getCreditLimitConfigService(),
        ));
    }

    public function getTerminalsAdminController(): TerminalsAdminController
    {
        return $this->resolve(TerminalsAdminController::class, fn() => new TerminalsAdminController(
            $this->getTerminalsService(),
            $this->getValidator(),
            $this->getStepUpAuthService(),
        ));
    }

    public function getPairingController(): PairingController
    {
        return $this->resolve(PairingController::class, fn() => new PairingController($this->getPairingService()));
    }

    public function getReportsRepository(): ReportsRepository
    {
        return $this->resolve(ReportsRepository::class, fn() => new ReportsRepository($this->pdo));
    }

    public function getReportsService(): ReportsService
    {
        return $this->resolve(ReportsService::class, fn() => new ReportsService($this->getReportsRepository()));
    }

    public function getReportsAdminController(): ReportsAdminController
    {
        return $this->resolve(ReportsAdminController::class, fn() => new ReportsAdminController(
            $this->getReportsService(),
        ));
    }

    public function getBankCodesAdminController(): BankCodesAdminController
    {
        return $this->resolve(BankCodesAdminController::class, fn() => new BankCodesAdminController($this->getBankCodeService()));
    }

    public function getDashboardRepository(): DashboardRepository
    {
        return $this->resolve(DashboardRepository::class, fn() => new DashboardRepository($this->pdo));
    }

    public function getDashboardService(): DashboardService
    {
        return $this->resolve(DashboardService::class, fn() => new DashboardService(
            $this->getDashboardRepository(),
            $this->getMembersRepository(),
            $this->getTransactionsRepository(),
            $this->getSettlementsRepository(),
            $this->getTerminalsRepository(),
            $this->getEncryptionKeysRepository(),
            $this->getSepaConfigRepository(),
            $this->getTerminalAnomaliesRepository(),
            $this->getJugendschutzViolationsRepository(),
            $this->getCreditLimitConfigService(),
            $this->getNearLimitRepository(),
        ));
    }

    public function getJugendschutzViolationsRepository(): JugendschutzViolationsRepository
    {
        return $this->resolve(JugendschutzViolationsRepository::class, fn() => new JugendschutzViolationsRepository($this->pdo));
    }

    public function getJugendschutzViolationService(): JugendschutzViolationService
    {
        return $this->resolve(JugendschutzViolationService::class, fn() => new JugendschutzViolationService(
            $this->getJugendschutzViolationsRepository(),
            $this->getAuditService(),
        ));
    }

    public function getDashboardAdminController(): DashboardAdminController
    {
        return $this->resolve(DashboardAdminController::class, fn() => new DashboardAdminController(
            $this->getDashboardService(),
        ));
    }

    // --- Container interface for Slim ---

    public function has(string $id): bool
    {
        return isset(self::FQCN_MAP[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset(self::FQCN_MAP[$id])) {
            $method = self::FQCN_MAP[$id];
            return $this->$method();
        }
        throw new \RuntimeException("Service not found: {$id}");
    }

    // --- Internal ---

    private function resolve(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }
}
