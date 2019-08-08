<?php namespace MijnKantoor\OauthMiddleware\Services;

use HelloHi\ApiClient\Client;

class ApiService
{
    /**
     * @var string
     */
    private $accessToken;
    /**
     * @var int
     */
    private $tenantId;
    /**
     * @var Client
     */
    private $client;

    public function init($accessToken)
    {
        $this->accessToken = $accessToken;
        $this->client = Client::initFromBearerToken(
            config('oauth-middleware.api_url'),
            $accessToken
        );
        return $this;
    }

    public function getMe()
    {
        $response = $this->client->get('me?include=tenants,person.image');
        if (isset($response['data'])) {
            return [
                'user' => $response['data'],
                'image' => $response['data']['person']['data']['image']['data']['url'] ?? null,
                'tenants' => $response['data']['tenants']['data']
            ];
        }
        return null;
    }

    public function setTenantId($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->client->setTenantId($tenantId);
    }

}