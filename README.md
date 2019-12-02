[![MIT license](http://img.shields.io/badge/license-GPL-brightgreen.svg)](https://opensource.org/licenses/GPL-3.0)
[![Packagist](https://img.shields.io/packagist/v/flownative/assetvariantbatchrendering.svg)](https://packagist.org/packages/flownative/assetvariantbatchrendering)
[![Maintenance level: Acquaintance](https://img.shields.io/badge/maintenance-%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Asset variant batch rendering

This package provides batch rendering of asset variants for Neos 4.3. The functionality is
[to be included in Neos 5.1](https://github.com/neos/neos-development-collection/pull/2751).

It provides the following functionality:

- (re-)render variants based on presets
- re-render variants when replacing an asset resource

## Installation

If you want to use this package, simply require it:

```bash
$ composer require 'flownative/assetvariantbatchrendering:1.*'
```

## Configuration

The package comes with no configurable settings itself. But you will need to configure asset
variant presets. Here is an example for a square preset:

```yaml
Neos:
  Media:
    variantPresets:
      'AcmeCom:Square':
        mediaTypePatterns: ['~image/.*~']
        variants:
          'square':
            label: 'Square'
            adjustments:
              'crop':
                type: 'Neos\Media\Domain\Model\Adjustment\CropImageAdjustment'
                options:
                  aspectRatio: '1:1'
```

See the [variant presets documentation](https://neos-media.readthedocs.io/en/stable/VariantPresets.html)
for details.
