<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Core\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Message\TestPresetMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Gère les tests de presets LLM depuis l'interface admin.
 */
class PresetTestController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $bus,
        private CacheInterface $cache,
        private PresetValidatorAgent $agent,
    ) {}

    /**
     * Teste un preset et affiche le rapport d'analyse.
     */
    #[Route(
        path: '/synapse/admin/presets/{id}/test',
        name: 'synapse_admin_presets_test',
        methods: ['POST'],
    )]
    public function test(SynapsePreset $preset): Response
    {
        $id = $preset->getId();
        $cacheKey = sprintf('synapse_preset_test_%d', $id);

        // Initialize cache status
        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return ['status' => 'pending', 'progress' => 0, 'report' => null];
        });

        // Force reset if already exists (cleanup old tests)
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return ['status' => 'pending', 'progress' => 0, 'report' => null];
        });

        $this->bus->dispatch(new TestPresetMessage($id));

        return $this->render('@Synapse/admin/preset_test_waiting.html.twig', [
            'preset' => $preset,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Polling endpoint to get test status.
     */
    #[Route(
        path: '/synapse/admin/presets/{id}/test/status',
        name: 'synapse_admin_presets_test_status',
        methods: ['GET'],
    )]
    public function status(SynapsePreset $preset): Response
    {
        set_time_limit(0);

        $id = $preset->getId();
        $cacheKey = sprintf('synapse_preset_test_%d', $id);
        $lockKey = sprintf('synapse_test_lock_%d', $id);

        $data = $this->cache->get($cacheKey, fn() => null);

        if (!$data) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }

        // --- ACTIVE POLLING ---
        if (in_array($data['status'], ['pending', 'processing'], true)) {
            $isLocked = $this->cache->get($lockKey, fn() => false);

            if (!$isLocked) {
                // Acquisition du verrou
                $this->cache->get($lockKey, function (ItemInterface $item) {
                    $item->expiresAfter(60);
                    return true;
                });

                try {
                    $data = $this->cache->get($cacheKey, fn() => null);
                    if (!$data) {
                        return new JsonResponse(['status' => 'not_found'], 404);
                    }

                    $currentStep = 0;
                    if ($data['status'] === 'pending') {
                        $currentStep = 1;
                    } elseif ($data['status'] === 'processing') {
                        if (($data['progress'] ?? 0) < 33) $currentStep = 1;
                        elseif (($data['progress'] ?? 0) < 66) $currentStep = 2;
                        elseif (($data['progress'] ?? 0) < 100) $currentStep = 3;
                    }

                    if ($currentStep > 0) {
                        // --- FAST RETURN UX OPTIMIZATION ---
                        $data['message'] = $this->agent->getStepLabel($currentStep);

                        $this->cache->delete($cacheKey);

                        /** @var \Closure(ItemInterface): array $callback */
                        $callback = function (ItemInterface $item) use ($data): array {
                            $item->expiresAfter(3600);
                            return (array) $data;
                        };
                        $this->cache->get($cacheKey, $callback);

                        if (function_exists('fastcgi_finish_request')) {
                            $data['is_processing_async'] = true;
                            $response = new JsonResponse($data);
                            $response->prepare(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
                            $response->send();

                            if (PHP_SAPI !== 'cli') {
                                ignore_user_abort(true);
                                fastcgi_finish_request();
                            }
                        }

                        $report = $data['report'] ?? [];
                        $this->agent->runStep($currentStep, $preset, $report);

                        $data['status'] = ($currentStep === 3) ? 'completed' : 'processing';
                        $data['progress'] = match ($currentStep) {
                            1 => 33,
                            2 => 66,
                            3 => 100,
                        };
                        $data['message'] = null;
                        $data['report'] = $report;
                        unset($data['is_processing_async']);

                        $this->cache->delete($cacheKey);

                        /** @var \Closure(ItemInterface): array $callbackAfter */
                        $callbackAfter = function (ItemInterface $item) use ($data): array {
                            $item->expiresAfter(3600);
                            return (array) $data;
                        };
                        $this->cache->get($cacheKey, $callbackAfter);

                        if (isset($data['is_processing_async'])) {
                            exit;
                        }
                    }
                } finally {
                    $this->cache->delete($lockKey);
                }
            }
        }

        if ($data['status'] === 'completed') {
            return $this->render('@Synapse/admin/preset_test_result.html.twig', [
                'report' => $data['report'],
            ]);
        }

        if ($data['status'] === 'error') {
            return new Response('<div class="synapse-admin__alert synapse-admin__alert--danger">Erreur : ' . htmlspecialchars($data['error'] ?? 'Inconnue') . '</div>');
        }

        return new JsonResponse($data);
    }
}
