# Configuration transformer for the Sylius Grid Bundle
Converts a yaml configuration file from the [SyliusGridBundle](https://github.com/Sylius/SyliusGridBundle) to the new PHP syntax.

## How to use:
* Clone the repository
* `composer install`
* `bin/config-converter <path> [namespace] [--functional] [--mutator] [--output-directory <path>] [-q]`

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
            ->withFields(
                DateTimeField::create('date')
                ->setLabel('sylius.ui.date')
                ->setPath('checkoutCompletedAt')
                ->setSortable(true, 'checkoutCompletedAt')
                ->addOptions([
                    'format' => 'd-m-Y H:i:s',
                ]),
                TwigField::create('number', '@SyliusAdmin/Order/Grid/Field/number.html.twig')
                ->setLabel('sylius.ui.number')
                ->setPath('.')
                ->setSortable(true)
            )
            ->withFilters(
                Filter::create('number', 'string')
                ->setLabel('sylius.ui.number'),
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

## Grid mutators

Use the `--mutator` flag to generate a [grid mutator](https://github.com/Sylius/SyliusGridBundle/blob/1.16/tests/Application/src/BoardGameBlog/Infrastructure/Sylius/Grid/Mutator/SortByNameBookGridMutator.php) class instead of a grid builder. This is useful when you need to override specific parts of an existing grid (e.g., disabling a field).

```yaml
# mutator_grid.yaml
sylius_grid:
    grids:
        sylius_admin_product:
            fields:
                image:
                    enabled: false
```

```bash
bin/config-converter mutator_grid.yaml "App\Grid\Mutator" --mutator
```

Will generate:

```php
<?php
declare (strict_types=1);
namespace App\Grid\Mutator;

use Sylius\Component\Grid\Attribute\AsGridMutator;
use Sylius\Component\Grid\Mutator\GridMutatorInterface;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
#[AsGridMutator(grid: 'sylius_admin_product')]
class SyliusAdminProductGridMutator implements GridMutatorInterface
{
    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->removeField('image')
        ;
    }
}
```

## Todo

- [x] Check if the output even works.
- [x] Check to see if there are options that are unhandled. (try to convert more grids)
- [x] See if the output of the yaml and the php produces the same grid array after being parsed by Sylius
- [ ] Maybe try to optimize the code. Currently, it generates a lot of extra use statements
- [x] Add an option to convert in a grid mutator
