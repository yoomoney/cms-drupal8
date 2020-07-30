<?php


namespace Drupal\yandex_checkout\Plugin\Commerce\PaymentMethodType;


use Drupal\commerce_payment\Entity\PaymentMethodInterface;

/**
 *
 * @CommercePaymentMethodType(
 *   id = "yandex_checkout_billing",
 *   label = @Translation("YC account"),
 *   create_label = @Translation("YC account"),
 * )
 */
class YandexCheckoutBilling extends YandexCheckoutPaymentMethod
{

    /**
     * Gets the payment method type label.
     *
     * @return string
     *   The payment method type label.
     */
    public function getLabel()
    {
        return t('Billing (bank card, e-wallets)');
    }

    public function buildLabel(PaymentMethodInterface $payment_method)
    {
        // TODO: Implement buildLabel() method.
    }
}