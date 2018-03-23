<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\ProductQuantityCartConnector\Business\Validator;

use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\CartPreCheckResponseTransfer;
use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\MessageTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Orm\Zed\Product\Persistence\SpyProductQuery;
use Orm\Zed\ProductQuantity\Persistence\SpyProductQuantity;

class ProductQuantityRestrictionValidator implements ProductQuantityRestrictionValidatorInterface
{
    /**
     * @param \Generated\Shared\Transfer\CartChangeTransfer $cartChangeTransfer
     *
     * @return \Generated\Shared\Transfer\CartPreCheckResponseTransfer
     */
    public function validateItems(CartChangeTransfer $cartChangeTransfer)
    {
        $responseTransfer = new CartPreCheckResponseTransfer();

        foreach ($cartChangeTransfer->getItems() as $itemTransfer) {
            if ($itemTransfer->getSku()) {
                $this->validateItem($itemTransfer, $cartChangeTransfer->getQuote(), $responseTransfer);
                continue;
            }
        }

        return $this->setResponseSuccessful($responseTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ItemTransfer $itemTransfer
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     * @param \Generated\Shared\Transfer\CartPreCheckResponseTransfer $responseTransfer
     *
     * @return void
     */
    protected function validateItem(ItemTransfer $itemTransfer, QuoteTransfer $quoteTransfer, CartPreCheckResponseTransfer $responseTransfer)
    {
        $quoteProductQuantity = $this->getQuoteProductQuantity($itemTransfer->getSku(), $quoteTransfer);
        $productQuantity = $itemTransfer->getQuantity() + $quoteProductQuantity;

        $this->validateItemQuantity($itemTransfer, $productQuantity, $responseTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ItemTransfer $itemTransfer
     * @param int $productQuantity
     * @param \Generated\Shared\Transfer\CartPreCheckResponseTransfer $responseTransfer
     *
     * @return void
     */
    protected function validateItemQuantity(ItemTransfer $itemTransfer, $productQuantity, CartPreCheckResponseTransfer $responseTransfer)
    {
        $productQuantityRestriction = $this->getProductQuantityRestrictionBySku($itemTransfer->getSku());

        $min = $productQuantityRestriction['min'];
        $max = $productQuantityRestriction['max'];
        $interval = $productQuantityRestriction['interval'];

        if ($productQuantity < $min) {
            $this->createViolationMessage($responseTransfer);
        }
        if ($max !== null && $productQuantity > $max) {
            $this->createViolationMessage($responseTransfer);
        }
        if (($productQuantity - $min) % $interval !== 0) {
            $this->createViolationMessage($responseTransfer);
        }
    }

    /**
     * @param string $productSku
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     *
     * @return int
     */
    protected function getQuoteProductQuantity($productSku, QuoteTransfer $quoteTransfer)
    {
        foreach ($quoteTransfer->getItems() as $quoteItemTransfer) {
            if ($quoteItemTransfer->getSku() === $productSku) {
                return $quoteItemTransfer->getQuantity();
            }
        }

        return 0;
    }

    /**
     * @param string $productSku
     *
     * @return array
     */
    protected function getProductQuantityRestrictionBySku($productSku)
    {
        $productQuantityRestriction = [
            'min' => 1,
            'max' => null,
            'interval' => 1,
        ];

        /** @var \Orm\Zed\Product\Persistence\SpyProduct $productEntity */
        $productEntity = SpyProductQuery::create()
            ->filterBySku($productSku)
            ->leftJoinWithSpyProductQuantity()
            ->find()
            ->getFirst();

        if ($productEntity === null) {
            return $productQuantityRestriction;
        }

        /** @var \Orm\Zed\ProductQuantity\Persistence\SpyProductQuantity $productQuantityEntity */
        $productQuantityEntity = $productEntity->getSpyProductQuantities()->getFirst();

        if ($productQuantityEntity === null) {
            return $productQuantityRestriction;
        }

        return $this->normalizeProductQuantityRestriction($productQuantityEntity, $productQuantityRestriction);
    }

    /**
     * @param \Orm\Zed\ProductQuantity\Persistence\SpyProductQuantity $productQuantityEntity
     * @param array $productQuantityRestriction
     *
     * @return array
     */
    protected function normalizeProductQuantityRestriction(SpyProductQuantity $productQuantityEntity, array $productQuantityRestriction)
    {
        $productQuantityRestriction['min'] = $productQuantityEntity->getQuantityMin();
        $productQuantityRestriction['max'] = $productQuantityEntity->getQuantityMax();
        if ($productQuantityEntity->getQuantityInterval() !== null) {
            $productQuantityRestriction['interval'] = $productQuantityEntity->getQuantityInterval();
        }
        if ($productQuantityRestriction['min'] === null) {
            $productQuantityRestriction['min'] = $productQuantityRestriction['interval'];
        }

        return $productQuantityRestriction;
    }

    /**
     * @param \Generated\Shared\Transfer\CartPreCheckResponseTransfer $responseTransfer
     * @param array $parameters
     *
     * @return void
     */
    protected function createViolationMessage(CartPreCheckResponseTransfer $responseTransfer, array $parameters = [])
    {
        $message = new MessageTransfer();
        $message->setValue('cart.error.quantity');
        $message->setParameters($parameters);

        $responseTransfer->addMessage($message);
    }

    /**
     * @param \Generated\Shared\Transfer\CartPreCheckResponseTransfer $responseTransfer
     *
     * @return \Generated\Shared\Transfer\CartPreCheckResponseTransfer
     */
    protected function setResponseSuccessful(CartPreCheckResponseTransfer $responseTransfer)
    {
        $isSuccessful = count($responseTransfer->getMessages()) === 0;
        $responseTransfer->setIsSuccess($isSuccessful);

        return $responseTransfer;
    }
}
