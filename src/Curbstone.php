<?php

declare(strict_types=1);

namespace Curbstone;

use Curbstone\Payment\PaymentMethodInterface;
use Curbstone\Payment\PaymentMethods;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class Curbstone extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        foreach (PaymentMethods::PAYMENT_METHODS as $cls) {
            $this->upsertPaymentMethod(new $cls(), $installContext->getContext());
        }
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        foreach (PaymentMethods::PAYMENT_METHODS as $cls) {
            $this->upsertPaymentMethod(new $cls(), $updateContext->getContext());
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $cls) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), new $cls());
        }

        if (!$uninstallContext->keepUserData()) {
            $this->dropCurbstoneTables();
        }

        parent::uninstall($uninstallContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $cls) {
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), new $cls());
        }
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods::PAYMENT_METHODS as $cls) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $cls());
        }
        parent::deactivate($deactivateContext);
    }

    private function upsertPaymentMethod(PaymentMethodInterface $pm, Context $ctx): void
    {
        /** @var EntityRepository<PaymentMethodCollection> $repo */
        $repo = $this->svcId('payment_method.repository');

        $existingId = $this->getPaymentMethodId($pm->getPaymentHandler(), $ctx);

        $payload = [
            'handlerIdentifier' => $pm->getPaymentHandler(),
            'technicalName'     => $pm->getTechnicalName(),
            'name'              => $pm->getName(),
            'description'       => $pm->getDescription(),
            'active'            => false,
            'afterOrderEnabled' => true,
            'pluginId'          => $this->getPluginId($ctx),
        ];

        if ($existingId) {
            $payload['id'] = $existingId;
            $repo->upsert([$payload], $ctx);
        } else {
            $repo->create([$payload], $ctx);
        }
    }

    private function setPaymentMethodIsActive(bool $active, Context $ctx, PaymentMethodInterface $pm): void
    {
        /** @var EntityRepository<PaymentMethodCollection> $repo */
        $repo = $this->svcId('payment_method.repository');

        if ($id = $this->getPaymentMethodId($pm->getPaymentHandler(), $ctx)) {
            $repo->update([['id' => $id, 'active' => $active]], $ctx);
        }
    }

    private function getPaymentMethodId(string $handler, Context $ctx): ?string
    {
        /** @var EntityRepository<PaymentMethodCollection> $repo */
        $repo = $this->svcId('payment_method.repository');

        $criteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', $handler));
        return $repo->searchIds($criteria, $ctx)->firstId();
    }

    private function getPluginId(Context $ctx): string
    {
        /** @var PluginIdProvider $provider */
        $provider = $this->svcClass(PluginIdProvider::class);
        return $provider->getPluginIdByBaseClass(static::class, $ctx);
    }

    private function dropCurbstoneTables(): void
    {
        /** @var Connection $connection */
        $connection = $this->svcClass(Connection::class);

        $connection->executeStatement(
            'DELETE FROM `migration` WHERE `class` LIKE :curbstone OR `class` LIKE :vaulted_shopper;',
            ['curbstone' => '%curbstonePayment%', 'vaulted_shopper' => '%VaultedShopper%']
        );

        /** @var string|false $mailTypeId */
        $mailTypeId = $connection->fetchOne(
            'SELECT `id` FROM `mail_template_type` WHERE `technical_name` LIKE :payment_link',
            ['payment_link' => '%admin.payment.link%']
        );

        /** @var string|false $mailTemplateId */
        $mailTemplateId = $connection->fetchOne(
            'SELECT `id` FROM `mail_template` WHERE `mail_template_type_id` = :template_type_id',
            ['template_type_id' => $mailTypeId]
        );

        $connection->executeStatement(
            'DELETE FROM `mail_template_translation` WHERE `mail_template_id` = :template_id',
            ['template_id' => $mailTemplateId]
        );
        $connection->executeStatement(
            'DELETE FROM `mail_template` WHERE `mail_template_type_id` = :template_type_id',
            ['template_type_id' => $mailTypeId]
        );
        $connection->executeStatement(
            'DELETE FROM `mail_template_type` WHERE `technical_name` LIKE :payment_link',
            ['payment_link' => '%admin.payment.link%']
        );
        $connection->executeStatement(
            'DELETE FROM `mail_template_type_translation` WHERE `name` LIKE :payment_name',
            ['payment_name' => '%Admin Payment Link%']
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function svcClass(string $id): object
    {
        $c = $this->containerStrict();
        /** @var T $svc */
        $svc = $c->get($id);
        return $svc;
    }

    private function svcId(string $id): object
    {
        $c = $this->containerStrict();
        return $c->get($id);
    }
    private function containerStrict(): ContainerInterface
    {
        $c = $this->container;
        if (!$c instanceof ContainerInterface) {
            throw new \RuntimeException('Service container is not initialized on plugin.');
        }
        return $c;
    }
}
