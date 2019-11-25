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
use Neos\Flow\Http\Uri;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Exception\AssetVariantGeneratorException;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Psr\Log\LoggerInterface;

/**
 * An asset service that handles for example commands on assets, retrieves information
 * about usage of assets and rendering thumbnails.
 *
 * @Flow\Scope("singleton")
 */
class AssetService extends \Neos\Media\Domain\Service\AssetService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var AssetVariantGenerator
     */
    protected $assetVariantGenerator;

    /**
     * Replace resource on an asset. Takes variants and redirect handling into account.
     *
     * @param AssetInterface $asset
     * @param PersistentResource $resource
     * @param array $options
     * @return void
     * @throws PropertyNotAccessibleException
     */
    public function replaceAssetResource(AssetInterface $asset, PersistentResource $resource, array $options = []): void
    {
        $originalAssetResource = $asset->getResource();
        $asset->setResource($resource);

        if (isset($options['keepOriginalFilename']) && (boolean)$options['keepOriginalFilename'] === true) {
            $asset->getResource()->setFilename($originalAssetResource->getFilename());
        }

        $uriMapping = [];
        $redirectHandlerEnabled = isset($options['generateRedirects']) && (boolean)$options['generateRedirects'] === true && $this->packageManager->isPackageAvailable('Neos.RedirectHandler');
        if ($redirectHandlerEnabled) {
            $originalAssetResourceUri = new Uri($this->resourceManager->getPublicPersistentResourceUri($originalAssetResource));
            $newAssetResourceUri = new Uri($this->resourceManager->getPublicPersistentResourceUri($asset->getResource()));
            $uriMapping[$originalAssetResourceUri->getPath()] = $newAssetResourceUri->getPath();
        }

        if (method_exists($asset, 'getVariants')) {
            $variants = $asset->getVariants();
            /** @var AssetVariantInterface $variant */
            foreach ($variants as $variant) {
                $originalVariantResource = $variant->getResource();
                try {
                    $presetIdentifier = $variant->getPresetIdentifier();
                    $variantName = $variant->getPresetVariantName();
                    $variant = $this->assetVariantGenerator->recreateVariant($asset, $presetIdentifier, $variantName);
                    if ($variant === null) {
                        $this->logger->debug(
                            sprintf('No variant returned when recreating asset variant %s::%s for %s', $presetIdentifier, $variantName, $asset->getTitle()),
                            LogEnvironment::fromMethodName(__METHOD__)
                        );
                        continue;
                    }

                    if ($redirectHandlerEnabled) {
                        $originalVariantResourceUri = new Uri($this->resourceManager->getPublicPersistentResourceUri($originalVariantResource));
                        $newVariantResourceUri = new Uri($this->resourceManager->getPublicPersistentResourceUri($variant->getResource()));
                        $uriMapping[$originalVariantResourceUri->getPath()] = $newVariantResourceUri->getPath();
                    }
                } catch (AssetVariantGeneratorException $exception) {
                    $this->logger->error(
                        sprintf('Error when recreating asset variant: %s', $exception->getMessage()),
                        LogEnvironment::fromMethodName(__METHOD__)
                    );
                }
            }
        }

        if ($redirectHandlerEnabled) {
            /** @var RedirectStorageInterface $redirectStorage */
            $redirectStorage = $this->objectManager->get(RedirectStorageInterface::class);
            foreach ($uriMapping as $originalUri => $newUri) {
                $existingRedirect = $redirectStorage->getOneBySourceUriPathAndHost($originalUri);
                if ($existingRedirect === null && $originalUri !== $newUri) {
                    $redirectStorage->addRedirect($originalUri, $newUri, 301);
                }
            }
        }

        $this->getRepository($asset)->update($asset);
        $this->emitAssetResourceReplaced($asset);
        $this->logger->info(
            sprintf('Replaced asset resource: %s', $asset->getTitle()),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }
}
