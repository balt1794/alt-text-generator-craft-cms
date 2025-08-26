<?php

namespace bryamloaiza\alttextgenerator\models;

use craft\base\Model;

/**
 * Alt Text Generator Settings
 *
 * Settings model for the Alt Text Generator plugin.
 * This model handles the configuration options for alt text generation.
 *
 * @property string $apiKey The API key for generating alt text
 * @property string $language The language for alt text generation
 * @property bool $generateForNewAssets Whether to generate alt text for new assets automatically
 */
class Settings extends Model
{
    /**
     * @var string The API key for generating alt text
     */
    public string $apiKey = '';

    /**
     * @var string The language for alt text generation
     */
    public string $language = 'en';

    /**
     * @var bool Whether to generate alt text for new assets automatically
     */
    public bool $generateForNewAssets = false;

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['apiKey'], 'string'],
            [['language'], 'string'],
            [['language'], 'default', 'value' => 'en'],
            [['generateForNewAssets'], 'boolean'],
            [['generateForNewAssets'], 'default', 'value' => false],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'apiKey' => 'API Key',
            'language' => 'Language',
            'generateForNewAssets' => 'Generate for New Assets',
        ];
    }
}
