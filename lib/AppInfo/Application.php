<?php

declare(strict_types=1);

namespace OCA\Daytracker\AppInfo;

use OCA\Daytracker\Dashboard\DaytrackerWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'daytracker';

    /**
     * @param array<string, mixed> $urlParams
     */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerDashboardWidget(DaytrackerWidget::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}
