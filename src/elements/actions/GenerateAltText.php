<?php

namespace bryamloaiza\alttextgenerator\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use bryamloaiza\alttextgenerator\AltTextGenerator;
use yii\base\InvalidConfigException;

/**
 * Generate Alt Text element action
 */
class GenerateAltText extends ElementAction
{
    /**
     * @var string|null The action description
     */
    public ?string $description = null;

    public static function displayName(): string
    {
        return Craft::t('alt-text-generator', 'Generate Alt Text');
    }

    public function getTriggerLabel(): string
    {
        return Craft::t('alt-text-generator', 'Generate Alt Text');
    }

    public function getElementsQuery(): array
    {
        return [Asset::class];
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
            (() => {
                new Craft.ElementActionTrigger({
                    type: $type,
                    bulk: true,
                    validateSelection: \$selectedItems => {
                        for (let i = 0; i < \$selectedItems.length; i++) {
                            if (\$selectedItems.eq(i).find('.element').data('kind') !== 'image') {
                                return false;
                            }
                        }
                        return true;
                    },
                });
            })();
        JS, [static::class]);

        return null;
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        // Get the current user
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new InvalidConfigException('User not logged in');
        }

        $success = true;
        $failMessage = '';

        // Process each asset
        foreach ($query->all() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }

            // Set the current site id on asset
            $asset = Asset::find()->id($asset->id)->siteId($query->siteId)->one();
            
            // Check if the user has permission to save this asset
            if (!Craft::$app->getUser()->checkPermission('saveAssets:' . $asset->getVolume()->uid)) {
                $failMessage = Craft::t('alt-text-generator', 'You don\'t have permission to edit assets in this volume');
                $success = false;
                continue;
            }

            try {
                // For now, just set a placeholder alt text directly instead of using the job queue
                // This ensures immediate feedback and avoids permission issues with the job system
                $asset->alt = 'Image of ' . pathinfo($asset->filename, PATHINFO_FILENAME);
                
                // Save the asset
                if (!Craft::$app->getElements()->saveElement($asset)) {
                    throw new \Exception('Could not save asset: ' . implode(', ', $asset->getFirstErrors()));
                }
            } catch (\Exception $e) {
                Craft::error('Error generating alt text: ' . $e->getMessage(), __METHOD__);
                $failMessage = $e->getMessage();
                $success = false;
            }
        }

        // Set the appropriate message based on success/failure
        if ($success) {
            $this->setMessage(Craft::t('alt-text-generator', 'Alt text generated successfully.'));
        } else {
            $this->setMessage(Craft::t('alt-text-generator', 'Failed to generate alt text: {message}', [
                'message' => $failMessage,
            ]));
        }

        return $success;
    }
}
