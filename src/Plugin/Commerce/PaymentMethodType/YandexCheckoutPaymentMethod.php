<?php

namespace Drupal\yandex_checkout\Plugin\Commerce\PaymentMethodType;


use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;


abstract class YandexCheckoutPaymentMethod extends PaymentMethodTypeBase
{

    /**
     * Builds a label for the given payment method.
     *
     * @param PaymentMethodInterface $payment_method
     *   The payment method.
     *
     * @return string
     *   The label.
     */
    abstract public function buildLabel(PaymentMethodInterface $payment_method);

    /**
     * Builds the field definitions for entities of this bundle.
     *
     * Important:
     * Field names must be unique across all bundles.
     * It is recommended to prefix them with the bundle name (plugin ID).
     *
     * @return \Drupal\entity\BundleFieldDefinition[]
     *   An array of bundle field definitions, keyed by field name.
     */
    public function buildFieldDefinitions()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateLabel()
    {
        return $this->getLabel();
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition
        );
    }
}