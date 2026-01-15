<?php

namespace App\Entity;

use App\Repository\BusinessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BusinessRepository::class)]
#[ORM\Table(name: 'business')]
#[ORM\HasLifecycleCallbacks]
class Business
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Business name is required')]
    private ?string $businessName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $tradeName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $businessType = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $uniqueIdentifierNumber = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $businessNumber = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $fiscalNumber = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $numberOfEmployees = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registrationDate = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $municipality = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Email(message: 'The email is not a valid email.')]
    private ?string $email = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $capital = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $arbkStatus = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'businesses')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'business', cascade: ['persist', 'remove'])]
    private $articles;

    #[ORM\OneToMany(targetEntity: BankAccount::class, mappedBy: 'business', cascade: ['persist', 'remove'])]
    private $bankAccounts;

    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'issuer')]
    private $invoicesAsIssuer;

    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'receiver')]
    private $invoicesAsReceiver;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->articles = new \Doctrine\Common\Collections\ArrayCollection();
        $this->bankAccounts = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invoicesAsIssuer = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invoicesAsReceiver = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): static
    {
        $this->businessName = $businessName;

        return $this;
    }

    public function getTradeName(): ?string
    {
        return $this->tradeName;
    }

    public function setTradeName(?string $tradeName): static
    {
        $this->tradeName = $tradeName;

        return $this;
    }

    public function getBusinessType(): ?string
    {
        return $this->businessType;
    }

    public function setBusinessType(?string $businessType): static
    {
        $this->businessType = $businessType;

        return $this;
    }

    public function getUniqueIdentifierNumber(): ?string
    {
        return $this->uniqueIdentifierNumber;
    }

    public function setUniqueIdentifierNumber(?string $uniqueIdentifierNumber): static
    {
        $this->uniqueIdentifierNumber = $uniqueIdentifierNumber;

        return $this;
    }

    public function getBusinessNumber(): ?string
    {
        return $this->businessNumber;
    }

    public function setBusinessNumber(?string $businessNumber): static
    {
        $this->businessNumber = $businessNumber;

        return $this;
    }

    public function getFiscalNumber(): ?string
    {
        return $this->fiscalNumber;
    }

    public function setFiscalNumber(?string $fiscalNumber): static
    {
        $this->fiscalNumber = $fiscalNumber;

        return $this;
    }

    public function getNumberOfEmployees(): ?int
    {
        return $this->numberOfEmployees;
    }

    public function setNumberOfEmployees(?int $numberOfEmployees): static
    {
        $this->numberOfEmployees = $numberOfEmployees;

        return $this;
    }

    public function getRegistrationDate(): ?\DateTimeImmutable
    {
        return $this->registrationDate;
    }

    public function setRegistrationDate(?\DateTimeImmutable $registrationDate): static
    {
        $this->registrationDate = $registrationDate;

        return $this;
    }

    public function getMunicipality(): ?string
    {
        return $this->municipality;
    }

    public function setMunicipality(?string $municipality): static
    {
        $this->municipality = $municipality;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCapital(): ?string
    {
        return $this->capital;
    }

    public function setCapital(?string $capital): static
    {
        $this->capital = $capital;

        return $this;
    }

    public function getArbkStatus(): ?string
    {
        return $this->arbkStatus;
    }

    public function setArbkStatus(?string $arbkStatus): static
    {
        $this->arbkStatus = $arbkStatus;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Article>
     */
    public function getArticles(): \Doctrine\Common\Collections\Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setBusiness($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getBusiness() === $this) {
                $article->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, BankAccount>
     */
    public function getBankAccounts(): \Doctrine\Common\Collections\Collection
    {
        return $this->bankAccounts;
    }

    public function addBankAccount(BankAccount $bankAccount): static
    {
        if (!$this->bankAccounts->contains($bankAccount)) {
            $this->bankAccounts->add($bankAccount);
            $bankAccount->setBusiness($this);
        }

        return $this;
    }

    public function removeBankAccount(BankAccount $bankAccount): static
    {
        if ($this->bankAccounts->removeElement($bankAccount)) {
            // set the owning side to null (unless already changed)
            if ($bankAccount->getBusiness() === $this) {
                $bankAccount->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Invoice>
     */
    public function getInvoicesAsIssuer(): \Doctrine\Common\Collections\Collection
    {
        return $this->invoicesAsIssuer;
    }

    public function addInvoiceAsIssuer(Invoice $invoice): static
    {
        if (!$this->invoicesAsIssuer->contains($invoice)) {
            $this->invoicesAsIssuer->add($invoice);
            $invoice->setIssuer($this);
        }

        return $this;
    }

    public function removeInvoiceAsIssuer(Invoice $invoice): static
    {
        if ($this->invoicesAsIssuer->removeElement($invoice)) {
            // set the owning side to null (unless already changed)
            if ($invoice->getIssuer() === $this) {
                $invoice->setIssuer(null);
            }
        }

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Invoice>
     */
    public function getInvoicesAsReceiver(): \Doctrine\Common\Collections\Collection
    {
        return $this->invoicesAsReceiver;
    }

    public function addInvoiceAsReceiver(Invoice $invoice): static
    {
        if (!$this->invoicesAsReceiver->contains($invoice)) {
            $this->invoicesAsReceiver->add($invoice);
            $invoice->setReceiver($this);
        }

        return $this;
    }

    public function removeInvoiceAsReceiver(Invoice $invoice): static
    {
        if ($this->invoicesAsReceiver->removeElement($invoice)) {
            // set the owning side to null (unless already changed)
            if ($invoice->getReceiver() === $this) {
                $invoice->setReceiver(null);
            }
        }

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
