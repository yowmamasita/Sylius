<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Bundle\PayumBundle\Controller;

use FOS\RestBundle\View\View;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\HttpRequestVerifierInterface;
use Payum\Core\Security\TokenInterface;
use Sylius\Bundle\PayumBundle\Factory\GetStatusFactoryInterface;
use Sylius\Bundle\PayumBundle\Factory\ResolveNextRouteFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfigurationFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\ViewHandlerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Metadata\MetadataInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

/**
 * @author Arkadiusz Krakowiak <arkadiusz.krakowiak@lakion.com>
 */
final class PayumController
{
    /**
     * @var Payum
     */
    private $payum;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var MetadataInterface
     */
    private $orderMetadata;

    /**
     * @var RequestConfigurationFactoryInterface
     */
    private $requestConfigurationFactory;

    /**
     * @var ViewHandlerInterface
     */
    private $viewHandler;

    /**
     * @var RouterInterface
     */
    private $router;

    /** @var GetStatusFactoryInterface */
    private $getStatusRequestFactory;

    /** @var ResolveNextRouteFactoryInterface */
    private $resolveNextRouteRequestFacotry;

    public function __construct(
        Payum $payum,
        OrderRepositoryInterface $orderRepository,
        MetadataInterface $orderMetadata,
        RequestConfigurationFactoryInterface $requestConfigurationFactory,
        ViewHandlerInterface $viewHandler,
        RouterInterface $router,
        GetStatusFactoryInterface $getStatusFactory,
        ResolveNextRouteFactoryInterface $resolveNextRouteFactory
    ) {
        $this->payum = $payum;
        $this->orderRepository = $orderRepository;
        $this->orderMetadata = $orderMetadata;
        $this->requestConfigurationFactory = $requestConfigurationFactory;
        $this->viewHandler = $viewHandler;
        $this->router = $router;
        $this->getStatusRequestFactory = $getStatusFactory;
        $this->resolveNextRouteRequestFacotry = $resolveNextRouteFactory;
    }

    public function prepareCaptureAction(Request $request, $tokenValue): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->orderMetadata, $request);

        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneByTokenValue($tokenValue);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order with token "%s" does not exist.', $tokenValue));
        }

        $request->getSession()->set('sylius_order_id', $order->getId());
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);

        if (null === $payment) {
            $url = $this->router->generate('sylius_shop_order_thank_you');

            return new RedirectResponse($url);
        }

        $token = $this->provideTokenBasedOnPayment($payment, $configuration->getParameters()->get('redirect'));

        $view = View::createRedirect($token->getTargetUrl());

        return $this->viewHandler->handle($configuration, $view);
    }

    public function afterCaptureAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->orderMetadata, $request);

        $token = $this->getHttpRequestVerifier()->verify($request);

        $status = $this->getStatusRequestFactory->createNewWithModel($token);
        $this->payum->getGateway($token->getGatewayName())->execute($status);
        $resolveNextRoute = $this->resolveNextRouteRequestFacotry->createNewWithModel($status->getFirstModel());
        $this->payum->getGateway($token->getGatewayName())->execute($resolveNextRoute);

        $this->getHttpRequestVerifier()->invalidate($token);

        if (PaymentInterface::STATE_NEW !== $status->getValue()) {
            $request->getSession()->getBag('flashes')->add('info', sprintf('sylius.payment.%s', $status->getValue()));
        }

        return $this->viewHandler->handle(
            $configuration,
            View::createRouteRedirect($resolveNextRoute->getRouteName(), $resolveNextRoute->getRouteParameters())
        );
    }

    private function getTokenFactory(): GenericTokenFactoryInterface
    {
        return $this->payum->getTokenFactory();
    }

    private function getHttpRequestVerifier(): HttpRequestVerifierInterface
    {
        return $this->payum->getHttpRequestVerifier();
    }

    private function provideTokenBasedOnPayment(PaymentInterface $payment, array $redirectOptions): TokenInterface
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $payment->getMethod()->getGatewayConfig();

        if (isset($gatewayConfig->getConfig()['use_authorize']) && $gatewayConfig->getConfig()['use_authorize'] == true) {
            $token = $this->getTokenFactory()->createAuthorizeToken(
                $gatewayConfig->getGatewayName(),
                $payment,
                isset($redirectOptions['route'])
                    ? $redirectOptions['route']
                    : null,
                isset($redirectOptions['parameters'])
                    ? $redirectOptions['parameters']
                    : []
            );
        } else {
            $token = $this->getTokenFactory()->createCaptureToken(
                $gatewayConfig->getGatewayName(),
                $payment,
                isset($redirectOptions['route'])
                    ? $redirectOptions['route']
                    : null,
                isset($redirectOptions['parameters'])
                    ? $redirectOptions['parameters']
                    : []
            );
        }

        return $token;
    }
}
