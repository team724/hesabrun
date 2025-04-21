<?php

namespace App\Controller\Front;

use App\Entity\Business;
use App\Entity\HesabdariDoc;
use App\Entity\HesabdariRow;
use App\Entity\PrintOptions;
use App\Entity\StoreroomTicket;
use App\Service\Provider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ShortlinksController extends AbstractController
{
    #[Route('/st/{bid}/{id}', name: 'shortlinks_storeroom_show')]
    public function shortlinks_storeroom_show(string $bid, string $id, EntityManagerInterface $entityManager): Response
    {
        $bus = $entityManager->getRepository(Business::class)->find($bid);
        if (!$bus)
            throw $this->createNotFoundException();
        $ticket = $entityManager->getRepository(StoreroomTicket::class)->findOneBy([
            'bid' => $bid,
            'id' => $id
        ]);
        if (!$ticket)
            throw $this->createNotFoundException();
        if(!$ticket->isCanShare())
            throw $this->createAccessDeniedException();

        $person = null;
        
        foreach ($ticket->getDoc()->getHesabdariRows() as $item) {
            if ($item->getPerson()) {
                $person = $item->getPerson();
            }
        }
        return $this->render('shortlinks/storeroom.html.twig', [
            'bid' => $bus,
            'doc' => $ticket,
            'person'=>$person
        ]);
    }

    #[Route('/sl/{type}/{bid}/{link}/{msg}', name: 'shortlinks_show')]
    public function shortlinks_show(string $bid, string $type, string $link, EntityManagerInterface $entityManager, string $msg = 'default'): Response
    {
        $bus = $entityManager->getRepository(Business::class)->find($bid);
        if (!$bus)
            throw $this->createNotFoundException();
        if (!$bus->isShortlinks())
            throw $this->createNotFoundException();
        if ($type == 'sell') {
            $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'type' => 'sell',
                'shortlink' => $link
            ]);
            if (!$doc) {
                $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                    'type' => 'sell',
                    'id' => $link,
                    'bid' => $bus
                ]);
                if (!$doc)
                    throw $this->createNotFoundException();
            }

            $rows = $entityManager->getRepository(HesabdariRow::class)->findBy(['doc' => $doc]);
            $items = [];
            $person = null;
            foreach ($rows as $row) {
                if (!$row->getPerson())
                    $items[] = $row;
                else
                    $person = $row->getPerson();
            }
            //calculate total pays
            $totalPays = 0;
            foreach ($doc->getRelatedDocs() as $relatedDoc)
                $totalPays += $relatedDoc->getAmount();
        }
        return $this->render('shortlinks/sell.html.twig', [
            'bid' => $doc->getBid(),
            'doc' => $doc,
            'link' => $link,
            'rows' => $items,
            'person' => $person,
            'totalPays' => $totalPays,
            'msg' => $msg
        ]);
    }

    #[Route('/slpdf/sell/{bid}/{link}', name: 'shortlinks_pdf')]
    public function shortlinks_pdf(string $bid, string $link, EntityManagerInterface $entityManager, Provider $provider): Response
    {
        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'type' => 'sell',
            'id' => $link
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        $bid = $entityManager->getRepository(Business::class)->find($bid);
        if (!$bid)
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
        $printOptions = [
            'bidInfo' => true,
            'pays' => true,
            'taxInfo' => true,
            'discountInfo' => true,
            'note' => true,
            'paper' => 'A4-L'
        ];
        $note = '';
        $printSettings = $entityManager->getRepository(PrintOptions::class)->findOneBy(['bid' => $bid]);
        if ($printSettings) {
            $note = $printSettings->getSellNoteString();
        }
        $pdfPid = $provider->createPrint(
            $bid,
            $bid->getOwner(),
            $this->renderView('pdf/printers/sell.html.twig', [
                'bid' => $bid,
                'doc' => $doc,
                'rows' => $doc->getHesabdariRows(),
                'person' => $person,
                'discount' => $discount,
                'transfer' => $transfer,
                'printOptions' => $printOptions,
                'note' => $note
            ]),
            false,
            $printOptions['paper']
        );
        return $this->redirectToRoute('app_front_print', ['id' => $pdfPid]);
    }
}
