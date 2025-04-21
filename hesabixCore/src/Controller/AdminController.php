<?php

namespace App\Controller;

use App\Entity\BankAccount;
use App\Entity\Business;
use App\Entity\Cashdesk;
use App\Entity\ChangeReport;
use App\Entity\Commodity;
use App\Entity\HesabdariDoc;
use App\Entity\Money;
use App\Entity\Person;
use App\Entity\Registry;
use App\Entity\Settings;
use App\Entity\StoreroomTicket;
use App\Entity\User;
use App\Entity\UserToken;
use App\Entity\WalletTransaction;
use App\Service\Extractor;
use App\Service\Jdate;
use App\Service\JsonResp;
use App\Service\Notification;
use App\Service\Provider;
use App\Service\registryMGR;
use App\Service\SMS;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AdminController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/api/admin/sync/database', name: 'app_admin_sync_database')]
    public function app_admin_sync_database(KernelInterface $kernel): JsonResponse
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'doctrine:schema:update',
            // (optional) define the value of command arguments
            '--force' => true,
            '--complete' => true
        ]);

        // You can use NullOutput() if you don't need the output
        $output = new BufferedOutput();
        $application->run($input, $output);

        // return the output, don't use if you used NullOutput()
        $content = $output->fetch();
        return $this->json([
            'message' => $content,
        ]);
    }

    #[Route('/api/admin/users/list', name: 'admin_users_list')]
    public function admin_users_list(Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $users = $entityManager->getRepository(User::class)->findBy([], ['id' => 'DESC']);
        $resp = [];
        foreach ($users as $user) {
            $temp = [];
            $temp['id'] = $user->getId();
            $temp['email'] = $user->getEmail();
            $temp['mobile'] = $user->getMobile();
            $temp['fullname'] = $user->getFullName();
            $temp['status'] = $user->isActive();
            $temp['dateRegister'] = $jdate->jdate('Y/n/d', $user->getDateRegister());
            $temp['bidCount'] = count($entityManager->getRepository(Business::class)->findBy(['owner' => $user]));
            $resp[] = $temp;
        }
        return $this->json($resp);
    }

    #[Route('/api/admin/user/info/{id}', name: 'admin_user_info')]
    public function admin_user_info(string $id, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $user = $entityManager->getRepository(User::class)->find($id);
        $temp = [];
        $temp['id'] = $user->getId();
        $temp['email'] = $user->getEmail();
        $temp['mobile'] = $user->getMobile();
        $temp['fullname'] = $user->getFullName();
        $temp['status'] = $user->isActive();
        $temp['dateRegister'] = $jdate->jdate('Y/n/d', $user->getDateRegister());
        $temp['bidCount'] = count($entityManager->getRepository(Business::class)->findBy(['owner' => $user]));
        return $this->json($temp);
    }

    #[Route('/api/admin/business/info/{id}', name: 'admin_business_info')]
    public function admin_business_info(string $id, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $bid = $entityManager->getRepository(Business::class)->find($id);
        if (!$bid)
            throw $this->createNotFoundException();
        $resp = [];
        $resp['id'] = $bid->getId();
        $resp['name'] = $bid->getName();
        $resp['owner'] = $bid->getOwner()->getFullName();
        return $this->json($resp);
    }
    #[Route('/api/admin/business/list', name: 'admin_business_list')]
    public function admin_business_list(Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $items = $entityManager->getRepository(Business::class)->findBy([], ['id' => 'DESC']);
        $resp = [];
        foreach ($items as $item) {
            $temp = [];
            $temp['id'] = $item->getId();
            $temp['name'] = $item->getName();
            $temp['owner'] = $item->getOwner()->getFullName();
            $temp['ownerMobile'] = $item->getOwner()->getMobile();
            $temp['dateRegister'] = $jdate->jdate('Y/n/d', $item->getDateSubmit());
            $temp['commodityCount'] = count($entityManager->getRepository(Commodity::class)->findBy(['bid' => $item]));
            $temp['personsCount'] = count($entityManager->getRepository(Person::class)->findBy(['bid' => $item]));
            $temp['hesabdariDocsCount'] = count($entityManager->getRepository(HesabdariDoc::class)->findBy(['bid' => $item]));
            $temp['StoreroomDocsCount'] = count($entityManager->getRepository(StoreroomTicket::class)->findBy(['bid' => $item]));

            $resp[] = $temp;
        }
        return $this->json($resp);
    }

    #[Route('/api/admin/business/count', name: 'admin_business_count')]
    public function admin_business_count(Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        return $this->json($entityManager->getRepository(Business::class)->countAll());
    }

    #[Route('/api/admin/users/count', name: 'admin_users_count')]
    public function admin_users_count(Extractor $extractor, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        return $this->json($extractor->operationSuccess($entityManager->getRepository(User::class)->countAll()));
    }

    #[Route('/api/admin/business/search', name: 'admin_business_list_search')]
    public function admin_business_list_search(Extractor $extractor, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        $items = $entityManager->getRepository(Business::class)->findByPage($params['options']['page'], $params['options']['rowsPerPage'], $params['search']);
        $resp = [];
        foreach ($items as $item) {
            $temp = [];
            $temp['id'] = $item->getId();
            $temp['name'] = $item->getName();
            $temp['owner'] = $item->getOwner()->getFullName();
            $temp['ownerMobile'] = $item->getOwner()->getMobile();
            $temp['dateRegister'] = $jdate->jdate('Y/n/d', $item->getDateSubmit());
            $temp['commodityCount'] = count($entityManager->getRepository(Commodity::class)->findBy(['bid' => $item]));
            $temp['personsCount'] = count($entityManager->getRepository(Person::class)->findBy(['bid' => $item]));
            $temp['hesabdariDocsCount'] = count($entityManager->getRepository(HesabdariDoc::class)->findBy(['bid' => $item]));
            $temp['StoreroomDocsCount'] = count($entityManager->getRepository(StoreroomTicket::class)->findBy(['bid' => $item]));
            $resp[] = $temp;
        }
        return $this->json($extractor->operationSuccess($resp));
    }

    #[Route('/api/admin/users/search', name: 'admin_users_list_search')]
    public function admin_users_list_search(Extractor $extractor, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        $items = $entityManager->getRepository(User::class)->findByPage($params['options']['page'], $params['options']['rowsPerPage'], $params['search']);
        $resp = [];
        foreach ($items as $item) {
            $temp = [];
            $temp['id'] = $item->getId();
            $temp['email'] = $item->getEmail();
            $temp['mobile'] = $item->getMobile();
            $temp['fullname'] = $item->getFullName();
            $temp['status'] = $item->isActive();
            $temp['dateRegister'] = $jdate->jdate('Y/n/d', $item->getDateRegister());
            $temp['bidCount'] = count($entityManager->getRepository(Business::class)->findBy(['owner' => $item]));
            $resp[] = $temp;
        }
        return $this->json($extractor->operationSuccess($resp));
    }

    #[Route('/api/admin/settings/sms/info', name: 'admin_settings_sms_info')]
    public function admin_settings_sms_info(Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $item = $entityManager->getRepository(Settings::class)->findAll()[0];
        $resp = [];
        $url = 'https://console.melipayamak.com/api/receive/credit/' . $item->getMelipayamakToken();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Content-Length: 0'
            )
        );
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true, JSON_PRETTY_PRINT);
        curl_close($ch);
        if ($err) {
            throw $this->createAccessDeniedException($err);
        } else {
            $resp['balanceCount'] = $result['amount'];
        }
        $resp['username'] = $item->getPayamakUsername();
        $resp['password'] = $item->getPayamakPassword();
        $resp['token'] = $item->getMelipayamakToken();
        return $this->json($resp);
    }
    #[Route('/api/admin/settings/sms/info/save', name: 'admin_settings_sms_info_save')]
    public function admin_settings_sms_info_save(Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (array_key_exists('username', $params) && array_key_exists('password', $params) && array_key_exists('token', $params)) {
            $item = $entityManager->getRepository(Settings::class)->findAll()[0];
            $item->setPayamakPassword($params['password']);
            $item->setPayamakUsername($params['username']);
            $item->setMelipayamakToken($params['token']);
            $entityManager->persist($item);
            $entityManager->flush();
            return $this->json(['result' => 1]);
        }
        throw $this->createNotFoundException();
    }

    #[Route('/api/admin/sms/plan/info', name: 'admin_sms_plan_info')]
    public function admin_sms_plan_info(registryMGR $registryMGR, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {

        $resp = [];
        $resp['username'] = $registryMGR->get('sms', 'username');
        $resp['password'] = $registryMGR->get('sms', 'password');
        $resp['token'] = $registryMGR->get('sms', 'token');
        $resp['walletpay'] = $registryMGR->get('sms', 'walletPay');
        $resp['changePassword'] = $registryMGR->get('sms', 'changePassword');
        $resp['recPassword'] = $registryMGR->get('sms', 'recPassword');
        $resp['f2a'] = $registryMGR->get('sms', 'f2a');
        $resp['ticketReplay'] = $registryMGR->get('sms', 'ticketReplay');
        $resp['ticketRec'] = $registryMGR->get('sms', 'ticketRec');
        $resp['fromNum'] = $registryMGR->get('sms', 'fromNum');
        $resp['sharefaktor'] = $registryMGR->get('sms', 'sharefaktor');
        $resp['plan'] = $registryMGR->get('sms', 'plan');
        $resp['plugRepservice'] = [
            'get' => $registryMGR->get('sms', 'plugRepserviceStateGet'),
            'getback' => $registryMGR->get('sms', 'plugRepserviceStateGetback'),
            'repired' => $registryMGR->get('sms', 'plugRepserviceStateRepaired'),
            'unrepaired' => $registryMGR->get('sms', 'plugRepserviceStateUnrepired'),
            'creating' => $registryMGR->get('sms', 'plugRepserviceStateCreating'),
            'created' => $registryMGR->get('sms', 'plugRepserviceStateCreated')
        ];
        $resp['plugAccpro'] = [
            'sharefaktor' => $registryMGR->get('sms', 'plugAccproSharefaktor'),
            'storeroomSmsOther' => $registryMGR->get('sms', 'plugAccproStoreroomSmsOther'),
            'storeroomSmsBarbari' => $registryMGR->get('sms', 'plugAccproStoreroomSmsBarbari'),
        ];
        return $this->json($resp);
    }

    #[Route('/api/admin/sms/plan/info/save', name: 'admin_sms_plan_info_save')]
    public function admin_sms_plan_info_save(registryMGR $registryMGR, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        if (array_key_exists('username', $params))
            $registryMGR->update('sms', 'username', $params['username']);
        if (array_key_exists('password', $params))
            $registryMGR->update('sms', 'password', $params['password']);
        if (array_key_exists('token', $params))
            $registryMGR->update('sms', 'token', $params['token']);

        if (array_key_exists('walletpay', $params))
            $registryMGR->update('sms', 'walletpay', $params['walletpay']);
        if (array_key_exists('changePassword', $params))
            $registryMGR->update('sms', 'changePassword', $params['changePassword']);
        if (array_key_exists('recPassword', $params))
            $registryMGR->update('sms', 'recPassword', $params['recPassword']);
        if (array_key_exists('f2a', $params))
            $registryMGR->update('sms', 'f2a', $params['f2a']);
        if (array_key_exists('ticketReplay', $params))
            $registryMGR->update('sms', 'ticketReplay', $params['ticketReplay']);
        if (array_key_exists('ticketRec', $params))
            $registryMGR->update('sms', 'ticketRec', $params['ticketRec']);
        if (array_key_exists('fromNum', $params))
            $registryMGR->update('sms', 'fromNum', $params['fromNum']);
        if (array_key_exists('sharefaktor', $params))
            $registryMGR->update('sms', 'sharefaktor', $params['sharefaktor']);
        if (array_key_exists('plan', $params))
            $registryMGR->update('sms', 'plan', $params['plan']);

        if (array_key_exists('plugRepservice', $params)) {
            if (array_key_exists('get', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateGet', $params['plugRepservice']['get']);
            if (array_key_exists('repired', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateRepaired', $params['plugRepservice']['repired']);
            if (array_key_exists('unrepaired', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateUnrepired', $params['plugRepservice']['unrepaired']);
            if (array_key_exists('getback', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateGetback', $params['plugRepservice']['getback']);
            if (array_key_exists('creating', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateCreating', $params['plugRepservice']['creating']);
            if (array_key_exists('created', $params['plugRepservice']))
                $registryMGR->update('sms', 'plugRepserviceStateCreated', $params['plugRepservice']['created']);
        }
        if (array_key_exists('plugAccpro', $params)) {
            if (array_key_exists('sharefaktor', $params['plugAccpro']))
                $registryMGR->update('sms', 'plugAccproSharefaktor', $params['plugAccpro']['sharefaktor']);
            if (array_key_exists('storeroomSmsBarbari', $params['plugAccpro']))
                $registryMGR->update('sms', 'plugAccproStoreroomSmsBarbari', $params['plugAccpro']['storeroomSmsBarbari']);
            if (array_key_exists('storeroomSmsOther', $params['plugAccpro']))
                $registryMGR->update('sms', 'plugAccproStoreroomSmsOther', $params['plugAccpro']['storeroomSmsOther']);
        }

        return $this->json(JsonResp::success());
    }

    #[Route('/api/admin/settings/system/info', name: 'admin_settings_system_info')]
    public function admin_settings_system_info(registryMGR $registryMGR, Jdate $jdate, #[CurrentUser] ?User $user, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, Request $request): Response
    {
        $item = $entityManager->getRepository(Settings::class)->findAll()[0];
        $resp = [];
        $resp['keywords'] = $item->getSiteKeywords();
        $resp['description'] = $item->getDiscription();
        $resp['scripts'] = $item->getScripts();
        $resp['zarinpal'] = $registryMGR->get('system', key: 'zarinpalKey');;
        $resp['footerScripts'] = $item->getFooterScripts();
        $resp['appSite'] = $item->getAppSite();
        $resp['footer'] = $item->getFooter();
        $resp['activeGateway'] = $registryMGR->get('system', key: 'activeGateway');
        $resp['parsianGatewayAPI'] = $registryMGR->get('system', key: 'parsianGatewayAPI');
        return $this->json($resp);
    }


    #[Route('/api/admin/settings/system/info/save', name: 'admin_settings_system_info_save')]
    public function admin_settings_system_info_save(registryMGR $registryMGR, EntityManagerInterface $entityManager, Request $request): Response
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (array_key_exists('keywords', $params) && array_key_exists('description', $params)) {
            $item = $entityManager->getRepository(Settings::class)->findAll()[0];
            $item->setSiteKeywords($params['keywords']);
            $item->setDiscription($params['description']);
            $item->setScripts($params['scripts']);
            $registryMGR->update('system', 'zarinpalKey', $params['zarinpal']);
            $item->setFooterScripts($params['footerScripts']);
            $item->setAppSite($params['appSite']);
            $item->setFooter($params['footer']);
            $registryMGR->update('system', 'activeGateway', $params['activeGateway']);
            $registryMGR->update('system', 'parsianGatewayAPI', $params['parsianGatewayAPI']);
            $entityManager->persist($item);
            $entityManager->flush();
            return $this->json(['result' => 1]);
        }
        throw $this->createNotFoundException();
    }

    #[Route('/api/admin/reportchange/lists', name: 'app_admin_reportchange_list')]
    public function app_admin_reportchange_list(Jdate $jdate, Provider $provider, EntityManagerInterface $entityManager): JsonResponse
    {
        $rows = $entityManager->getRepository(ChangeReport::class)->findBy([], ['id' => 'DESC']);
        foreach ($rows as $row) {
            $row->setDateSubmit($jdate->jdate('Y/n/d', $row->getDateSubmit()));
        }
        return $this->json($provider->ArrayEntity2ArrayJustIncludes($rows, ['getDateSubmit', 'getVersion', 'getId']));
    }

    #[Route('/api/admin/reportchange/delete/{id}', name: 'app_admin_reportchange_delete')]
    public function app_admin_reportchange_delete(string $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $item = $entityManager->getRepository(ChangeReport::class)->find($id);
        if ($item) {
            $entityManager->remove($item);
            $entityManager->flush();
        }
        return $this->json(['result' => 1]);
    }

    #[Route('/api/admin/reportchange/get/{id}', name: 'app_admin_reportchange_get')]
    public function app_admin_reportchange_get(string $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $item = $entityManager->getRepository(ChangeReport::class)->find($id);
        if (!$item)
            throw $this->createNotFoundException();
        return $this->json($item);
    }

    #[Route('/api/admin/reportchange/mod/{id}', name: 'app_admin_reportchange_mod')]
    public function app_admin_reportchange_mod(Request $request, EntityManagerInterface $entityManager, int $id = 0): JsonResponse
    {
        $item = new ChangeReport();
        $item->setDateSubmit(time());

        if ($id != 0) {
            $item = $entityManager->getRepository(ChangeReport::class)->find($id);
            if (!$item)
                throw $this->createNotFoundException();
            else
                $item->setDateSubmit(time());
        }
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (array_key_exists('version', $params) && array_key_exists('body', $params)) {
            $item->setBody($params['body']);
            $item->setVersion($params['version']);
        } else
            throw $this->createNotFoundException();
        $entityManager->persist($item);
        $entityManager->flush();
        return $this->json(['result' => 1]);
    }

    #[Route('/api/admin/wallets/list', name: 'app_admin_wallets_list')]
    public function app_admin_wallets_list(Jdate $jdate, Provider $provider, EntityManagerInterface $entityManager): JsonResponse
    {
        $bids = $entityManager->getRepository(Business::class)->findBy(['walletEnable' => true]);
        $resp = [];
        foreach ($bids as $bid) {
            $temp = [];
            $walletPays = $entityManager->getRepository(WalletTransaction::class)->findBy(['bid' => $bid, 'type' => 'pay']);
            $totalPays = 0;
            foreach ($walletPays as $walletPay) {
                $totalPays += $walletPay->getAmount();
            }
            $temp['totalPays'] = $totalPays;

            $walletIncomes = $entityManager->getRepository(WalletTransaction::class)->findAllIncome($bid);
            $totalIcome = 0;
            foreach ($walletIncomes as $walletIncome) {
                $totalIcome += $walletIncome->getAmount();
            }
            $temp['totalIncome'] = $totalIcome;

            $temp['id'] = $bid->getId();
            $temp['bidName'] = $bid->getName();
            $temp['walletEnabled'] = $bid->isWalletEnable();
            if ($bid->isWalletEnable()) {
                $temp['bankAcName'] = $bid->getWalletMatchBank()->getName();
                $temp['bankAcShaba'] = $bid->getWalletMatchBank()->getShaba();
                $temp['bankAcOwner'] = $bid->getWalletMatchBank()->getOwner();
                $temp['bankAcCardNum'] = $bid->getWalletMatchBank()->getCardNum();
            }

            $resp[] = $temp;
        }
        return $this->json($resp);
    }

    #[Route('/api/admin/wallets/transactions/list', name: 'app_admin_wallets_transactions_list')]
    public function app_admin_wallets_transactions_list(Jdate $jdate, Provider $provider, EntityManagerInterface $entityManager): JsonResponse
    {
        $items = $entityManager->getRepository(WalletTransaction::class)->findAll();
        $resp = [];
        foreach ($items as $item) {
            $temp = [];
            $temp['id'] = $item->getId();
            $temp['bidName'] = $item->getBid()->getName();
            $temp['walletEnabled'] = $item->getBid()->isWalletEnable();
            $temp['bankAcName'] = $item->getBid()->getWalletMatchBank()->getName();
            $temp['bankAcShaba'] = $item->getBid()->getWalletMatchBank()->getShaba();
            $temp['bankAcOwner'] = $item->getBid()->getWalletMatchBank()->getOwner();
            $temp['bankAcCardNum'] = $item->getBid()->getWalletMatchBank()->getCardNum();
            $temp['type'] = $item->getType();
            $temp['cardPan'] = $item->getCardPan();
            $temp['refID'] = $item->getRefID();
            $temp['shaba'] = $item->getShaba();
            $temp['dateSubmit'] = $jdate->jdate('Y/n/d H:i', $item->getDateSubmit());
            $temp['gatePay'] = $item->getGatePay();
            $resp[] = $temp;
        }
        return $this->json($resp);
    }

    #[Route('/api/admin/wallets/transactions/insert', name: 'app_admin_wallets_transactions_insert')]
    public function app_admin_wallets_transactions_insert(registryMGR $registryMGR, SMS $SMS, Jdate $jdate, Notification $notification, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (array_key_exists('bank', $params) && array_key_exists('refID', $params) && array_key_exists('bid', $params) && array_key_exists('amount', $params) && array_key_exists('shaba', $params) && array_key_exists('card', $params)) {
            $bid = $entityManager->getRepository(Business::class)->find($params['bid']['id']);
            if (!$bid)
                throw $this->createNotFoundException();
            $item = new WalletTransaction();
            $item->setBid($bid);
            $item->setType('pay');
            $item->setShaba($params['shaba']);
            $item->setAmount($params['amount']);
            $item->setCardPan($params['card']);
            $item->setDateSubmit(time());
            $item->setDes('واریز به حساب کسب و کار از طرف حسابیکس');
            $item->setRefID($params['refID']);
            $item->setGatePay($params['bank']);
            $item->setBank($bid->getWalletMatchBank()->getName());
            $entityManager->persist($item);
            $entityManager->flush();
            $notification->insert('تسویه کیف پول انجام شد.', '/acc/wallet/view', $bid, $bid->getOwner());
            $SMS->send(
                [$bid->getName()],
                $registryMGR->get('sms', 'walletpay'),
                $bid->getOwner()->getMobile()
            );
            return $this->json(['result' => 1]);
        }
        throw $this->createNotFoundException();
    }

    /**
     * @throws Exception
     */
    #[Route('/api/admin/database/backup/create', name: 'app_admin_database_backup_create')]
    public function app_admin_database_backup_create(KernelInterface $kernel): JsonResponse
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'doctrine:schema:create',
            // (optional) define the value of command arguments
            '--dump-sql' => true,
        ]);

        // You can use NullOutput() if you don't need the output
        $output = new BufferedOutput();
        $application->run($input, $output);
        // return the output, don't use if you used NullOutput()
        $content = $output->fetch();
        $time = time();
        $file = fopen(dirname(__DIR__, 3) . '/hesabixBackup/versions/Hesabix-' . $time . '.sql', 'w');
        fwrite($file, $content);
        fclose($file);
        return $this->json([
            'result' => 0,
            'filename' => 'Hesabix-' . $time . '.sql',
        ]);
    }
    #[Route('/api/admin/logs/last', name: 'api_admin_logs_last')]
    public function api_admin_logs_last(Extractor $extractor, Jdate $jdate, EntityManagerInterface $entityManager): JsonResponse
    {
        $logs = $entityManager->getRepository(\App\Entity\Log::class)->findBy([], ['id' => 'DESC'], 250);
        $temps = [];
        $logs = array_reverse($logs);
        foreach ($logs as $log) {
            $temp = [];
            if ($log->getUser())
                $temp['user'] = $log->getUser()->getFullName();
            else
                $temp['user'] = '';
            $temp['des'] = $log->getDes();
            $temp['part'] = $log->getPart();
            $temp['bid'] = $log->getBid()->getName();
            $temp['date'] = $jdate->jdate('Y/n/d H:i', $log->getDateSubmit());
            $temp['ipaddress'] = $log->getIpaddress();
            $temps[] = $temp;
        }
        return $this->json($extractor->operationSuccess(array_reverse($temps)));
    }

    #[Route('/api/admin/onlineusers/list', name: 'api_admin_online_users_list')]
    public function api_admin_online_users_list(Extractor $extractor, Jdate $jdate, EntityManagerInterface $entityManager): JsonResponse
    {
        $tokens = $entityManager->getRepository(UserToken::class)->getOnlines(120);
        $res = [];
        foreach ($tokens as $token) {
            $res[] = [
                'name' => $token->getUser()->getFullName(),
                'email' => $token->getUser()->getEmail(),
                'mobile' => $token->getUser()->getMobile(),
                'lastActive' => $token->getLastActive() - time(),
            ];
        }
        return $this->json($res);
    }


    /**
     * @throws Exception
     */
    #[Route('/script', name: 'script')]
    public function script(EntityManagerInterface $entitymanager): JsonResponse
    {
        $items = $entitymanager->getRepository(\App\Entity\HesabdariDoc::class)->findAll();
        foreach ($items as $item) {
            $item->setDate(str_replace("-", "/", $item->getDate()));
            $entitymanager->persist($item);
            $entitymanager->flush();

        }
        echo str_replace("-", "/", "1403-02-06");
    }
    /**
     * @throws Exception
     */
    #[Route('/script2', name: 'script2')]
    public function script2(EntityManagerInterface $entitymanager): JsonResponse
    {
        $banks = $entitymanager->getRepository(BankAccount::class)->findAll();
        foreach ($banks as $bank) {
            if ($bank->getMoney() == null) {
                $bank->setMoney($bank->getBid()->getMoney());
                $entitymanager->persist($bank);
            }
        }

        $items = $entitymanager->getRepository(Cashdesk::class)->findAll();
        foreach ($items as $item) {
            if ($item->getMoney() == null) {
                $item->setMoney($item->getBid()->getMoney());
                $entitymanager->persist($bank);
            }
        }
        $entitymanager->flush();
    }
}
