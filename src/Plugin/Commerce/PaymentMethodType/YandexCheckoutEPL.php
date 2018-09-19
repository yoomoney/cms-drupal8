<?php

namespace Drupal\yandex_checkout\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;

/**
 * Provides the PayPal payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "yandex_checkout_epl",
 *   label = @Translation("YC account"),
 *   create_label = @Translation("YC account"),
 * )
 */
class YandexCheckoutEPL extends YandexCheckoutPaymentMethod
{

    /**
     * Gets the payment method type label.
     *
     * @return string
     *   The payment method type label.
     */
    public function getLabel()
    {
        return 'Яндекс.Касса (банковские карты, электронные деньги и другое)';
    }

    /**
     * Builds a label for the given payment method.
     *
     * @param PaymentMethodInterface $payment_method
     *   The payment method.
     *
     * @return string
     *   The label.
     */
    public function buildLabel(PaymentMethodInterface $payment_method)
    {
        // TODO: Implement buildLabel() method.
    }
}