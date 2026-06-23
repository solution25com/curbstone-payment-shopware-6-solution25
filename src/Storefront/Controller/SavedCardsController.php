<?php declare(strict_types=1);

namespace Curbstone\Storefront\Controller;

use Curbstone\Service\CurbstoneVaultService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class SavedCardsController extends StorefrontController
{
    public function __construct(
        private readonly GenericPageLoaderInterface $genericPageLoader,
        private readonly LoggerInterface            $logger,
        private readonly CurbstoneVaultService      $vaultService,
    ) {}

    #[Route(
        path: '/account/saved-cards',
        name: 'frontend.account.saved_cards.page',
        methods: ['GET']
    )]
    public function savedCards(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if (!$customer) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $page = $this->genericPageLoader->load($request, $salesChannelContext);

        $savedCards = $this->vaultService->loadNormalizedSavedCards(
            $customer->getId(),
            $salesChannelContext->getContext()
        );

        $page->addExtension('curbstone_saved_cards', new ArrayStruct($savedCards));

        return $this->renderStorefront(
            '@Curbstone/storefront/page/account/saved-cards/index.html.twig',
            ['page' => $page]
        );
    }

    #[Route(
        path: '/account/saved-cards/delete',
        name: 'frontend.account.saved_cards.delete',
        methods: ['POST'],
        defaults: ['_csrf_protected' => true]
    )]
    public function deleteCard(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if (!$customer) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $mfkeyp = (string) $request->request->get('mfkeyp', '');

        $this->logger->info('[Curbstone] Account delete card request', [
            'customerId' => $customer->getId(),
            'mfkeyp'     => $mfkeyp,
        ]);

        if ($mfkeyp !== '') {
            $this->vaultService->deleteVaultedCard(
                $customer->getId(),
                $mfkeyp,
                $salesChannelContext->getContext()
            );
            $this->addFlash('success', 'Saved card has been removed.');
        } else {
            $this->addFlash('danger', 'Could not remove card – missing identifier.');
        }

        return $this->redirectToRoute('frontend.account.saved_cards.page');
    }
}
