<?php

namespace Drupal\commerce_checkout_api\Plugin\rest\resource;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\profile\Entity\Profile;
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
 *   id = "complete_order_resource",
 *   label = @Translation("Complete order resource"),
 *   uri_paths = {
 *     "canonical" = "/api/rest/checkout/complete-order/{commerce_order}"
 *   }
 * )
 */
class CompleteOrderResource extends ResourceBase
{

    /**
     * A current user instance.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected $currentUser;

    /**
     * Constructs a new CompleteOrderResource object.
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
    protected function getBaseRoute($canonical_path, $method) {
        $route = parent::getBaseRoute($canonical_path, $method);
        $parameters = $route->getOption('parameters') ?: [];
        $parameters['commerce_order']['type'] = 'entity:commerce_order';
        $route->setOption('parameters', $parameters);

        return $route;
    }

    /**
     * 获取一个完整的订单，包含它的必要关联数据
     *
     * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
     *   The order.
     *
     * @return \Drupal\rest\ResourceResponse
     *   The resource response.
     */
    public function get(Order $entity)
    {
        $response = new ResourceResponse($entity);
        $response->addCacheableDependency($entity);
        return $response;
    }

    /**
     * Responds to PATCH requests.
     *
     * @param OrderInterface $commerce_order
     * @param array $unserialized
     * @return \Drupal\rest\ModifiedResourceResponse
     *   The HTTP response object.
     *
     */
    public function patch(OrderInterface $commerce_order, array $unserialized)
    {
        // You must to implement the logic of your REST Resource here.
        // Use current user after pass authentication to validate access.
        if (!$this->currentUser->hasPermission('access content')) {
            throw new AccessDeniedHttpException();
        }

        // 保存联系信息、更新数量
        $profile = null;
        if (isset($unserialized['contact']) && !empty($unserialized['contact'])) {
            if ($commerce_order->get('contact')->isEmpty()) {
                // 创建profile
                $profile = Profile::create([
                    'type' => 'order_contact'
                ]);
            } else {
                $profile = $commerce_order->get('contact')->entity;
            }

            if (!empty($unserialized['contact']['address'])) {
                $profile->set('field_address_province', $unserialized['contact']['address']['field_address_province']);
                $profile->set('field_address_province_code', $unserialized['contact']['address']['field_address_province_code']);

                $profile->set('field_address_city', $unserialized['contact']['address']['field_address_city']);
                $profile->set('field_address_city_code', $unserialized['contact']['address']['field_address_city_code']);

                $profile->set('field_address_district', $unserialized['contact']['address']['field_address_district']);
                $profile->set('field_address_district_code', $unserialized['contact']['address']['field_address_district_code']);

                $profile->set('field_address_detail', $unserialized['contact']['address']['field_address_detail']);
            }

            $profile->set('field_name', $unserialized['contact']['field_name']);
            $profile->set('field_phone', $unserialized['contact']['field_phone']);
            $profile->set('field_remark', $unserialized['contact']['field_remark']);

            $profile->save();
        }

        // 更新数量
        if (!empty($unserialized['quantity'])) {
            foreach ($commerce_order->getItems() as $orderItem) {
                $orderItem->setQuantity($unserialized['quantity']);
                $violations = $orderItem->validate();
                if (count($violations) > 0) {
                    throw new UnprocessableEntityHttpException('You have provided an invalid quantity value');
                }
                $orderItem->save();
            }

            $commerce_order->setRefreshState(OrderInterface::REFRESH_ON_SAVE);
        }

        if ($commerce_order->get('contact')->isEmpty() && $profile) {
            $commerce_order->set('contact', $profile);
        }

        // 保存 billing_profile，它是 commerce_order 的原生订单资料字段
        if ($unserialized['billing_profile']) {
            $billing_profile = Profile::load($unserialized['billing_profile']);
            $commerce_order->setBillingProfile($billing_profile);
        }

        $commerce_order->save();

        return new ModifiedResourceResponse($commerce_order, 204);
    }
}
