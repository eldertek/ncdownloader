<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Controller\MainController as Main;
use OCA\NCDownloader\Controller\SearchController as Search;
use OCA\NCDownloader\Controller\YtdlController as YTD;
use OCP\AppFramework\ApiController as API;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ApiController extends API
{
    private YTD $ytdl;
    private Main $main;
    private Search $search;

    public function __construct(
        IRequest $request,
        YTD $ytdl,
        Main $main,
        Search $search
    ) {
        $this->main = $main;
        $this->search = $search;
        $this->ytdl = $ytdl;
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @CORS
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $url = '', string $type = 'aria2', array $options = []): JSONResponse
    {
        if ($type === 'aria2') {
            return $this->main->Download($url);
        }
        if ($type === 'ytdl') {
            $extension = (string) ($options['extension'] ?? 'mp4');
            return $this->ytdl->Download($url, $extension);
        }
        if ($type === 'bt') {
            return $this->main->Upload();
        }
        return new JSONResponse(['error' => 'Invalid download type'], 400);
    }

    /**
     * @CORS
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[CORS]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(string $keyword = '', string $site = 'TPB'): JSONResponse
    {
        return $this->search->execute($keyword, $site);
    }
}
