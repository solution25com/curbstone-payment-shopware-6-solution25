<?php

declare(strict_types=1);

namespace Curbstone\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class CurbstoneReturnController extends StorefrontController
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/curbstone/return', name: 'frontend.curbstone.return', methods: ['GET','POST'])]
    public function __invoke(Request $request, Context $context): Response
    {
        if ($request->isMethod('POST')) {
            $mfref = (string) ($request->request->get('MFREFR') ?? $request->request->get('tx') ?? '');
            if ($mfref === '') {
                $this->logger->error('Curbstone return POST missing MFREFR/tx', [
                    'post' => $request->request->all(),
                ]);
                return $this->redirectToRoute('frontend.home.page');
            }

            $url = $this->generateUrl('frontend.curbstone.return', ['tx' => $mfref], UrlGeneratorInterface::ABSOLUTE_URL);
            return new RedirectResponse($url, 303);
        }

        $mfref = (string) ($request->query->get('MFREFR') ?? $request->query->get('tx') ?? '');
        if ($mfref === '') {
            $this->logger->error('Curbstone return GET missing MFREFR/tx', [
                'query' => $request->query->all(),
            ]);
            return $this->redirectToRoute('frontend.home.page');
        }

        $criteria = (new Criteria([$mfref]))
            ->addAssociation('order')
            ->addAssociation('order.transactions');

        /** @var OrderTransactionEntity|null $tx */
        $tx = $this->orderTransactionRepository->search($criteria, $context)->first();
        if ($tx === null) {
            $this->logger->error('Curbstone return: transaction not found', ['mfref' => $mfref]);
            return $this->redirectToRoute('frontend.home.page');
        }

        $order = $tx->getOrder();
        if ($order === null) {
            $this->logger->error('Curbstone return: transaction has no order', ['mfref' => $mfref]);
            return $this->redirectToRoute('frontend.home.page');
        }

        $custom       = $tx->getCustomFields() ?? [];
        $curbstoneCf  = \is_array($custom['curbstone'] ?? null) ? $custom['curbstone'] : [];
        $returnUrl    = (string) ($curbstoneCf['shopReturnUrl'] ?? '');
        $flow         = (string) ($curbstoneCf['flow'] ?? 'auth_only');
        $contextName  = (string) ($curbstoneCf['contextCookieName'] ?? 'sw-context-token');
        $contextToken = (string) ($curbstoneCf['contextToken'] ?? '');
        $sessionName  = (string) ($curbstoneCf['sessionCookieName'] ?? 'PHPSESSID');
        $sessionId    = (string) ($curbstoneCf['sessionId'] ?? '');

        $payload = $request->query->all();

        $mfStatu = isset($payload['MFSTATU']) && \is_string($payload['MFSTATU'])
            ? \strtoupper($payload['MFSTATU'])
            : null;
        $isOk = $mfStatu === null ? true : ($mfStatu === 'OK');

        $finishUrl = $this->generateUrl(
            'frontend.checkout.finish.page',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $fallbackUrl = $returnUrl !== ''
            ? $returnUrl
            : $this->generateUrl(
                'frontend.account.edit-order.page',
                [
                    'orderId'      => $order->getId(),
                    'deepLinkCode' => $order->getDeepLinkCode(),
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        $targetUrl = $isOk ? $finishUrl : $fallbackUrl;

        $response = new RedirectResponse($targetUrl, 302);

        $secure = $request->isSecure();
        $path   = '/';

        if ($contextToken !== '') {
            $response->headers->setCookie(
                Cookie::create($contextName, $contextToken)
                    ->withPath($path)
                    ->withSecure($secure)
                    ->withHttpOnly(false)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );
        }

        if ($sessionId !== '') {
            $response->headers->setCookie(
                Cookie::create($sessionName, $sessionId)
                    ->withPath($path)
                    ->withSecure($secure)
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );
        }

        try {
            $current = $tx->getStateMachineState()?->getTechnicalName() ?? 'unknown';

            $this->logger->info('Curbstone return received', [
                'tx'      => $tx->getId(),
                'flow'    => $flow,
                'current' => $current,
                'ok'      => $isOk,
            ]);

            if (!$isOk) {
                if ($current !== 'failed') {
                    $this->stateHandler->fail($tx->getId(), $context);
                }
                return $response;
            }

            if ($current === 'open') {
                try {
                    $this->stateHandler->process($tx->getId(), $context);
                } catch (\Throwable) {
                }
                $current = 'in_progress';
            }

            if ($flow === 'auth_capture') {
                if ($current !== 'paid') {
                    try {
                        $this->stateHandler->paid($tx->getId(), $context);
                    } catch (\Throwable) {
                        try {
                            $this->stateHandler->process($tx->getId(), $context);
                            $this->stateHandler->paid($tx->getId(), $context);
                        } catch (\Throwable $e2) {
                            throw $e2;
                        }
                    }
                }
            } else {
                if ($current !== 'authorized') {
                    try {
                        $this->stateHandler->authorize($tx->getId(), $context);
                    } catch (\Throwable) {
                        try {
                            $this->stateHandler->process($tx->getId(), $context);
                            $this->stateHandler->authorize($tx->getId(), $context);
                        } catch (\Throwable $e2) {
                            throw $e2;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone return state transition failed', [
                'tx'    => $tx->getId(),
                'flow'  => $flow,
                'error' => $e->getMessage(),
            ]);

            try {
                $cur = $tx->getStateMachineState()?->getTechnicalName();
                if ($cur !== 'paid' && $cur !== 'authorized') {
                    $this->stateHandler->fail($tx->getId(), $context);
                }
            } catch (\Throwable) {
            }
        }

        return $response;
    }
}
