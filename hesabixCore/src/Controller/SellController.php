<?php

namespace App\Controller;

use App\Service\Jdate;
use App\Service\Log;
use App\Service\Access;
use App\Service\Explore;
use App\Entity\Commodity;
use App\Service\PluginService;
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
use App\Service\registryMGR;
use App\Service\SMS;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class SellController extends AbstractController
{
    #[Route('/api/sell/edit/can/{code}', name: 'app_sell_can_edit')]
    public function app_sell_can_edit(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, string $code): JsonResponse
    {
        $canEdit = true;
        $acc = $access->hasRole('sell');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $code,
            'money' => $acc['money']
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

    #[Route('/api/sell/get/info/{code}', name: 'app_sell_get_info')]
    public function app_sell_get_info(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, string $code): JsonResponse
    {
        $acc = $access->hasRole('sell');
        if (!$acc)
            throw $this->createAccessDeniedException();
        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'code' => $code,
            'money' => $acc['money']
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        $result = Explore::ExploreSellDoc($doc);
        $profit = 0;
        //calculate profit
        foreach ($doc->getHesabdariRows() as $item) {
            if ($item->getCommodity() && $item->getCommdityCount()) {
                if ($acc['bid']->getProfitCalctype() == 'simple') {
                    $profit = $profit + (($item->getCommodity()->getPriceSell() - $item->getCommodity()->getPriceSell()) * $item->getCommdityCount());
                } elseif ($acc['bid']->getProfitCalctype() == 'lis') {
                    $last = $entityManager->getRepository(HesabdariRow::class)->findOneBy([
                        'commodity' => $item->getCommodity(),
                        'bs' => 0
                    ], [
                        'id' => 'DESC'
                    ]);
                    if ($last) {
                        $price = $last->getBd() / $last->getCommdityCount();
                        $profit = $profit + ((($item->getBs() / $item->getCommdityCount()) - $price) * $item->getCommdityCount());
                    } else {
                        $profit = $profit + $item->getBs();
                    }
                } else {
                    $lasts = $entityManager->getRepository(HesabdariRow::class)->findBy([
                        'commodity' => $item->getCommodity(),
                        'bs' => 0
                    ], [
                        'id' => 'DESC'
                    ]);
                    $avg = 0;
                    $count = 0;
                    foreach ($lasts as $last) {
                        $avg = $avg + $last->getBd();
                        $count = $count + $last->getCommdityCount();
                    }
                    if ($count != 0) {
                        $price = $avg / $count;
                        $profit = $profit + ((($item->getBs() / $item->getCommdityCount()) - $price) * $item->getCommdityCount());
                    } else {
                        $profit = $profit + $item->getBs();
                    }

                }

                //round output
                $profit = round($profit);
            }
        }
        $result['profit'] = $profit;
        return $this->json($result);
    }

    #[Route('/api/sell/mod', name: 'app_sell_mod')]
    public function app_sell_mod(registryMGR $registryMGR, PluginService $pluginService, SMS $SMS, Provider $provider, Extractor $extractor, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('sell');
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

            $rows = $doc->getHesabdariRows();
            foreach ($rows as $row)
                $entityManager->remove($row);
        } else {
            $doc = new HesabdariDoc();
            $doc->setBid($acc['bid']);
            $doc->setYear($acc['year']);
            $doc->setDateSubmit(time());
            $doc->setType('sell');
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
            $hesabdariRow->setBs($params['transferCost']);
            $hesabdariRow->setBd(0);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '61' // transfer cost income
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
            $hesabdariRow->setBs(0);
            $hesabdariRow->setBd($params['discountAll']);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '104' // سایر هزینه های پخش و فروش
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
            $hesabdariRow->setBs($row['sumWithoutTax'] + $row['tax']);
            $hesabdariRow->setBd(0);
            $hesabdariRow->setDiscount($row['discount']);
            $hesabdariRow->setTax($row['tax']);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => '53' // sell commodity
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
            if ($acc['bid']->isCommodityUpdateSellPriceAuto() == true && $commodity->getPriceSell() != $row['price']) {
                $commodity->setPriceSell($row['price']);
                $entityManager->persist($commodity);
            }
        }
        //set amount of document
        $doc->setAmount($sumTax + $sumTotal - $params['discountAll'] + $params['transferCost']);
        //set person person
        $hesabdariRow = new HesabdariRow();
        $hesabdariRow->setDes('فاکتور فروش');
        $hesabdariRow->setBid($acc['bid']);
        $hesabdariRow->setYear($acc['year']);
        $hesabdariRow->setDoc($doc);
        $hesabdariRow->setBs(0);
        $hesabdariRow->setBd($sumTax + $sumTotal + $params['transferCost'] - $params['discountAll']);
        $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
            'code' => '3' // persons
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
        if (!$doc->getShortlink()) {
            $doc->setShortlink($doc->getId());
        }

        //add pair docs
        if (array_key_exists('pair_docs', $params)) {
            foreach ($params['pair_docs'] as $pairCode) {
                $pair = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                    'bid' => $acc['bid'],
                    'code' => $pairCode,
                    'type' => 'buy'
                ]);
                if ($pair) {
                    $doc->addPairDoc($pair);
                }
            }
        }
        $entityManager->persist($doc);
        $entityManager->flush();

        $log->insert(
            'حسابداری',
            'سند حسابداری شماره ' . $doc->getCode() . ' ثبت / ویرایش شد.',
            $this->getUser(),
            $request->headers->get('activeBid'),
            $doc
        );
        //send sms to customer
        if (array_key_exists('sms', $params)) {
            if ($params['sms'] == true) {
                if ($pluginService->isActive('accpro', $acc['bid']) && $person->getMobile() != '' && $acc['bid']->getTel()) {
                    return $this->json([
                        'result' =>
                            $SMS->sendByBalance(
                                [$person->getnikename(), 'sell/' . $acc['bid']->getId() . '/' . $doc->getId(), $acc['bid']->getName(), $acc['bid']->getTel()],
                                $registryMGR->get('sms', 'plugAccproSharefaktor'),
                                $person->getMobile(),
                                $acc['bid'],
                                $this->getUser(),
                                3
                            )
                    ]);
                } else {
                    return $this->json([
                        'result' =>
                            $SMS->sendByBalance(
                                [$acc['bid']->getName(), 'sell/' . $acc['bid']->getId() . '/' . $doc->getId()],
                                $registryMGR->get('sms', 'sharefaktor'),
                                $person->getMobile(),
                                $acc['bid'],
                                $this->getUser(),
                                3
                            )
                    ]);
                }
            }
        }
        return $this->json($extractor->operationSuccess());
    }

    #[Route('/api/sell/label/change', name: 'app_sell_label_change')]
    public function app_sell_label_change(Request $request, Access $access, Extractor $extractor, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('sell');
        if (!$acc)
            throw $this->createAccessDeniedException();
        if ($params['label'] != 'clear') {
            $label = $entityManager->getRepository(InvoiceType::class)->findOneBy([
                'code' => $params['label']['code'],
                'type' => 'sell'
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

    #[Route('/api/sell/docs/search', name: 'app_sell_docs_search')]
    public function app_sell_docs_search(Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('sell');
        if (!$acc)
            throw $this->createAccessDeniedException();

        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        $data = $entityManager->getRepository(HesabdariDoc::class)->findBy([
            'bid' => $acc['bid'],
            'year' => $acc['year'],
            'type' => 'sell',
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
            // this variable is for store profit of invoice
            $temp['profit'] = 0;
            foreach ($item->getHesabdariRows() as $item) {
                if ($item->getRef()->getCode() == '104') {
                    $temp['discountAll'] = $item->getBd();
                } elseif ($item->getRef()->getCode() == '61') {
                    $temp['transferCost'] = $item->getBs();
                }

                //calculate profit
                if ($item->getCommodity() && $item->getCommdityCount()) {
                    if ($acc['bid']->getProfitCalctype() == 'lis') {
                        $last = $entityManager->getRepository(HesabdariRow::class)->findOneBy([
                            'commodity' => $item->getCommodity(),
                            'bs' => 0
                        ], [
                            'id' => 'DESC'
                        ]);
                        if ($last) {
                            $price = $last->getBd() / $last->getCommdityCount();
                            $temp['profit'] = $temp['profit'] + ((($item->getBs() / $item->getCommdityCount()) - $price) * $item->getCommdityCount());
                        } else {
                            $temp['profit'] = $temp['profit'] + $item->getBs();
                        }
                    } else {
                        $lasts = $entityManager->getRepository(HesabdariRow::class)->findBy([
                            'commodity' => $item->getCommodity(),
                            'bs' => 0
                        ], [
                            'id' => 'DESC'
                        ]);
                        $avg = 0;
                        $count = 0;
                        foreach ($lasts as $last) {
                            $avg = $avg + $last->getBd();
                            $count = $count + $last->getCommdityCount();
                        }
                        if ($count != 0) {
                            $price = $avg / $count;
                            $temp['profit'] = $temp['profit'] + ((($item->getBs() / $item->getCommdityCount()) - $price) * $item->getCommdityCount());
                        } else {
                            $temp['profit'] = $temp['profit'] + $item->getBs();
                        }
                    }

                    //round output
                    $temp['profit'] = round($temp['profit']);
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

    #[Route('/api/sell/posprinter/invoice', name: 'app_sell_posprinter_invoice')]
    public function app_sell_posprinter_invoice(Printers $printers, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('sell');
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
                $this->renderView('pdf/posPrinters/sell.html.twig', [
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
                $this->renderView('pdf/posPrinters/justSell.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                ]),
                true
            );
            $printers->addFile($pid, $acc, "fastSellInvoice");
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
            $printers->addFile($pid, $acc, "fastSellCashdesk");
        }

        return $this->json(['id' => $pdfPid]);
    }

    #[Route('/api/sell/print/invoice', name: 'app_sell_print_invoice')]
    public function app_sell_print_invoice(Printers $printers, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }

        $acc = $access->hasRole('sell');
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
                $note = $printSettings->getSellNoteString();
            }
            $pdfPid = $provider->createPrint(
                $acc['bid'],
                $this->getUser(),
                $this->renderView('pdf/printers/sell.html.twig', [
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
                $this->renderView('pdf/posPrinters/justSell.html.twig', [
                    'bid' => $acc['bid'],
                    'doc' => $doc,
                    'rows' => $doc->getHesabdariRows(),
                ]),
                false
            );
            $printers->addFile($pid, $acc, "fastSellInvoice");
        }
        return $this->json(['id' => $pdfPid]);
    }

    #[Route('/api/sell/chart/data', name: 'app_sell_chart_data')]
    public function app_sell_chart_data(Jdate $jdate, Printers $printers, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {

        $acc = $access->hasRole('sell');
        if (!$acc)
            throw $this->createAccessDeniedException();
        // create data numbers
        $dayTime = 3600 * 24;
        $dayNames = [];
        $daySells = [];
        for ($i = 0; $i < 7; $i++) {
            $dayInfo = [
                $jdate->jdate('l', time() - ($i * $dayTime)),
                $jdate->jdate('Y/n/d', time() - ($i * $dayTime))
            ];
            $dayNames[] = $jdate->jdate('l', time() - ($i * $dayTime));
            //get sell docs
            $docs = $entityManager->getRepository(HesabdariDoc::class)->findBy([
                'bid' => $acc['bid'],
                'money' => $acc['money'],
                'year' => $acc['year'],
                'type' => 'sell',
                'date' => $dayInfo[1],
            ]);
            $bd = 0;
            foreach ($docs as $doc) {
                foreach ($doc->getHesabdariRows() as $row) {
                    if ($row->getPerson()) {
                        $bd += $row->getBd();
                    }
                }
            }
            $daySells[] = $bd;
        }
        return $this->json([
            'dayNames' => $dayNames,
            'daySells' => $daySells
        ]);
    }
}
