<?php

namespace OCA\NCDownloader\Db;

use OCP\IConfig;

class Settings
{
    private IConfig $appConfig;
    private ?string $user;
    private string $appName = 'ncdownloader';
    private int $type;
    private static ?self $instance = null;

    public const TYPE = ['SYSTEM' => 1, 'USER' => 2, 'APP' => 3];

    public function __construct(?string $user = null)
    {
        $this->appConfig = \OC::$server->get(IConfig::class);
        $this->type = self::TYPE['USER'];
        $this->user = $user;
    }

    public static function create(?string $user = null): static
    {
        if (!self::$instance || ($user !== null && self::$instance->user !== $user)) {
            self::$instance = new static($user);
        }
        return self::$instance;
    }

    public function setType(int $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function get(string $key, $default = null)
    {
        if ($this->type === self::TYPE['USER'] && $this->user !== null && $this->user !== '') {
            return $this->appConfig->getUserValue($this->user, $this->appName, $key, $default);
        } elseif ($this->type === self::TYPE['SYSTEM']) {
            return $this->appConfig->getSystemValue($key, $default);
        }
        return $this->appConfig->getAppValue($this->appName, $key, (string) ($default ?? ''));
    }

    public function getAria2(): array
    {
        if ($this->user === null || $this->user === '') {
            return [];
        }
        $settings = (string) $this->appConfig->getUserValue($this->user, $this->appName, 'custom_aria2_settings', '');
        if ($settings === '') {
            return [];
        }
        $decoded = json_decode($settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getYtdl(): array
    {
        $settings = (string) ($this->get('custom_ytdl_settings', '') ?? '');
        if ($settings === '') {
            return [];
        }
        $decoded = json_decode($settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getAll(): array
    {
        if ($this->type === self::TYPE['APP']) {
            return $this->getAllAppValues();
        }
        return $this->getAllUserSettings();
    }

    public function save(string $key, $value): array
    {
        try {
            if ($this->type === self::TYPE['USER'] && $this->user !== null && $this->user !== '') {
                $this->appConfig->setUserValue($this->user, $this->appName, $key, $value);
            } elseif ($this->type === self::TYPE['SYSTEM']) {
                $this->appConfig->setSystemValue($key, $value);
            } else {
                $this->appConfig->setAppValue($this->appName, $key, (string) $value);
            }
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return ['message' => 'Saved!'];
    }

    public function getAllAppValues(): array
    {
        $keys = $this->getAllKeys();
        $value = [];
        foreach ($keys as $key) {
            $value[$key] = $this->appConfig->getAppValue($this->appName, $key);
        }
        return $value;
    }

    public function getAllKeys(): array
    {
        return $this->appConfig->getAppKeys($this->appName);
    }

    public function getAllUserSettings(): array
    {
        if ($this->user === null || $this->user === '') {
            return [];
        }
        $keys = $this->appConfig->getUserKeys($this->user, $this->appName);
        $value = [];
        foreach ($keys as $key) {
            $value[$key] = $this->appConfig->getUserValue($this->user, $this->appName, $key);
        }
        return $value;
    }
}
