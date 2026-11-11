<?php

namespace OCA\NCDownloader\Settings;

use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings
{
    private IURLGenerator $urlGenerator;

    public function __construct(IURLGenerator $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    public function getForm(): TemplateResponse
    {
        Util::addScript('ncdownloader', 'appSettings');
        Util::addStyle('ncdownloader', 'appSettings');

        $settings = Helper::getAllAdminSettings();
        $settings['path'] = $this->urlGenerator->linkToRoute('ncdownloader.Settings.saveAdmin');
        $settings['aria2_version'] = Helper::getAria2Version();
        $settings['ytdl_version'] = Helper::getYtdlVersion();

        $parameters = [
            'settings' => $settings,
            'options' => Helper::getAdminOptions($settings),
        ];
        return new TemplateResponse('ncdownloader', 'settings/Admin', $parameters, '');
    }

    public function getSection(): string
    {
        return 'ncdownloader';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
