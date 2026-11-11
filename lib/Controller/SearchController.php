<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Search\siteSearch;
use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SearchController extends Controller
{
    private siteSearch $search;

    public function __construct(IRequest $request)
    {
        parent::__construct(Application::APP_ID, $request);
        $this->search = new siteSearch();
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function execute(string $keyword = '', string $site = 'TPB'): JSONResponse
    {
        $keyword = (string) Helper::sanitize($keyword);
        $site = (string) Helper::sanitize($site);
        if (trim($keyword) === '') {
            return new JSONResponse(['error' => 'Empty search keyword']);
        }
        $this->search->setSite($site);
        $data = $this->search->go($keyword);
        return new JSONResponse($data);
    }
}
