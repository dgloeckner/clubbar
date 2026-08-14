<?php

declare(strict_types=1);

namespace App;

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Logging\Logger;
use App\Shared\Validation\Validator;

// Repositories
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
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
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
use App\Shared\Services\SecurityCheckService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Products\Services\ProductsService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\Services\DrainService;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\MailContentRegistry;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\SettlementMailBuilder;
use App\Shared\Mail\MailTransportFactory;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Terminals\Services\TerminalsService;
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
use App\Modules\Members\Controllers\ExtractionController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Factories\LlmClientFactory;
use App\Modules\Members\Factories\VisionClientFactory;
use App\Modules\Members\Services\DirectExtractionService;
use App\Modules\Members\Services\ExtractionService;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Products\Controllers\SyncController as ProductsSyncController;
use App\Modules\Settlements\Controllers\AdminController as SettlementsAdminController;
use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\Instance\Controllers\InstanceConfigController;
use App\Modules\Notifications\Controllers\CronController;
use App\Modules\Notifications\Controllers\MailConfigController;
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
        ExtractionController::class => 'getExtractionController',
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

        // Notifications
        MailConfigController::class => 'getMailConfigController',
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

    public function getSessionRepository(): SessionRepository
    {
        return $this->resolve(SessionRepository::class, fn() => new SessionRepository($this->pdo, $this->logger));
    }

    public function getSettlementsRepository(): SettlementsRepository
    {
        return $this->resolve(SettlementsRepository::class, fn() => new SettlementsRepository($this->pdo, $this->logger));
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

    public function getAdminUsersService(): AdminUsersService
    {
        return $this->resolve(AdminUsersService::class, fn() => new AdminUsersService($this->getAdminUsersRepository(), $this->getAuditService()));
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
        return $this->resolve(MembersService::class, fn() => new MembersService($this->getMembersRepository(), $this->getTransactionsRepository(), $this->getAuditService(), $this->getAuditLogRepository(), $this->pdo, $this->getBankCodeService()));
    }

    public function getLlmClientFactory(): LlmClientFactory
    {
        return $this->resolve(LlmClientFactory::class, fn() => new LlmClientFactory($this->config));
    }

    public function getVisionClientFactory(): VisionClientFactory
    {
        return $this->resolve(VisionClientFactory::class, fn() => new VisionClientFactory($this->config));
    }

    public function getExtractionService(): ?ExtractionServiceInterface
    {
        $llmClient = $this->getLlmClientFactory()->create();
        if ($llmClient === null) {
            return null;
        }

        $visionClient = $this->getVisionClientFactory()->create();
        if ($visionClient !== null) {
            // Full Vision OCR pipeline: Google Vision → OcrPreprocessor → LLM text extraction
            return $this->resolve(ExtractionService::class, fn() => new ExtractionService($visionClient, $llmClient));
        }

        // Direct vision pipeline: image sent straight to the LLM's vision capability
        return $this->resolve(DirectExtractionService::class, fn() => new DirectExtractionService($llmClient));
    }

    public function getExtractionController(): ExtractionController
    {
        return $this->resolve(ExtractionController::class, fn() => new ExtractionController(
            $this->getExtractionService(),
        ));
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
        ));
    }

    public function getNotificationsService(): NotificationsService
    {
        return $this->resolve(NotificationsService::class, fn() => new NotificationsService(
            $this->getMailOutboxRepository(),
            $this->getMembersRepository(),
            $this->getAuditService(),
            $this->getAdminUsersRepository(),
        ));
    }

    public function getSettlementMailBuilder(): SettlementMailBuilder
    {
        return $this->resolve(SettlementMailBuilder::class, fn() => new SettlementMailBuilder(
            $this->getSettlementsRepository(),
            $this->getMembersRepository(),
            $this->getSepaConfigRepository(),
            $this->getMailConfigService(),
        ));
    }

    /**
     * What the drain (#403) asks to turn a claimed row into a message. One
     * builder today; #410 and #438 add theirs here and the drain does not
     * change.
     */
    public function getMailContentRegistry(): MailContentRegistry
    {
        return $this->resolve(MailContentRegistry::class, fn() => new MailContentRegistry(
            $this->getSettlementMailBuilder(),
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
     * Batch size and wall-clock budget are read from the environment so a host
     * with a tighter gateway timeout can lower the budget without a code
     * change; the defaults live on the service, with the reasoning.
     */
    public function getDrainService(): DrainService
    {
        return $this->resolve(DrainService::class, fn() => new DrainService(
            $this->getNotificationsService(),
            $this->getMailContentRegistry(),
            $this->getMailTransportFactory(),
            $this->getMailConfigService(),
            $this->getCronHeartbeatRepository(),
            $this->getLogger(),
            self::positiveEnv('MAIL_DRAIN_BATCH_SIZE', DrainService::DEFAULT_BATCH_SIZE),
            self::positiveEnv('MAIL_DRAIN_BUDGET_SECONDS', DrainService::DEFAULT_BUDGET_SECONDS),
        ));
    }

    /** An unset, unparseable or non-positive value falls back to the default. */
    private static function positiveEnv(string $key, int $default): int
    {
        $value = (int) Env::get($key, '');

        return $value > 0 ? $value : $default;
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

    public function getPairingService(): PairingService
    {
        return $this->resolve(PairingService::class, fn() => new PairingService(
            $this->getInstanceConfigService(),
            $this->getAuditService(),
        ));
    }

    public function getTransactionsService(): TransactionsService
    {
        return $this->resolve(TransactionsService::class, fn() => new TransactionsService($this->getTransactionsRepository(), $this->getMembersRepository(), $this->getAuditService(), $this->logger));
    }

    public function getBankCodeService(): BankCodeService
    {
        return $this->resolve(BankCodeService::class, fn() => new BankCodeService($this->getBankCodesRepository(), $this->logger));
    }

    // --- Middleware ---

    public function getAdminSessionAuth(): AdminSessionAuth
    {
        return $this->resolve(AdminSessionAuth::class, fn() => new AdminSessionAuth($this->getAdminUsersRepository(), $this->config));
    }

    public function getTerminalTokenAuth(): TerminalTokenAuth
    {
        return $this->resolve(TerminalTokenAuth::class, fn() => new TerminalTokenAuth(
            $this->getTerminalsRepository(),
            $this->getTerminalAuthAttemptsRepository(),
            $this->getTerminalTokenAuthenticator(),
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
            new SecurityCheckService($this->config),
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
        return $this->resolve(ProductsSyncController::class, fn() => new ProductsSyncController($this->getCategoriesService(), $this->getProductsService()));
    }

    public function getTransactionsAdminController(): TransactionsAdminController
    {
        return $this->resolve(TransactionsAdminController::class, fn() => new TransactionsAdminController($this->getTransactionsService(), $this->getValidator()));
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
        return $this->resolve(MailConfigController::class, fn() => new MailConfigController($this->getMailConfigService(), $this->getValidator()));
    }

    public function getCronController(): CronController
    {
        return $this->resolve(CronController::class, fn() => new CronController(
            $this->getDrainService(),
            $this->config,
            $this->getLogger(),
        ));
    }

    public function getInstanceConfigController(): InstanceConfigController
    {
        return $this->resolve(InstanceConfigController::class, fn() => new InstanceConfigController($this->getInstanceConfigService(), $this->getValidator()));
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
