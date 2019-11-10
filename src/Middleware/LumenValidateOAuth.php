<?php namespace MijnKantoor\OauthMiddleware\Middleware;

use Closure;
use GuzzleHttp\Client;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MijnKantoor\OauthMiddleware\Services\ApiService;

class LumenValidateOAuth
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
    public function __construct(ApiService $service)
    {
        $this->service = $service;
        $this->client = new Client();
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

            //Store token respones somewhere we can test it again

            $this->storeTokenResponse($request, $data);

            // create new user or login?
            $this->createOrLoginUser($request, $data);
            // token is in memory now

            return redirect(route(config('oauth-middleware.default_redirect')));
        }

        if (!$this->refreshToken($request) || !auth()->check()) { // no valid token in memory? redirect to login
            $query = http_build_query([
                'client_id' => config('oauth-middleware.client_id'),
                'redirect_uri' => route(config('oauth-middleware.redirect_route')),
                'response_type' => 'code',
                'scope' => '',
            ]);

            return redirect(env('HH_MW_AUTH_URL') . '/oauth/authorize?' . $query);
        }

        // token is in memory now
        return $next($request);
    }

    private function createOrLoginUser($request, $tokenResponse)
    {
        $this->service->init($tokenResponse['access_token']);
        $me = $this->service->getMe();

        if ($me) {
            $tenantId = $me['tenants'][0]['id'];
            $user = $this->createUserModel(
                $me['user']['email'],
                implode(" ", [$me['user']['first_name'], $me['user']['last_name']])
            );

            auth()->login($user);

            // set the tenantId from the tenants include
            $this->storeTenantId($request, $tenantId);
            $this->service->setTenantId($tenantId);
        }
    }

    private function refreshToken($request): bool
    {
        $accessToken = $request->session->get($this->getCacheKey(self::ACCESS_TOKEN));
        $expires = $request->session->get($this->getCacheKey(self::EXPIRES_IN));
        $refreshToken = $request->session->get($this->getCacheKey(self::REFRESH_TOKEN));

        if (!$accessToken || !$expires || !$refreshToken) {
            return false;
        }

        $expires = Carbon::createFromTimestamp($expires);
        if (Carbon::now()->gt($expires)) {
            $http = new Client();
            $response = $http->post(env('HH_MW_API_URL') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => env('HH_MW_CLIENT_ID'),
                    'client_secret' => env('HH_MW_CLIENT_SECRET'),
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

    private function storeTokenResponse($request, $data)
    {
        $request->session->put($this->getCacheKey(self::ACCESS_TOKEN), $data['access_token']);
        $request->session->put($this->getCacheKey(self::REFRESH_TOKEN), $data['refresh_token']);
        $request->session->put(
            $this->getCacheKey(self::EXPIRES_IN),
            \Carbon\Carbon::now()->addSeconds($data['expires_in'])->timestamp
        );
    }

    private function storeTenantId($request, $tenantId)
    {
        $request->session->put($this->getCacheKey(self::TENANT_ID), $tenantId);
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
