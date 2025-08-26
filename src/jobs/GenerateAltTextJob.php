<?php

namespace bryamloaiza\alttextgenerator\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use bryamloaiza\alttextgenerator\AltTextGenerator;

/**
 * Generate Alt Text Job
 *
 * Queue job to generate alt text for assets in the background
 */
class GenerateAltTextJob extends BaseJob
{
    /**
     * @var int The asset ID to generate alt text for
     */
    public $assetId;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        try {
            // Get the asset
            $asset = Asset::find()->id($this->assetId)->one();
            
            if (!$asset) {
                Craft::warning("Asset with ID {$this->assetId} not found for alt text generation", __METHOD__);
                return;
            }

            // Check if asset is an image
            if ($asset->kind !== Asset::KIND_IMAGE) {
                Craft::warning("Asset {$asset->filename} is not an image, skipping alt text generation", __METHOD__);
                return;
            }

            // Check if alt text already exists
            if (!empty($asset->alt)) {
                Craft::info("Asset {$asset->filename} already has alt text, skipping generation", __METHOD__);
                return;
            }

            // Generate alt text using the service
            $plugin = AltTextGenerator::getInstance();
            $success = $plugin->altTextService->generateAltText($asset);

            if ($success) {
                Craft::info("Successfully generated alt text for asset: {$asset->filename}", __METHOD__);
            } else {
                Craft::warning("Failed to generate alt text for asset: {$asset->filename}", __METHOD__);
            }

        } catch (\Exception $e) {
            Craft::error("Error in GenerateAltTextJob: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('alt-text-generator', 'Generating alt text for asset');
    }
} 