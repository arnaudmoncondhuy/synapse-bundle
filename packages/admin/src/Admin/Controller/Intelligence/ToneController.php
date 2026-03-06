<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gestion des tons de réponse de l'IA — Administration Synapse.
 *
 * Un ton définit le style de communication du LLM (registre, format, posture).
 * Les tons builtin fournis par le bundle ne peuvent pas être supprimés.
 */
#[Route('%synapse.admin_prefix%/intelligence/tones', name: 'synapse_admin_')]
class ToneController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseToneRepository $toneRepo,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    // ─── Index ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'tones', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tones = $this->toneRepo->findAllOrdered();

        return $this->render('@Synapse/admin/intelligence/tones.html.twig', [
            'tones' => $tones,
        ]);
    }

    // ─── Nouveau ───────────────────────────────────────────────────────────────

    #[Route('/nouveau', name: 'tones_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tone = new SynapseTone();
        $tone->setIsBuiltin(false);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_edit');
            $this->applyFormData($tone, $request->request->all());
            $this->em->persist($tone);
            $this->em->flush();

            $this->addFlash('success', sprintf('Ton "%s" créé avec succès.', $tone->getName()));

            return $this->redirectToRoute('synapse_admin_tones');
        }

        return $this->render('@Synapse/admin/intelligence/tone_edit.html.twig', [
            'tone' => $tone,
            'is_new' => true,
        ]);
    }

    // ─── Édition ───────────────────────────────────────────────────────────────

    #[Route('/{id}/editer', name: 'tones_edit', methods: ['GET', 'POST'])]
    public function edit(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_edit');
            $this->applyFormData($tone, $request->request->all());
            $this->em->flush();

            $this->addFlash('success', sprintf('Ton "%s" mis à jour.', $tone->getName()));

            return $this->redirectToRoute('synapse_admin_tones');
        }

        return $this->render('@Synapse/admin/intelligence/tone_edit.html.twig', [
            'tone' => $tone,
            'is_new' => false,
        ]);
    }

    // ─── Toggle actif/inactif ──────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'tones_toggle', methods: ['POST'])]
    public function toggle(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_toggle_'.$tone->getId());

        $tone->setIsActive(!$tone->isActive());
        $this->em->flush();

        $state = $tone->isActive() ? 'activé' : 'désactivé';
        $this->addFlash('success', sprintf('Ton "%s" %s.', $tone->getName(), $state));

        return $this->redirectToRoute('synapse_admin_tones');
    }

    // ─── Suppression ───────────────────────────────────────────────────────────

    #[Route('/{id}/supprimer', name: 'tones_delete', methods: ['POST'])]
    public function delete(SynapseTone $tone, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_tone_delete_'.$tone->getId());

        if ($tone->isBuiltin()) {
            $this->addFlash('error', 'Les tons intégrés au bundle ne peuvent pas être supprimés.');

            return $this->redirectToRoute('synapse_admin_tones');
        }

        $name = $tone->getName();
        $this->em->remove($tone);
        $this->em->flush();

        $this->addFlash('success', sprintf('Ton "%s" supprimé.', $name));

        return $this->redirectToRoute('synapse_admin_tones');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function applyFormData(SynapseTone $tone, array $data): void
    {
        $keyVal = $data['key'] ?? null;
        if (is_string($keyVal) && !$tone->isBuiltin()) {
            $tone->setKey(trim($keyVal));
        }

        $emojiVal = $data['emoji'] ?? null;
        if (is_string($emojiVal)) {
            $tone->setEmoji(trim($emojiVal));
        }

        $nameVal = $data['name'] ?? null;
        if (is_string($nameVal)) {
            $tone->setName(trim($nameVal));
        }

        $descVal = $data['description'] ?? null;
        if (is_string($descVal)) {
            $tone->setDescription(trim($descVal));
        }

        $promptVal = $data['system_prompt'] ?? null;
        if (is_string($promptVal)) {
            $tone->setSystemPrompt(trim($promptVal));
        }

        $tone->setIsActive(isset($data['is_active']));

        $sortOrder = $data['sort_order'] ?? null;
        if (is_numeric($sortOrder)) {
            $tone->setSortOrder((int) $sortOrder);
        }
    }
}
