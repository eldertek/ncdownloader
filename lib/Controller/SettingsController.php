<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\AppInfo\Application;
use OCA\NCDownloader\Db\Settings;
use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Ytdl\Options as YtdlOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller
{
    private const ALLOWED_USER_KEYS = [
        'ncd_downloader_dir',
        'ncd_torrents_dir',
        'ncd_seed_ratio',
        'ncd_seed_time_unit',
        'ncd_seed_time',
        'ncd_hide_errors',
    ];

    private const ALLOWED_ADMIN_KEYS = [
        'ncd_aria2_rpc_host',
        'ncd_aria2_rpc_port',
        'ncd_aria2_rpc_token',
        'ncd_yt_binary',
        'ncd_aria2_binary',
        'ncd_disable_bt',
        'disallow_aria2_settings',
    ];

    private string $uid;
    private Settings $settings;

    public function __construct(IRequest $request, IUserSession $userSession)
    {
        parent::__construct(Application::APP_ID, $request);
        $user = $userSession->getUser();
        $this->uid = $user ? $user->getUID() : '';
        $this->settings = new Settings($this->uid);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getSettings(): JSONResponse
    {
        $name = (string) $this->request->getParam('name', '');
        $type = (int) ($this->request->getParam('type') ?? Settings::TYPE['USER']);
        $default = $this->request->getParam('default');
        if ($type === Settings::TYPE['SYSTEM'] && !Helper::isAdmin($this->uid)) {
            if (!in_array($name, ['ncd_disable_bt', 'disallow_aria2_settings'], true)) {
                return new JSONResponse(['error' => 'Forbidden'], 403);
            }
        }
        return new JSONResponse(Helper::getSettings($name, $default, $type, $this->uid));
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function saveCustom(): JSONResponse
    {
        $params = $this->request->getParams();
        $resp = ['message' => 'Saved!', 'status' => true];
        foreach ($params as $key => $value) {
            if (!in_array((string) $key, self::ALLOWED_USER_KEYS, true)) {
                continue;
            }
            $resp = $this->save((string) $key, $value, Settings::TYPE['USER']);
        }
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getCustomAria2(): JSONResponse
    {
        $raw = (string) ($this->settings->get('custom_aria2_settings') ?? '');
        $data = $raw !== '' ? json_decode($raw, true) : [];
        return new JSONResponse(is_array($data) ? $data : []);
    }

    public function saveAdmin(): JSONResponse
    {
        if (!Helper::isAdmin($this->uid)) {
            return new JSONResponse(['error' => 'Forbidden', 'status' => false], 403);
        }
        $params = $this->request->getParams();
        $data = $this->settings->setType(Settings::TYPE['SYSTEM'])->get('ncd_admin_settings', []);
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($params as $key => $value) {
            $keyStr = (string) $key;
            if (str_starts_with($keyStr, '_') || !in_array($keyStr, self::ALLOWED_ADMIN_KEYS, true)) {
                continue;
            }
            $data[$keyStr] = $value;
        }
        $resp = $this->save('ncd_admin_settings', $data, Settings::TYPE['SYSTEM']);
        return new JSONResponse($resp);
    }

    public function saveGlobalAria2(): JSONResponse
    {
        if (!Helper::isAdmin($this->uid)) {
            return new JSONResponse(['error' => 'Forbidden', 'status' => false], 403);
        }
        $params = $this->request->getParams();
        $data = Helper::filterData($params, Helper::aria2UserOptions());
        $resp = $this->save('global_aria2_config', $data, Settings::TYPE['SYSTEM']);
        return new JSONResponse($resp);
    }

    public function getGlobalAria2(): JSONResponse
    {
        if (!Helper::isAdmin($this->uid)) {
            return new JSONResponse(['error' => 'Forbidden', 'status' => false], 403);
        }
        return new JSONResponse(Helper::getSettings('global_aria2_config', [], Settings::TYPE['SYSTEM']));
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function saveCustomAria2(): JSONResponse
    {
        $noAria2Settings = (bool) Helper::getAdminSettings('disallow_aria2_settings');
        if ($noAria2Settings && !Helper::isAdmin($this->uid)) {
            return new JSONResponse(['error' => 'forbidden', 'status' => false], 403);
        }
        $params = $this->request->getParams();
        $data = Helper::filterData($params, Helper::aria2UserOptions());
        foreach ($data as $k => $v) {
            $data[$k] = Helper::sanitize($v);
        }
        $resp = $this->settings->setType(Settings::TYPE['USER'])->save('custom_aria2_settings', json_encode($data));
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function deleteCustomAria2(): JSONResponse
    {
        $raw = (string) ($this->settings->get('custom_aria2_settings') ?? '');
        $saved = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($saved)) {
            $saved = [];
        }
        $params = $this->request->getParams();
        $data = Helper::filterData($params, Helper::aria2Options());
        foreach ($data as $key => $_value) {
            unset($saved[$key]);
        }
        $resp = $this->settings->setType(Settings::TYPE['USER'])->save('custom_aria2_settings', json_encode($saved));
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function getYtdl(): JSONResponse
    {
        $raw = (string) ($this->settings->get('custom_ytdl_settings') ?? '');
        $data = $raw !== '' ? json_decode($raw, true) : [];
        return new JSONResponse(is_array($data) ? $data : []);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function saveYtdl(): JSONResponse
    {
        $params = $this->request->getParams();
        $allowed = YtdlOptions::get();
        $data = [];
        foreach ($params as $key => $value) {
            $cleanKey = ltrim((string) $key, '-');
            if (str_starts_with((string) $key, '_') || !in_array($cleanKey, $allowed, true)) {
                continue;
            }
            $data[$cleanKey] = Helper::sanitize($value);
        }
        $resp = $this->settings->setType(Settings::TYPE['USER'])->save('custom_ytdl_settings', json_encode($data));
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function deleteYtdl(): JSONResponse
    {
        $raw = (string) ($this->settings->get('custom_ytdl_settings') ?? '');
        $saved = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($saved)) {
            $saved = [];
        }
        $params = $this->request->getParams();
        foreach ($params as $key => $_value) {
            $cleanKey = ltrim((string) $key, '-');
            unset($saved[$key], $saved[$cleanKey]);
        }
        $resp = $this->settings->setType(Settings::TYPE['USER'])->save('custom_ytdl_settings', json_encode($saved));
        return new JSONResponse($resp);
    }

    public function save(string $key, $value, int $type = Settings::TYPE['USER']): array
    {
        if (str_starts_with($key, '_')) {
            return ['message' => 'Ignored', 'status' => false];
        }
        $key = (string) Helper::sanitize($key);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = Helper::sanitize($v);
            }
        } else {
            $value = Helper::sanitize($value);
        }
        try {
            $this->settings->setType($type);
            $this->settings->save($key, $value);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'status' => false];
        }
        return ['message' => 'Saved!', 'status' => true];
    }
}
