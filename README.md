# Configuration transformer for the Sylius Grid Bundle
Converts a yaml configuration file from the [SyliusGridBundle](https://github.com/Sylius/SyliusGridBundle) to the new PHP syntax.

## How to use:
* Clone the repository
* `composer install`
* `bin/config-converter <path> [namespace] [--functional] [--output-directory <path>] [-q]`

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
declare (strict_types=1);
use Sylius\Component\Grid\Attribute\AsGrid;
use Sylius\Bundle\GridBundle\Builder\Filter\Filter;
use Sylius\Bundle\GridBundle\Builder\Field\Field;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Config\GridConfig;
use Sylius\Bundle\GridBundle\Builder\GridBuilder;
use Sylius\Bundle\GridBundle\Builder\Action\Action;
use Sylius\Bundle\GridBundle\Builder\Action\ShowAction;
use Sylius\Bundle\GridBundle\Builder\Action\CreateAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Field\DateTimeField;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Field\TwigField;
#[AsGrid(name: 'sylius_admin_order', resourceClass: '%sylius.model.order.class%')]
class SyliusAdminOrder
{
    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->setRepositoryMethod('createListQueryBuilder')
            ->addOrderBy('number', 'desc')
            ->setLimits([
                30,
                12,
                48,
            ])
            ->addField(
                DateTimeField::create('date')
                ->setLabel('sylius.ui.date')
                ->setPath('checkoutCompletedAt')
                ->setSortable(true, 'checkoutCompletedAt')
                ->addOptions([
                    'format' => 'd-m-Y H:i:s',
                ])
            )
            ->addField(
                TwigField::create('number', '@SyliusAdmin/Order/Grid/Field/number.html.twig')
                ->setLabel('sylius.ui.number')
                ->setPath('.')
                ->setSortable(true)
            )
            ->addFilter(
                Filter::create('number', 'string')
                ->setLabel('sylius.ui.number')
            )
            ->addFilter(
                Filter::create('shipping_method', 'entity')
                ->setLabel('sylius.ui.shipping_method')
                ->setOptions([
                    'fields' => [
                        'shipments.method',
                    ],
                ])
                ->setFormOptions([
                    'class' => '%sylius.model.shipping_method.class%',
                ])
            )
            ->withItemActions(ShowAction::create())
        ;
    }
}
```

## Todo

- [x] Check if the output even works.
- [x] Check to see if there are options that are unhandled. (try to convert more grids)
- [x] See if the output of the yaml and the php produces the same grid array after being parsed by Sylius
- [ ] Maybe try to optimize the code. Currently, it generates a lot of extra use statements
- [ ] Add an option to convert in a grid mutator
