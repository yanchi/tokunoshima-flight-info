<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Port\ContactMessageNotSent;
use App\Application\UseCase\SendContactMessage;
use App\Presentation\Form\ContactFormData;
use App\Presentation\Form\ContactFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly SendContactMessage $sendContactMessage,
        #[Autowire(service: 'limiter.contact_form')]
        private readonly RateLimiterFactory $perClientLimiter,
        #[Autowire(service: 'limiter.contact_form_global')]
        private readonly RateLimiterFactory $globalLimiter,
    ) {
    }

    #[Route('/contact', name: 'contact', methods: ['GET', 'POST'])]
    public function form(Request $request): Response
    {
        if (!$this->sendContactMessage->isAvailable()) {
            return $this->render('contact/form.html.twig', ['form' => null, 'state' => 'unavailable'], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $form = $this->createForm(ContactFormType::class, new ContactFormData());
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->render('contact/form.html.twig', ['form' => $form, 'state' => 'input']);
        }

        // Every POST counts against the client's own limit, valid or not, so
        // that a bot cannot probe for free. The global limit is spent only when
        // a message is about to be sent, so invalid posts and honeypot hits do
        // not use it up. A client that sends valid messages can still use five
        // an hour of it (accepted: docs §8 「問い合わせフォーム」).
        if (!$this->perClientLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->limited($form);
        }

        if (!$form->isValid()) {
            return $this->render('contact/form.html.twig', ['form' => $form, 'state' => 'input'], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        /** @var ContactFormData $data */
        $data = $form->getData();

        // A filled honeypot is a bot: answer as if it worked, and send nothing.
        if (null !== $data->trap && '' !== trim($data->trap)) {
            return $this->redirectToRoute('contact_sent');
        }

        if (!$this->globalLimiter->create('all')->consume()->isAccepted()) {
            return $this->limited($form);
        }

        try {
            ($this->sendContactMessage)((string) $data->body, $data->replyTo);
        } catch (ContactMessageNotSent) {
            return $this->render('contact/form.html.twig', ['form' => $form, 'state' => 'failed'], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->redirectToRoute('contact_sent');
    }

    #[Route('/contact/sent', name: 'contact_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('contact/sent.html.twig');
    }

    /**
     * @param FormInterface<ContactFormData> $form
     */
    private function limited(FormInterface $form): Response
    {
        return $this->render('contact/form.html.twig', ['form' => $form, 'state' => 'limited'], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
    }
}
