<?php

namespace bryamloaiza\alttextgenerator;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\DefineMenuItemsEvent;
use craft\web\View;
use craft\web\UrlManager;
use bryamloaiza\alttextgenerator\elements\actions\GenerateAltText;
use bryamloaiza\alttextgenerator\services\AltTextService;
use bryamloaiza\alttextgenerator\models\Settings;
use yii\base\Event;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;

/**
 * Alt Text Generator Plugin
 *
 * A Craft CMS plugin that generates alt text for images.
 * This plugin provides functionality to automatically generate descriptive alt text
 * for images in the Craft CMS asset library.
 *
 * @property AltTextService $altTextService The service for generating alt text
 * @property Settings $settings The plugin settings
 */
class AltTextGenerator extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['altTextService' => AltTextService::class],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Register the service
        $this->setComponents([
            'aiAltTextService' => AiAltTextService::class,
        ]);

        // Register template path
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function($event) {
                $event->roots[$this->id] = $this->getBasePath() . '/templates';
            }
        );

        // Register controller routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['alt-text-generator/generate/single-asset'] = 'alt-text-generator/generate/single-asset';
                $event->rules['alt-text-generator/settings/verify-api'] = 'alt-text-generator/settings/verify-api';
                $event->rules['alt-text-generator/settings/get-settings'] = 'alt-text-generator/settings/get-settings';
            }
        );

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $event->actions[] = GenerateAltText::class;
            }
        );

        // The element action is already registered above via EVENT_REGISTER_ACTIONS
        // No need for additional menu items

        // Listen for asset creation/save events
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                // Get plugin instance
                $plugin = AltTextGenerator::getInstance();
                $settings = $plugin->getSettings();

                // Only process new assets that are images and if the setting is enabled
                if (
                    $event->isNew
                    && $asset->kind === Asset::KIND_IMAGE
                    && $settings->generateForNewAssets
                    && !empty($settings->apiKey) // Make sure API key is configured
                ) {
                    // Use a queue job to avoid blocking the save operation
                    Craft::$app->queue->push(new \bryamloaiza\alttextgenerator\jobs\GenerateAltTextJob([
                        'assetId' => $asset->id
                    ]));
                }
            }
        );

        // Add JavaScript to asset edit pages
        Event::on(
            \craft\web\View::class,
            \craft\web\View::EVENT_END_BODY,
            function(\yii\base\Event $event) {
                $request = Craft::$app->getRequest();
                if ($request->getIsCpRequest()) {
                    
                    // Get plugin settings
                    $settings = AltTextGenerator::getInstance()->getSettings();
                    $apiKey = $settings->apiKey ?? '';
                    $language = $settings->language ?? 'english';
                    
                    $js = <<<JS
// Embed plugin settings directly in JavaScript
window.AltTextGeneratorSettings = {
    apiKey: '$apiKey',
    language: '$language'
};
console.log('Alt Text Generator: Script loaded on', window.location.href);

// Try multiple selectors for the alt field
const altSelectors = ['textarea[name="alt"]', 'input[name="alt"]', '[name="alt"]'];
let altInput = null;
let altField = null;

for (const selector of altSelectors) {
    altInput = document.querySelector(selector);
    if (altInput) {
        console.log('Alt Text Generator: Found alt field with selector:', selector);
        altField = altInput.closest('.field');
        break;
    }
}

if (altInput && altField && !document.getElementById('generate-alt-btn')) {
    console.log('Alt Text Generator: Creating button');
    
    const button = document.createElement('button');
    button.id = 'generate-alt-btn';
    button.type = 'button';
    button.className = 'btn small';
    button.textContent = 'Generate Alt Text';
    button.style.marginTop = '10px';
    button.style.backgroundColor = '#3f4f5f';
    button.style.color = 'white';
    button.style.border = 'none';
    button.style.padding = '8px 12px';
    button.style.borderRadius = '3px';
    button.style.cursor = 'pointer';
    
         button.addEventListener('click', async function(e) {
         e.preventDefault();
         console.log('Alt Text Generator: Button clicked');
         
         // Disable button and show loading state
         button.disabled = true;
         button.textContent = 'Generating...';
         button.style.backgroundColor = '#6c757d';
         
         const titleField = document.querySelector('input[name="title"]');
         
         if (titleField && altInput) {
            try {
                // NEW LOGIC: Grab image URL from .preview-thumb img srcset
                let finalImageUrl = '';
                const previewThumbImg = document.querySelector('.preview-thumb img');

                if (previewThumbImg) {
                    // Grab the first URL from the srcset attribute
                    const srcset = previewThumbImg.getAttribute('srcset') || '';
                    const firstUrl = srcset.split(',')[0].trim().split(' ')[0]; // get first URL before width
                    const baseUrl = window.location.origin;
                    finalImageUrl = baseUrl + firstUrl;
                    console.log('Alt Text Generator: Using preview-thumb srcset URL:', finalImageUrl);
                } else {
                    console.log('Alt Text Generator: preview-thumb image not found, skipping URL assignment');
                }
                 console.log('Alt Text Generator: Making API call to generate alt text...');
                 console.log('Alt Text Generator: Image URL:', finalImageUrl);
                 
                 // Convert image to base64 since local URLs aren't accessible to external APIs
                 let base64Image = '';
                 try {
                     const imageResponse = await fetch(finalImageUrl);
                     if (!imageResponse.ok) {
                         throw new Error('Failed to fetch image');
                     }
                     const imageBlob = await imageResponse.blob();
                     const reader = new FileReader();
                     
                     base64Image = await new Promise((resolve, reject) => {
                         reader.onload = () => resolve(reader.result);
                         reader.onerror = reject;
                         reader.readAsDataURL(imageBlob);
                     });
                     
                     console.log('Alt Text Generator: Converted image to base64, length:', base64Image.length);
                 } catch (error) {
                     console.error('Alt Text Generator: Failed to convert image to base64:', error);
                     throw new Error('Could not load image for processing');
                 }
                 
                 // Get plugin settings from embedded JavaScript
                 console.log('Alt Text Generator: Loading settings...');
                 const settings = window.AltTextGeneratorSettings || {};
                 const apiKey = settings.apiKey || '';
                 const language = settings.language || 'english';
                 
                 console.log('Alt Text Generator: API key from settings:', apiKey ? 'Found (' + apiKey.length + ' chars)' : 'Not found');
                 console.log('Alt Text Generator: Language from settings:', language);
                 
                 if (!apiKey) {
                     throw new Error('Please configure your API key in the plugin settings. Go to Settings → Plugins → Alt Text Generator and enter your API key.');
                 }
                 
                 // Make API call to generate alt text with base64 image
                 const response = await fetch('https://alttextgeneratorai.com/api/craft', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                     },
                     body: JSON.stringify({
                         image: base64Image,
                         wpkey: apiKey,
                         language: language
                     })
                 });
                 
                 if (response.ok) {
                     const altText = await response.text();
                     console.log('Alt Text Generator: Received alt text:', altText);
                     
                     // Set the generated alt text
                     altInput.value = altText;
                     
                     // Trigger change events
                     altInput.dispatchEvent(new Event('input', { bubbles: true }));
                     altInput.dispatchEvent(new Event('change', { bubbles: true }));
                     
                     // Success state
                     button.textContent = 'Alt text generated!';
                     button.style.backgroundColor = '#28a745';
                     
                 } else {
                     console.error('Alt Text Generator: API call failed:', response.status, response.statusText);
                     const errorText = await response.text();
                     console.error('Alt Text Generator: Error response:', errorText);
                     
                     // Error state
                     button.textContent = 'Error - try again';
                     button.style.backgroundColor = '#dc3545';
                     altInput.value = 'Error generating alt text: ' + errorText;
                 }
                 
             } catch (error) {
                 console.error('Alt Text Generator: Error:', error);
                 button.textContent = 'Error - try again';
                 button.style.backgroundColor = '#dc3545';
                 altInput.value = 'Error generating alt text: ' + error.message;
             } finally {
                 // Reset button after 3 seconds
                 setTimeout(() => {
                     button.disabled = false;
                     button.textContent = 'Generate Alt Text';
                     button.style.backgroundColor = '#3f4f5f';
                 }, 3000);
             }
         } else {
             console.log('Alt Text Generator: Could not find title or alt field');
             button.disabled = false;
             button.textContent = 'Generate Alt Text';
             button.style.backgroundColor = '#3f4f5f';
         }
     });
    
    altField.appendChild(button);
    console.log('Alt Text Generator: Button added to page');
} else {
    console.log('Alt Text Generator: Alt field not found or button already exists');
    console.log('Alt input:', altInput);
    console.log('Alt field:', altField);
    console.log('Existing button:', document.getElementById('generate-alt-btn'));
}
JS;
                    
                    Craft::$app->getView()->registerJs($js);
                }
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        // Get current site
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $sites = Craft::$app->getSites()->getAllSites();
        
        // Initialize totals
        $totalAssetsWithAltTextForAllSites = 0;
        $totalAssetsWithoutAltTextForAllSites = 0;
        $siteAltTextCounts = [];
        
        // Manually count assets for each site to avoid problematic database queries
        foreach ($sites as $site) {
            $siteAltTextCounts[$site->id] = [
                'total' => 0,
                'with' => 0,
                'without' => 0
            ];

            try {
                // Use Craft's asset service to get all assets rather than direct SQL
                $assets = Asset::find()
                    ->kind(Asset::KIND_IMAGE)
                    ->siteId($site->id)
                    ->status(null)  // Get all assets regardless of status
                    ->all();
                
                $imageAssetCount = count($assets);
                $withAltCount = 0;
                $withoutAltCount = 0;
                
                // For each asset, check if it has alt text
                foreach ($assets as $asset) {
                    // Check alt text
                    $altText = $asset->alt;
                    if ($altText !== null && trim($altText) !== '') {
                        $withAltCount++;
                    } else {
                        $withoutAltCount++;
                    }
                }
                
                // Store counts for this site
                $siteAltTextCounts[$site->id] = [
                    'total' => $imageAssetCount,
                    'with' => $withAltCount,
                    'without' => $withoutAltCount
                ];
                
                // Add to totals
                $totalAssetsWithAltTextForAllSites += $withAltCount;
                $totalAssetsWithoutAltTextForAllSites += $withoutAltCount;
                
                Craft::info("Site {$site->name}: Total: {$imageAssetCount}, With Alt: {$withAltCount}, Without Alt: {$withoutAltCount}", __METHOD__);
            } catch (\Exception $e) {
                Craft::error("Error counting assets for site {$site->name}: " . $e->getMessage(), __METHOD__);
            }
        }
        
        // For backward compatibility with the template
        $totalAssets = $siteAltTextCounts[$currentSite->id]['total'] ?? 0;
        $totalAssetsWithAltText = $siteAltTextCounts[$currentSite->id]['with'] ?? 0;
        $totalAssetsWithoutAltText = $siteAltTextCounts[$currentSite->id]['without'] ?? 0;
        
        return Craft::$app->view->renderTemplate(
            'alt-text-generator/_settings',
            [
                'settings' => $this->getSettings(),
                'totalAssets' => $totalAssets,
                'totalAssetsWithAltText' => $totalAssetsWithAltText,
                'totalAssetsWithoutAltText' => $totalAssetsWithoutAltText,
                'totalAssetsWithAltTextForAllSites' => $totalAssetsWithAltTextForAllSites,
                'totalAssetsWithoutAltTextForAllSites' => $totalAssetsWithoutAltTextForAllSites,
                'currentSite' => $currentSite,
                'sites' => $sites,
                'siteAltTextCounts' => $siteAltTextCounts,
            ]
        );
    }
}
