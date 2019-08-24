<?php namespace MijnKantoor\OauthMiddleware;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom($this->configPath(), 'oauth-middleware');
    }

    /**
     * Add the Cors middleware to the router.
     *
     */
    public function boot()
    {
        $this->publishes([$this->configPath() => config_path('oauth-middleware.php')]);
    }

    protected function configPath()
    {
        return __DIR__ . '/../config/oauth-middleware.php';
    }
}
