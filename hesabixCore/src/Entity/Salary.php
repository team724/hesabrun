<?php

namespace App\Entity;

use App\Repository\SalaryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;

#[ORM\Entity(repositoryClass: SalaryRepository::class)]
class Salary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $des = null;

    #[ORM\Column(length: 255)]
    private ?string $code = null;

    #[ORM\ManyToOne(inversedBy: 'salaries')]
    #[ORM\JoinColumn(nullable: false)]
    #[Ignore]
    private ?Business $bid = null;

    #[ORM\OneToMany(mappedBy: 'salary', targetEntity: HesabdariRow::class)]
    #[Ignore]
    private Collection $hesabdariRows;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $balance = null;

    #[ORM\ManyToOne(inversedBy: 'salaries')]
    private ?Money $money = null;

    public function __construct()
    {
        $this->hesabdariRows = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDes(): ?string
    {
        return $this->des;
    }

    public function setDes(?string $des): self
    {
        $this->des = $des;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getBid(): ?Business
    {
        return $this->bid;
    }

    public function setBid(?Business $bid): self
    {
        $this->bid = $bid;

        return $this;
    }

    /**
     * @return Collection<int, HesabdariRow>
     */
    public function getHesabdariRows(): Collection
    {
        return $this->hesabdariRows;
    }

    public function addHesabdariRow(HesabdariRow $hesabdariRow): self
    {
        if (!$this->hesabdariRows->contains($hesabdariRow)) {
            $this->hesabdariRows->add($hesabdariRow);
            $hesabdariRow->setSalary($this);
        }

        return $this;
    }

    public function removeHesabdariRow(HesabdariRow $hesabdariRow): self
    {
        if ($this->hesabdariRows->removeElement($hesabdariRow)) {
            // set the owning side to null (unless already changed)
            if ($hesabdariRow->getSalary() === $this) {
                $hesabdariRow->setSalary(null);
            }
        }

        return $this;
    }

    public function getBalance(): ?string
    {
        return $this->balance;
    }

    public function setBalance(?string $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    public function getMoney(): ?Money
    {
        return $this->money;
    }

    public function setMoney(?Money $money): static
    {
        $this->money = $money;

        return $this;
    }
}
