<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('ncdownloader', 'ncdownloader.svg');
    }

    public function getID(): string
    {
        return 'ncdownloader';
    }

    public function getName(): string
    {
        return $this->l->t('NCDownloader Settings');
    }

    public function getPriority(): int
    {
        return 100;
    }
}
