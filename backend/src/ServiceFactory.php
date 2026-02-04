<?php

declare(strict_types=1);

namespace App;

use App\Config\AppConfig;
use App\Config\Env;
use App\Logging\Logger;
use App\Validation\Validator;
use App\Repository\AdminUsersRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CategoriesRepository;
use App\Repository\MembersRepository;
use App\Repository\ProductsRepository;
use App\Repository\SepaConfigRepository;
use App\Repository\SessionRepository;
use App\Repository\SettlementsRepository;
use App\Repository\TerminalsRepository;
use App\Repository\TransactionsRepository;
use App\Service\AdminUsersService;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\CategoriesService;
use App\Service\MembersService;
use App\Service\ProductsService;
use App\Service\SepaConfigService;
use App\Service\SepaExportService;
use App\Service\SettlementsService;
use App\Service\TerminalsService;
use App\Service\TransactionsService;
use App\Controller\AdminUsersController;
use App\Controller\AuditLogController;
use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\HealthController;
use App\Controller\MembersAdminController;
use App\Controller\MembersSyncController;
use App\Controller\ProductsAdminController;
use App\Controller\ProductsSyncController;
use App\Controller\SepaConfigController;
use App\Controller\SettlementsController;
use App\Controller\TerminalsController;
use App\Controller\TransactionsAdminController;
use App\Controller\TransactionsSyncController;
use App\Middleware\AdminSessionAuth;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandler;
use App\Middleware\JsonBodyParser;
use App\Middleware\TerminalTokenAuth;
use PDO;
use Psr\Container\ContainerInterface;

class ServiceFactory implements ContainerInterface
{
    private array $instances = [];

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

    public function getAdminUsersController(): AdminUsersController
    {
        return $this->resolve(AdminUsersController::class, fn() => new AdminUsersController($this->getAdminUsersService(), $this->getValidator()));
    }

    public function getAuditLogController(): AuditLogController
    {
        return $this->resolve(AuditLogController::class, fn() => new AuditLogController($this->getAuditLogRepository()));
    }

    public function getSettlementsController(): SettlementsController
    {
        return $this->resolve(SettlementsController::class, fn() => new SettlementsController($this->getSettlementsService(), $this->getSepaExportService(), $this->getValidator()));
    }

    public function getSepaConfigController(): SepaConfigController
    {
        return $this->resolve(SepaConfigController::class, fn() => new SepaConfigController($this->getSepaConfigService(), $this->getValidator()));
    }

    public function getTerminalsController(): TerminalsController
    {
        return $this->resolve(TerminalsController::class, fn() => new TerminalsController($this->getTerminalsService(), $this->getValidator()));
    }

    public function getDashboardController(): DashboardController
    {
        return $this->resolve(DashboardController::class, fn() => new DashboardController(
            $this->getMembersRepository(),
            $this->getTransactionsRepository(),
            $this->getSettlementsRepository(),
        ));
    }

    // --- Container interface for Slim ---

    public function has(string $id): bool
    {
        return method_exists($this, 'get' . $this->classNameFromId($id));
    }

    public function get(string $id): mixed
    {
        $method = 'get' . $this->classNameFromId($id);
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        throw new \RuntimeException("Service not found: {$id}");
    }

    // --- Internal ---

    private function resolve(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }

    private function classNameFromId(string $id): string
    {
        $parts = explode('\\', $id);
        return end($parts);
    }
}
