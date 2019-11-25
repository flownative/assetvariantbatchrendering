<?php
declare(strict_types=1);

namespace Flownative\AssetVariantBatchRendering\Domain\Service;

/*
 * This file is part of the Flownative.AssetVariantBatchRendering package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 * (c) Karsten Dambekalns
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Adjustment\ImageAdjustmentInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\ValueObject\Configuration;
use Neos\Media\Domain\ValueObject\Configuration\VariantPreset;
use Neos\Media\Exception\AssetVariantGeneratorException;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;

/**
 * @Flow\Scope("singleton")
 */
class AssetVariantGenerator extends \Neos\Media\Domain\Service\AssetVariantGenerator
{
    /**
     * @param AssetInterface $asset
     * @param string $presetIdentifier
     * @param string $variantIdentifier
     * @return AssetVariantInterface The created variant (if any)
     * @throws AssetVariantGeneratorException
     */
    public function createOneVariant(AssetInterface $asset, string $presetIdentifier, string $variantIdentifier): ?AssetVariantInterface
    {
        // Currently only Image Variants are supported. Other asset classes can be supported, as soon as there is a common
        // interface for creating and adding variants.
        if (!$asset instanceof Image) {
            return null;
        }

        $createdVariant = null;
        $preset = $this->getVariantPresets()[$presetIdentifier] ?? null;
        if ($preset instanceof VariantPreset && $preset->matchesMediaType($asset->getMediaType())) {
            $variantConfiguration = $preset->variants()[$variantIdentifier] ?? null;

            if ($variantConfiguration instanceof Configuration\Variant) {
                $createdVariant = $this->renderVariant($asset, $presetIdentifier, $variantConfiguration);
                // for the time being only ImageVariant is possible
                assert($createdVariant instanceof ImageVariant);
                $asset->addVariant($createdVariant);
            }
        }

        return $createdVariant;
    }

    /**
     * @param AssetInterface $asset
     * @param string $presetIdentifier
     * @param string $variantIdentifier
     * @return AssetVariantInterface The created variant (if any)
     * @throws AssetVariantGeneratorException
     * @throws PropertyNotAccessibleException
     */
    public function recreateVariant(AssetInterface $asset, string $presetIdentifier, string $variantIdentifier): ?AssetVariantInterface
    {
        // Currently only Image Variants are supported. Other asset classes can be supported, as soon as there is a common
        // interface for creating and adding variants.
        if (!$asset instanceof Image) {
            return null;
        }

        $createdVariant = null;
        $preset = $this->getVariantPresets()[$presetIdentifier] ?? null;
        if ($preset instanceof VariantPreset && $preset->matchesMediaType($asset->getMediaType())) {
            $variantConfiguration = $preset->variants()[$variantIdentifier] ?? null;

            if ($variantConfiguration instanceof Configuration\Variant) {
                $createdVariant = $this->renderVariant($asset, $presetIdentifier, $variantConfiguration);
                // for the time being only ImageVariant is possible
                assert($createdVariant instanceof ImageVariant);
                $this->replaceVariant($createdVariant, $asset);
            }
        }

        return $createdVariant;
    }

    /**
     * @param AssetInterface $originalAsset
     * @param string $presetIdentifier
     * @param Configuration\Variant $variantConfiguration
     * @return AssetVariantInterface
     * @throws AssetVariantGeneratorException
     */
    protected function renderVariant(AssetInterface $originalAsset, string $presetIdentifier, Configuration\Variant $variantConfiguration): AssetVariantInterface
    {
        $adjustments = [];
        foreach ($variantConfiguration->adjustments() as $adjustmentConfiguration) {
            assert($adjustmentConfiguration instanceof Configuration\Adjustment);
            $adjustmentClassName = $adjustmentConfiguration->type();
            if (!class_exists($adjustmentClassName)) {
                throw new AssetVariantGeneratorException(sprintf('Unknown image variant adjustment type "%s".', $adjustmentClassName), 1548066841);
            }
            $adjustment = new $adjustmentClassName();
            if (!$adjustment instanceof ImageAdjustmentInterface) {
                throw new AssetVariantGeneratorException(sprintf('Image variant adjustment "%s" does not implement "%s".', $adjustmentClassName, ImageAdjustmentInterface::class), 1548071529);
            }
            foreach ($adjustmentConfiguration->options() as $key => $value) {
                ObjectAccess::setProperty($adjustment, $key, $value);
            }
            $adjustments[] = $adjustment;
        }

        $assetVariant = $this->createAssetVariant($originalAsset);
        $assetVariant->setPresetIdentifier($presetIdentifier);
        $assetVariant->setPresetVariantName($variantConfiguration->identifier());

        try {
            foreach ($adjustments as $adjustment) {
                $assetVariant->addAdjustment($adjustment);
            }
        } catch (\Throwable $exception) {
            throw new AssetVariantGeneratorException('Error when adding adjustments to asset', 1570022741, $exception);
        }

        return $assetVariant;
    }

    /**
     * @param AssetInterface $asset
     * @return AssetVariantInterface
     * @throws AssetVariantGeneratorException
     */
    protected function createAssetVariant(AssetInterface $asset): AssetVariantInterface
    {
        if ($asset instanceof Image) {
            return new ImageVariant($asset);
        }

        throw new AssetVariantGeneratorException('Only Image assets are supported so far', 1570023645);
    }

    /**
     * Replace a variant of this image, based on preset identifier and preset variant name.
     *
     * If the variant is not based on a preset, it is simply added.
     *
     * @param ImageVariant $variant The new variant to replace an existing one
     * @param AssetInterface $asset
     * @return void
     * @throws PropertyNotAccessibleException
     */
    protected function replaceVariant(ImageVariant $variant, AssetInterface $asset): void
    {
        if ($variant->getOriginalAsset() !== $asset) {
            throw new \InvalidArgumentException('Could not add the given ImageVariant to the list of the Asset\'s variants because the variant refers to a different original asset.', 1574695592);
        }

        $existingVariant = $this->getVariant($variant->getPresetIdentifier(), $variant->getPresetVariantName(), $asset);
        if ($existingVariant instanceof AssetVariantInterface) {
            $variants = ObjectAccess::getProperty($asset, 'variants', true);
            $variants->removeElement($existingVariant);
        }
        $asset->addVariant($variant);
    }

    /**
     * Returns the variant identified by $presetIdentifier and $presetVariantName (if existing)
     *
     * @param string $presetIdentifier
     * @param string $presetVariantName
     * @param AssetInterface $asset
     * @return AssetVariantInterface|ImageVariant
     */
    protected function getVariant(string $presetIdentifier, string $presetVariantName, AssetInterface $asset): ?AssetVariantInterface
    {
        if ($asset->getVariants() === []) {
            return null;
        }

        $filtered = array_filter(
            $asset->getVariants(),
            static function (AssetVariantInterface $variant) use ($presetIdentifier, $presetVariantName) {
                return ($variant->getPresetIdentifier() === $presetIdentifier && $variant->getPresetVariantName() === $presetVariantName);
            }
        );

        return $filtered === [] ? null : reset($filtered);
    }

}
