<?php

namespace AntQa\Bundle\PayUBundle\Controller;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

use AntQa\Bundle\PayUBundle\Model\Payment;

/**
 * Class PaymentController
 *
 * @author Piotr Antosik <mail@piotrantosik.com>
 */
class PaymentController
{
    /**
     * @var array
     */
    private $orderDetails;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var string
     */
    private $class;

    /**
     * @param EntityManager $em
     * @param string        $class
     */
    public function __construct(EntityManager $em, $class)
    {
        $this->em = $em;
        $this->class = $class;
    }

    private function __configurePayU($env, $posId, $signatureKey, $secret)
    {
        \OpenPayU_Configuration::setEnvironment($env);
        \OpenPayU_Configuration::setMerchantPosId($posId);
        \OpenPayU_Configuration::setSignatureKey($signatureKey);

        \OpenPayU_Configuration::setOauthClientId($posId);
        \OpenPayU_Configuration::setOauthClientSecret($secret);
    }

    /**
     * @param array      $orderDetails
     * @param array|null $options
     *
     * @return RedirectResponse
     */
    public function createOrder(array $orderDetails = [], array $options = [], $payu_config = [])
    {
        $this->__configurePayU($payu_config['env'], $payu_config['posId'], $payu_config['signatureKey'], $payu_config['secret']);

        $resolver = new OptionsResolver();
        $this->configureOrder($resolver);
        $this->orderDetails = $resolver->resolve($orderDetails);

        $response = \OpenPayU_Order::create($this->orderDetails);
        /** @var Payment $payment */
        $payment = new $this->class;
        $payment
            ->setPayUId($response->getResponse()->orderId)
            ->setOrderId($response->getResponse()->extOrderId);

        if (!empty($options)) {
            $accessor = PropertyAccess::createPropertyAccessor();

            foreach ($options as $key => $value) {
                $accessor->setValue($payment, $key, $value);
            }
        }

        $this->em->persist($payment);
        $this->em->flush();

        return $this->redirectToUrl($response->getResponse()->redirectUri);
    }

    public function cancelPayment($payUOrderId, $payuConfig = [])
    {
        $this->__configurePayU($payuConfig['env'], $payuConfig['posId'], $payuConfig['signatureKey'], $payuConfig['secret']);
        $response1 = \OpenPayU_Order::cancel($payUOrderId);
        $response2 = \OpenPayU_Order::cancel($payUOrderId);

        return compact('response1', 'response2');
    }

    /**
     * @param string $url
     *
     * @return RedirectResponse
     */
    public function redirectToUrl($url)
    {
        return new RedirectResponse($url);
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    protected function configureOrder(OptionsResolverInterface $resolver)
    {
        $resolver
            ->setRequired([
                'continueUrl',
                'notifyUrl',
                'customerIp',
                'merchantPosId',
                'description',
                'currencyCode',
                'totalAmount',
                'extOrderId',
                'products',
                'buyer'
            ])
            ->setDefaults([
                'currencyCode' => 'PLN',
                'merchantPosId' => (int) \OpenPayU_Configuration::getMerchantPosId() //todo - as config parameter
            ])
            ->setAllowedTypes([
                'continueUrl' => 'string',
                'notifyUrl' => 'string',
                'customerIp' => 'string',
                'merchantPosId' => 'int',
                'description' => 'string',
                'currencyCode' => 'string',
                'totalAmount' => 'integer',
                'extOrderId' => 'integer',
                'products' => 'array',
                'buyer' => 'array'
            ]);
    }
}
