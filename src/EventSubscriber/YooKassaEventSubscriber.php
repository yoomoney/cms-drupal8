<?php

namespace Drupal\yookassa\EventSubscriber;

use Drupal;
use Drupal\commerce_log\LogStorageInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\yookassa\Plugin\Commerce\PaymentGateway\YooKassa;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use YooKassa\Client;
use YooKassa\Common\AbstractRequest;
use YooKassa\Common\Exceptions\ApiException;
use YooKassa\Common\Exceptions\BadApiRequestException;
use YooKassa\Common\Exceptions\ExtensionNotFoundException;
use YooKassa\Common\Exceptions\ForbiddenException;
use YooKassa\Common\Exceptions\InternalServerError;
use YooKassa\Common\Exceptions\NotFoundException;
use YooKassa\Common\Exceptions\ResponseProcessingException;
use YooKassa\Common\Exceptions\TooManyRequestsException;
use YooKassa\Common\Exceptions\UnauthorizedException;
use YooKassa\Model\Receipt\PaymentMode;
use YooKassa\Model\ReceiptCustomer;
use YooKassa\Model\ReceiptItem;
use YooKassa\Model\ReceiptType;
use YooKassa\Model\Settlement;
use YooKassa\Request\Receipts\CreatePostReceiptRequest;
use YooKassa\Request\Receipts\PaymentReceiptResponse;
use YooKassa\Request\Receipts\ReceiptResponseItemInterface;

/**
 * Send Second Receipt when the order transitions to Fulfillment.
 */
class YooKassaEventSubscriber implements EventSubscriberInterface
{

    /**
     * The log storage.
     *
     * @var LogStorageInterface
     */
    protected $logStorage;

    /**
     * @var Client
     */
    public $apiClient;

    protected $config;

    /**
     * Constructor for YooKassaEventSubscriber.
     * @param EntityTypeManagerInterface $entity_type_manager
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(EntityTypeManagerInterface $entity_type_manager) {
        $this->logStorage = $entity_type_manager->getStorage('commerce_log');
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'commerce_order.commerce_order.presave' => 'onSendSecondReceipt'
        ];
    }

    /**
     * @param $message
     * @param $type
     */
    private function log($message, $type)
    {
        Drupal::logger('yookassa')->$type($message);
    }

    /**
     * Send Second Receipt.
     * @param OrderEvent $event
     * @return void|null
     * @throws ApiException
     * @throws BadApiRequestException
     * @throws Drupal\Core\Entity\EntityStorageException
     * @throws ExtensionNotFoundException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws InvalidPluginDefinitionException
     * @throws NotFoundException
     * @throws PluginNotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     */
    public function onSendSecondReceipt(OrderEvent $event)
    {
        /** @var Order $order */
        $order = $event->getOrder();
        $isSentSecondReceipt = $order->getData('send_second_receipt') ?? false;
        $state = $order->getState()->getValue();
        $this->config = $this->getPaymentConfig($order);

        if (
            !$isSentSecondReceipt
            && !empty($this->config)
            && !empty($this->config['second_receipt_enabled'])
            && $state['value'] == $this->config['second_receipt_status']
        ) {
            $this->apiClient = $this->getClient($this->config);
            $order->setData('send_second_receipt', true);

            $orderId = $order->get('order_id')->getString();
            $payments = Drupal::entityTypeManager()->getStorage('commerce_payment')->loadByProperties(['order_id' => $orderId]);
            $paymentId = !empty($payments) ? array_shift($payments)->getRemoteId() : null;
            $amount = $order->getTotalPrice()->getNumber();
            $customerEmail = $order->get('mail')->getString();

            if (!$lastReceipt = $this->getLastReceipt($paymentId)) {
                $this->log('LastReceipt is empty!', 'error');
                return null;
            }

            if ($receiptRequest = $this->buildSecondReceipt($lastReceipt, $paymentId, $customerEmail)) {

                try {
                    $this->apiClient->createReceipt($receiptRequest);
                    $this->logStorage->generate($order, 'order_sent_second_reciept', ['amount' => number_format((float)$amount, 2, '.', '')])->save();
                    $this->log('SecondReceipt Send: ' . json_encode($receiptRequest), 'info');
                } catch (ApiException $e) {
                    $this->log('SecondReceipt Error: ' . json_encode([
                            'error' => $e->getMessage(),
                            'request' => $receiptRequest->toArray(),
                            'lastReceipt' => $lastReceipt->toArray(),
                            'paymentId' => $paymentId,
                            'customEmail' => $customerEmail
                        ]), 'error');
                }
            }
        }
    }

