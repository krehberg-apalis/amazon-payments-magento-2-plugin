<?php

namespace Amazon\Pay\Model\Spc;

use Amazon\Pay\Api\SpcApplyCouponInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Api\CartRepositoryInterface;
use Amazon\Pay\Helper\SpcCart;

class ApplyCoupon implements SpcApplyCouponInterface
{
    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var SpcCart
     */
    protected $cartHelper;

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param SpcCart $cartHelper
     */
    public function __construct(
        CartRepositoryInterface $cartRepository,
        SpcCart $cartHelper
    )
    {
        $this->cartRepository = $cartRepository;
        $this->cartHelper = $cartHelper;
    }

    /**
     * @inheritdoc
     */
    public function applyCoupon(int $cartId, string $couponCode)
    {
        // Get quote
        try {
            $quote = $this->cartRepository->get($cartId);
        } catch (NoSuchEntityException $e) {
            throw new WebapiException(
                new Phrase($e->getMessage())
            );
        }

        // Attempt to set coupon code
        $quote->setCouponCode($couponCode);

        // Save cart
        $this->cartRepository->save($quote);

        // Check if the coupon was applied
        if ($quote->getCouponCode() != $couponCode) {
            throw new WebapiException(
                new Phrase('Coupon code is not applicable.')
            );
        }

        return $this->cartHelper->createResponse($quote);
    }
}
