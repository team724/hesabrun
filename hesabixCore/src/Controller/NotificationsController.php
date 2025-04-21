<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Notification;
use App\Entity\UserToken;
use App\Service\Access;
use App\Service\Jdate;
use App\Service\Log;
use App\Service\twigFunctions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class NotificationsController extends AbstractController
{
    #[Route('/api/notifications/list/{type}', name: 'api_notification_list')]
    public function api_notification_list(twigFunctions $twigFunctions,Access $access,Request $request, Jdate $jdate, EntityManagerInterface $entityManager,String $type = 'all'): JsonResponse
    {
        if(!$this->getUser())
            throw $this->createAccessDeniedException('lot loged in');

        $acc = $access->hasRole('join');
        if(!$acc)
            throw $this->createAccessDeniedException();
        $business = $entityManager->getRepository(Business::class)->find($acc['bid']);
        if(!$business)
            throw $this->createNotFoundException();
        if($type == 'all'){
            $items = $entityManager->getRepository(\App\Entity\Notification::class)->findBy([
                'bid'=>$business,
                'user'=>$this->getUser()
            ],['id'=>'DESC']);
            $userItems = $entityManager->getRepository(\App\Entity\Notification::class)->findBy([
                'user'=>$this->getUser()
            ],['id'=>'DESC']);
            $items = array_merge($items,$userItems);
        }
        elseif ($type = 'new'){
            $items = $entityManager->getRepository(\App\Entity\Notification::class)->findBy([
                'bid'=>$business,
                'user'=>$this->getUser(),
                'viewed' => false
            ],['id'=>'DESC']);
            $userItems = $entityManager->getRepository(\App\Entity\Notification::class)->findBy([
                'user'=>$this->getUser(),
                'viewed' => false
            ],['id'=>'DESC']);
            $items = array_merge($items,$userItems);
        }

        $temps = [];
        foreach ($items as $item){
            $temp = [];
            $temp['user'] = $item->getUser()->getFullName();
            $temp['message'] = $item->getMessage();
            $temp['icon'] = $item->getIcon();
            $temp['date'] = $twigFunctions->dayToNow($item->getDateSubmit());
            $temp['url'] = $item->getUrl();
            $temp['id'] = $item->getId();
            $temps[] = $temp;
        }

        $userToken = $entityManager->getRepository(UserToken::class)->findOneBy([
            'token'=> $request->headers->get('X-AUTH-TOKEN')
        ]);
        if($userToken){
            $userToken->setLastActive(time());
            $entityManager->persist($userToken);
            $entityManager->flush();
        }
        return $this->json($temps);
    }

    #[Route('/api/notifications/read/{id}', name: 'api_notification_read')]
    public function api_notification_read(String $id,Access $access, Jdate $jdate, EntityManagerInterface $entityManager,Log $log): JsonResponse
    {   if(!$this->getUser())
        throw $this->createAccessDeniedException('lot loged in');

        $acc = $access->hasRole('join');
        if(!$acc)
            throw $this->createAccessDeniedException();
        $business = $entityManager->getRepository(Business::class)->find($acc['bid']);
        if(!$business)
            throw $this->createNotFoundException();
        $item = $entityManager->getRepository(Notification::class)->find($id);
        if($item){
            $item->setViewed(true);
            $entityManager->persist($item);
            $entityManager->flush();
        }
        return $this->json([
            'result'=>'ok'
        ]);
    }
}