    /**
     * @param $paymentId
     * @return mixed|null
     * @throws ApiException
     * @throws ExtensionNotFoundException
     * @throws BadApiRequestException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws NotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     */
    private function getLastReceipt($paymentId)
    {
        $receipts = $this->apiClient->getReceipts(array('payment_id' => $paymentId))->getItems();

        return array_pop($receipts);
    }

    /**
     * @param PaymentReceiptResponse $lastReceipt
     * @param string $paymentId
     * @param string $customerEmail
     * @return AbstractRequest|null
     */
    private function buildSecondReceipt(PaymentReceiptResponse $lastReceipt, string $paymentId, string $customerEmail)
    {
        if ($lastReceipt->getType() === "refund") {
            $this->log('Last receipt is refund', 'error');
            return null;
        }

        $resendItems = $this->getResendItems($lastReceipt->getItems());

        if (!count($resendItems['items'])) {
            $this->log('Second receipt is not required', 'error');
            return null;
        }

        try {
            $customer = new ReceiptCustomer();
            $customer->setEmail($customerEmail);

            if (empty($customer)) {
                $this->log('Customer email for second receipt are empty', 'error');
                return null;
            }

            $settlement = new Settlement([
                'type' => 'prepayment',
                'amount' => [
                    'value' => $resendItems['amount'],
                    'currency' => 'RUB',
                ],
            ]);
            $receiptBuilder = CreatePostReceiptRequest::builder();
            $receiptBuilder->setObjectId($paymentId)
                ->setType(ReceiptType::PAYMENT)
                ->setItems($resendItems['items'])
                ->setSettlements([$settlement])
                ->setCustomer($customer)
                ->setSend(true);

            return $receiptBuilder->build();

        } catch (Exception $e) {
            $this->log('Build second receipt error: ' . json_encode([
                    'message' => $e->getMessage()
                ]), 'error');
        }

        return null;
    }

    /**
     * @param ReceiptResponseItemInterface[] $items
     *
     * @return array
     */
    private function getResendItems(array $items): array
    {
        $result = array(
            'items' => array(),
            'amount' => 0,
        );

        foreach ($items as $item) {
            if ($this->isNeedResendItem($item->getPaymentMode())) {
                $item->setPaymentMode(PaymentMode::FULL_PAYMENT);
                $result['items'][] = new ReceiptItem($item->toArray());
                $result['amount'] += $item->getAmount() / 100.0;
            }
        }

        return $result;
    }

    /**
     * @param string $paymentMode
     *
     * @return bool
     */
    private function isNeedResendItem(string $paymentMode): bool
    {
        return in_array($paymentMode, self::getValidPaymentMode());
    }

    /**
     * @return array
     */
    private static function getValidPaymentMode(): array
    {
        return array(
            PaymentMode::FULL_PREPAYMENT,
            PaymentMode::PARTIAL_PREPAYMENT,
            PaymentMode::ADVANCE,
            PaymentMode::PARTIAL_PAYMENT,
            PaymentMode::CREDIT,
            PaymentMode::CREDIT_PAYMENT
        );
    }

    /**
     * @param Order $order
     * @return mixed|null
     */
    private function getPaymentConfig(Order $order)
    {
        $paymentId = $order->get('payment_gateway')->getString();

        return $paymentId ? Drupal::config('commerce_payment.commerce_payment_gateway.'.$paymentId)->getOriginal('configuration') : null;
    }

    /**
     * @param array $yookassaConfig
     * @return Client
     */
    private function getClient(array $yookassaConfig): Client
    {
        $yookassaClient = new Client();
        $yookassaClient->setAuth($yookassaConfig['shop_id'], $yookassaConfig['secret_key']);
        $userAgent = $yookassaClient->getApiClient()->getUserAgent();
        $userAgent->setCms('Drupal', Drupal::VERSION);
        $userAgent->setModule('yoomoney-cms', YooKassa::YOOMONEY_MODULE_VERSION);
        return $yookassaClient;
    }
}
