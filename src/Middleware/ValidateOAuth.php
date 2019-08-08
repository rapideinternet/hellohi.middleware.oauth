<?php


namespace Middleware;


use App\Services\HelloHiService;
use App\User;
use Closure;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ValidateOauth
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // redirecting back from oauth server?
        if (request()->code) {
            $http = new Client();
            $response = $http->post(env('HH_AUTH_URL') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => env('HH_CLIENT_ID'),
                    'client_secret' => env('HH_CLIENT_SECRET'),
                    'redirect_uri' => route('user.redirect'),
                    'code' => request()->code,
                ],
            ]);
            $data = json_decode((string)$response->getBody(), true);
            $this->storeTokenResponse($data);
            // create new user or login?
            $this->createOrLoginUser($data);
            // token is in memory now
            return redirect("/");
        } elseif (!$this->refreshToken() || !auth()->check()) { // no valid token in memory? redirect to login
            $query = http_build_query([
                'client_id' => env('HH_CLIENT_ID'),
                'redirect_uri' => route('user.redirect'),
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
        $helloHiService = app()->make(HelloHiService::class);
        $helloHiService->init($tokenResponse['access_token']);
        $me = $helloHiService->getMe();
        if ($me) {
            $email = $me['user']['email'];
            $name = implode(" ", [$me['user']['first_name'], $me['user']['last_name']]);
            $tenantId = $me['tenants'][0]['id'];
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Str::random(32)
                ]
            );
            auth()->login($user);
            // set the tenantId from the tenants include
            $this->storeTenantId($tenantId);
            $helloHiService->setTenantId($tenantId);
        }
    }

    private function refreshToken()
    {
        $accesToken = Session::get('hellohi_access_token');
        $expires = Session::get('hellohi_expires_in');
        $refreshToken = Session::get('hellohi_refresh_token');
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
        Session::put('hellohi_access_token', $data['access_token']);
        Session::put('hellohi_refresh_token', $data['refresh_token']);
        Session::put('hellohi_expires_in', Carbon::now()->addSeconds($data['expires_in'])->timestamp);
    }

    private function storeTenantId($tenantId)
    {
        Session::put('hellohi_tenant_id', $tenantId);
    }
}