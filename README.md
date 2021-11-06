# Configuration transformer for the Sylius Grid Bundle
Converts a file to the new PHP Syntax.

## How to use:
* Clone the repository
* `composer install`
* `bin/config-converter <path> [namespace] [--functional]`

This will print the generated code out to the screen and produce a file that contains the new configuration.

## Examples
```yaml
# order_short.yml
sylius_grid:
  grids:
    sylius_admin_order:
      limits: [30, 12, 48]
      driver:
        name: doctrine/orm
        options:
          class: "%sylius.model.order.class%"
          repository:
            method: createListQueryBuilder
      sorting:
        number: desc
      fields:
        date:
          type: datetime
          label: sylius.ui.date
          path: checkoutCompletedAt
          sortable: checkoutCompletedAt
          options:
            format: d-m-Y H:i:s
        number:
          type: twig
          label: sylius.ui.number
          path: .
          sortable: ~
          options:
            template: "@SyliusAdmin/Order/Grid/Field/number.html.twig"
      filters:
        number:
          type: string
          label: sylius.ui.number
        shipping_method:
          type: entity
          label: sylius.ui.shipping_method
          options:
            fields: [shipments.method]
          form_options:
            class: "%sylius.model.shipping_method.class%"
      actions:
        item:
          show:
            type: show
```

Will be converted to:
```php
<?php
// order_short.php
use Sylius\Bundle\GridBundle\AbstractGrid;
use Sylius\Bundle\GridBundle\Builder\Field;
use Sylius\Bundle\GridBundle\Builder\Filter;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Builder\Field\DateTimeField;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Field\TwigFiel;
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
        $gridBuilder->setRepositoryMethod('createListQueryBuilder');
        $gridBuilder->addOrderBy('number', 'desc');
        $gridBuilder->setLimits([30, 12, 48]);
        $gridBuilder->addField(DateTimeField::create('date')->addLabel('sylius.ui.date')->setPath('checkoutCompletedAt')->setSortable(true, 'checkoutCompletedAt')->setOptions(['format' => 'd-m-Y H:i:s']));
        $gridBuilder->addField(TwigField::create('number')->addLabel('sylius.ui.number')->setPath('.')->setSortable(true)->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/number.html.twig']));
        $gridBuilder->addField(TwigField::create('channel')->addLabel('sylius.ui.channel')->setSortable(true, 'channel.code')->setOptions(['template' => '@SyliusAdmin/Order/Grid/Field/channel.html.twig']));
        $gridBuilder->addField(Filter::fromNameAndType('number', 'string')->addLabel('sylius.ui.number'));
        $gridBuilder->addField(Filter::fromNameAndType('shipping_method', 'entity')->addLabel('sylius.ui.shipping_method')->setOptions(['fields' => ['0' => 'shipments.method']])->setFormOptions(['class' => '%sylius.model.shipping_method.class%']));
        $gridBuilder->addActionGroup(ItemActionGroup::create(ShowAction::create()));
    }
}
```

## Todo
* Check if the output even works.
* Check to see if there are options that are unhandled. (try to convert more grids)
* See if the output of the yaml and the php produces the same grid array after being parsed by Sylius
* Maybe try to optimize the code. Currently, it generates a lot of extra use statements
