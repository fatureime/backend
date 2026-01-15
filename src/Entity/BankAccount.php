<?php

namespace App\Entity;

use App\Repository\BankAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'bank_account')]
#[ORM\HasLifecycleCallbacks]
class BankAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Business::class, inversedBy: 'bankAccounts')]
    #[ORM\JoinColumn(name: 'business_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\Column(type: Types::STRING, length: 11, nullable: true)]
    #[Assert\Length(max: 11, maxMessage: 'SWIFT code must be at most 11 characters')]
    private ?string $swift = null;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    #[Assert\Length(max: 34, maxMessage: 'IBAN must be at most 34 characters')]
    private ?string $iban = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Bank account number is required')]
    private ?string $bankAccountNumber = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function setBusiness(?Business $business): static
    {
        $this->business = $business;

        return $this;
    }

    public function getSwift(): ?string
    {
        return $this->swift;
    }

    public function setSwift(?string $swift): static
    {
        $this->swift = $swift ? strtoupper(trim($swift)) : null;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban ? strtoupper(str_replace(' ', '', trim($iban))) : null;

        return $this;
    }

    public function getBankAccountNumber(): ?string
    {
        return $this->bankAccountNumber;
    }

    public function setBankAccountNumber(string $bankAccountNumber): static
    {
        $this->bankAccountNumber = trim($bankAccountNumber);

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName ? trim($bankName) : null;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
