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
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->container->get('event_dispatcher');

        try {
            $body = file_get_contents('php://input');
            $data = stripslashes(trim($body));
            $data_array = \OpenPayU_Util::convertJsonToArray($data, true);

            $order_id = explode('-', $data_array['order']['extOrderId']);

            $payment = $this->getDoctrine()->getManager()->getRepository($this->container->getParameter('payu_bundle.payment_class'))->findOneByOrder($order_id);

            /** @var \stdClass $result */
            $result = \OpenPayU_Order::consumeNotification($data)->getResponse();

            if ($result->order->orderId) {
                $order = \OpenPayU_Order::retrieve($result->order->orderId);
                /** @var Payment $payment */

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

                $response = new Response();
                $response->setContent("OK");

                return $response;
            }
        } catch (\Exception $e) {
            $this->get('logger')->addError($e->getMessage());

            return new JsonResponse($e->getMessage());
        }

        return new Response('thanks for notice');
    }
}
