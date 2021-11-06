<?php
use Sylius\Bundle\GridBundle\Grid\AbstractGrid;
use Sylius\Bundle\GridBundle\Builder\Filter\Filter;
use Sylius\Bundle\GridBundle\Builder\Field\Field;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Config\GridConfig;
use Sylius\Bundle\GridBundle\Builder\GridBuilder;
use Sylius\Bundle\GridBundle\Builder\Action\Action;
use Sylius\Bundle\GridBundle\Builder\Action\ShowAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Field\DateTimeField;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Field\TwigField;
class SyliusAdminOrder extends AbstractGrid
{
    public static function getName() : string
    {
        return 'sylius_admin_order';
    }
    public static function getResourceClass() : string
    {
        return '%sylius.model.order.class%';
    }
    public function buildGrid(GridBuilderInterface $gridBuilder) : void
    {
        $gridBuilder->setDriver('doctrine/orm')->setRepositoryMethod('myCustomMethod', ['id' => 'resource.id'])->setDriverOption('class', '%sylius.model.order.class%')->setDriverOption('pagination', ['fetch_join_collection' => false, 'use_output_walkers' => false])->addOrderBy('number', 'desc')->setLimits([30, 12, 48])->addField(DateTimeField::create('date')->setEnabled(false)->setLabel('sylius.ui.date')->setPosition(100)->setPath('checkoutCompletedAt')->setSortable(true, 'checkoutCompletedAt')->setOptions(['format' => 'd-m-Y H:i:s']))->addField(TwigField::create('number', '@SyliusAdmin/Order/Grid/Field/number.html.twig')->setLabel('sylius.ui.number')->setPath('.')->setSortable(true)->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/number.html.twig']))->addField(TwigField::create('channel', '@SyliusAdmin/Order/Grid/Field/channel.html.twig')->setLabel('sylius.ui.channel')->setSortable(true, 'channel.code')->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/channel.html.twig']))->addField(TwigField::create('customer', '@SyliusAdmin/Order/Grid/Field/customer.html.twig')->setLabel('sylius.ui.customer')->setSortable(true, 'customer.lastName')->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/customer.html.twig']))->addField(TwigField::create('state', '@SyliusUi/Grid/Field/state.html.twig')->setLabel('sylius.ui.state')->setSortable(true)->setOptions(['template' => '@SyliusUi/Grid/Field/state.html.twig', 'vars' => ['labels' => '@SyliusAdmin/Order/Label/State']]))->addField(TwigField::create('paymentState', '@SyliusUi/Grid/Field/state.html.twig')->setLabel('sylius.ui.payment_state')->setSortable(true)->setOptions(['template' => '@SyliusUi/Grid/Field/state.html.twig', 'vars' => ['labels' => '@SyliusAdmin/Order/Label/PaymentState']]))->addField(TwigField::create('shippingState', '@SyliusUi/Grid/Field/state.html.twig')->setLabel('sylius.ui.shipping_state')->setSortable(true)->setOptions(['template' => '@SyliusUi/Grid/Field/state.html.twig', 'vars' => ['labels' => '@SyliusAdmin/Order/Label/ShippingState']]))->addField(TwigField::create('total', '@SyliusAdmin/Order/Grid/Field/total.html.twig')->setLabel('sylius.ui.total')->setPath('.')->setSortable(true, 'total')->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/total.html.twig']))->addField(StringField::create('currencyCode')->setLabel('sylius.ui.currency')->setSortable(true))->addFilter(Filter::create('number', 'string')->setLabel('sylius.ui.number'))->addFilter(Filter::create('customer', 'string')->setLabel('sylius.ui.customer')->setOptions(['fields' => ['customer.email', 'customer.firstName', 'customer.lastName']]))->addFilter(Filter::create('date', 'date')->setLabel('sylius.ui.date')->setOptions(['field' => 'checkoutCompletedAt', 'inclusive_to' => true]))->addFilter(Filter::create('channel', 'entity')->setLabel('sylius.ui.channel')->setFormOptions(['class' => '%sylius.model.channel.class%']))->addFilter(Filter::create('total', 'money')->setLabel('sylius.ui.total')->setOptions(['currency_field' => 'currencyCode']))->addFilter(Filter::create('shipping_method', 'entity')->setLabel('sylius.ui.shipping_method')->setOptions(['fields' => ['shipments.method']])->setFormOptions(['class' => '%sylius.model.shipping_method.class%']))->addActionGroup(ItemActionGroup::create(ShowAction::create()));
    }
}
