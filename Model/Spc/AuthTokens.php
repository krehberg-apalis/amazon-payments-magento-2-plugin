<?php

namespace Amazon\Pay\Model\Spc;

use Amazon\Pay\Model\Adapter\AmazonPayAdapter;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Integration\Model\AuthorizationService;
use Magento\Integration\Model\Integration as IntegrationModel;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\OauthService;
use Magento\Integration\Model\ResourceModel\Integration;
use Magento\Integration\Model\ResourceModel\Integration\Collection as IntegrationCollection;
use Magento\Integration\Model\IntegrationFactory;
use Magento\Integration\Model\ResourceModel\Oauth\Token as TokenResourceModel;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

class AuthTokens
{
    const INTEGRATION_USER_NAME = 'Amazon Single Page Checkout';

    const STATUS_CONFIG_PATH = 'payment/amazon_pay/spc_tokens_sync_status';

    const LAST_SYNC_CONFIG_PATH = 'payment/amazon_pay/spc_tokens_last_sync';

    const AUTH_VERSION = 'OAuth 1.0a';

    /**
     * @var IntegrationFactory
     */
    protected $integrationFactory;

    /**
     * @var Integration
     */
    protected $integrationResourceModel;

    /**
     * @var IntegrationCollection
     */
    protected $integrationCollection;

    /**
     * @var AuthorizationService
     */
    protected $authorizationService;

    /**
     * @var OauthService
     */
    protected $oauthService;

    /**
     * @var Token
     */
    protected $integrationToken;

    /**
     * @var TokenResourceModel
     */
    protected $integrationTokenResourceModel;

    /**
     * @var AmazonPayAdapter
     */
    protected $amazonPayAdapter;

    /**
     * @var Store
     */
    protected $store;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var WriterInterface
     */
    protected $configWriter;

    /**
     * @var MutableScopeConfig
     */
    protected $mutableScopeConfig;

    /**
     * @var StoreRepositoryInterface
     */
    protected $storeRepository;

    /**
     * @param IntegrationFactory $integrationFactory
     * @param Integration $integrationResourceModel
     * @param IntegrationCollection $integrationCollection
     * @param AuthorizationService $authorizationService
     * @param OauthService $oauthService
     * @param Token $integrationToken
     * @param TokenResourceModel $integrationTokenResourceModel
     * @param AmazonPayAdapter $amazonPayAdapter
     * @param Store $store
     * @param Json $json
     * @param WriterInterface $configWriter
     * @param MutableScopeConfig $mutableScopeConfig
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        IntegrationFactory $integrationFactory,
        Integration $integrationResourceModel,
        IntegrationCollection $integrationCollection,
        AuthorizationService $authorizationService,
        OauthService $oauthService,
        Token $integrationToken,
        TokenResourceModel $integrationTokenResourceModel,
        AmazonPayAdapter $amazonPayAdapter,
        Store $store,
        Json $json,
        WriterInterface $configWriter,
        MutableScopeConfig $mutableScopeConfig,
        StoreRepositoryInterface $storeRepository
    )
    {
        $this->integrationFactory = $integrationFactory;
        $this->integrationResourceModel = $integrationResourceModel;
        $this->integrationCollection = $integrationCollection;
        $this->authorizationService = $authorizationService;
        $this->oauthService = $oauthService;
        $this->integrationToken = $integrationToken;
        $this->integrationTokenResourceModel = $integrationTokenResourceModel;
        $this->amazonPayAdapter = $amazonPayAdapter;
        $this->store = $store;
        $this->json = $json;
        $this->configWriter = $configWriter;
        $this->mutableScopeConfig = $mutableScopeConfig;
        $this->storeRepository = $storeRepository;
    }

    /**
     * @return IntegrationModel
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Oauth\Exception
     */
    public function createOrRenewTokens()
    {
        $integrationCollection = $this->integrationCollection->load();
        $userExists = false;
        $integrationName = self::INTEGRATION_USER_NAME;

        // Check for integration already existing
        foreach ($integrationCollection as $user) {
            /* @var $user IntegrationModel */
            if ($user->getName() == $integrationName) {
                $userExists = true;
                $integration = $user;

                break;
            }
        }

        // Create integration user
        if (!$userExists) {
            $integration = $this->integrationFactory->create();
            $integration->setData([
                'name' => $integrationName,
                'status' => IntegrationModel::STATUS_ACTIVE,
                'setup_type' => 0
            ]);
            $this->integrationResourceModel->save($integration);
            $this->authorizationService->grantAllPermissions($integration->getId());

            // Create Integration user consumer
            $consumer = $this->oauthService->createConsumer(['name' => 'Prime'. $integration->getId()]);
            $integration->setConsumerId($consumer->getId());
            $this->integrationResourceModel->save($integration);

            // Create integration user token
            $token = $this->integrationToken;
            $token->createVerifierToken($consumer->getId());
            $token->setType('access');
            $this->integrationTokenResourceModel->save($token);
        }
        // Renew tokens for existing integration user
        else {
            $this->oauthService->createAccessToken($integration->getConsumerId(), true);
            $integration->setStatus(IntegrationModel::STATUS_ACTIVE);
            $this->integrationResourceModel->save($integration);
        }

        return $integration;
    }

