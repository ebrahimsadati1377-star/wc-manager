<?php
require_once __DIR__ . '/ProductWorkflowSchema.php';
ProductWorkflowSchema::ensure();
require_once __DIR__ . '/OpenAIAgent.php';
require_once __DIR__ . '/ChatImageService.php';
require_once __DIR__ . '/ProductAiSeoService.php';
require_once __DIR__ . '/ProductImageGenerator.php';
require_once __DIR__ . '/ProductWorkflowRepository.php';
require_once __DIR__ . '/ProductImageProviderManager.php';
require_once __DIR__ . '/ProductWorkflowService.php';
require_once __DIR__ . '/ProductImageQualityService.php';
require_once __DIR__ . '/ProductCategoryRuleService.php';
require_once __DIR__ . '/ProductVisualQualityService.php';
require_once __DIR__ . '/ProductDiversityService.php';
require_once __DIR__ . '/ProductPreviewService.php';
require_once __DIR__ . '/ProductPublishVerificationService.php';
require_once __DIR__ . '/ProductPublishService.php';
