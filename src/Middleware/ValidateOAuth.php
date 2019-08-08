<?php namespace MijnKantoor\OauthMiddleware\Middleware;

use Closure;
use GuzzleHttp\Client;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MijnKantoor\OauthMiddleware\Services\ApiService;

class ValidateOauth
{
    /**
     * @var ApiService
     */
    private $service;
    /**
     * @var Store
     */
    private $session;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var StatefulGuard
     */
    private $auth;


    const ACCESS_TOKEN = 'access_token';
    const EXPIRES_IN = 'expires_in';
    const REFRESH_TOKEN = 'refresh_token';
    const TENANT_ID = 'tenant_id';

    /**
     * ValidateOauth constructor.
     * @param ApiService $service
     * @param Store $session
     * @param StatefulGuard $auth
     */
    public function __construct(ApiService $service, Store $session, StatefulGuard $auth)
    {
        $this->service = $service;
        $this->client = new Client();
        $this->session = $session;
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->has('code')) {
            $response = $this->client->post(config('oauth-middleware.api_url') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('oauth-middleware.client_id'),
                    'client_secret' => config('oauth-middleware.client_secret'),
                    'redirect_uri' => route(config('oauth-middleware.redirect_route')),
                    'code' => $request->get('code'),
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            $this->storeTokenResponse($data);

            // create new user or login?
            $this->createOrLoginUser($data);
            // token is in memory now
            return redirect(route(config('oauth-middleware.default_redirect')));
        } elseif (!$this->refreshToken() || !auth()->check()) { // no valid token in memory? redirect to login
            $query = http_build_query([
                'client_id' => config('oauth-middleware.client_id'),
                'redirect_uri' => route(config('oauth-middleware.redirect_route')),
                'response_type' => 'code',
                'scope' => '',
            ]);
            return redirect(env('HH_AUTH_URL') . '/oauth/authorize?' . $query);
        }
        // token is in memory now
        return $next($request);
    }

    private function createOrLoginUser($tokenResponse)
    {
        $this->service->init($tokenResponse['access_token']);
        $me = $this->service->getMe();

        if ($me) {
            $tenantId = $me['tenants'][0]['id'];
            $user = $this->createUserModel(
                $me['user']['email'],
                implode(" ", [$me['user']['first_name'], $me['user']['last_name']])
            );


            $this->auth->login($user);

            // set the tenantId from the tenants include
            $this->storeTenantId($tenantId);
            $this->service->setTenantId($tenantId);
        }
    }

    private function refreshToken(): bool
    {
        $accesToken = $this->session->get($this->getCacheKey(self::ACCESS_TOKEN));
        $expires = $this->session->get($this->getCacheKey(self::EXPIRES_IN));
        $refreshToken = $this->session->get($this->getCacheKey(self::REFRESH_TOKEN));

        if (!$accesToken || !$expires || !$refreshToken) {
            return false;
        }

        $expires = Carbon::createFromTimestamp($expires);
        if (Carbon::now()->gt($expires)) {
            $http = new Client();
            $response = $http->post(env('HH_AUTH_URL') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => env('HH_CLIENT_ID'),
                    'client_secret' => env('HH_CLIENT_SECRET'),
                    'scope' => '',
                ],
            ]);
            $data = json_decode((string)$response->getBody(), true);
            if (!isset($data['access_token'])) {
                return false;
            }
            $this->storeTokenResponse($data);
        }
        return true;
    }

    private function storeTokenResponse($data)
    {
        $this->session->put($this->getCacheKey(self::ACCESS_TOKEN), $data['access_token']);
        $this->session->put($this->getCacheKey(self::REFRESH_TOKEN), $data['refresh_token']);
        $this->session->put(
            $this->getCacheKey(self::EXPIRES_IN),
            \Carbon\Carbon::now()->addSeconds($data['expires_in'])->timestamp
        );
    }

    private function storeTenantId($tenantId)
    {
        $this->session->put($this->getCacheKey(self::TENANT_ID), $tenantId);
    }

    private function getCacheKey($keyName)
    {
        return sprintf(
            '%s_%s',
            config('oauth-middleware.cache.prefix'),
            config('oauth-middleware.cache.keys.' . $keyName)
        );
    }

    private function createUserModel($name, $email)
    {
        /** @var \Illuminate\Database\Eloquent\Builder $class */
        $class = config('oauth-middleware.user');

        /** @var \Illuminate\Auth\Authenticatable; $user */
        $user = $class::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Str::random(32)
            ]
        );

        return $user;
    }
}