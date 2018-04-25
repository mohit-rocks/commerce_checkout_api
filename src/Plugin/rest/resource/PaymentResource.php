<?php

namespace Drupal\commerce_checkout_api\Plugin\rest\resource;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "payment_resource",
 *   label = @Translation("Payment resource"),
 *   uri_paths = {
 *     "create" = "/api/rest/checkout/complete-order/{commerce_order}/payment"
 *   }
 * )
 */
class PaymentResource extends ResourceBase
{

    /**
     * A current user instance.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected $currentUser;

    /**
     * Constructs a new PaymentResource object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param array $serializer_formats
     *   The available serialization formats.
     * @param \Psr\Log\LoggerInterface $logger
     *   A logger instance.
     * @param \Drupal\Core\Session\AccountProxyInterface $current_user
     *   A current user instance.
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        array $serializer_formats,
        LoggerInterface $logger,
        AccountProxyInterface $current_user)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

        $this->currentUser = $current_user;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->getParameter('serializer.formats'),
            $container->get('logger.factory')->get('commerce_checkout_api'),
            $container->get('current_user')
        );
    }

    /**
     * Responds to POST requests.
     *
     * @param OrderInterface $commerce_order
     * @param array $unserialized
     * @return \Drupal\rest\ModifiedResourceResponse
     *   The HTTP response object.
     *
     */
    public function post(OrderInterface $commerce_order, array $unserialized)
    {

        // You must to implement the logic of your REST Resource here.
        // Use current user after pass authentication to validate access.
        if (!$this->currentUser->hasPermission('access content')) {
            throw new AccessDeniedHttpException();
        }

        // 检查用户选择的支付方式，保存到订单
        $gateway_name = empty($unserialized['gateway']) ? 'wechat_pay_h5_client' : $unserialized['gateway'];

        /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $payment_gateway_storage */
        $payment_gateway_storage = \Drupal::service('entity_type.manager')->getStorage('commerce_payment_gateway');
        /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
        $payment_gateway = $payment_gateway_storage->load($gateway_name);
        if (!$payment_gateway) {
            throw new \Exception('无效的支付网关');
        }

        $commerce_order->set('payment_gateway', $payment_gateway);
        $commerce_order->save();

        // TODO::暂时直接把订单设置为已支付状态
        $this->placeOrder($commerce_order);
        return new ModifiedResourceResponse([], 200);

        // 调用支付网关，创建支付，并返回支付调起数据
        // 实例化网关插件
        /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
        $payment_gateway = $commerce_order->payment_gateway->entity;
        /** @var \Drupal\commerce_checkout_api\Plugin\Commerce\PaymentGateway\WechatPayH5Client $payment_gateway_plugin */
        $payment_gateway_plugin = $payment_gateway->getPlugin();

        $config_data = $payment_gateway_plugin->generateClientPayConfigData($commerce_order);
    }

    private function placeOrder(OrderInterface $commerce_order) {
        $transition = $commerce_order->getState()->getWorkflow()->getTransition('place');
        $commerce_order->getState()->applyTransition($transition);
        $commerce_order->save();
    }

    /**
     * 暂时不做权限检查
     * @inheritdoc
     */
    public function permissions()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function getBaseRoute($canonical_path, $method)
    {
        $route = parent::getBaseRoute($canonical_path, $method);
        $parameters = $route->getOption('parameters') ?: [];
        $parameters['commerce_order']['type'] = 'entity:commerce_order';
        $route->setOption('parameters', $parameters);

        return $route;
    }
}
