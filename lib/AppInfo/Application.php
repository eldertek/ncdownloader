<?php

namespace OCA\NCDownloader\AppInfo;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Settings;
use OCA\NCDownloader\Http\Client;
use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Ytdl\Ytdl;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'ncdownloader';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerService(Client::class, function () {
            return Client::create(['ipv4' => true]);
        });
        $context->registerService(Crawler::class, function () {
            return new Crawler();
        });
        $context->registerService('uid', function (ContainerInterface $c): string {
            $user = $c->get(IUserSession::class)->getUser();
            return $user ? $user->getUID() : '';
        });
        $context->registerService(Aria2::class, function (ContainerInterface $c): Aria2 {
            $user = $c->get(IUserSession::class)->getUser();
            $uid = $user ? $user->getUID() : '';
            return new Aria2(Helper::getAria2Config($uid));
        });
        $context->registerService(Ytdl::class, function (ContainerInterface $c): Ytdl {
            $user = $c->get(IUserSession::class)->getUser();
            $uid = $user ? $user->getUID() : '';
            return new Ytdl(Helper::getYtdlConfig($uid));
        });
        $context->registerService(Settings::class, function (ContainerInterface $c): Settings {
            $user = $c->get(IUserSession::class)->getUser();
            $uid = $user ? $user->getUID() : '';
            return new Settings($uid);
        });

        $sites = Helper::getSearchSites();
        foreach ($sites as $site) {
            $className = $site['class'];
            $context->registerService($className, function (ContainerInterface $container) use ($className) {
                $crawler = new Crawler();
                $client = $container->get(Client::class);
                return $className::create($crawler, $client);
            });
        }
    }

    public function boot(IBootContext $c): void
    {
    }
}