    /**
     * @param IntegrationModel $integration
     * @param $storeCode
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Oauth\Exception
     */
    protected function sendTokens(IntegrationModel $integration, $storeCode)
    {
        $store = $this->store->load('admin');

        $consumer = $this->oauthService->loadConsumer($integration->getConsumerId());
        $accessTokens = $this->oauthService->getAccessToken($consumer->getId());
        parse_str($accessTokens, $accessTokens);

        $payload = [
            'authDetails' => [
                'merchantStoreReferenceId' => $storeCode,
                'authInformation' => [
                    [
                        'type' => 'CONSUMER_KEY',
                        'value' => $consumer->getKey()
                    ],
                    [
                        'type' => 'CONSUMER_SECRET',
                        'value' => $consumer->getSecret()
                    ],
                    [
                        'type' => 'ACCESS_TOKEN',
                        'value' => $accessTokens['oauth_token']
                    ],
                    [
                        'type' => 'ACCESS_TOKEN_SECRET',
                        'value' => $accessTokens['oauth_token_secret']
                    ],
                ],
                'auth_timestamp' => time(),
                'auth_version' => self::AUTH_VERSION
            ]
        ];

        return $this->amazonPayAdapter->syncTokens($store->getId(), $this->json->serialize($payload));
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Oauth\Exception
     */
    public function createOrRenewAndSendTokens()
    {
        $integration = $this->createOrRenewTokens();

        $stores = $this->storeRepository->getList();

        $errorResponses = [];

        foreach ($stores as $store) {
            if ($store->getId() == 0) {
                continue;
            }
            $response = $this->sendTokens($integration, $store->getCode());

            $responseCode = $response['status'] ?? '404';
            if (!preg_match('/^2\d\d$/', $responseCode)) {
                $this->saveStatus(__('Tokens failed to sync on the last attempt'), $store->getId());

                $errorResponses[] = $response['message'];

                continue;
            }

            $this->saveLastSync($store->getId());

            $this->saveStatus(__('Tokens synced successfully'), $store->getId());
        }

        if (!empty($errorResponses)) {
            throw new \Exception($errorResponses[0]);
        }

        return $response;
    }

    /**
     * @param $message
     * @param $storeId
     * @return void
     */
    protected function saveStatus($message, $storeId)
    {
        $this->configWriter->save(
            AuthTokens::STATUS_CONFIG_PATH,
            $message .', '. date('Y-m-d H:i:s', time()) .' UTC',
            ScopeInterface::SCOPE_STORES,
            $storeId
        );

        $this->mutableScopeConfig->clean();
    }

    /**
     * @param $storeId
     * @return void
     */
    protected function saveLastSync($storeId)
    {
        $this->configWriter->save(
            self::LAST_SYNC_CONFIG_PATH,
            date('Y-m-d H:i:s', time()),
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
    }
}
