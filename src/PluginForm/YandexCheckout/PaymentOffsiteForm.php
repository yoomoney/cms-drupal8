<?php

namespace Drupal\yandex_checkout\PluginForm\YandexCheckout;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Plugin\Field\FieldType\AdjustmentItemList;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\yandex_checkout\Plugin\Commerce\PaymentGateway\YandexCheckout;
use YandexCheckout\Common\Exceptions\ApiException;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\Payment;
use YandexCheckout\Request\Payments\CreatePaymentRequest;

class PaymentOffsiteForm extends BasePaymentOffsiteForm
{
    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return array
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \Drupal\commerce\Response\NeedsRedirectException
     * @throws \Exception
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        try {
            $form = parent::buildConfigurationForm($form, $form_state);

            /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
            $payment = $this->entity;
            /** @var YandexCheckout $paymentGatewayPlugin */
            $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
            $client               = $paymentGatewayPlugin->apiClient;
            $order                = $payment->getOrder();
            $amount               = $order->getTotalPrice();
            $config               = $paymentGatewayPlugin->getConfiguration();

            $builder = CreatePaymentRequest::builder()
                ->setAmount($amount->getNumber())
                ->setCapture(true)
                ->setDescription($this->createDescription($order, $config))
                ->setConfirmation(array(
                    'type'      => ConfirmationType::REDIRECT,
                    'returnUrl' => $form['#return_url'],
                ))
                ->setMetadata(array(
                    'cms_name'       => 'ya_api_drupal8',
                    'module_version' => YandexCheckout::YAMONEY_MODULE_VERSION,
                ));

            if ($config['receipt_enabled'] == 1) {
                /** @var UserInterface $profile */
                $profile = $order->getCustomer();
                $builder->setReceiptEmail($profile->getEmail());
                $items = $order->getItems();
                /** @var OrderItem $item */
                foreach ($items as $item) {
                    /** @var AdjustmentItemList $adjustments */
                    $adjustments = $item->get('adjustments');

                    $taxUuid    = null;
                    $percentage = 0;
                    foreach ($adjustments->getValue() as $adjustmentValue) {
                        /** @var Adjustment $adjustment */
                        $adjustment = $adjustmentValue['value'];
                        if ($adjustment->getType() == 'tax') {
                            $sourceId   = explode('|', $adjustment->getSourceId());
                            $taxUuid    = $sourceId[2];
                            $percentage = $adjustment->getPercentage();
                        }
                    }
                    if (in_array($taxUuid, array_keys($config['yandex_checkout_tax']))) {
                        $vat_code = $config['yandex_checkout_tax'][$taxUuid];
                    } else {
                        $vat_code = $config['default_tax'];
                    }

                    $priceWithTax = $item->getUnitPrice()->getNumber() * (1 + $percentage);
                    $builder->addReceiptItem($item->getTitle(), $priceWithTax, $item->getQuantity(), $vat_code);
                }
            }
            $paymentRequest = $builder->build();
            if (($config['receipt_enabled'] == 1) && $paymentRequest->getReceipt() !== null) {
                $paymentRequest->getReceipt()->normalize($paymentRequest->getAmount());
            }
            $response = $client->createPayment($paymentRequest);

            $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
            $payments        = $payment_storage->loadByProperties(['order_id' => $order->id()]);
            if ($payments) {
                $payment = reset($payments);
                $payment->enforceIsNew(false);
            }
            $payment->setRemoteId($response->getId());
            $payment->setRemoteState($response->getStatus());
            $payment->save();
            $redirect_url = $response->confirmation->confirmationUrl;
            $data         = [
                'return' => $form['#return_url'],
                'cancel' => $form['#cancel_url'],
                'total'  => $payment->getAmount()->getNumber(),
            ];

            return $this->buildRedirectForm($form, $form_state, $redirect_url, $data);
        } catch (ApiException $e) {
            \Drupal::logger('yandex_checkout')->error('Api error: ' . $e->getMessage());
            drupal_set_message(t('Не удалось создать платеж.'), 'error');
            throw new PaymentGatewayException();
        }
    }

    /**
     * @param OrderInterface $order
     * @param array $config
     * @return string
     */
    private function createDescription(OrderInterface $order, $config)
    {
        $descriptionTemplate = !empty($config['description_template'])
            ? $config['description_template']
            : t('Оплата заказа №%order_id%');

        $replace = array();
        foreach ($order as $property => $fieldItems) {
            foreach ($fieldItems as $key => $fieldItem) {
                if (!($fieldItem instanceof FieldItemInterface)) {
                    continue;
                }
                $params = $fieldItem->getEntity()->toArray();
                if (empty($params[$property])) {
                    continue;
                }
                if (!is_array($params[$property])) {
                    continue;
                }
                if (empty($params[$property][0])) {
                    continue;
                }
                $fieldData = $params[$property][0];
                if (!is_array($fieldData)) {
                    continue;
                }
                $value = current($fieldData);
                if (!is_scalar($value)) {
                    continue;
                }
                $replace['%'.$property.'%'] = $value;
            }
        }

        $description = strtr($descriptionTemplate, $replace);

        return (string)mb_substr($description, 0, Payment::MAX_LENGTH_DESCRIPTION);
    }

}
