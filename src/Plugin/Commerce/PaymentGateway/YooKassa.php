<?php

namespace Drupal\yookassa\Plugin\Commerce\PaymentGateway;


use Drupal;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use YooKassa\Client;
use YooKassa\Common\Exceptions\ApiException;
use YooKassa\Common\Exceptions\BadApiRequestException;
use YooKassa\Common\Exceptions\ExtensionNotFoundException;
use YooKassa\Common\Exceptions\ForbiddenException;
use YooKassa\Common\Exceptions\InternalServerError;
use YooKassa\Common\Exceptions\NotFoundException;
use YooKassa\Common\Exceptions\ResponseProcessingException;
use YooKassa\Common\Exceptions\TooManyRequestsException;
use YooKassa\Common\Exceptions\UnauthorizedException;
use YooKassa\Model\NotificationEventType;
use YooKassa\Model\Notification\NotificationSucceeded;
use YooKassa\Model\Notification\NotificationWaitingForCapture;
use YooKassa\Model\PaymentStatus;
use YooKassa\Request\Payments\Payment\CreateCaptureRequest;

/**
 *
 * @CommercePaymentGateway(
 *   id = "yookassa",
 *   label = "YooKassa",
 *   display_label = "YooKassa",
 *   forms = {
 *     "offsite-payment" = "Drupal\yookassa\PluginForm\YooKassa\PaymentOffsiteForm",
 *     "test-action" = "Drupal\yookassa\PluginForm\YooKassa\PaymentMethodAddForm"
 *   },
 *   payment_method_types = {
 *     "yookassa_epl"
 *   },
 *   modes = {
 *     "n/a" = @Translation("N/A"),
 *   }
 * )
 *
 */
class YooKassa extends OffsitePaymentGatewayBase
{
    const YOOMONEY_MODULE_VERSION = '2.2.6';

