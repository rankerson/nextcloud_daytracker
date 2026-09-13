<?php

declare(strict_types=1);

namespace OCA\Daytracker\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

final class DaytrackerWidget implements IWidget
{
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string
    {
        return 'daytracker';
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
        Util::addStyle('daytracker', 'style');
        Util::addScript('daytracker', 'dashboard');
    }
}
