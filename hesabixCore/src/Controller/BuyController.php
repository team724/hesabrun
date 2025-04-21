<?php

namespace App\Controller;

use App\Service\Log;
use App\Service\Access;
use App\Service\Explore;
use App\Entity\Commodity;
use App\Service\Provider;
use App\Service\Extractor;
use App\Entity\HesabdariDoc;
use App\Entity\HesabdariRow;
use App\Entity\HesabdariTable;
use App\Entity\InvoiceType;
use App\Entity\Person;
use App\Entity\PrintOptions;
use App\Entity\StoreroomTicket;
use App\Service\Printers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class BuyController extends AbstractController
{
    #[Route('/api/buy/edit/can/{code}', name: 'app_buy_can_edit')]
    public function app_buy_can_edit(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, string $code): JsonResponse
    {
        $canEdit = true;
        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $code
        ]);
        //check related documents
        if (count($doc->getRelatedDocs()) != 0)
            $canEdit = false;

        //check storeroom tickets
        $tickets = $entityManager->getRepository(StoreroomTicket::class)->findBy(['doc' => $doc]);
        if (count($tickets) != 0)
            $canEdit = false;
        return $this->json([
            'result' => $canEdit
        ]);
    }

    #[Route('/api/buy/get/info/{code}', name: 'app_buy_get_info')]
    public function app_buy_get_info(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, string $code): JsonResponse
    {
        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();
        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $code
        ]);
        if (!$doc)
            throw $this->createNotFoundException();

        return $this->json(Explore::ExploreBuyDoc($doc));
    }

    #[Route('/api/buy/get/invoices/list', name: 'app_buy_get_invoices_list')]
    public function app_buy_get_invoices_list(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, string $code): JsonResponse
    {
        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();
        $invoices = $entityManager->getRepository(HesabdariDoc::class)->findBy([
            'bid' => $acc['bid'],
            'year' => $acc['year'],
            'type' => 'buy',
            'money' => $acc['money']
        ]);
        return $this->json(Explore::ExploreBuyDocsList($invoices));
    }

    #[Route('/api/buy/mod', name: 'app_buy_mod')]
    public function app_buy_mod(Provider $provider, Extractor $extractor, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();

        if (!array_key_exists('update', $params)) {
            return $this->json($extractor->paramsNotSend());
        }
        if ($params['update'] != '') {
            $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'bid' => $acc['bid'],
                'year' => $acc['year'],
                'code' => $params['update'],
                'money' => $acc['money']
            ]);
            if (!$doc)
                return $this->json($extractor->notFound());

            $rows = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'doc' => $doc
            ]);
            foreach ($rows as $row)
                $entityManager->remove($row);
        } else {
            $doc = new HesabdariDoc();
            $doc->setBid($acc['bid']);
            $doc->setYear($acc['year']);
            $doc->setDateSubmit(time());
            $doc->setType('buy');
            $doc->setSubmitter($this->getUser());
            $doc->setMoney($acc['money']);
            $doc->setCode($provider->getAccountingCode($acc['bid'], 'accounting'));
        }
        if ($params['transferCost'] != 0) {
            $hesabdariRow = new HesabdariRow();
            $hesabdariRow->setDes('حمل و نقل کالا');
            $hesabdariRow->setBid($acc['bid']);
            $hesabdariRow->setYear($acc['year']);
            $hesabdariRow->setDoc($doc);
            $hesabdariRow->setBd($params['transferCost']);
            $hesabdariRow->setBs(0);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '90' // transfer cost income
            ]);
            $hesabdariRow->setRef($ref);
            $entityManager->persist($hesabdariRow);
        }
        if ($params['discountAll'] != 0) {
            $hesabdariRow = new HesabdariRow();
            $hesabdariRow->setDes('تخفیف فاکتور');
            $hesabdariRow->setBid($acc['bid']);
            $hesabdariRow->setYear($acc['year']);
            $hesabdariRow->setDoc($doc);
            $hesabdariRow->setBd(0);
            $hesabdariRow->setBs($params['discountAll']);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '51' //  تخفیفات نقدی خرید 
            ]);
            $hesabdariRow->setRef($ref);
            $entityManager->persist($hesabdariRow);
        }
        $doc->setDes($params['des']);
        $doc->setDate($params['date']);
        $sumTax = 0;
        $sumTotal = 0;
        foreach ($params['rows'] as $row) {
            $sumTax += $row['tax'];
            $sumTotal += $row['sumWithoutTax'];
            $hesabdariRow = new HesabdariRow();
            $hesabdariRow->setDes($row['des']);
            $hesabdariRow->setBid($acc['bid']);
            $hesabdariRow->setYear($acc['year']);
            $hesabdariRow->setDoc($doc);
            $hesabdariRow->setBd($row['sumWithoutTax'] + $row['tax']);
            $hesabdariRow->setBs(0);
            $hesabdariRow->setDiscount($row['discount']);
            $hesabdariRow->setTax($row['tax']);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '120' // buy commodity
            ]);
            $hesabdariRow->setRef($ref);
            $row['count'] = str_replace(',', '', $row['count']);
            $commodity = $entityManager->getRepository(Commodity::class)->findOneBy([
                'id' => $row['commodity']['id'],
                'bid' => $acc['bid']
            ]);
            if (!$commodity)
                return $this->json($extractor->paramsNotSend());
            $hesabdariRow->setCommodity($commodity);
            $hesabdariRow->setCommdityCount($row['count']);
            $entityManager->persist($hesabdariRow);

            //update commodity price for auto update price option
            if ($acc['bid']->isCommodityUpdateBuyPriceAuto() == true && $commodity->getPriceBuy() != $row['price']) {
                $commodity->setPriceBuy($row['price']);
                $entityManager->persist($commodity);
            }
        }
        //set amount of document
        $doc->setAmount($sumTax + $sumTotal - $params['discountAll'] + $params['transferCost']);
        //set person person
        $hesabdariRow = new HesabdariRow();
        $hesabdariRow->setDes('فاکتور خرید');
        $hesabdariRow->setBid($acc['bid']);
        $hesabdariRow->setYear($acc['year']);
        $hesabdariRow->setDoc($doc);
        $hesabdariRow->setBd(0);
        $hesabdariRow->setBs($sumTax + $sumTotal + $params['transferCost'] - $params['discountAll']);
        $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
            'code' => '8' // persons
        ]);
        $hesabdariRow->setRef($ref);
        $person = $entityManager->getRepository(Person::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $params['person']['code']
        ]);
        if (!$person)
            return $this->json($extractor->paramsNotSend());
        $hesabdariRow->setPerson($person);
        $entityManager->persist($hesabdariRow);

        //set tax info

        $entityManager->persist($doc);
        $entityManager->flush();
        $log->insert(
            'حسابداری',
            'سند حسابداری شماره ' . $doc->getCode() . ' ثبت / ویرایش شد.',
            $this->getUser(),
            $request->headers->get('activeBid'),
            $doc
        );
        return $this->json($extractor->operationSuccess());
    }

    #[Route('/api/buy/label/change', name: 'app_buy_label_change')]
    public function app_buy_label_change(Request $request, Access $access, Extractor $extractor, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();
        if ($params['label'] != 'clear') {
            $label = $entityManager->getRepository(InvoiceType::class)->findOneBy([
                'code' => $params['label']['code'],
                'type' => 'buy'
            ]);
            if (!$label)
                return $this->json($extractor->notFound());
        }
        foreach ($params['items'] as $item) {
            $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'bid' => $acc['bid'],
                'year' => $acc['year'],
                'code' => $item['code'],
                'money' => $acc['money']
            ]);
            if (!$doc)
                return $this->json($extractor->notFound());
            if ($params['label'] != 'clear') {
                $doc->setInvoiceLabel($label);
                $entityManager->persist($doc);
                $log->insert(
                    'حسابداری',
                    ' تغییر برچسب فاکتور‌ شماره ' . $doc->getCode() . ' به ' . $label->getLabel(),
                    $this->getUser(),
                    $acc['bid']->getId(),
                    $doc
                );
            } else {
                $doc->setInvoiceLabel(null);
                $entityManager->persist($doc);
                $log->insert(
                    'حسابداری',
                    ' حذف برچسب فاکتور‌ شماره ' . $doc->getCode(),
                    $this->getUser(),
                    $acc['bid']->getId(),
                    $doc
                );
            }
        }
        $entityManager->flush();
        return $this->json($extractor->operationSuccess());
    }

    #[Route('/api/buy/docs/search', name: 'app_buy_docs_search')]
    public function app_buy_docs_search(Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        $data = $entityManager->getRepository(HesabdariDoc::class)->findBy([
            'bid' => $acc['bid'],
            'year' => $acc['year'],
            'type' => 'buy',
            'money' => $acc['money']
        ], [
            'id' => 'DESC'
        ]);

        $dataTemp = [];
        foreach ($data as $item) {
            $temp = [
                'id' => $item->getId(),
                'dateSubmit' => $item->getDateSubmit(),
                'date' => $item->getDate(),
                'type' => $item->getType(),
                'code' => $item->getCode(),
                'des' => $item->getDes(),
                'amount' => $item->getAmount(),
                'submitter' => $item->getSubmitter()->getFullName(),
            ];
            $mainRow = $entityManager->getRepository(HesabdariRow::class)->getNotEqual($item, 'person');
            $temp['person'] = '';
            if ($mainRow)
                $temp['person'] = Explore::ExplorePerson($mainRow->getPerson());

            $temp['label'] = null;
            if ($item->getInvoiceLabel()) {
                $temp['label'] = [
                    'code' => $item->getInvoiceLabel()->getCode(),
                    'label' => $item->getInvoiceLabel()->getLabel()
                ];
            }

            $temp['relatedDocsCount'] = count($item->getRelatedDocs());
            $pays = 0;
            foreach ($item->getRelatedDocs() as $relatedDoc) {
                $pays += $relatedDoc->getAmount();
            }
            $temp['relatedDocsPays'] = $pays;

            $temp['commodities'] = [];
            foreach ($item->getHesabdariRows() as $item) {
                if ($item->getRef()->getCode() == '104') {
                    $temp['discountAll'] = $item->getBd();
                } elseif ($item->getRef()->getCode() == '90') {
                    $temp['transferCost'] = $item->getBs();
                }
                if ($item->getCommodity()) {
                    $temp['commodities'][] = Explore::ExploreCommodity($item->getCommodity(), $item->getCommdityCount());
                }
            }
            if (!array_key_exists('discountAll', $temp))
                $temp['discountAll'] = 0;
            if (!array_key_exists('transferCost', $temp))
                $temp['transferCost'] = 0;

            $dataTemp[] = $temp;
        }
        return $this->json($dataTemp);
    }

    #[Route('/api/buy/posprinter/invoice', name: 'app_buy_posprinter_invoice')]
    public function app_buy_posprinter_invoice(Printers $printers, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $params['code'],
            'money' => $acc['money']
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        $pdfPid = 0;
        if ($params['pdf']) {
            $pdfPid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/posPrinters/buy.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                    'printInvoice' => $params['posPrint'],
                    'printcashdeskRecp' => $params['posPrintRecp'],
                ]),
                true
            );
        }


        if ($params['posPrint'] == true) {
            $pid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/posPrinters/justBuy.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                ]),
                true
            );
            $printers->addFile($pid, $acc, "fastBuyInvoice");
        }
        if ($params['posPrintRecp'] == true) {
            $pid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/posPrinters/cashdesk.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                ]),
                true
            );
            $printers->addFile($pid, $acc, "fastBuyCashdesk");
        }

        return $this->json(['id' => $pdfPid]);
    }

    #[Route('/api/buy/print/invoice', name: 'app_buy_print_invoice')]
    public function app_buy_print_invoice(Printers $printers, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('buy');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $params['code'],
            'money' => $acc['money']
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        $person = null;
        $discount = 0;
        $transfer = 0;
        foreach ($doc->getHesabdariRows() as $item) {
            if ($item->getPerson()) {
                $person = $item->getPerson();
            } elseif ($item->getRef()->getCode() == 104) {
                $discount = $item->getBd();
            } elseif ($item->getRef()->getCode() == 61) {
                $transfer = $item->getBs();
            }
        }
        $pdfPid = 0;
        if ($params['pdf']) {
            $printOptions = [
                'bidInfo' => true,
                'pays' => true,
                'taxInfo' => true,
                'discountInfo' => true,
                'note' => true,
                'paper' => 'A4-L'
            ];
            if (array_key_exists('printOptions', $params)) {
                if (array_key_exists('bidInfo', $params['printOptions'])) {
                    $printOptions['bidInfo'] = $params['printOptions']['bidInfo'];
                }
                if (array_key_exists('pays', $params['printOptions'])) {
                    $printOptions['pays'] = $params['printOptions']['pays'];
                }
                if (array_key_exists('taxInfo', $params['printOptions'])) {
                    $printOptions['taxInfo'] = $params['printOptions']['taxInfo'];
                }
                if (array_key_exists('discountInfo', $params['printOptions'])) {
                    $printOptions['discountInfo'] = $params['printOptions']['discountInfo'];
                }
                if (array_key_exists('note', $params['printOptions'])) {
                    $printOptions['note'] = $params['printOptions']['note'];
                }
                if (array_key_exists('paper', $params['printOptions'])) {
                    $printOptions['paper'] = $params['printOptions']['paper'];
                }
            }
            $note = '';
            $printSettings = $entityManager->getRepository(PrintOptions::class)->findOneBy(['bid' => $acc['bid']]);
            if ($printSettings) {
                $note = $printSettings->getBuyNoteString();
            }
            $pdfPid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/printers/buy.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                    'person' => $person,
                    'printInvoice' => $params['printers'],
                    'discount' => $discount,
                    'transfer' => $transfer,
                    'printOptions' => $printOptions,
                    'note' => $note
                ]),
                false,
                $printOptions['paper']
            );
        }
        if ($params['printers'] == true) {
            $pid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/posPrinters/justBuy.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                ]),
                false
            );
            $printers->addFile($pid, $acc, "fastBuyInvoice");
        }
        return $this->json(['id' => $pdfPid]);
    }
}
