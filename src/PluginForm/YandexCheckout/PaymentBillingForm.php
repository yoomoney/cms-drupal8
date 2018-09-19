<?php


namespace Drupal\yandex_checkout\PluginForm\YandexCheckout;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class PaymentBillingForm extends BasePaymentOffsiteForm
{
    const QUICK_API_PAY_VERSION = 2;
    const RETURN_URL = 'https://money.yandex.ru/fastpay/confirm';

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment              = $this->entity;
        $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
        $configuration        = $paymentGatewayPlugin->getConfiguration();

        $form['#attached']['library'][] = 'yandex_checkout/billing_validation';

        $form['formId'] = [
            '#type'    => 'hidden',
            '#value'   => $configuration['billing_id'],
            '#parents' => ['formId'],
        ];

        $form['sum'] = [
            '#type'    => 'hidden',
            '#value'   => $payment->getAmount()->getNumber(),
            '#parents' => ['sum'],
        ];

        $narrative = str_replace(
            '%order_id%',
            $payment->getOrderId(),
            $configuration['narrative']
        );

        $form['narrative'] = [
            '#type'    => 'hidden',
            '#value'   => $narrative,
            '#parents' => ['narrative'],
        ];

        $form['quickPayVersion'] = [
            '#type'    => 'hidden',
            '#value'   => self::QUICK_API_PAY_VERSION,
            '#parents' => ['quickPayVersion'],
        ];

        $form['fio'] = [
            '#type'     => 'textfield',
            '#title'    => t('Payer\'s full name'),
            '#required' => true,
            '#parents'  => ['fio'],
            '#prefix'   => '<div class="form-group">',
            '#suffix'   => '<div class="fio-error"></div></div>',
        ];

        $form['commerce_message'] = [
            '#weight'  => -10,
            '#process' => [
                [get_class($this), 'processRedirectForm'],
            ],
        ];

        return $form;
    }

    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        $customerName = trim($form_state->getValue('fio'));
        $parts        = preg_split('/\s+/', $customerName);
        if ($customerName
            && count($parts) != 3
        ) {
            $form_state->setErrorByName('plugin', t('Ф.И.О. плательщика введено не верно.'));
        }
    }

    /**
     * Prepares the complete form for a POST redirect.
     *
     * Sets the form #action, adds a class for the JS to target.
     * Workaround for buildConfigurationForm() not receiving $complete_form.
     *
     * @param array $element
     *   The form element whose value is being processed.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     * @param array $complete_form
     *   The complete form structure.
     *
     * @return array The processed form element.
     * The processed form element.
     */
    public static function processRedirectForm(array $element, FormStateInterface $form_state, array &$complete_form)
    {
        $complete_form['#attributes']['class'][] = 'payment-redirect-form';
        $complete_form['#action']                = self::RETURN_URL;
        $complete_form['actions']['#access']     = true;
        foreach (Element::children($complete_form['actions']) as $element_name) {
            $complete_form['actions'][$element_name]['#access'] = true;
        }

        return $element;
    }
}