    /**
     * @var Client apiClient
     */
    public $apiClient;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        array  $configuration,
        string $plugin_id,
        array  $plugin_definition,
        EntityTypeManagerInterface $entity_type_manager,
        PaymentTypeManager $payment_type_manager,
        PaymentMethodTypeManager $payment_method_type_manager,
        TimeInterface $time
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager,
            $payment_method_type_manager, $time);
        $shopId               = $this->configuration['shop_id'];
        $secretKey            = $this->configuration['secret_key'];
        $yookassaClient = new Client();
        $yookassaClient->setAuth($shopId, $secretKey);
        $userAgent = $yookassaClient->getApiClient()->getUserAgent();
        $userAgent->setCms('Drupal', Drupal::VERSION);
        $userAgent->setModule('yoomoney-cms', self::YOOMONEY_MODULE_VERSION);
        $this->apiClient = $yookassaClient;
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
                'yookassa_tax' => [],
                'notification_url'    => '',
                'second_receipt_enabled' => '',
                'order_type' => [],
                'second_receipt_status' => [],
                'default_tax_rate' => [],
                'default_payment_subject' => [],
                'default_payment_mode' => []
            ] + parent::defaultConfiguration();
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return array
     * @throws InvalidPluginDefinitionException|PluginNotFoundException
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $values = $form_state->getValue($form['#parents']);
        $form['column'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'columns-wrapper'],
        ];

        $form['column']['shop_id'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('shopId'),
            '#default_value' => $this->configuration['shop_id'],
            '#required'      => true,
        ];

        $form['column']['secret_key'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Secret Key'),
            '#default_value' => $this->configuration['secret_key'],
            '#required'      => true,
        ];

        $form = parent::buildConfigurationForm($form, $form_state);

        $form['column']['description_template'] = [
            '#type'          => 'textfield',
            '#title'         => t('Описание платежа'),
            '#description'   => t('Это описание транзакции, которое пользователь увидит при оплате, а вы — в личном кабинете ЮKassa. Например, «Оплата заказа №72».<br>
Чтобы в описание подставлялся номер заказа (как в примере), поставьте на его месте %order_id% (Оплата заказа №%order_id%).<br>
Ограничение для описания — 128 символов.'),
            '#default_value' => !empty($this->configuration['description_template'])
                ? $this->configuration['description_template']
                : $this->t('Оплата заказа №%order_id%'),
        ];

        $form['column']['receipt_enabled'] = [
            '#type'          => 'checkbox',
            '#title'         => $this->t('Отправлять в ЮKassa данные для чеков (54-ФЗ)'),
            '#default_value' => $this->configuration['receipt_enabled'],
            '#ajax' => [
                'callback' => [$this, 'verifyingReceipt'],
                'event' => 'change',
                'progress' => [
                    'type' => 'throbber',
                    'message' => t('Verifying second receipt settings..'),
                ],
                'wrapper'  => 'columns-wrapper',
            ],
        ];

        $receiptEnabled = $this->checkValuesField($values,'receipt_enabled');
        $form['column']['check_receipt_enabled_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div id="check_receipt_enabled" style="display: @display">', ['@display' => $receiptEnabled ? "block" : "none"]),
        ];

        $form['column']['default_tax'] = [
            '#type'          => 'select',
            '#title'         => 'Ставка по умолчанию',
            '#options'       => [
                1 => t('Без НДС'),
                2 => t('0%'),
                3 => t('10%'),
                4 => t('20%'),
                5 => t('Расчётная ставка 10/110'),
                6 => t('Расчётная ставка 20/120'),
            ],
            '#default_value' => $this->configuration['default_tax'],
        ];

        $tax_storage = $this->entityTypeManager->getStorage('commerce_tax_type');
        $taxTypes    = $tax_storage->loadMultiple();
        $taxRates    = [];
        foreach ($taxTypes as $taxType) {
            /** @var TaxType $taxType */
            $taxTypeConfiguration = $taxType->getPluginConfiguration();
            $taxRates             += $taxTypeConfiguration['rates'];
        }

        if ($taxRates) {

            $form['column']['yookassa_tax_label'] = [
                '#type'  => 'html_tag',
                '#tag'   => 'label',
                '#value' => $this->t('Сопоставьте ставки'),
                '#state' => [
                    'visible' => [
                        [
                            [':input[name="measurementmethod"]' => ['value' => '5']],
                            'xor',
                            [':input[name="measurementmethod"]' => ['value' => '6']],
                            'xor',
                            [':input[name="measurementmethod"]' => ['value' => '7']],
                        ],
                    ],
                ],
            ];

            $form['column']['yookassa_tax_wrapper_begin'] = [
                '#markup' => '<div>',
            ];

            $form['column']['yookassa_label_shop_tax'] = [
                '#markup' => t('<div style="float: left;width: 200px;">Ставка в вашем магазине.</div>'),
            ];

            $form['column']['yookassa_label_tax_rate'] = [
                '#markup' => t('<div>Ставка для чека в налоговую.</div>'),
            ];

            $form['column']['yookassa_tax_wrapper_end'] = [
                '#markup' => '</div>',
            ];

            foreach ($taxRates as $taxRate) {
                $form['column']['yookassa_tax']['yookassa_tax_label_'.$taxRate['id'].'_begin'] = [
                    '#markup' => '<div>',
                ];
                $form['column']['yookassa_tax']['yookassa_tax_label_'.$taxRate['id'].'_lbl']   = [
                    '#markup' => t('<div style="width: 200px;float: left;padding-top: 5px;"><label>'.$taxRate['label'].'</label></div>'),
                ];

                $defaultTaxValue = isset($this->configuration['yookassa_tax'][$taxRate['id']])
                    ? $this->configuration['yookassa_tax'][$taxRate['id']]
                    : 1;
                $form['column']['yookassa_tax'][$taxRate['id']] = [
                    '#type'          => 'select',
                    '#title'         => false,
                    '#label'         => false,
                    '#options'       => [
                        1 => t('Без НДС'),
                        2 => t('0%'),
                        3 => t('10%'),
                        4 => t('20%'),
                        5 => t('Расчётная ставка 10/110'),
                        6 => t('Расчётная ставка 20/120'),
                    ],
                    '#default_value' => $defaultTaxValue,
                ];

                $form['column']['yookassa_tax']['yookassa_tax_label_'.$taxRate['id'].'_end'] = [
                    '#markup' => '</div><br style="clear: both;">',
                ];
            }
        }

        $form['column']['default_tax_rate'] = [
            '#type'          => 'select',
            '#title'         => 'Система налогообложения по умолчанию',
            '#options'       => [
                1 => t('Общая система налогообложения'),
                2 => t('Упрощенная (УСН, доходы)'),
                3 => t('Упрощенная (УСН, доходы минус расходы)'),
                4 => t('Единый налог на вмененный доход (ЕНВД)'),
                5 => t('Единый сельскохозяйственный налог (ЕСН)'),
                6 => t('Патентная система налогообложения'),
            ],
            '#default_value' => $this->configuration['default_tax_rate'],
        ];

        $form['column']['default_tax_rate_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div>Выберите систему налогообложения по умолчанию. Параметр необходим, только если у вас несколько систем налогообложения, в остальных случаях не передается', []),
        ];

        $form['column']['default_tax_rate_wrapper_end'] = [
            '#markup' => new FormattableMarkup('</div>', []),
        ];

        $form['column']['taxes_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div style="width: 50%">', []),
        ];

        $form['column']['taxes_block_left_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div style="width: 50%; float: left">', []),
        ];

        $form['column']['default_payment_subject'] = [
            '#type'          => 'select',
            '#title'         => 'Предмет расчета',
            '#options'       => [
                'commodity' => t('Товар (commodity)'),
                'excise' => t('Подакцизный товар (excise)'),
                'job' => t('Работа (job)'),
                'service' => t('Услуга (service)'),
                'gambling_bet' => t('Ставка в азартной игре (gambling_bet)'),
                'gambling_prize' => t('Выигрыш в азартной игре (gambling_prize)'),
                'lottery' => t('Лотерейный билет (lottery)'),
                'lottery_prize' => t('Выигрыш в лотерею (lottery_prize)'),
                'intellectual_activity' => t('Результаты интеллектуальной деятельности (intellectual_activity)'),
                'payment' => t('Платеж (payment)'),
                'agent_commission' => t('Агентское вознаграждение (agent_commission)'),
                'composite' => t('Несколько вариантов (composite)'),
                'another' => t('Другое (another)'),
            ],
            '#default_value' => $this->configuration['default_payment_subject'],
        ];

        $form['column']['taxes_block_left_wrapper_end'] = [
            '#markup' => new FormattableMarkup('</div>', []),
        ];

        $form['column']['taxes_block_right_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div style="width: 50%; float: right">', []),
        ];

        $form['column']['default_payment_mode'] = [
            '#type'          => 'select',
            '#title'         => 'Способ расчета',
            '#options'       => [
                'full_prepayment' => t('Полная предоплата (full_prepayment)'),
                'partial_prepayment' => t('Частичная предоплата (partial_prepayment)'),
                'advance' => t('Аванс (advance)'),
                'full_payment' => t('Полный расчет (full_payment)'),
                'partial_payment' => t('Частичный расчет и кредит (partial_payment)'),
                'credit' => t('Кредит (credit)'),
                'credit_payment' => t('Выплата по кредиту (credit_payment)'),
            ],
            '#default_value' => $this->configuration['default_payment_mode'],
        ];

        $form['column']['taxes_block_right_wrapper_end'] = [
            '#markup' => new FormattableMarkup('</div>', []),
        ];


        $form['column']['taxes_wrapper_end'] = [
            '#markup' => new FormattableMarkup('</div>', []),
        ];


        $form['column']['second_receipt_enabled'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Отправлять второй чек'),
            '#default_value' => $this->configuration['second_receipt_enabled'] ?? 0,
            '#ajax' => [
                'callback' => [$this, 'verifyingReceipt'],
                'event' => 'change',
                'progress' => [
                    'type' => 'throbber',
                    'message' => t('Verifying second receipt settings..'),
                ],
                'wrapper' => 'columns-wrapper',
            ],
        ];

        $secondReceiptEnabled = $this->checkValuesField($values, 'second_receipt_enabled', 'receipt_enabled');

        $form['column']['check_second_receipt_enabled_wrapper_begin'] = [
            '#markup' => new FormattableMarkup('<div id="check_second_receipt_enabled" style="display: @display">', ['@display' => $secondReceiptEnabled ? "block" : "none"]),
        ];

        $form['column']['order_type'] = [
            '#type' => 'select',
            '#title' => 'Типа заказа, используемый на сайте',
            '#label' => false,
            '#options' => $this->getOrderTypes(),
            '#default_value' => $this->configuration['order_type'] ?? [],
            '#empty_option' => '- Выбрать -',
            '#ajax' => [
                'callback' => [$this, 'verifyingOrderStatuses'],
                'event' => 'change',
                'progress' => [
                    'type' => 'throbber',
                    'message' => t('Verifying order statuses..'),
                ],
                'wrapper' => 'columns-wrapper'
            ],
            '#required' => $secondReceiptEnabled ?? false,
        ];


        $form['column']['second_receipt_status'] = [
            '#type' => 'select',
            '#title' => 'Отправлять второй чек при переходе заказа в статус',
            '#label' => false,
            '#options' => $this->getStates(!empty($values['column']['order_type']) ? $values['column']['order_type'] : $this->configuration['order_type'] ?? ''),
            '#default_value' => $this->configuration['second_receipt_status'] ?? [],
            '#empty_option' => '- Выбрать -',
            '#required' => $secondReceiptEnabled ?? false,
        ];

        $form['column']['check_second_receipt_enabled_wrapper_end'] = [
            '#markup' => '</div>',
        ];

        $form['column']['check_receipt_enabled_wrapper_end'] = [
            '#markup' => '</div>',
        ];

        if ($form_state->getValue('id') || !empty($this->configuration['notification_url'])) {
            $form['column']['notification_url'] = [
                '#type' => 'textfield',
                '#title' => t('Url для нотификаций'),
                '#default_value' => $this->getPaymentName($form_state),
                '#attributes' => ['readonly' => 'readonly'],
            ];
        }

        $form['column']['log_file'] = [
            '#type' => 'item',
            '#title' => t('Логирование'),
            '#markup' => t('Посмотреть <a href="' . $GLOBALS['base_url'] . '/admin/reports/dblog?type[]=yookassa"
             target="_blank">записи журнала</a>.')
        ];


        return $form;
    }

    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        $values = $form_state->getValue($form['#parents']);
        if (!preg_match('/^test_.*|live_.*$/i', $values['column']['secret_key'])) {
            $markup = new TranslatableMarkup('Такого секретного ключа нет. Если вы уверены, что скопировали ключ правильно, значит, он по какой-то причине не работает.
                  Выпустите и активируйте ключ заново —
                  <a href="https://yookassa.ru/joinups">в личном кабинете ЮKassa</a>');
            $form_state->setError($form['column']['secret_key'], $markup);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values                                                   = $form_state->getValue($form['#parents']);
            $this->configuration['shop_id']                           = $values['column']['shop_id'];
            $this->configuration['secret_key']                        = $values['column']['secret_key'];
            $this->configuration['description_template']              = $values['column']['description_template'];
            $this->configuration['receipt_enabled']                   = $values['column']['receipt_enabled'];
            $this->configuration['second_receipt_enabled']            = $values['column']['receipt_enabled'] ? $values['column']['second_receipt_enabled'] : 0;
            $this->configuration['default_tax']                       = $values['column']['default_tax'] ?? [];
            $this->configuration['yookassa_tax']                      = $values['column']['yookassa_tax'] ?? [];
            $this->configuration['second_receipt_status']             = $values['column']['second_receipt_status'] ?? [];
            $this->configuration['order_type']                        = $values['column']['order_type'] ?? 'default';
            $this->configuration['default_tax_rate']                  = $values['column']['default_tax_rate'] ?? [];
            $this->configuration['default_payment_subject']           = $values['column']['default_payment_subject'] ?? [];
            $this->configuration['default_payment_mode']              = $values['column']['default_payment_mode'] ?? [];
            $this->configuration['notification_url']                  = $values['column']['notification_url'] ?? '';
        }
    }

    /**
     * Processes the "return" request.
     *
     * @param OrderInterface $order
     *   The order.
     * @param Request $request
     *   The request.
     *
     * @throws NeedsRedirectException
     * @throws InvalidPluginDefinitionException
     * @throws EntityStorageException
     * @throws ApiException
     * @throws BadApiRequestException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws NotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     * @throws PluginNotFoundException|ExtensionNotFoundException
     */
    public function onReturn(OrderInterface $order, Request $request)
    {
        $payment_storage = Drupal::entityTypeManager()->getStorage('commerce_payment');
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
     * @param Request $request
     *   The request.
     *
     * @return Response|null
     *   The response, or NULL to return an empty HTTP 200 response.
     * @throws InvalidPluginDefinitionException
     * @throws EntityStorageException
     * @throws ApiException
     * @throws BadApiRequestException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws NotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     * @throws ExtensionNotFoundException|PluginNotFoundException
     */
    public function onNotify(Request $request)
    {
        $rawBody           = $request->getContent();
        $notificationData  = json_decode($rawBody, true);
        if (!$notificationData) {
            return new Response('Bad request', 400);
        }
        $this->log('Notification: ' . $rawBody);
        $notificationModel = ($notificationData['event'] === NotificationEventType::PAYMENT_SUCCEEDED)
            ? new NotificationSucceeded($notificationData)
            : new NotificationWaitingForCapture($notificationData);
        $apiClient         = $this->apiClient;
        $paymentResponse   = $notificationModel->getObject();
        $paymentId         = $paymentResponse->id;
        $payment_storage   = Drupal::entityTypeManager()->getStorage('commerce_payment');
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
                        $payment->setState('completed');
                        $payment->save();
                        $this->log('Payment completed');

                        return new Response('Payment completed', 200);
                    } elseif ($captureResponse->status == PaymentStatus::CANCELED) {
                        $payment->setRemoteState($paymentInfo->status);
                        $payment->setState('canceled');
                        $payment->save();
                        $this->log('Payment canceled');

                        return new Response('Payment canceled', 200);
                    }
                    break;
                case PaymentStatus::PENDING:
                    $payment->setRemoteState($paymentInfo->status);
                    $payment->setState('pending');
                    $payment->save();
                    $this->log('Payment pending');

                    return new Response(' Payment Required', 402);
                case PaymentStatus::SUCCEEDED:
                    $payment->setRemoteState($paymentInfo->status);
                    $payment->setState('completed');
                    $payment->save();
                    $this->log('Payment complete');

                    return new Response('Payment complete', 200);
                case PaymentStatus::CANCELED:
                    $payment->setRemoteState($paymentInfo->status);
                    $payment->setState('canceled');
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
        Drupal::logger('yookassa')->info($message);
    }

    /**
     * @return array
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    private function getOrderTypes(): array
    {
        $entity_type_manager = \Drupal::entityTypeManager();
        $order_type_storage = $entity_type_manager->getStorage('commerce_order_type');
        $order_types = $order_type_storage->loadMultiple();

        $result = [];
        foreach ($order_types as $type) {
            $result[$type->id()] = $type->label();
        }

        return $result;
    }

    /**
     * Получение доступных статусов в выбранном типе заказов
     *
     * @param $orderType
     * @return array
     */
    private function getStates($orderType): array
    {
        $result = [];
        $config = !is_array($orderType) ? \Drupal::config('commerce_order.commerce_order_type.'.$orderType)->getRawData() : null;

        if (!empty($config)) {
            $workflow_manager = \Drupal::service('plugin.manager.workflow');
            $workflow = $workflow_manager->createInstance($config['workflow']);

            foreach ($workflow->getStates() as $state) {
                $result[$state->getId()] = $state->getLabel();
            }
        }

        return $result;
    }

    /**
     * Ajax перестройка формы после внесения изменения в нее пользователем (включение\отключение чекбоксов)
     *
     * @param array $form
     * @return mixed
     */
    public function verifyingReceipt(array $form)
    {
        return $form['configuration']['form']['column'];
    }

    /**
     * Ajax изменение статусов заказов select поле second_receipt_status (после изменения выбора в поле order_type)
     *
     * @param array $form
     * @param FormStateInterface $form_state
     * @return mixed
     */
    public function verifyingOrderStatuses(array &$form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();

        $states = $this->getStates($trigger['#value']);
        $form['configuration']['form']['column']['second_receipt_status']['#options'] = $states ?? [];
        return $form['configuration']['form']['column'];
    }

    /**
     * Проверка значения полей для отображения зависимых полей после переформирования формы
     *
     * @param $values
     * @param string $field
     * @param string|null $fieldDepended
     * @return bool
     */
    private function checkValuesField($values, string $field, string $fieldDepended = null): bool
    {
        if (!empty($fieldDepended) && empty($values['column'][$fieldDepended]) && empty($this->configuration[$fieldDepended])) {
            return false;
        }

        if (!empty($values['column'][$field]) || !empty($this->configuration[$field])) {
            return true;
        }

        return false;
    }

    /**
     * Формирование url для уведомлений
     * @param string $paymentName
     * @return Url
     */
    private function getNotificationUrl(string $paymentName): Url
    {
        return Url::fromRoute(
            'commerce_payment.notify',
            ['commerce_payment_gateway' => $paymentName],
            ['absolute' => TRUE]
        );
    }

    /**
     * Получение сформированного url для уведомлений
     * @param FormStateInterface $form_state
     * @return Drupal\Core\GeneratedUrl|string
     */
    private function getPaymentName(FormStateInterface $form_state)
    {
        $name = !empty($form_state->getValue('id')) ? $form_state->getValue('id') : 'yookassa';
        $url = !empty($this->configuration['notification_url']) ? $this->configuration['notification_url'] : $this->getNotificationUrl($name);

        return !is_string($url) ? $url->toString() : $url;
    }
}
