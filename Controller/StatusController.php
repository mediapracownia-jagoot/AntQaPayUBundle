<?php

namespace AntQa\Bundle\PayUBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use AntQa\Bundle\PayUBundle\AntQaPaymentEvents;
use AntQa\Bundle\PayUBundle\Event\PaymentEvent;
use AntQa\Bundle\PayUBundle\Model\Payment;

/**
 * Class StatusController
 *
 * @author Piotr Antosik <mail@piotrantosik.com>
 */
class StatusController extends Controller
{
    /**
     * @param Request $request
     *
     * @return Response
     */
    public function thanksAction(Request $request)
    {
        if ($request->attributes->has('template')) {
            return $this->render($request->attributes->get('template'));
        }

        //default template
        return $this->render('@AntPayU/Thanks/thanks.html.twig');
    }

    /**
     * @return JsonResponse|Response
     */
    public function notifyAction()
    {
        $paymentController = $this->get('ant_qa.payu_bundle.controller.payment');
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->container->get('event_dispatcher');

        try {
            $body = file_get_contents('php://input');
            $data = stripslashes(trim($body));
            $data_array = \OpenPayU_Util::convertJsonToArray($data, true);

            $run = $this->getDoctrine()->getManager()->getRepository($this->container->getParameter('payu_bundle.payment_class'))->findBy(['order_id' => $data_array['order']['extOrderId']])
                            ->getOrder()->getRunUser()->getRun();

            \OpenPayU_Configuration::setEnvironment($run->getEnv());
            \OpenPayU_Configuration::setMerchantPosId($run->getPosId());
            \OpenPayU_Configuration::setSignatureKey($run->getSignatureKey());

            /** @var \stdClass $result */
            $result = \OpenPayU_Order::consumeNotification($data)->getResponse();

            if ($result->order->orderId) {
                $order = \OpenPayU_Order::retrieve($result->order->orderId);
                /** @var Payment $payment */
                $payment = $this->getDoctrine()->getManager()->getRepository($this->container->getParameter('payu_bundle.payment_class'))->find($result->order->orderId);

                if ($payment) {
                    if (
                        $payment->getStatus() !== Payment::STATUS_COMPLETED
                        &&
                        $payment->getStatus() !== Payment::STATUS_CANCELED
                    ) {
                        //update payment status
                        $payment->setStatus($result->order->status);
                        $this->getDoctrine()->getManager()->flush();

                        $event = new PaymentEvent($payment);
                        $dispatcher->dispatch(AntQaPaymentEvents::PAYMENT_STATUS_UPDATE, $event);

                        if ($result->order->status === Payment::STATUS_CANCELED) {
                            //payment canceled - eg. notify user?
                            $event = new PaymentEvent($payment);
                            $dispatcher->dispatch(AntQaPaymentEvents::PAYMENT_CANCELED, $event);
                        }

                        if ($result->order->status === Payment::STATUS_COMPLETED) {
                            //process payment action - eg. add user point?
                            $event = new PaymentEvent($payment);
                            $dispatcher->dispatch(AntQaPaymentEvents::PAYMENT_COMPLETED, $event);
                        }
                    }
                }

                $responseContent = \OpenPayU::buildOrderNotifyResponse($result->order->orderId);

                $response = new Response();
                $response->setContent($responseContent);
                $response->headers->add(['Content-Type' => 'application/json']);

                return $response;
            }
        } catch (\Exception $e) {
            $this->get('logger')->addError($e->getMessage());

            return new JsonResponse($e->getMessage());
        }

        return new Response('thanks for notice');
    }
}
