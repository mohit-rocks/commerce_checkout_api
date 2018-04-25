<?php

namespace Drupal\commerce_checkout_api\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\social_auth\Entity\SocialAuth;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use EasyWeChat\Foundation\Application;
use EasyWeChat\Payment\Order;

/**
 * 微信H5客户端支付，微信公众号JSSDK支付
 *
 * @CommercePaymentGateway(
 *   id = "wechat_pay_h5_client",
 *   label = "Wechat Pay For H5 Client",
 *   display_label = "Wechat Pay For H5 Client"
 * )
 */
class WechatPayH5Client extends OffsitePaymentGatewayBase implements SupportsRefundsInterface
{
    use StringTranslationTrait;

    /** @var  \EasyWeChat\Payment\payment $gateway_lib */
    protected $gateway_lib;

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['appid'] = [
            '#type' => 'textfield',
            '#title' => $this->t('公众账号ID'),
            '#description' => $this->t('绑定支付的APPID（开户邮件中可查看）'),
            '#default_value' => $this->configuration['appid'],
            '#required' => TRUE,
        ];

        $form['mch_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('商户号'),
            '#description' => $this->t('开户邮件中可查看'),
            '#default_value' => $this->configuration['mch_id'],
            '#required' => TRUE,
        ];

        $form['key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('商户支付密钥'),
            '#description' => $this->t('参考开户邮件设置（必须配置，登录商户平台自行设置）, 设置地址：https://pay.weixin.qq.com/index.php/account/api_cert'),
            '#default_value' => $this->configuration['key'],
            '#required' => TRUE,
        ];


        $form['cert_pem_path'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Cert证书路径'),
            '#description' => $this->t('apiclient_cert.pem'),
            '#default_value' => $this->configuration['cert_pem_path']
        ];

