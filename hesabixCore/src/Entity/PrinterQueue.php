<?php

namespace App\Entity;

use App\Repository\PrinterQueueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrinterQueueRepository::class)]
class PrinterQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $dateSubmit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $submitter = null;

    #[ORM\Column(length: 255)]
    private ?string $pid = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $view = null;

    #[ORM\ManyToOne]
    private ?Business $bid = null;

    #[ORM\Column(nullable: true)]
    private ?bool $posprint = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paperSize = null;

    #[ORM\Column(nullable: true)]
    private ?bool $footer = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateSubmit(): ?string
    {
        return $this->dateSubmit;
    }

    public function setDateSubmit(string $dateSubmit): static
    {
        $this->dateSubmit = $dateSubmit;

        return $this;
    }

    public function getSubmitter(): ?User
    {
        return $this->submitter;
    }

    public function setSubmitter(?User $submitter): static
    {
        $this->submitter = $submitter;

        return $this;
    }

    public function getPid(): ?string
    {
        return $this->pid;
    }

    public function setPid(string $pid): static
    {
        $this->pid = $pid;

        return $this;
    }

    public function getView(): ?string
    {
        return $this->view;
    }

    public function setView(?string $view): static
    {
        $this->view = $view;

        return $this;
    }

    public function getBid(): ?Business
    {
        return $this->bid;
    }

    public function setBid(?Business $bid): static
    {
        $this->bid = $bid;

        return $this;
    }

    public function isPosprint(): ?bool
    {
        return $this->posprint;
    }

    public function setPosprint(?bool $posprint): static
    {
        $this->posprint = $posprint;

        return $this;
    }

    public function getPaperSize(): ?string
    {
        return $this->paperSize;
    }

    public function setPaperSize(?string $paperSize): static
    {
        $this->paperSize = $paperSize;

        return $this;
    }

    public function isFooter(): ?bool
    {
        return $this->footer;
    }

    public function setFooter(?bool $footer): static
    {
        $this->footer = $footer;

        return $this;
    }
}
