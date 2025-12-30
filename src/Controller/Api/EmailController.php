<?php

namespace App\Controller\Api;

use App\Service\EmailRateLimiter;
use App\Service\EmailSecurityValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use OpenApi\Attributes as OA;

final class EmailController extends AbstractController
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ValidatorInterface $validator,
        private readonly EmailRateLimiter $rateLimiter,
        private readonly EmailSecurityValidator $securityValidator,
        private readonly LoggerInterface $logger
    ) {}

    #[OA\Post(
        path: '/api/email/send',
        summary: 'Enviar un email',
        description: 'Endpoint para enviar emails con soporte para HTML, CC, BCC y múltiples destinatarios',
        tags: ['Email']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['to', 'subject', 'body'],
            properties: [
                new OA\Property(
                    property: 'to',
                    description: 'Email del destinatario',
                    type: 'string',
                    format: 'email',
                    example: 'destinatario@ejemplo.com'
                ),
                new OA\Property(
                    property: 'subject',
                    description: 'Asunto del email',
                    type: 'string',
                    maxLength: 255,
                    example: 'Notificación importante'
                ),
                new OA\Property(
                    property: 'body',
                    description: 'Contenido del email (texto plano o HTML según isHtml)',
                    type: 'string',
                    example: 'Este es el contenido del mensaje'
                ),
                new OA\Property(
                    property: 'from',
                    description: 'Email del remitente (opcional)',
                    type: 'string',
                    format: 'email',
                    example: 'remitente@ejemplo.com'
                ),
                new OA\Property(
                    property: 'replyTo',
                    description: 'Email para respuestas (opcional)',
                    type: 'string',
                    format: 'email',
                    example: 'respuesta@ejemplo.com'
                ),
                new OA\Property(
                    property: 'cc',
                    description: 'Array de emails en copia (opcional)',
                    type: 'array',
                    items: new OA\Items(type: 'string', format: 'email'),
                    example: ['copia1@ejemplo.com', 'copia2@ejemplo.com']
                ),
                new OA\Property(
                    property: 'bcc',
                    description: 'Array de emails en copia oculta (opcional)',
                    type: 'array',
                    items: new OA\Items(type: 'string', format: 'email'),
                    example: ['copiaoculta@ejemplo.com']
                ),
                new OA\Property(
                    property: 'isHtml',
                    description: 'Indica si el body es HTML (opcional, default: false)',
                    type: 'boolean',
                    example: false
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Email enviado correctamente',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Email enviado correctamente'),
                new OA\Property(
                    property: 'data',
                    properties: [
                        new OA\Property(property: 'to', type: 'string', example: 'destinatario@ejemplo.com'),
                        new OA\Property(property: 'subject', type: 'string', example: 'Notificación importante'),
                        new OA\Property(property: 'sentAt', type: 'string', format: 'date-time', example: '2025-12-30 15:30:00')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Error de validación o JSON inválido',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                new OA\Property(
                    property: 'errors',
                    type: 'object',
                    example: ['to' => 'El email del destinatario no es válido']
                )
            ]
        )
    )]
    #[OA\Response(
        response: 500,
        description: 'Error al enviar el email',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'Error al enviar el email'),
                new OA\Property(property: 'message', type: 'string', example: 'Connection refused')
            ]
        )
    )]
    #[Route('/api/email/send', name: 'app_api_email_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        try {
            // 1. VERIFICAR RATE LIMITING
            $rateLimitCheck = $this->rateLimiter->isAllowed($request);
            if (!$rateLimitCheck['allowed']) {
                $this->logger->warning('Rate limit exceeded', [
                    'ip' => $request->getClientIp(),
                    'reason' => $rateLimitCheck['reason']
                ]);
                
                return $this->json([
                    'success' => false,
                    'error' => 'Rate limit exceeded',
                    'message' => $rateLimitCheck['reason'],
                    'retryAfter' => $rateLimitCheck['retryAfter']
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
            
            // Obtener datos del request
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON format',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validar datos requeridos
            $constraints = new Assert\Collection([
                'to' => [
                    new Assert\NotBlank(['message' => 'El campo "to" es requerido']),
                    new Assert\Email(['message' => 'El email del destinatario no es válido'])
                ],
                'subject' => [
                    new Assert\NotBlank(['message' => 'El campo "subject" es requerido']),
                    new Assert\Length(['min' => 1, 'max' => 255])
                ],
                'body' => [
                    new Assert\NotBlank(['message' => 'El campo "body" es requerido'])
                ],
                'from' => new Assert\Optional([
                    new Assert\Email(['message' => 'El email del remitente no es válido'])
                ]),
                'replyTo' => new Assert\Optional([
                    new Assert\Email(['message' => 'El email de respuesta no es válido'])
                ]),
                'cc' => new Assert\Optional([
                    new Assert\Type('array'),
                    new Assert\All([
                        new Assert\Email(['message' => 'Uno de los emails en CC no es válido'])
                    ])
                ]),
                'bcc' => new Assert\Optional([
                    new Assert\Type('array'),
                    new Assert\All([
                        new Assert\Email(['message' => 'Uno de los emails en BCC no es válido'])
                    ])
                ]),
                'isHtml' => new Assert\Optional([
                    new Assert\Type('bool')
                ])
            ]);

            $violations = $this->validator->validate($data, $constraints);

            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[$violation->getPropertyPath()] = $violation->getMessage();
                }

                return $this->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $errors
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // 2. VALIDACIONES DE SEGURIDAD
            $securityCheck = $this->securityValidator->validate($data);
            if (!$securityCheck['valid']) {
                $this->logger->warning('Security validation failed', [
                    'ip' => $request->getClientIp(),
                    'errors' => $securityCheck['errors']
                ]);
                
                return $this->json([
                    'success' => false,
                    'error' => 'Security validation failed',
                    'errors' => $securityCheck['errors']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Crear el email
            $email = (new Email())
                ->to($data['to'])
                ->subject($data['subject']);

            // Configurar remitente
            if (isset($data['from'])) {
                $email->from($data['from']);
            }

            // Configurar reply-to
            if (isset($data['replyTo'])) {
                $email->replyTo($data['replyTo']);
            }

            // Agregar CC
            if (isset($data['cc']) && is_array($data['cc'])) {
                foreach ($data['cc'] as $cc) {
                    $email->addCc($cc);
                }
            }

            // Agregar BCC
            if (isset($data['bcc']) && is_array($data['bcc'])) {
                foreach ($data['bcc'] as $bcc) {
                    $email->addBcc($bcc);
                }
            }

            // Configurar contenido (HTML o texto plano)
            $isHtml = $data['isHtml'] ?? false;
            if ($isHtml) {
                // Sanitizar HTML para prevenir XSS
                $sanitizedBody = $this->securityValidator->sanitizeHtml($data['body']);
                $email->html($sanitizedBody);
            } else {
                $email->text($data['body']);
            }

            // Enviar el email
            $this->mailer->send($email);
            
            // 3. REGISTRAR ENVÍO EXITOSO
            $this->rateLimiter->recordSent($request);
            
            // Log de éxito
            $this->logger->info('Email sent successfully', [
                'ip' => $request->getClientIp(),
                'to' => $data['to'],
                'subject' => $data['subject']
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Email enviado correctamente',
                'data' => [
                    'to' => $data['to'],
                    'subject' => $data['subject'],
                    'sentAt' => (new \DateTime())->format('Y-m-d H:i:s')
                ]
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            // Log de error
            $this->logger->error('Failed to send email', [
                'ip' => $request->getClientIp(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->json([
                'success' => false,
                'error' => 'Error al enviar el email',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Endpoint para consultar las estadísticas de rate limiting del cliente
     */
    #[OA\Get(
        path: '/api/email/stats',
        summary: 'Obtener estadísticas de envío',
        description: 'Consulta las estadísticas de envío de emails para la IP actual',
        tags: ['Email']
    )]
    #[OA\Response(
        response: 200,
        description: 'Estadísticas obtenidas correctamente',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'stats',
                    properties: [
                        new OA\Property(property: 'ip', type: 'string', example: '192.168.1.100'),
                        new OA\Property(property: 'sentLastMinute', type: 'integer', example: 1),
                        new OA\Property(property: 'sentLastHour', type: 'integer', example: 5),
                        new OA\Property(property: 'sentLastDay', type: 'integer', example: 15),
                        new OA\Property(property: 'remainingMinute', type: 'integer', example: 1),
                        new OA\Property(property: 'remainingHour', type: 'integer', example: 5),
                        new OA\Property(property: 'remainingDay', type: 'integer', example: 35)
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[Route('/api/email/stats', name: 'app_api_email_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->rateLimiter->getStats($request);
        
        return $this->json([
            'success' => true,
            'stats' => $stats
        ], Response::HTTP_OK);
    }
}