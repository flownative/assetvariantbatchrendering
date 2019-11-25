<?php
declare(strict_types=1);

namespace Flownative\AssetVariantBatchRendering\Command;

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

use Doctrine\ORM\NonUniqueResultException;
use Flownative\AssetVariantBatchRendering\Domain\Repository\ImageRepository;
use Flownative\AssetVariantBatchRendering\Domain\Service\AssetVariantGenerator;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Exception\AssetVariantGeneratorException;
use Neos\Utility\Exception\PropertyNotAccessibleException;

/**
 * @Flow\Scope("singleton")
 */
class MediaCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetVariantGenerator
     */
    protected $assetVariantGenerator;

    /**
     * @Flow\Inject
     * @var ImageRepository
     */
    protected $imageRepository;

    /**
     * Render image variants
     *
     * Loops over missing configured image variants and renders them. Optional ``limit`` parameter to
     * limit the amount of variants to be rendered to avoid memory exhaustion.
     *
     * If the re-render parameter is given, any existing variants will be rendered again, too.
     *
     * @param integer $limit Limit the amount of variants to be rendered to avoid memory exhaustion
     * @param bool $quiet If set, only errors will be displayed.
     * @param bool $recreate
     * @return void
     * @throws AssetVariantGeneratorException
     * @throws IllegalObjectTypeException
     * @throws NonUniqueResultException
     * @throws PropertyNotAccessibleException
     */
    public function renderVariantsCommand($limit = null, bool $quiet = false, bool $recreate = false): void
    {
        $resultMessage = null;
        $generatedVariants = 0;
        $configuredVariantsCount = 0;
        $configuredPresets = $this->assetVariantGenerator->getVariantPresets();
        foreach ($configuredPresets as $configuredPreset) {
            $configuredVariantsCount += count($configuredPreset->variants());
        }

        $assetCount = $this->imageRepository->countAll();
        $variantCount = $configuredVariantsCount * $assetCount;

        !$quiet && $this->outputLine('Checking up to %u variants for %s for existence…', [$variantCount, Image::class]);
        !$quiet && $this->output->progressStart($variantCount);

        $currentAsset = null;
        /** @var AssetInterface $currentAsset */
        foreach ($this->imageRepository->findAssetIdentifiersWithVariants() as $assetIdentifier => $assetVariants) {
            foreach ($configuredPresets as $presetIdentifier => $preset) {
                foreach ($preset->variants() as $presetVariantName => $presetVariant) {
                    if ($recreate || !isset($assetVariants[$presetIdentifier][$presetVariantName])) {
                        $currentAsset = $this->imageRepository->findByIdentifier($assetIdentifier);
                        $createdVariant = $recreate ? $this->assetVariantGenerator->recreateVariant($currentAsset, $presetIdentifier, $presetVariantName) : $this->assetVariantGenerator->createOneVariant($currentAsset, $presetIdentifier, $presetVariantName);
                        if ($createdVariant !== null) {
                            $this->imageRepository->update($currentAsset);
                            if (++$generatedVariants % 10 === 0) {
                                $this->persistenceManager->persistAll();
                            }
                            if ($generatedVariants === $limit) {
                                $resultMessage = sprintf('Generated %u variants, exiting after reaching limit', $limit);
                                !$quiet && $this->output->progressFinish();
                                break 3;
                            }
                        }
                    }
                    !$quiet && $this->output->progressAdvance(1);
                }
            }
        }
        !$quiet && $this->output->progressFinish();

        !$quiet && $this->outputLine();
        !$quiet && $this->outputLine($resultMessage ?? sprintf('Generated %u variants', $generatedVariants));
    }
}
