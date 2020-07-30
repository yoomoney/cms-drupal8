<?php

namespace Drupal\yandex_checkout\Plugin\Commerce\PaymentGateway;


use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use YandexCheckout\Client;
use YandexCheckout\Model\NotificationEventType;
use YandexCheckout\Model\Notification\NotificationSucceeded;
use YandexCheckout\Model\Notification\NotificationWaitingForCapture;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequest;

/**
 *
 * @CommercePaymentGateway(
 *   id = "yandex_checkout",
 *   label = "Yandex Checkout",
 *   display_label = "Yandex Checkout",
 *   forms = {
 *     "offsite-payment" = "Drupal\yandex_checkout\PluginForm\YandexCheckout\PaymentOffsiteForm",
 *     "test-action" = "Drupal\yandex_checkout\PluginForm\YandexCheckout\PaymentMethodAddForm"
 *   },
 *   payment_method_types = {
 *     "yandex_checkout_epl"
 *   },
 *   modes = {
 *     "n/a" = @Translation("N/A"),
 *   }
 * )
 *
 */
class YandexCheckout extends OffsitePaymentGatewayBase
{
    const YAMONEY_MODULE_VERSION = '1.1.1';

    /**
     * @property Client apiClient
     */
    public $apiClient;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        EntityTypeManagerInterface $entity_type_manager,
        PaymentTypeManager $payment_type_manager,
        PaymentMethodTypeManager $payment_method_type_manager,
        TimeInterface $time
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager,
            $payment_method_type_manager, $time);
        $shopId               = $this->configuration['shop_id'];
        $secretKey            = $this->configuration['secret_key'];
        $yandexCheckoutClient = new Client();
        $yandexCheckoutClient->setAuth($shopId, $secretKey);
        $userAgent = $yandexCheckoutClient->getApiClient()->getUserAgent();
        $userAgent->setCms('Drupal', \Drupal::VERSION);
        $userAgent->setModule('yandex-money-cms-v2', self::YAMONEY_MODULE_VERSION);
        $this->apiClient = $yandexCheckoutClient;
    }

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return [
                   'shop_id'             => '',
                   'secret_key'          => '',
                   'description_template' => '',
                   'receipt_enabled'     => '',
                   'default_tax'         => '',
                   'yandex_checkout_tax' => array(),
                   'notification_url'    => '',
               ] + parent::defaultConfiguration();
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return array
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form['shop_id'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t('shopId'),
            '#default_value' => $this->configuration['shop_id'],
            '#required'      => true,
        );

        $form['secret_key'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t('Secret Key'),
            '#default_value' => $this->configuration['secret_key'],
            '#required'      => true,
        );

        $form = parent::buildConfigurationForm($form, $form_state);

        $form['description_template'] = [
            '#type'          => 'textfield',
            '#title'         => t('Описание платежа'),
            '#description'   => t('Это описание транзакции, которое пользователь увидит при оплате, а вы — в личном кабинете Яндекс.Кассы. Например, «Оплата заказа №72».<br>
Чтобы в описание подставлялся номер заказа (как в примере), поставьте на его месте %order_id% (Оплата заказа №%order_id%).<br>
Ограничение для описания — 128 символов.'),
            '#default_value' => !empty($this->configuration['description_template'])
                ? $this->configuration['description_template']
                : $this->t('Оплата заказа №%order_id%'),
        ];

        $form['receipt_enabled'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t('Отправлять в Яндекс.Кассу данные для чеков (54-ФЗ)'),
            '#default_value' => $this->configuration['receipt_enabled'],
        );
        if ($this->configuration['receipt_enabled']) {


            $form['default_tax'] = array(
                '#type'          => 'select',
                '#title'         => 'Ставка по умолчанию',
                '#options'       => array(
                    1 => t('Без НДС'),
                    2 => t('0%'),
                    3 => t('10%'),
                    4 => t('20%'),
                    5 => t('Расчётная ставка 10/110'),
                    6 => t('Расчётная ставка 20/120'),
                ),
                '#default_value' => $this->configuration['default_tax'],
            );

            $tax_storage = $this->entityTypeManager->getStorage('commerce_tax_type');
            $taxTypes    = $tax_storage->loadMultiple();
            $taxRates    = [];
            foreach ($taxTypes as $taxType) {
                /** @var TaxType $taxType */
                $taxTypeConfiguration = $taxType->getPluginConfiguration();
                $taxRates             += $taxTypeConfiguration['rates'];
            }

            if ($taxRates) {

                $form['yandex_checkout_tax_label'] = [
                    '#type'  => 'html_tag',
                    '#tag'   => 'label',
                    '#value' => $this->t('Сопоставьте ставки'),
                    '#state' => array(
                        'visible' => array(
                            array(
                                array(':input[name="measurementmethod"]' => array('value' => '5')),
                                'xor',
                                array(':input[name="measurementmethod"]' => array('value' => '6')),
                                'xor',
                                array(':input[name="measurementmethod"]' => array('value' => '7')),
                            ),
                        ),
                    ),

                ];

                $form['yandex_checkout_tax_wrapper_begin'] = array(
                    '#markup' => '<div>',
                );

                $form['yandex_checkout_label_shop_tax'] = array(
                    '#markup' => t('<div style="float: left;width: 200px;">Ставка в вашем магазине.</div>'),
                );

                $form['yandex_checkout_label_tax_rate'] = array(
                    '#markup' => t('<div>Ставка для чека в налоговую.</div>'),
                );

                $form['yandex_checkout_tax_wrapper_end'] = array(
                    '#markup' => '</div>',
                );

                foreach ($taxRates as $taxRate) {
                    $form['yandex_checkout_tax']['yandex_checkout_tax_label_'.$taxRate['id'].'_begin'] = array(
                        '#markup' => '<div>',
                    );
                    $form['yandex_checkout_tax']['yandex_checkout_tax_label_'.$taxRate['id'].'_lbl']   = array(
                        '#markup' => t('<div style="width: 200px;float: left;padding-top: 5px;"><label>'.$taxRate['label'].'</label></div>'),
                    );

                    $defaultTaxValue = isset($this->configuration['yandex_checkout_tax'][$taxRate['id']])
                        ? $this->configuration['yandex_checkout_tax'][$taxRate['id']]
                        : 1;
                    $form['yandex_checkout_tax'][$taxRate['id']] = array(
                        '#type'          => 'select',
                        '#title'         => false,
                        '#label'         => false,
                        '#options'       => array(
                            1 => t('Без НДС'),
                            2 => t('0%'),
                            3 => t('10%'),
                            4 => t('20%'),
                            5 => t('Расчётная ставка 10/110'),
                            6 => t('Расчётная ставка 20/120'),
                        ),
                        '#default_value' => $defaultTaxValue,
                    );

                    $form['yandex_checkout_tax']['yandex_checkout_tax_label_'.$taxRate['id'].'_end'] = array(
                        '#markup' => '</div><br style="clear: both;">',
                    );
                }
            }
        }
        $this->entityId = $form_state->getValue('id');
        if ($this->entityId) {
            $form['notification_url'] = [
                '#type'          => 'textfield',
                '#title'         => t('Url для нотификаций'),
                '#default_value' => $this->getNotifyUrl()->toString(),
                '#attributes'    => ['readonly' => 'readonly'],
            ];
        }
        $form['log_file'] = [
            '#type' => 'item',
            '#title' => t('Логирование'),
            '#markup' => t('Посмотреть <a href="' . $GLOBALS['base_url'] . '/admin/reports/dblog?type[]=yandex_checkout"
             target="_blank">записи журнала</a>.')
        ];


        return $form;
    }

    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        $values = $form_state->getValue($form['#parents']);
        if (!preg_match('/^test_.*|live_.*$/i', $values['secret_key'])) {
            $markup = new TranslatableMarkup('Такого секретного ключа нет. Если вы уверены, что скопировали ключ правильно, значит, он по какой-то причине не работает.
                  Выпустите и активируйте ключ заново — 
                  <a href="https://money.yandex.ru/joinups">в личном кабинете Яндекс.Кассы</a>');
            $form_state->setError($form['secret_key'], $markup);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values                                     = $form_state->getValue($form['#parents']);
            $this->configuration['shop_id']             = $values['shop_id'];
            $this->configuration['secret_key']          = $values['secret_key'];
            $this->configuration['description_template'] = $values['description_template'];
            $this->configuration['receipt_enabled']     = $values['receipt_enabled'];
            $this->configuration['default_tax']         = isset($values['default_tax']) ? $values['default_tax'] : '';
            $this->configuration['yandex_checkout_tax'] = isset($values['yandex_checkout_tax']) ? $values['yandex_checkout_tax'] : '';
        }
    }

    /**
     * Processes the "return" request.
     *
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *   The order.
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request.
     *
     * @throws NeedsRedirectException
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \YandexCheckout\Common\Exceptions\ApiException
     * @throws \YandexCheckout\Common\Exceptions\BadApiRequestException
     * @throws \YandexCheckout\Common\Exceptions\ForbiddenException
     * @throws \YandexCheckout\Common\Exceptions\InternalServerError
     * @throws \YandexCheckout\Common\Exceptions\NotFoundException
     * @throws \YandexCheckout\Common\Exceptions\ResponseProcessingException
     * @throws \YandexCheckout\Common\Exceptions\TooManyRequestsException
     * @throws \YandexCheckout\Common\Exceptions\UnauthorizedException
     */
    public function onReturn(OrderInterface $order, Request $request)
    {
        $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
        $payments        = $payment_storage->loadByProperties(['order_id' => $order->id()]);
        if ($payments) {
            $payment = reset($payments);
        }
        /** @var Payment $payment */
        $paymentId           = $payment->getRemoteId();
        $apiClient           = $this->apiClient;
        $cancelUrl           = $this->buildCancelUrl($order);
        $paymentInfoResponse = $apiClient->getPaymentInfo($paymentId);
        $this->log('Payment info: ' . json_encode($paymentInfoResponse));
        if ($paymentInfoResponse->status == PaymentStatus::WAITING_FOR_CAPTURE) {
            $captureRequest = CreateCaptureRequest::builder()->setAmount($paymentInfoResponse->getAmount())->build();
            $paymentInfoResponse  = $apiClient->capturePayment($captureRequest, $paymentId);
            $this->log('Payment info after capture: ' . json_encode($paymentInfoResponse));
        }
        if ($paymentInfoResponse->status == PaymentStatus::SUCCEEDED) {
            $payment->setRemoteState($paymentInfoResponse->status);
            $payment->setState('completed');
            $payment->save();
            $this->log('Payment completed');
        } elseif ($paymentInfoResponse->status == PaymentStatus::PENDING && $paymentInfoResponse->getPaid()) {
            $payment->setRemoteState($paymentInfoResponse->status);
            $payment->setState('pending');
            $payment->save();
            $this->log('Payment pending');
        } elseif ($paymentInfoResponse->status == PaymentStatus::CANCELED) {
            $payment->setRemoteState($paymentInfoResponse->status);
            $payment->setState('canceled');
            $payment->save();
            $this->log('Payment canceled');
            throw new NeedsRedirectException($cancelUrl->toString());
        } else {
            $this->log('Wrong payment status: ' . $paymentInfoResponse->status);
            throw new NeedsRedirectException($cancelUrl->toString());
        }
    }

    /**
     * Processes the notification request.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null
     *   The response, or NULL to return an empty HTTP 200 response.
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Core\Entity\EntityStorageException
     * @throws \YandexCheckout\Common\Exceptions\ApiException
     * @throws \YandexCheckout\Common\Exceptions\BadApiRequestException
     * @throws \YandexCheckout\Common\Exceptions\ForbiddenException
     * @throws \YandexCheckout\Common\Exceptions\InternalServerError
     * @throws \YandexCheckout\Common\Exceptions\NotFoundException
     * @throws \YandexCheckout\Common\Exceptions\ResponseProcessingException
     * @throws \YandexCheckout\Common\Exceptions\TooManyRequestsException
     * @throws \YandexCheckout\Common\Exceptions\UnauthorizedException
     */
    public function onNotify(Request $request)
    {
        $rawBody           = $request->getContent();
        $this->log('Notification: ' . $rawBody);
        $notificationData  = json_decode($rawBody, true);
        $notificationModel = ($notificationData['event'] === NotificationEventType::PAYMENT_SUCCEEDED)
            ? new NotificationSucceeded($notificationData)
            : new NotificationWaitingForCapture($notificationData);
        $apiClient         = $this->apiClient;
        $paymentResponse   = $notificationModel->getObject();
        $paymentId         = $paymentResponse->id;
        $payment_storage   = \Drupal::entityTypeManager()->getStorage('commerce_payment');
        $payments          = $payment_storage->loadByProperties(['remote_id' => $paymentId]);
        if (!$payments) {
            return new Response('Bad request', 400);
        }
        /** @var Payment $payment */
        $payment = reset($payments);
        /** @var Order $order */
        $order = $payment->getOrder();
        if (!$order) {
            return new Response('Order not found', 404);
        }

        $paymentInfo = $apiClient->getPaymentInfo($paymentId);
        $this->log('Payment info: ' . json_encode($paymentInfo));

        $state = $order->getState()->value;
        if ($state !== 'completed') {
            switch ($paymentInfo->status) {
                case PaymentStatus::WAITING_FOR_CAPTURE:
                    $captureRequest  = CreateCaptureRequest::builder()->setAmount($paymentInfo->getAmount())->build();
                    $captureResponse = $apiClient->capturePayment($captureRequest, $paymentId);
                    $this->log('Payment info after capture: ' . json_encode($captureResponse));
                    if ($captureResponse->status == PaymentStatus::SUCCEEDED) {
                        $payment->setRemoteState($paymentInfo->status);
                        $order->state = 'completed';
                        $order->setCompletedTime(\Drupal::time()->getRequestTime());
                        $order->save();
                        $payment->save();
                        $this->log('Payment completed');

                        return new Response('Payment completed', 200);
                    } elseif ($captureResponse->status == PaymentStatus::CANCELED) {
                        $payment->setRemoteState($paymentInfo->status);
                        $payment->save();
                        $this->log('Payment canceled');

                        return new Response('Payment canceled', 200);
                    }
                    break;
                case PaymentStatus::PENDING:
                    $payment->setRemoteState($paymentInfo->status);
                    $payment->save();
                    $this->log('Payment pending');

                    return new Response(' Payment Required', 402);
                case PaymentStatus::SUCCEEDED:
                    $payment->setRemoteState($paymentInfo->status);
                    $order->state = 'completed';
                    $order->setCompletedTime(\Drupal::time()->getRequestTime());
                    $order->save();
                    $payment->save();
                    $this->log('Payment complete');

                    return new Response('Payment complete', 200);
                case PaymentStatus::CANCELED:
                    $payment->setRemoteState($paymentInfo->status);
                    $payment->save();
                    $this->log('Payment canceled');

                    return new Response('Payment canceled', 200);
            }
        }

        return new Response('OK', 200);
    }

    /**
     * Builds the URL to the "cancel" page.
     *
     * @param OrderInterface $order
     *
     * @return Url The "cancel" page URL.
     * The "cancel" page URL.
     */
    protected function buildCancelUrl($order)
    {
        return Url::fromRoute('commerce_payment.checkout.cancel', [
            'commerce_order' => $order->id(),
            'step'           => 'payment',
        ], ['absolute' => true]);
    }

    /**
     * @param $message
     */
    private function log($message) {
        \Drupal::logger('yandex_checkout')->info($message);
    }
}