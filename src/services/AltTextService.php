<?php

namespace bryamloaiza\alttextgenerator\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\events\DefineMenuItemsEvent;
use craft\enums\MenuItemType;

/**
 * Alt Text Service
 *
 * Main service class for generating alt text.
 * This service provides functionality to generate placeholder alt text for assets.
 */
class AltTextService extends Component
{
    /**
     * Generate alt text for an asset
     *
     * @param Asset $asset The asset to generate alt text for
     * @return bool Whether the operation was successful
     */
    public function generateAltText(Asset $asset): bool
    {
        try {
            $settings = \bryamloaiza\alttextgenerator\AltTextGenerator::getInstance()->getSettings();
            $apiKey = $settings->apiKey ?? '';
            $language = $settings->language ?? 'english';
            
            if (empty($apiKey)) {
                Craft::warning('API key not configured for automatic alt text generation', __METHOD__);
                return false;
            }
            
            // Get the asset URL for the API
            $assetUrl = $asset->getUrl();
            if (!$assetUrl) {
                Craft::warning('Could not get asset URL for: ' . $asset->filename, __METHOD__);
                return false;
            }
            
            // Make sure we have the full URL
            if (!parse_url($assetUrl, PHP_URL_HOST)) {
                $siteUrl = rtrim(Craft::$app->sites->currentSite->baseUrl, '/');
                $assetUrl = $siteUrl . $assetUrl;
            }
            
            // Convert image to base64 since local URLs aren't accessible to external APIs
            // Try multiple ways to get the image data
            $imageData = false;
            
            // Method 1: Try to get the physical file path
            try {
                $volume = $asset->getVolume();
                if ($volume && method_exists($volume, 'getRootPath')) {
                    $rootPath = $volume->getRootPath();
                    if ($rootPath) {
                        $filePath = $rootPath . DIRECTORY_SEPARATOR . $asset->getPath();
                        if (file_exists($filePath)) {
                            $imageData = file_get_contents($filePath);
                        }
                    }
                }
            } catch (\Exception $e) {
                Craft::warning('Could not get physical file path for: ' . $asset->filename . ' - ' . $e->getMessage(), __METHOD__);
            }
            
            // Method 2: Try transform source path
            if ($imageData === false) {
                try {
                    $sourcePath = $asset->getImageTransformSourcePath();
                    if ($sourcePath && file_exists($sourcePath)) {
                        $imageData = file_get_contents($sourcePath);
                    }
                } catch (\Exception $e) {
                    Craft::warning('Could not get transform source path for: ' . $asset->filename . ' - ' . $e->getMessage(), __METHOD__);
                }
            }
            
            // Method 3: Try URL (last resort for local files)
            if ($imageData === false) {
                $imageData = @file_get_contents($assetUrl);
            }
            
            if ($imageData === false) {
                Craft::warning('Could not read image data for: ' . $asset->filename . ' using any method', __METHOD__);
                return false;
            }
            
            $base64Image = 'data:' . $asset->getMimeType() . ';base64,' . base64_encode($imageData);
            
            // Call the AI API
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://alttextgeneratorai.com/api/craft');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'image' => $base64Image,
                'wpkey' => $apiKey,
                'language' => $language
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response !== false && $httpCode === 200) {
                $altText = trim($response);
                if (!empty($altText)) {
                    $asset->alt = $altText;
                    Craft::info('Generated AI alt text for: ' . $asset->filename . ' -> ' . $altText, __METHOD__);
                } else {
                    $asset->alt = 'Image of ' . pathinfo($asset->filename, PATHINFO_FILENAME);
                    Craft::warning('Empty response from AI API, using fallback for: ' . $asset->filename, __METHOD__);
                }
            } else {
                $asset->alt = 'Image of ' . pathinfo($asset->filename, PATHINFO_FILENAME);
                Craft::warning('AI API call failed (HTTP ' . $httpCode . '), using fallback for: ' . $asset->filename, __METHOD__);
            }
            
            // Save the asset
            return Craft::$app->elements->saveElement($asset);
        } catch (\Exception $e) {
            Craft::error('Error generating alt text: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Handle asset action menu items
     *
     * @param DefineMenuItemsEvent $event
     */
    public function handleAssetActionMenuItems(DefineMenuItemsEvent $event): void
    {
        $element = $event->sender;
        
        if (!($element instanceof Asset) || $element->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $event->items[] = [
            'type' => MenuItemType::Button,
            'label' => 'Generate Alt Text',
            'attributes' => [
                'data-action' => 'generate-alt-text',
                'data-asset-id' => $element->id,
            ],
            'data' => [
                'action' => 'alt-text-generator/generate/single-asset',
                'params' => [
                    'assetId' => $element->id,
                ],
            ],
        ];
    }
}
