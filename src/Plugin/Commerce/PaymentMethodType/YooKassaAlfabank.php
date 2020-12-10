<?php

namespace Drupal\yookassa\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce\BundleFieldDefinition;

/**
 *
 * @CommercePaymentMethodType(
 *   id = "yookassa_alfabank",
 *   label = @Translation("YC account"),
 *   create_label = @Translation("YC account"),
 * )
 */
class YooKassaAlfabank extends YooKassaPaymentMethod
{

    /**
     * Gets the payment method type label.
     *
     * @return string
     *   The payment method type label.
     */
    public function getLabel()
    {
        return 'Альфа-Клик';
    }

    /**
     * {@inheritdoc}
     */
    public function buildLabel(PaymentMethodInterface $payment_method) {
        $args = [
            '@paypal_mail' => $payment_method->alfabank_login->value,
        ];
        return $this->t('Alfabank account (@paypal_mail)', $args);
    }

    /**
     * {@inheritdoc}
     */
    public function buildFieldDefinitions() {
        $fields = parent::buildFieldDefinitions();

        $fields['alfabank_login'] = BundleFieldDefinition::create('string')
                                                      ->setLabel(t('Alfabank Login'))
                                                      ->setDescription(t('The email address associated with the PayPal account.'))
                                                      ->setRequired(TRUE);

        return $fields;
    }

}