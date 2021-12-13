<?php
namespace Amazon\Pay\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Amazon\Pay\Model\AmazonConfig;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'amazon_payment_v2';

    const VAULT_CODE = 'amazon_payment_v2_vault';    

    /**
     * @var Config
     */
    private $config;

    /**
     * ConfigProvider constructor.
     * @param AmazonConfig $config
     */
    public function __construct(
        AmazonConfig $config
    ) {
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
    
        $config = [
            'payment' => [
                self::CODE => [
                    'isActive' => $this->config->isActive(),
                ]
            ]
        ];

        if ($this->config->isVaultEnabled()) {

            $config['vault'] = [
                self::VAULT_CODE => [
                    'is_enabled' => true
                ]
            ];
        }

        return $config;
    }
}