        $form['key_pem_path'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Key证书路径'),
            '#description' => $this->t('apiclient_key.pem'),
            '#default_value' => $this->configuration['cert_pem_path']
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {

    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['appid'] = $values['appid'];
            $this->configuration['mch_id'] = $values['mch_id'];
            $this->configuration['key'] = $values['key'];
            $this->configuration['cert_pem_path'] = $values['key_pem_path'];
            $this->configuration['key_pem_path'] = $values['key_pem_path'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
    {
        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
        // If not specified, refund the entire amount.
        $amount = $amount ?: $payment->getAmount();
        // Validate the requested amount.
        $this->assertRefundAmount($payment, $amount);

        if (!$this->gateway_lib) {
            $this->loadGatewayConfig();
        }

        /** @var \EasyWeChat\Payment\API $gateway ; */
        $gateway = $this->gateway_lib;

        if (!$gateway->getMerchant()->get('cert_path') || !$gateway->getMerchant()->get('key_path')) {
            throw new \InvalidArgumentException($this->t('Could not load the apiclient_cert.pem or apiclient_key.pem files, which are required for WeChat Refund. Did you configure them?'));
        }

        $result = $gateway->refund($payment->getOrderId(), $payment->getOrderId() . date("zHis"), floatval($payment->getAmount()) * 100, floatval($amount->getNumber()) * 100);

        if (!$result->return_code == 'SUCCESS' || !$result->result_code == 'SUCCESS') {
            // For any reason, we cannot get a preorder made by WeChat service
            throw new InvalidRequestException($this->t('WeChat Service cannot approve this request: ') . $result->err_code_des);
        }

        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
            $payment->setState('partially_refunded');
        } else {
            $payment->setState('refunded');
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
    }

    /**
     * {@inheritdoc}
     */
    public function onNotify(Request $request)
    {

        if (!$this->gateway_lib) {
            $this->loadGatewayConfig();
        }

        /** @var \EasyWeChat\Payment\API $gateway ; */
        $gateway = $this->gateway_lib;

        $response = $gateway->handleNotify(function ($notify, $successful) {
            $result = $notify->toArray();

            if ($this->getMode()) {
                \Drupal::logger('commerce_wechat_pay')->notice(print_r($result, TRUE));
            }

            // load the payment
            /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
            $query = \Drupal::entityQuery('commerce_payment')
                ->condition('order_id', $result['out_trade_no'])
                ->addTag('commerce_wechat_pay:check_payment');
            $payment_id = $query->execute();
            /** @var \Drupal\commerce_payment\Entity\Payment $payment_entity */
            $payment_entity = Payment::load(array_values($payment_id)[0]);

            if ($successful) {

                if ($payment_id) {

                    if ($payment_entity) {
                        $payment_entity->setState('completed');
                        $payment_entity->setRemoteId($result['transaction_id']);
                        $payment_entity->save();
                    } else {
                        // Payment doesn't exist
                        \Drupal::logger('commerce_wechat_pay')->error(print_r($result, TRUE));
                    }
                }
            } else { // When payment failed
                \Drupal::logger('commerce_wechat_pay')->error(print_r($result, TRUE));
            }

            return TRUE; // Respond WeChat request that we have finished processing this notification
        });

        return $response;
    }

    /**
     * Create a Commerce Payment from a WeChat request successful result
     * @param  array $result
     * @param  string $state
     * @param null $order_id
     * @param  string $remote_state
     * @param \Drupal\commerce_price\Price|null $price
     * @return \Drupal\Core\Entity\EntityInterface
     */
    public function createPayment(array $result, $state, $order_id = NULL, $remote_state = NULL, Price $price = NULL)
    {
        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

        $request_time = \Drupal::time()->getRequestTime();

        if (array_key_exists('transaction_id', $result)) {
            $remote_id = $result['transaction_id'];
        } elseif (array_key_exists('prepay_id', $result)) {
            $remote_id = $result['prepay_id'];
        } else {
            $remote_id = NULL; // There is no $remote_id when USERPAYING
        }

        $payment = $payment_storage->create([
            'state' => $state,
            'amount' => $price ? $price : new Price(strval($result['total_fee'] / 100), $result['fee_type']),
            'payment_gateway' => $this->entityId,
            'order_id' => $order_id ? $order_id : $result['out_trade_no'],
            'test' => $this->getMode() == 'test',
            'remote_id' => $remote_id,
            'remote_state' => $remote_state,
            'authorized' => array_key_exists('time_start', $result) ? strtotime($result['time_start']) : $request_time,
            'authorization_expires' => array_key_exists('time_expire', $result) ? strtotime($result['time_expire']) : strtotime('+2 hours', $request_time),
            'captured' => $state === 'completed' ? strtotime($result['time_end']) : NULL
        ]);

        $payment->save();

        return $payment;
    }

    /**
     * Load configuration from parameters first, otherwise from system configuration. This method exists so other part of system can override the configurations.
     * One use case would be multi-stores, each store has its own payment gateway configuration saved on other entity.
     * @param null $appid
     * @param null $mch_id
     * @param null $key
     * @param null $cert_path
     * @param null $key_path
     * @param null $mode
     * @param null $sub_appid
     * @param null $sub_mch_id
     */
    public function loadGatewayConfig($appid = NULL, $mch_id = NULL, $key = NULL, $cert_path = NULL, $key_path = NULL, $mode = NULL, $sub_appid = NULL, $sub_mch_id = NULL)
    {
        if (!$appid) {
            $appid = $this->getConfiguration()['appid'];
        }
        if (!$mch_id) {
            $mch_id = $this->getConfiguration()['mch_id'];
        }
        if (!$key) {
            $key = $this->getConfiguration()['key'];
        }
        if (!$cert_path) {
            $cert_path = $this->getConfiguration()['cert_pem_path'];
        }
        if (!$key_path) {
            $key_path = $this->getConfiguration()['key_pem_path'];
        }
        if (!$mode) {
            $mode = $this->getMode();
        }

        $options = [
            // 前面的appid什么的也得保留哦
            'app_id' => $appid,
            // payment
            'payment' => [
                'merchant_id' => $mch_id,
                'key' => $key,
                'cert_path' => $cert_path,
                'key_path' => $key_path
            ],
        ];

        $app = new Application($options);
        $wechat_pay = $app->payment;

        if ($mode == 'test') {
            $wechat_pay->sandboxMode(true);
        }

        $this->gateway_lib = $wechat_pay;
    }

    /**
     * 为订单创建支付，并返回用于客户端发起支付的配置数据
     */
    public function generateClientPayConfigData(\Drupal\commerce_order\Entity\Order $commerce_order)
    {
        // 调用 overtrue SDK，统一下单，再生成JSSDK配置
        if (!$this->gateway_lib) {
            $this->loadGatewayConfig();
        }

        $order_item_names = '';
        foreach ($commerce_order->getItems() as $order_item) {
            /** @var OrderItem $order_item */
            $order_item_names .= $order_item->getTitle() . ', ';
        }

        // 查看用户 open_id
        /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
        $query = \Drupal::entityQuery('social_auth')
            ->condition('user_id', \Drupal::currentUser()->id())
            ->condition('plugin_id', 'social_auth');
        $social_auth_id = $query->execute();

        if (empty($social_auth_id)) throw new \Exception('找不到当前用户 '.\Drupal::currentUser()->getAccountName().' 的 openid，');

        /** @var SocialAuth $social_auth_user */
        $social_auth_user = SocialAuth::load(array_pop($social_auth_id));


        global $base_url;
        $notify_url = $base_url . '/' . $this->getNotifyUrl()->getInternalPath();

        $attributes = [
            'trade_type'       => 'JSAPI', // JSAPI，NATIVE，APP...
            'body'             => \Drupal::config('system.site')->get('name') . $this->t(' Order: ') . $commerce_order->getOrderNumber(),
            'detail'           => $order_item_names,
            'out_trade_no'     => $commerce_order->id().'at'.time(),
            'total_fee'        => $commerce_order->getTotalPrice()->getNumber() * 100, // 单位：分
            'notify_url'       => $notify_url, // 支付结果通知网址，如果不设置则会使用配置里的默认地址
            'openid'           => $social_auth_user->get('provider_user_id')->value, // trade_type=JSAPI，此参数必传，用户在商户appid下的唯一标识，
        ];

        $order = new Order($attributes);

        $result = $this->gateway_lib->prepare($order);
        if ($result->return_code == 'SUCCESS'){
            $prepayId = $result->prepay_id;

            // 创建commerce_payment
            /** @var \Drupal\commerce_payment\Entity\Payment $payment_entity */
            $payment_entity = $this->createPayment(
                $result->toArray(),
                'authorization',
                $commerce_order->id(),
                $result->code_url,
                $commerce_order->getTotalPrice());

            return $this->gateway_lib->configForPayment($prepayId, false);
        } else {
            throw new \Exception('下单错误');
        }
    }
}
