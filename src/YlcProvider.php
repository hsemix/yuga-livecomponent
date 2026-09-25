<?php

namespace Yuga\Live;

use Yuga\Interfaces\Application\Application;
use Yuga\Live\Controllers\LiveController;
use Yuga\Live\Controllers\LiveStreamController;
use Yuga\Live\Controllers\LiveUploadController;
use Yuga\Providers\ServiceProvider;
use Yuga\Route\Exceptions\NotFoundHttpExceptionHandler;
use Yuga\Route\Route;

class YlcProvider extends ServiceProvider
{
    protected $users = [];
    protected $namespace = 'App\Controllers';
    /**
     * Register a service to the application
     * 
     * @param \Yuga\Interfaces\Application\Application
     * 
     * @return mixed
     */
    public function load(Application $app)
    {
        return $app;
    }

    public function boot(Route $router)
    {
        $this->publishes([
            __DIR__ . '/../config/ylc.php' => path('config/ylc.php'),
            __DIR__ . '/../public/plugins/ylc-live-plugin.js' => path('public/plugins/ylc-live-plugin.js'),
        ], 'ylc-assets');

        $router->post('/ylc/message', [LiveController::class, 'message']);
        $router->get('/ylc/stream', [LiveStreamController::class, 'stream']);
        $router->post('/ylc/lazy', [LiveController::class, 'lazy']);
        $router->post('/ylc/upload', [LiveUploadController::class, 'upload']);
        $router->get('/ylc/temp-upload/{token}', [LiveUploadController::class, 'preview']);

        $discoverer = new ComponentDiscoverer();

        foreach (config('ylc.discovery', []) as $source) {
            $components = $discoverer->discover(
                $source['path'],
                $source['namespace'],
                $source['prefix'] ?? null
            );

            foreach ($components as $name => $definition) {
                $class = $definition['class'];
                $config = $definition['config'] ?? null;

                YLC::component($name, $class);

                if ($config) {
                    YLC::options($name, [
                        'lazy' => $config->lazy ?? false,
                        'stream' => $config->stream ?? false,
                        'streamInterval' => $config->streamInterval ?? null,
                        'streamAlways' => $config->streamAlways ?? false,
                        'poll' => $config->poll ?? false,
                        'pollInterval' => $config->pollInterval ?? null,
                    ]);
                }
            }
        }
    }
}
