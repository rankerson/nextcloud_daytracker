<?php

declare(strict_types=1);

namespace OCA\Daytracker\Dashboard;

use OCA\Daytracker\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class DaytrackerWidget implements IWidget
{
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator)
    {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
    }

    public function getId(): string
    {
        return Application::APP_ID;
    }

    public function getTitle(): string
    {
        return $this->l10n->t('Daytracker heute');
    }

    public function getOrder(): int
    {
        return 50;
    }

    public function getIconClass(): string
    {
        return 'icon-daytracker';
    }

    public function getUrl(): ?string
    {
        return $this->urlGenerator->linkToRouteAbsolute('daytracker.page.index');
    }

    public function load(): void
    {
        Util::addStyle(Application::APP_ID, 'style');
        Util::addScript(Application::APP_ID, 'dashboard');
    }
}
