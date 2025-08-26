<?php

namespace bryamloaiza\alttextgenerator\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use bryamloaiza\alttextgenerator\AltTextGenerator;

/**
 * Settings Controller
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Verify API Key
     *
     * @return Response
     */
    public function actionVerifyApi(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $apiKey = $this->request->getRequiredBodyParam('apiKey');

        try {
            if (empty($apiKey)) {
                throw new \Exception('API Key is required');
            }

            // Call your credits API to verify
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://alttextgeneratorai.com/api/verify');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['apiKey' => $apiKey]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response === false || $httpCode !== 200) {
                if ($httpCode === 404) {
                    throw new \Exception('API Key not found');
                } else {
                    throw new \Exception('Failed to verify API Key');
                }
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['freeRewritesLeft'])) {
                return $this->asJson([
                    'success' => true,
                    'message' => 'API Key is valid!',
                    'credits' => $data['freeRewritesLeft']
                ]);
            } else {
                throw new \Exception('Invalid API response');
            }
            
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get Settings for JavaScript
     *
     * @return Response
     */
    public function actionGetSettings(): Response
    {
        // Don't require CSRF for this read-only endpoint
        $this->requirePostRequest();
        
        try {
            $settings = AltTextGenerator::getInstance()->getSettings();
            
            $response = [
                'success' => true,
                'apiKey' => $settings->apiKey ?? '',
                'language' => $settings->language ?? 'english'
            ];
            
            return $this->asJson($response);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'apiKey' => '',
                'language' => 'english'
            ]);
        }
    }
} 