<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EmailController extends AbstractController
{
    #[Route('/api/email', name: 'app_api_email', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'message' => 'Email API endpoint is working!',
            'path' => 'src/backend/src/Controller/Api/EmailController.php',
        ]);
    }
}
