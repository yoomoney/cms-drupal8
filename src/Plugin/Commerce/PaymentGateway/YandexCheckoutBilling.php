<?php


namespace Drupal\yandex_checkout\Plugin\Commerce\PaymentGateway;


use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;

/**
 *
 * @CommercePaymentGateway(
 *   id = "yandex_checkout_billing",
 *   label = "Billing",
 *   display_label = "Billing",
 *   forms = {
 *     "offsite-payment" = "Drupal\yandex_checkout\PluginForm\YandexCheckout\PaymentBillingForm",
 *   },
 *   payment_method_types = {
 *     "yandex_checkout_billing"
 *   },
 *   modes = {
 *     "n/a" = @Translation("N/A"),
 *   }
 * )
 *
 */
class YandexCheckoutBilling extends OffsitePaymentGatewayBase
{
    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return [
                   'billing_id' => '',
                   'narrative'  => $this->t('Order No. %order_id% Payment via Billing'),
               ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['billing_id'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t('Billing\'s identifier'),
            '#default_value' => $this->configuration['billing_id'],
        );

        $form['narrative'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t('Payment purpose'),
            '#description'   => $this->t(
                'Payment purpose is added to the payment order: specify whatever will help identify the order paid via Billing'
            ),
            '#default_value' => $this->configuration['narrative'],
        );

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values                            = $form_state->getValue($form['#parents']);
            $this->configuration['billing_id'] = $values['billing_id'];
            $this->configuration['narrative']  = $values['narrative'];
        }
    }
}