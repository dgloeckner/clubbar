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
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;

// Services
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Shared\Services\AuditService;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Products\Services\CategoriesService;
use App\Shared\Services\HealthCheckService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Products\Services\ProductsService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Terminals\Services\TerminalsService;
use App\Modules\Transactions\Services\TransactionsService;

// Controllers
use App\Modules\AdminUsers\Controllers\AdminController as AdminUsersAdminController;
use App\Modules\AuditLog\Controllers\AdminController as AuditLogAdminController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Dashboard\Controllers\AdminController as DashboardAdminController;
use App\Shared\Controllers\HealthController;
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Products\Controllers\SyncController as ProductsSyncController;
use App\Modules\Settlements\Controllers\AdminController as SettlementsAdminController;
use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\Terminals\Controllers\AdminController as TerminalsAdminController;
use App\Modules\Transactions\Controllers\AdminController as TransactionsAdminController;
use App\Modules\Transactions\Controllers\SyncController as TransactionsSyncController;

// Middleware
use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Shared\Middleware\CorsMiddleware;
use App\Shared\Middleware\ErrorHandler;
use App\Shared\Middleware\JsonBodyParser;

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

        // AdminUsers
        AdminUsersAdminController::class => 'getAdminUsersAdminController',

        // AuditLog
        AuditLogAdminController::class => 'getAuditLogAdminController',

        // Terminals
        TerminalsAdminController::class => 'getTerminalsAdminController',

        // Dashboard
        DashboardAdminController::class => 'getDashboardAdminController',

        // Auth
        AuthController::class => 'getAuthController',

        // Middleware
        AdminSessionAuth::class => 'getAdminSessionAuth',
        TerminalTokenAuth::class => 'getTerminalTokenAuth',
        CorsMiddleware::class => 'getCorsMiddleware',
        JsonBodyParser::class => 'getJsonBodyParser',
        ErrorHandler::class => 'getErrorHandler',
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
        return $this->resolve(Validator::class, fn() => new Validator());
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
        return $this->resolve(MembersRepository::class, fn() => new MembersRepository($this->pdo, $this->logger));
    }

    public function getProductsRepository(): ProductsRepository
    {
        return $this->resolve(ProductsRepository::class, fn() => new ProductsRepository($this->pdo, $this->logger));
    }

    public function getSepaConfigRepository(): SepaConfigRepository
    {
        return $this->resolve(SepaConfigRepository::class, fn() => new SepaConfigRepository($this->pdo, $this->logger));
    }

    public function getSessionRepository(): SessionRepository
    {
        return $this->resolve(SessionRepository::class, fn() => new SessionRepository($this->pdo, $this->logger));
    }

    public function getSettlementsRepository(): SettlementsRepository
    {
        return $this->resolve(SettlementsRepository::class, fn() => new SettlementsRepository($this->pdo, $this->logger));
    }

    public function getTerminalsRepository(): TerminalsRepository
    {
        return $this->resolve(TerminalsRepository::class, fn() => new TerminalsRepository($this->pdo, $this->logger));
    }

    public function getTransactionsRepository(): TransactionsRepository
    {
        return $this->resolve(TransactionsRepository::class, fn() => new TransactionsRepository($this->pdo, $this->logger));
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

    public function getAdminUsersService(): AdminUsersService
    {
        return $this->resolve(AdminUsersService::class, fn() => new AdminUsersService($this->getAdminUsersRepository(), $this->getAuditService()));
    }

    public function getCategoriesService(): CategoriesService
    {
        return $this->resolve(CategoriesService::class, fn() => new CategoriesService($this->getCategoriesRepository(), $this->getProductsRepository(), $this->getAuditService()));
    }

    public function getMembersService(): MembersService
    {
        return $this->resolve(MembersService::class, fn() => new MembersService($this->getMembersRepository(), $this->getAuditService()));
    }

    public function getProductsService(): ProductsService
    {
        return $this->resolve(ProductsService::class, fn() => new ProductsService($this->getProductsRepository(), $this->getCategoriesRepository(), $this->getAuditService()));
    }

    public function getSepaConfigService(): SepaConfigService
    {
        return $this->resolve(SepaConfigService::class, fn() => new SepaConfigService($this->getSepaConfigRepository(), $this->getAuditService()));
    }

    public function getSepaExportService(): SepaExportService
    {
        return $this->resolve(SepaExportService::class, fn() => new SepaExportService($this->getSepaConfigRepository(), $this->getMembersRepository(), $this->getSettlementsRepository()));
    }

    public function getSettlementsService(): SettlementsService
    {
        return $this->resolve(SettlementsService::class, fn() => new SettlementsService(
            $this->getSettlementsRepository(),
            $this->getMembersRepository(),
            $this->getTransactionsRepository(),
            $this->getAuditService(),
            $this->pdo,
        ));
    }

    public function getTerminalsService(): TerminalsService
    {
        return $this->resolve(TerminalsService::class, fn() => new TerminalsService($this->getTerminalsRepository(), $this->getAuditService()));
    }

    public function getTransactionsService(): TransactionsService
    {
        return $this->resolve(TransactionsService::class, fn() => new TransactionsService($this->getTransactionsRepository(), $this->getMembersRepository(), $this->logger));
    }

    // --- Middleware ---

    public function getAdminSessionAuth(): AdminSessionAuth
    {
        return $this->resolve(AdminSessionAuth::class, fn() => new AdminSessionAuth($this->getAdminUsersRepository()));
    }

    public function getTerminalTokenAuth(): TerminalTokenAuth
    {
        return $this->resolve(TerminalTokenAuth::class, fn() => new TerminalTokenAuth($this->getTerminalsRepository()));
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

    // --- Controllers ---

    public function getHealthController(): HealthController
    {
        return $this->resolve(HealthController::class, fn() => new HealthController());
    }

    public function getAuthController(): AuthController
    {
        return $this->resolve(AuthController::class, fn() => new AuthController(
            $this->getAuthService(),
            $this->getAdminUsersService(),
            $this->getAuditService(),
            $this->getValidator(),
        ));
    }

    public function getMembersSyncController(): MembersSyncController
    {
        return $this->resolve(MembersSyncController::class, fn() => new MembersSyncController($this->getMembersService()));
    }

    public function getMembersAdminController(): MembersAdminController
    {
        return $this->resolve(MembersAdminController::class, fn() => new MembersAdminController($this->getMembersService(), $this->getValidator()));
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
        return $this->resolve(AdminUsersAdminController::class, fn() => new AdminUsersAdminController($this->getAdminUsersService(), $this->getValidator()));
    }

    public function getAuditLogAdminController(): AuditLogAdminController
    {
        return $this->resolve(AuditLogAdminController::class, fn() => new AuditLogAdminController($this->getAuditLogRepository()));
    }

    public function getSettlementsAdminController(): SettlementsAdminController
    {
        return $this->resolve(SettlementsAdminController::class, fn() => new SettlementsAdminController($this->getSettlementsService(), $this->getSepaExportService(), $this->getValidator()));
    }

    public function getSepaConfigController(): SepaConfigController
    {
        return $this->resolve(SepaConfigController::class, fn() => new SepaConfigController($this->getSepaConfigService(), $this->getValidator()));
    }

    public function getTerminalsAdminController(): TerminalsAdminController
    {
        return $this->resolve(TerminalsAdminController::class, fn() => new TerminalsAdminController($this->getTerminalsService(), $this->getValidator()));
    }

    public function getDashboardAdminController(): DashboardAdminController
    {
        return $this->resolve(DashboardAdminController::class, fn() => new DashboardAdminController(
            $this->getMembersRepository(),
            $this->getTransactionsRepository(),
            $this->getSettlementsRepository(),
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
