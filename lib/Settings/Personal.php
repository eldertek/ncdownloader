<?php

namespace OCA\NCDownloader\Settings;

use OCA\NCDownloader\Db\Settings;
use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings
{
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;

    public function __construct(
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IL10N $l10n
    ) {
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
    }

    public function getForm(): TemplateResponse
    {
        Util::addScript('ncdownloader', 'appSettings');
        Util::addStyle('ncdownloader', 'appSettings');

        $user = $this->userSession->getUser();
        $uid = $user ? $user->getUID() : '';
        $settingsStore = new Settings($uid);
        $path = $this->urlGenerator->linkToRoute('ncdownloader.Settings.saveCustom');
        $isAdmin = Helper::isAdmin($uid);
        $dlDir = Helper::getDownloadDir($uid);
        $torrentsDir = $settingsStore->get('ncd_torrents_dir', '/Torrents');

        $parameters = [
            'settings' => [
                'ncd_downloader_dir' => $dlDir,
                'ncd_torrents_dir' => $torrentsDir,
                'ncd_seed_ratio' => $settingsStore->get('ncd_seed_ratio'),
                'ncd_seed_time_unit' => $settingsStore->get('ncd_seed_time_unit'),
                'ncd_seed_time' => $settingsStore->get('ncd_seed_time'),
                'path' => $path,
                'disallow_aria2_settings' => Helper::getAdminSettings('disallow_aria2_settings'),
                'is_admin' => $isAdmin,
            ],
            'options' => [
                [
                    'label' => $this->l10n->t('Downloads Folder'),
                    'id' => 'ncd_downloader_dir',
                    'value' => $dlDir,
                    'placeholder' => $dlDir ?: '/Downloads',
                    'path' => $path,
                ],
                [
                    'label' => $this->l10n->t('Torrents Folder'),
                    'id' => 'ncd_torrents_dir',
                    'value' => $torrentsDir,
                    'placeholder' => $torrentsDir ?: '/Torrents',
                    'path' => $path,
                ],
            ],
        ];

        return new TemplateResponse('ncdownloader', 'settings/Personal', $parameters, '');
    }

    public function getSection(): string
    {
        return 'ncdownloader';
    }

    public function getPriority(): int
    {
        return 100;
    }
}
