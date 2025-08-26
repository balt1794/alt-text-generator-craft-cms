<?php

namespace bryamloaiza\alttextgenerator\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use yii\web\Response;
use bryamloaiza\alttextgenerator\AltTextGenerator;

/**
 * Generate Controller
 */
class GenerateController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Generate AI alt text for a single asset
     *
     * @return Response
     */
    public function actionSingleAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $assetId = $this->request->getRequiredBodyParam('assetId');
        $siteId = $this->request->getRequiredBodyParam('siteId');

        // Get the asset
        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('alt-text-generator', 'Asset not found'),
            ]);
        }

        // Check permissions
        $this->requirePermission('saveAssets:' . $asset->getVolume()->uid);

        try {
            // Instead of queuing a job, directly update the alt text
            $asset->alt = 'Image of ' . pathinfo($asset->filename, PATHINFO_FILENAME);
            
            // Save the asset
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new \Exception('Could not save asset: ' . implode(', ', $asset->getFirstErrors()));
            }

            // Return success
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('alt-text-generator', 'Alt text has been generated'),
            ]);
        } catch (\Exception $e) {
            Craft::error('Error generating alt text: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate AI alt text for assets without alt text
     *
     * @return Response
     */
    public function actionGenerateAssetsWithoutAltText(): Response
    {
        // Require permissions to save assets
        $this->requirePermission('accessCp');
        
        $totalCount = 0;
        $processedCount = 0;
        $queuedCount = 0;
        $settings = AiAltText::getInstance()->getSettings();
        
        // Check if a specific site ID was provided
        $siteId = $this->request->getParam('siteId');
        
        // If site ID was provided, only process that site
        if ($siteId) {
            $sites = [Craft::$app->getSites()->getSiteById($siteId)];
            if (!$sites[0]) {
                Craft::$app->getSession()->setError(
                    Craft::t('alt-text-generator', 'Invalid site ID: {siteId}', ['siteId' => $siteId])
                );
                return $this->redirect('settings/plugins/alt-text-generator');
            }
        } else {
            // Otherwise process all sites
            $sites = Craft::$app->getSites()->getAllSites();
        }
        
        try {
            // First, count how many assets we need to process
            foreach ($sites as $site) {
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->andWhere(['or', 
                        ['alt' => null],
                        ['alt' => '']
                    ])
                    ->count();
                
                $totalCount += $assets;
            }
            
            Craft::info('Total assets without alt text: ' . $totalCount, __METHOD__);
            
            // Now process each site's assets
            foreach ($sites as $site) {
                // Process each site
                Craft::info('Processing assets for site: ' . $site->name . ' (ID: ' . $site->id . ')', __METHOD__);
                
                // Find all image assets without alt text for this site
                // Process in batches to avoid memory issues
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                while ($hasMore) {
                    $assets = Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->andWhere(['or', 
                            ['alt' => null],
                            ['alt' => '']
                        ])
                        ->offset($offset)
                        ->limit($limit)
                        ->all();
                    
                    $batchSize = count($assets);
                    $processedCount += $batchSize;
                    
                    if ($batchSize === 0) {
                        $hasMore = false;
                        continue;
                    }
                    
                    Craft::info("Processing batch of {$batchSize} assets for site {$site->name} (offset: {$offset})", __METHOD__);
                    
                    foreach ($assets as $asset) {
                        // Double-check that the asset doesn't have alt text (just in case)
                        if (!empty($asset->alt)) {
                            Craft::info('Skipping asset ' . $asset->id . ' because it already has alt text: ' . $asset->alt, __METHOD__);
                            continue;
                        }
                        
                        try {
                            // Log which asset we're queuing
                            Craft::info('Queuing alt text generation for asset: ' . $asset->id . ' (' . $asset->filename . ') in site ' . $site->name, __METHOD__);
                            
                            // Create a job for the asset
                            AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $site->id, false, true, true);
                            $queuedCount++;
                        } catch (\Exception $e) {
                            Craft::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
                        }
                    }
                    
                    // Move to next batch
                    $offset += $limit;
                    
                    // Prevent PHP from timing out
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Set flash message
            if ($siteId) {
                $siteName = $sites[0]->name;
                Craft::$app->getSession()->setNotice(
                    Craft::t('alt-text-generator', 'Queued alt text generation for {count} assets in site {site}', [
                        'count' => $queuedCount,
                        'site' => $siteName
                    ])
                );
            } else {
                Craft::$app->getSession()->setNotice(
                    Craft::t('alt-text-generator', 'Queued alt text generation for {count} assets across all sites', [
                        'count' => $queuedCount,
                    ])
                );
            }
            
            // Redirect back to settings page
            return $this->redirect('settings/plugins/alt-text-generator');
        } catch (\Exception $e) {
            Craft::error('Error queueing alt text generation for assets without alt text: ' . $e->getMessage(), __METHOD__);
            
            Craft::$app->getSession()->setError(
                Craft::t('alt-text-generator', 'Error: {message}', ['message' => $e->getMessage()])
            );
            
            return $this->redirect('settings/plugins/alt-text-generator');
        }
    }

    /**
     * Generate AI alt text for ALL assets
     *
     * @return Response
     */
    public function actionGenerateAllAssets(): Response
    {
        // Require permissions to save assets
        $this->requirePermission('accessCp');
        
        $totalCount = 0;
        $processedCount = 0;
        $queuedCount = 0;
        $settings = AiAltText::getInstance()->getSettings();
        
        // Check if a specific site ID was provided
        $siteId = $this->request->getParam('siteId');
        
        // If site ID was provided, only process that site
        if ($siteId) {
            $sites = [Craft::$app->getSites()->getSiteById($siteId)];
            if (!$sites[0]) {
                Craft::$app->getSession()->setError(
                    Craft::t('alt-text-generator', 'Invalid site ID: {siteId}', ['siteId' => $siteId])
                );
                return $this->redirect('settings/plugins/alt-text-generator');
            }
        } else {
            // Otherwise process all sites
            $sites = Craft::$app->getSites()->getAllSites();
        }
        
        try {
            // First, count how many assets we need to process
            foreach ($sites as $site) {
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->count();
                
                $totalCount += $assets;
            }
            
            Craft::info('Total image assets: ' . $totalCount, __METHOD__);
            
            // Now process each site's assets
            foreach ($sites as $site) {
                // Process each site
                Craft::info('Processing all assets for site: ' . $site->name . ' (ID: ' . $site->id . ')', __METHOD__);
                
                // Find all image assets for this site
                // Process in batches to avoid memory issues
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                while ($hasMore) {
                    $assets = Asset::find()
                        ->kind(Asset::KIND_IMAGE)
                        ->siteId($site->id)
                        ->offset($offset)
                        ->limit($limit)
                        ->all();
                    
                    $batchSize = count($assets);
                    $processedCount += $batchSize;
                    
                    if ($batchSize === 0) {
                        $hasMore = false;
                        continue;
                    }
                    
                    Craft::info("Processing batch of {$batchSize} assets for site {$site->name} (offset: {$offset})", __METHOD__);
                    
                    foreach ($assets as $asset) {
                        try {
                            // Log which asset we're queuing
                            Craft::info('Queuing alt text generation for asset: ' . $asset->id . ' (' . $asset->filename . ') in site ' . $site->name, __METHOD__);
                            
                            // Set force regeneration to true to regenerate all assets
                            AiAltText::getInstance()->aiAltTextService->createJob($asset, false, $site->id, false, true, true);
                            $queuedCount++;
                        } catch (\Exception $e) {
                            Craft::error('Error queuing job for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
                        }
                    }
                    
                    // Move to next batch
                    $offset += $limit;
                    
                    // Prevent PHP from timing out
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Set flash message
            if ($siteId) {
                $siteName = $sites[0]->name;
                Craft::$app->getSession()->setNotice(
                    Craft::t('alt-text-generator', 'Queued alt text generation for {count} assets in site {site}.', [
                        'count' => $queuedCount,
                        'site' => $siteName
                    ])
                );
            } else {
                Craft::$app->getSession()->setNotice(
                    Craft::t('alt-text-generator', 'Queued alt text generation for {count} assets across all sites.', [
                        'count' => $queuedCount,
                    ])
                );
            }
            
            // Redirect back to settings page
            return $this->redirect('settings/plugins/alt-text-generator');
        } catch (\Exception $e) {
            Craft::error('Error queueing alt text generation for all assets: ' . $e->getMessage(), __METHOD__);
            
            Craft::$app->getSession()->setError(
                Craft::t('alt-text-generator', 'Error: {message}', ['message' => $e->getMessage()])
            );
            
            return $this->redirect('settings/plugins/alt-text-generator');
        }
    }
}
