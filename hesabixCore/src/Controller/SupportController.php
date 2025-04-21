<?php

namespace App\Controller;

use App\Entity\Settings;
use App\Entity\Support;
use App\Entity\User;
use App\Service\Explore;
use App\Service\Extractor;
use App\Service\Jdate;
use App\Service\Notification;
use App\Service\Provider;
use App\Service\registryMGR;
use App\Service\SMS;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

class SupportController extends AbstractController
{
    /**
     * function to generate random strings
     * @param 		int 	$length 	number of characters in the generated string
     * @return 		string	a new string is created with random characters of the desired length
     */
    private function RandomString($length = 32)
    {
        return substr(str_shuffle(str_repeat($x = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    #[Route('/api/admin/support/list', name: 'app_admin_support_list')]
    public function app_admin_support_list(Extractor $extractor, EntityManagerInterface $entityManager): JsonResponse
    {
        $items = $entityManager->getRepository(Support::class)->findBy(['main' => 0], ['id' => 'DESC']);
        $res = [];
        foreach ($items as $item) {
            $res[] = Explore::ExploreSupportTicket($item, $this->getUser());
        }
        return $this->json($extractor->operationSuccess($res));
    }
    #[Route('/api/admin/support/view/{id}', name: 'app_admin_support_view')]
    public function app_admin_support_view(Extractor $extractor, Jdate $jdate, EntityManagerInterface $entityManager, string $id = ''): JsonResponse
    {
        $item = $entityManager->getRepository(Support::class)->find($id);
        if (!$item)
            throw $this->createNotFoundException();
        $replays = $entityManager->getRepository(Support::class)->findBy(['main' => $item->getId()]);
        $res = [];
        foreach ($replays as $replay) {
            if ($replay->getSubmitter() == $this->getUser())
                $replay->setState(1);
            else
                $replay->setState(0);
            $res[] = Explore::ExploreSupportTicket($replay, $this->getUser());
        }
        return $this->json(
            $extractor->operationSuccess([
                'item' => Explore::ExploreSupportTicket($item, $this->getUser()),
                'replays' => $res
            ])
        );
    }
    #[Route('/api/admin/support/mod/{id}', name: 'app_admin_support_mod')]
    public function app_admin_support_mod(registryMGR $registryMGR, SMS $SMS, Request $request, EntityManagerInterface $entityManager, Notification $notifi, string $id = ''): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $item = $entityManager->getRepository(Support::class)->find($id);
        if (!$item)
            $this->createNotFoundException();
        if (array_key_exists('body', $params)) {
            $support = new Support();
            $support->setDateSubmit(time());
            $support->setTitle('0');
            $support->setBody($params['body']);
            $support->setState('0');
            $support->setMain($item->getId());
            $support->setSubmitter($this->getUser());
            $entityManager->persist($support);
            $entityManager->flush();
            $item->setState('پاسخ داده شده');
            $entityManager->persist($support);
            $entityManager->flush();
            //send sms to customer
            if ($item->getSubmitter()->getMobile()) {
                $SMS->send(
                    [$item->getId()],
                    $registryMGR->get('sms', 'ticketReplay'),
                    $item->getSubmitter()->getMobile()
                );
            }
            //send notification to user
            $settings = $entityManager->getRepository(Settings::class)->findAll()[0];
            $url = '/profile/support-view/' . $item->getId();
            $notifi->insert("به درخواست پشتیبانی پاسخ داده شد", $url, null, $item->getSubmitter());
            return $this->json([
                'error' => 0,
                'message' => 'successful'
            ]);
        }
        return $this->json([
            'error' => 999,
            'message' => 'تمام موارد لازم را وارد کنید.'
        ]);
    }
    #[Route('/api/support/list', name: 'app_support_list')]
    public function app_support_list(Jdate $jdate, EntityManagerInterface $entityManager): JsonResponse
    {
        $items = $entityManager->getRepository(Support::class)->findBy(
            [
                'submitter' => $this->getUser(),
                'main' => 0
            ],
            [
                'id' => 'DESC'
            ]
        );
        foreach ($items as $item) {
            $item->setDateSubmit($jdate->jdate('Y/n/d', $item->getDateSubmit()));
        }
        return $this->json($items);
    }

    #[Route('/api/support/mod/{id}', name: 'app_support_mod')]
    public function app_support_mod(registryMGR $registryMGR, SMS $SMS, Request $request, EntityManagerInterface $entityManager, string $id = ''): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if ($id == '') {
            if (array_key_exists('title', $params) && array_key_exists('body', $params)) {
                $item = new Support();
                $item->setBody($params['body']);
                $item->setTitle($params['title']);
                $item->setDateSubmit(time());
                $item->setSubmitter($this->getUser());
                $item->setMain(0);
                $item->setCode($this->RandomString(8));
                $item->setState('در حال پیگیری');
                $entityManager->persist($item);
                $entityManager->flush();
                //send sms to manager
                $SMS->send(
                    [$item->getId()],
                    $registryMGR->get('sms', 'ticketRec'),
                    $registryMGR->get('ticket', 'managerMobile')
                );

                return $this->json([
                    'error' => 0,
                    'message' => 'ok',
                    'url' => $item->getId()
                ]);
            }
        } else {
            if (array_key_exists('body', $params)) {
                $item = new Support();
                $upper = $entityManager->getRepository(Support::class)->find($id);
                if ($upper)
                    $item->setMain($upper->getid());

                $item->setBody($params['body']);
                $item->setTitle($upper->getTitle());
                $item->setDateSubmit(time());
                $item->setSubmitter($this->getUser());
                $item->setState('در حال پیگیری');
                $entityManager->persist($item);
                $entityManager->flush();
                $upper->setState('در حال پیگیری');
                $entityManager->persist($upper);
                $entityManager->flush();
                //send sms to manager
                $SMS->send(
                    [$item->getId()],
                    $registryMGR->get('sms', 'ticketRec'),
                    $registryMGR->get('ticket', 'managerMobile')
                );
                return $this->json([
                    'error' => 0,
                    'message' => 'ok',
                    'url' => $item->getId()
                ]);
            }
        }

        return $this->json([
            'error' => 999,
            'message' => 'تمام موارد لازم را وارد کنید.'
        ]);
    }

    #[Route('/api/support/view/{id}', name: 'app_support_view')]
    public function app_support_view(Jdate $jdate, EntityManagerInterface $entityManager, string $id = ''): JsonResponse
    {
        $item = $entityManager->getRepository(Support::class)->find($id);
        if (!$item)
            throw $this->createNotFoundException();
        if ($item->getSubmitter() != $this->getUser())
            throw $this->createAccessDeniedException();
        $replays = $entityManager->getRepository(Support::class)->findBy(['main' => $item->getId()]);
        $replaysArray = [];
        foreach ($replays as $replay) {
            $replaysArray[] = Explore::ExploreSupportTicket($replay, $this->getUser());
        }
        return $this->json([
            'item' => Explore::ExploreSupportTicket($item, $this->getUser()),
            'replays' => $replaysArray
        ]);
    }
}
